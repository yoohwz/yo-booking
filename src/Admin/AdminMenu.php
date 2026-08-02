<?php
/**
 * WordPress admin shell.
 *
 * @package YoBooking
 */

namespace YoBooking\Admin;

use YoBooking\Database\Migrator;
use YoBooking\Payments\PaymentProviderRegistry;
use YoBooking\Payments\Currency;
use YoBooking\Payments\Money;
use YoBooking\Repositories\AuditLogRepository;
use YoBooking\Settings\Repository as SettingsRepository;
use YoBooking\Support\Capabilities;
use YoBooking\Support\DateTimeFormatter;
use YoBooking\Support\PhoneNumber;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the base admin screen.
 */
final class AdminMenu {
	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_menu', array( $this, 'register_settings_menu' ), 99 );
		add_action( 'admin_init', array( $this, 'redirect_legacy_settings_pages' ) );
		add_action( 'admin_post_yo_booking_save_settings', array( $this, 'save_settings' ) );
		add_filter( 'plugin_action_links_' . YO_BOOKING_BASENAME, array( $this, 'add_action_links' ) );
	}

	/**
	 * Add the top-level admin page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Yo Booking', 'yo-booking' ),
			__( 'Yo Booking', 'yo-booking' ),
			Capabilities::appointments(),
			'yo-booking',
			array( new DashboardPage(), 'render' ),
			'none',
			3
		);

		add_submenu_page(
			'yo-booking',
			__( 'Dashboard', 'yo-booking' ),
			__( 'Dashboard', 'yo-booking' ),
			Capabilities::appointments(),
			'yo-booking'
		);
	}

	/**
	 * Add settings after the operational menu items.
	 *
	 * @return void
	 */
	public function register_settings_menu() {
		add_submenu_page(
			'yo-booking',
			__( 'Settings', 'yo-booking' ),
			__( 'Settings', 'yo-booking' ),
			Capabilities::settings(),
			'yo-booking-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Add plugin row shortcuts.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=yo-booking-settings' ) ),
			esc_html__( 'Settings', 'yo-booking' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Render settings.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( Capabilities::settings() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'yo-booking' ) );
		}

		$active_tab = isset( $_GET['settings_tab'] ) ? sanitize_key( wp_unslash( $_GET['settings_tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = in_array( $active_tab, array( 'general', 'payments', 'integrations', 'audit', 'maintenance' ), true ) ? $active_tab : 'general';

		?>
		<div class="wrap yo-booking-admin">
			<div class="yo-page-header"><div><h1><?php echo esc_html__( 'Settings', 'yo-booking' ); ?></h1><p><?php echo esc_html__( 'Configure booking rules, payments, integrations, and data handling.', 'yo-booking' ); ?></p></div></div>
			<nav class="yo-settings-tabs" aria-label="<?php echo esc_attr__( 'Settings sections', 'yo-booking' ); ?>">
				<?php foreach ( array( 'general' => __( 'General', 'yo-booking' ), 'payments' => __( 'Payments', 'yo-booking' ), 'integrations' => __( 'Integrations', 'yo-booking' ), 'audit' => __( 'Audit log', 'yo-booking' ), 'maintenance' => __( 'Maintenance', 'yo-booking' ) ) as $tab => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'yo-booking-settings', 'settings_tab' => $tab ), admin_url( 'admin.php' ) ) ); ?>" class="<?php echo esc_attr( $active_tab === $tab ? 'is-active' : '' ); ?>" <?php echo $active_tab === $tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php if ( isset( $_GET['settings-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Settings saved.', 'yo-booking' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( 'integrations' === $active_tab ) : ?>
				<?php ( new IntegrationsPage() )->render_embedded(); ?>
			</div>
				<?php return; ?>
			<?php elseif ( 'audit' === $active_tab ) : ?>
				<?php ( new AuditLogPage() )->render_embedded(); ?>
			</div>
				<?php return; ?>
			<?php elseif ( 'maintenance' === $active_tab ) : ?>
				<?php ( new MaintenancePage() )->render_embedded(); ?>
			</div>
				<?php return; ?>
			<?php endif; ?>

			<?php
			$settings          = ( new SettingsRepository() )->all();
			$payment_providers = ( new PaymentProviderRegistry() )->all();
			$company_logo_id   = absint( $settings['company']['logo_id'] );
			$company_logo_url  = $company_logo_id && wp_attachment_is_image( $company_logo_id ) ? wp_get_attachment_image_url( $company_logo_id, 'thumbnail' ) : '';
			?>

			<?php if ( 'general' === $active_tab ) : ?>
			<details class="yo-card" style="max-width: 850px;">
				<summary><strong><?php echo esc_html__( 'System status', 'yo-booking' ); ?></strong></summary>
			<table class="widefat striped" style="margin-top: 14px;">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Plugin version', 'yo-booking' ); ?></th>
						<td><?php echo esc_html( YO_BOOKING_VERSION ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Schema version', 'yo-booking' ); ?></th>
						<td><?php echo esc_html( get_option( 'yo_booking_schema_version', 'not installed' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Managed tables', 'yo-booking' ); ?></th>
						<td><?php echo esc_html( (string) count( Migrator::managed_tables() ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Shortcode', 'yo-booking' ); ?></th>
						<td><code>[yo-booking]</code></td>
					</tr>
				</tbody>
			</table></details>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yo-editor yo-settings-layout" style="margin-top: 20px;">
				<input type="hidden" name="action" value="yo_booking_save_settings" />
				<input type="hidden" name="settings_tab" value="<?php echo esc_attr( $active_tab ); ?>" />
				<?php wp_nonce_field( 'yo_booking_save_settings' ); ?>

				<div class="yo-settings-groups">
					<?php if ( 'general' === $active_tab ) : ?>
					<section class="yo-card yo-settings-panel" data-settings-section="company">
						<div class="yo-settings-panel__header"><h2><?php echo esc_html__( 'Company information', 'yo-booking' ); ?></h2><p><?php echo esc_html__( 'Business details shown across booking and customer communications.', 'yo-booking' ); ?></p></div>
						<table class="form-table" role="presentation"><tbody>
						<tr>
							<th scope="row">
								<label for="yo_booking_company_name"><?php echo esc_html__( 'Company name', 'yo-booking' ); ?></label>
							</th>
							<td>
								<input name="company_name" id="yo_booking_company_name" type="text" class="regular-text" value="<?php echo esc_attr( $settings['company']['name'] ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Company logo', 'yo-booking' ); ?></th>
							<td>
								<div class="yo-media-field" data-yo-media-field data-frame-title="<?php echo esc_attr__( 'Choose a company logo', 'yo-booking' ); ?>" data-frame-button="<?php echo esc_attr__( 'Use this logo', 'yo-booking' ); ?>" data-preview-alt="<?php echo esc_attr__( 'Company logo preview', 'yo-booking' ); ?>">
									<input name="company_logo_id" id="yo_booking_company_logo_id" type="hidden" value="<?php echo esc_attr( (string) $company_logo_id ); ?>" data-yo-media-id />
									<div class="yo-media-preview" aria-live="polite">
										<img data-yo-media-image alt="<?php echo esc_attr__( 'Company logo preview', 'yo-booking' ); ?>" <?php if ( $company_logo_url ) : ?>src="<?php echo esc_url( $company_logo_url ); ?>"<?php else : ?>hidden<?php endif; ?> />
										<span data-yo-media-placeholder <?php if ( $company_logo_url ) : ?>hidden<?php endif; ?>><?php echo esc_html__( 'No logo selected', 'yo-booking' ); ?></span>
									</div>
									<div class="yo-media-actions">
										<button type="button" class="button" data-yo-media-select><span class="fi fi-rr-picture" aria-hidden="true"></span><?php echo esc_html__( 'Choose logo', 'yo-booking' ); ?></button>
										<button type="button" class="button-link yo-media-remove" data-yo-media-remove <?php if ( ! $company_logo_url ) : ?>hidden<?php endif; ?>><?php echo esc_html__( 'Remove logo', 'yo-booking' ); ?></button>
									</div>
								</div>
								<p class="description yo-media-description"><?php echo esc_html__( 'Choose an existing image or upload a new one from the WordPress Media Library.', 'yo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="yo_booking_company_email"><?php echo esc_html__( 'Company email', 'yo-booking' ); ?></label>
							</th>
							<td>
								<input name="company_email" id="yo_booking_company_email" type="email" class="regular-text" value="<?php echo esc_attr( $settings['company']['email'] ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="yo_booking_company_phone"><?php echo esc_html__( 'Company phone', 'yo-booking' ); ?></label>
							</th>
							<td>
								<input name="company_phone" id="yo_booking_company_phone" type="tel" class="regular-text" value="<?php echo esc_attr( $settings['company']['phone'] ); ?>" data-yo-phone />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="yo_booking_company_address"><?php echo esc_html__( 'Company address', 'yo-booking' ); ?></label>
							</th>
							<td>
								<textarea name="company_address" id="yo_booking_company_address" class="large-text" rows="3"><?php echo esc_textarea( $settings['company']['address'] ); ?></textarea>
							</td>
						</tr>
						</tbody></table>
					</section>

					<section class="yo-card yo-settings-panel" data-settings-section="regional">
						<div class="yo-settings-panel__header"><h2><?php echo esc_html__( 'Regional settings', 'yo-booking' ); ?></h2><p><?php echo esc_html__( 'Phone, timezone, date, and time defaults inherited by bookings.', 'yo-booking' ); ?></p></div>
						<table class="form-table" role="presentation"><tbody>
						<tr>
							<th scope="row">
								<label for="yo_booking_default_phone_country"><?php echo esc_html__( 'Default phone country', 'yo-booking' ); ?></label>
							</th>
							<td>
								<select name="default_phone_country" id="yo_booking_default_phone_country" data-phone-country-setting data-selected-country="<?php echo esc_attr( $settings['booking']['default_phone_country'] ); ?>">
									<option value=""><?php echo esc_html__( 'Automatic — use WordPress locale', 'yo-booking' ); ?></option>
								</select>
								<p class="description"><?php echo esc_html__( 'Used for new phone numbers when no customer, staff, or session country is available.', 'yo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<?php echo esc_html__( 'Date and time', 'yo-booking' ); ?>
							</th>
							<td>
								<strong><?php echo esc_html( DateTimeFormatter::timezone_name() ); ?></strong><br />
								<span class="description"><?php echo esc_html( DateTimeFormatter::timestamp( time() ) ); ?></span>
								<p class="description"><?php echo esc_html__( 'Yo Booking follows the site timezone, date format, and time format.', 'yo-booking' ); ?> <a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>"><?php echo esc_html__( 'Change in WordPress Settings', 'yo-booking' ); ?></a></p>
							</td>
						</tr>
						</tbody></table>
					</section>

					<section class="yo-card yo-settings-panel" data-settings-section="booking-rules">
						<div class="yo-settings-panel__header"><h2><?php echo esc_html__( 'Booking rules', 'yo-booking' ); ?></h2><p><?php echo esc_html__( 'Default timing, availability window, and appointment status.', 'yo-booking' ); ?></p></div>
						<table class="form-table" role="presentation"><tbody>
						<tr>
							<th scope="row">
								<label for="yo_booking_slot_interval"><?php echo esc_html__( 'Slot interval', 'yo-booking' ); ?></label>
							</th>
							<td>
								<input name="slot_interval_minutes" id="yo_booking_slot_interval" type="number" min="5" step="5" value="<?php echo esc_attr( (string) $settings['booking']['slot_interval_minutes'] ); ?>" />
								<span><?php echo esc_html__( 'minutes', 'yo-booking' ); ?></span>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="yo_booking_lead_time"><?php echo esc_html__( 'Lead time', 'yo-booking' ); ?></label>
							</th>
							<td>
								<input name="lead_time_minutes" id="yo_booking_lead_time" type="number" min="0" step="5" value="<?php echo esc_attr( (string) $settings['booking']['lead_time_minutes'] ); ?>" />
								<span><?php echo esc_html__( 'minutes', 'yo-booking' ); ?></span>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="yo_booking_window_days"><?php echo esc_html__( 'Booking window', 'yo-booking' ); ?></label>
							</th>
							<td>
								<input name="booking_window_days" id="yo_booking_window_days" type="number" min="1" max="730" value="<?php echo esc_attr( (string) $settings['booking']['booking_window_days'] ); ?>" />
								<span><?php echo esc_html__( 'days', 'yo-booking' ); ?></span>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="yo_booking_default_status"><?php echo esc_html__( 'Default appointment status', 'yo-booking' ); ?></label>
							</th>
							<td>
								<select name="default_status" id="yo_booking_default_status">
									<option value="pending" <?php selected( $settings['booking']['default_status'], 'pending' ); ?>><?php echo esc_html__( 'Pending', 'yo-booking' ); ?></option>
									<option value="confirmed" <?php selected( $settings['booking']['default_status'], 'confirmed' ); ?>><?php echo esc_html__( 'Confirmed', 'yo-booking' ); ?></option>
								</select>
							</td>
						</tr>
						</tbody></table>
					</section>

					<section class="yo-card yo-settings-panel" data-settings-section="customer-booking">
						<div class="yo-settings-panel__header"><h2><?php echo esc_html__( 'Customer booking', 'yo-booking' ); ?></h2><p><?php echo esc_html__( 'Control booking access, required details, and self-service deadlines.', 'yo-booking' ); ?></p></div>
						<table class="form-table" role="presentation"><tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Booking access', 'yo-booking' ); ?></th>
							<td>
								<fieldset class="yo-checkbox-list">
									<label><input name="allow_guest_booking" type="checkbox" value="1" <?php checked( ! empty( $settings['booking']['allow_guest_booking'] ) ); ?> /> <?php echo esc_html__( 'Allow visitors to book without a WordPress account.', 'yo-booking' ); ?></label>
									<label><input name="allow_staff_selection" type="checkbox" value="1" <?php checked( ! empty( $settings['booking']['allow_staff_selection'] ) ); ?> /> <?php echo esc_html__( 'Allow customers to select a staff member.', 'yo-booking' ); ?></label>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Required customer details', 'yo-booking' ); ?></th>
							<td>
								<fieldset class="yo-checkbox-list">
									<label><input name="require_email" type="checkbox" value="1" <?php checked( ! empty( $settings['booking']['require_email'] ) ); ?> /> <?php echo esc_html__( 'Require an email address.', 'yo-booking' ); ?></label>
									<label><input name="require_phone" type="checkbox" value="1" <?php checked( ! empty( $settings['booking']['require_phone'] ) ); ?> /> <?php echo esc_html__( 'Require a phone number.', 'yo-booking' ); ?></label>
									<label><input name="marketing_consent_required" type="checkbox" value="1" <?php checked( ! empty( $settings['privacy']['marketing_consent_required'] ) ); ?> /> <?php echo esc_html__( 'Require explicit marketing consent before booking.', 'yo-booking' ); ?></label>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Self-service deadlines', 'yo-booking' ); ?></th>
							<td>
								<div class="yo-form-row">
									<div class="yo-form-field"><label for="yo_booking_cancel_window"><?php echo esc_html__( 'Cancellation', 'yo-booking' ); ?></label><input name="cancellation_window_hours" id="yo_booking_cancel_window" type="number" min="0" max="8760" value="<?php echo esc_attr( (string) $settings['booking']['cancellation_window_hours'] ); ?>" /><span class="description"><?php echo esc_html__( 'hours before start', 'yo-booking' ); ?></span></div>
									<div class="yo-form-field"><label for="yo_booking_reschedule_window"><?php echo esc_html__( 'Reschedule', 'yo-booking' ); ?></label><input name="reschedule_window_hours" id="yo_booking_reschedule_window" type="number" min="0" max="8760" value="<?php echo esc_attr( (string) $settings['booking']['reschedule_window_hours'] ); ?>" /><span class="description"><?php echo esc_html__( 'hours before start', 'yo-booking' ); ?></span></div>
								</div>
							</td>
						</tr>
						</tbody></table>
					</section>
					<?php endif; ?>
					<?php if ( 'payments' === $active_tab ) : ?>
					<section class="yo-card yo-settings-panel" data-settings-section="currency">
						<div class="yo-settings-panel__header"><h2><?php echo esc_html__( 'Currency & display', 'yo-booking' ); ?></h2><p><?php echo esc_html__( 'Choose the default currency and how monetary values appear.', 'yo-booking' ); ?></p></div>
						<table class="form-table" role="presentation"><tbody>
						<tr>
							<th scope="row"><label for="yo_booking_company_currency"><?php echo esc_html__( 'Default currency', 'yo-booking' ); ?></label></th>
							<td><select name="company_currency" id="yo_booking_company_currency"><?php foreach ( Currency::choices( $settings['company']['currency'] ) as $currency_code => $currency_data ) : ?><option value="<?php echo esc_attr( $currency_code ); ?>" <?php selected( $settings['company']['currency'], $currency_code ); ?>><?php echo esc_html( $currency_code . ' - ' . $currency_data['name'] ); ?></option><?php endforeach; ?></select><p class="description"><?php echo esc_html__( 'Used as the default for new services. Existing services keep their currency.', 'yo-booking' ); ?></p></td>
						</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Currency display', 'yo-booking' ); ?></th>
								<td>
									<div class="yo-form-row">
										<div class="yo-form-field">
											<label for="yo_booking_currency_position"><?php echo esc_html__( 'Currency position', 'yo-booking' ); ?></label>
											<select name="payment_currency_position" id="yo_booking_currency_position">
												<option value="currency" <?php selected( $settings['payments']['currency_position'], 'currency' ); ?>><?php echo esc_html__( 'Currency default', 'yo-booking' ); ?></option>
												<option value="left" <?php selected( $settings['payments']['currency_position'], 'left' ); ?>><?php echo esc_html__( 'Left ($99.00)', 'yo-booking' ); ?></option>
												<option value="left_space" <?php selected( $settings['payments']['currency_position'], 'left_space' ); ?>><?php echo esc_html__( 'Left with space ($ 99.00)', 'yo-booking' ); ?></option>
												<option value="right" <?php selected( $settings['payments']['currency_position'], 'right' ); ?>><?php echo esc_html__( 'Right (99.00$)', 'yo-booking' ); ?></option>
												<option value="right_space" <?php selected( $settings['payments']['currency_position'], 'right_space' ); ?>><?php echo esc_html__( 'Right with space (99.00 $)', 'yo-booking' ); ?></option>
											</select>
										</div>
										<div class="yo-form-field">
											<label for="yo_booking_thousand_separator"><?php echo esc_html__( 'Thousand separator', 'yo-booking' ); ?></label>
											<select name="payment_thousand_separator" id="yo_booking_thousand_separator">
												<option value="locale" <?php selected( $settings['payments']['thousand_separator'], 'locale' ); ?>><?php echo esc_html__( 'WordPress locale', 'yo-booking' ); ?></option>
												<option value="comma" <?php selected( $settings['payments']['thousand_separator'], 'comma' ); ?>><?php echo esc_html__( 'Comma (1,000)', 'yo-booking' ); ?></option>
												<option value="period" <?php selected( $settings['payments']['thousand_separator'], 'period' ); ?>><?php echo esc_html__( 'Period (1.000)', 'yo-booking' ); ?></option>
												<option value="space" <?php selected( $settings['payments']['thousand_separator'], 'space' ); ?>><?php echo esc_html__( 'Space (1 000)', 'yo-booking' ); ?></option>
												<option value="apostrophe" <?php selected( $settings['payments']['thousand_separator'], 'apostrophe' ); ?>><?php echo esc_html__( "Apostrophe (1'000)", 'yo-booking' ); ?></option>
												<option value="none" <?php selected( $settings['payments']['thousand_separator'], 'none' ); ?>><?php echo esc_html__( 'None (1000)', 'yo-booking' ); ?></option>
											</select>
										</div>
										<div class="yo-form-field">
											<label for="yo_booking_decimal_separator"><?php echo esc_html__( 'Decimal separator', 'yo-booking' ); ?></label>
											<select name="payment_decimal_separator" id="yo_booking_decimal_separator">
												<option value="locale" <?php selected( $settings['payments']['decimal_separator'], 'locale' ); ?>><?php echo esc_html__( 'WordPress locale', 'yo-booking' ); ?></option>
												<option value="period" <?php selected( $settings['payments']['decimal_separator'], 'period' ); ?>><?php echo esc_html__( 'Period (99.00)', 'yo-booking' ); ?></option>
												<option value="comma" <?php selected( $settings['payments']['decimal_separator'], 'comma' ); ?>><?php echo esc_html__( 'Comma (99,00)', 'yo-booking' ); ?></option>
											</select>
										</div>
										<div class="yo-form-field">
											<label for="yo_booking_number_of_decimals"><?php echo esc_html__( 'Number of decimals', 'yo-booking' ); ?></label>
											<select name="payment_number_of_decimals" id="yo_booking_number_of_decimals">
												<option value="currency" <?php selected( (string) $settings['payments']['number_of_decimals'], 'currency' ); ?>><?php echo esc_html__( 'Currency default', 'yo-booking' ); ?></option>
												<?php for ( $decimal_count = 0; $decimal_count <= 4; $decimal_count++ ) : ?>
													<option value="<?php echo esc_attr( (string) $decimal_count ); ?>" <?php selected( (string) $settings['payments']['number_of_decimals'], (string) $decimal_count ); ?>><?php echo esc_html( (string) $decimal_count ); ?></option>
												<?php endfor; ?>
											</select>
										</div>
										</div>
										<?php // translators: %s: sample amount formatted with the selected currency settings. ?>
										<p class="description"><?php echo esc_html( sprintf( __( 'Preview: %s. These options change display formatting only; calculations retain each currency\'s ISO precision.', 'yo-booking' ), Currency::format( '1234.5', $settings['company']['currency'] ) ) ); ?></p>
								</td>
							</tr>
						</tbody></table>
					</section>

					<section class="yo-card yo-settings-panel" data-settings-section="payment-collection">
						<div class="yo-settings-panel__header"><h2><?php echo esc_html__( 'Payment collection', 'yo-booking' ); ?></h2><p><?php echo esc_html__( 'Enable payments and configure when customers are charged.', 'yo-booking' ); ?></p></div>
						<table class="form-table" role="presentation"><tbody>
							<tr>
							<th scope="row"><?php echo esc_html__( 'Payment layer', 'yo-booking' ); ?></th>
							<td>
								<label>
									<input name="payments_enabled" type="checkbox" value="1" <?php checked( ! empty( $settings['payments']['enabled'] ) ); ?> />
									<?php echo esc_html__( 'Enable payment method selection and payment records for bookings.', 'yo-booking' ); ?>
								</label>
								<p class="description"><?php echo esc_html__( 'Online gateways can be installed separately as payment add-ons.', 'yo-booking' ); ?></p>
							</td>
						</tr>
						<tr data-payment-field="enabled">
							<th scope="row">
								<label for="yo_booking_payment_mode"><?php echo esc_html__( 'Collection mode', 'yo-booking' ); ?></label>
							</th>
							<td>
								<select name="payment_collection_mode" id="yo_booking_payment_mode">
									<option value="none" <?php selected( $settings['payments']['collection_mode'], 'none' ); ?>><?php echo esc_html__( 'No payment required', 'yo-booking' ); ?></option>
									<option value="full" <?php selected( $settings['payments']['collection_mode'], 'full' ); ?>><?php echo esc_html__( 'Full payment', 'yo-booking' ); ?></option>
									<option value="deposit" <?php selected( $settings['payments']['collection_mode'], 'deposit' ); ?>><?php echo esc_html__( 'Deposit', 'yo-booking' ); ?></option>
								</select>
							</td>
						</tr>
						<tr data-payment-field="deposit">
							<th scope="row">
								<label for="yo_booking_deposit_type"><?php echo esc_html__( 'Deposit type', 'yo-booking' ); ?></label>
							</th>
							<td>
								<div class="yo-form-row">
									<div class="yo-form-field"><span class="yo-form-label"><?php echo esc_html__( 'Calculation', 'yo-booking' ); ?></span><select name="payment_deposit_type" id="yo_booking_deposit_type"><option value="percent" <?php selected( $settings['payments']['deposit_type'], 'percent' ); ?>><?php echo esc_html__( 'Percent', 'yo-booking' ); ?></option><option value="fixed" <?php selected( $settings['payments']['deposit_type'], 'fixed' ); ?>><?php echo esc_html__( 'Fixed amount', 'yo-booking' ); ?></option></select></div>
									<?php $fixed_deposit = 'fixed' === $settings['payments']['deposit_type']; ?>
									<div class="yo-form-field"><label for="yo_booking_deposit_amount"><?php echo esc_html__( 'Amount', 'yo-booking' ); ?></label><input id="yo_booking_deposit_amount" name="payment_deposit_amount" type="<?php echo esc_attr( $fixed_deposit ? 'text' : 'number' ); ?>" <?php if ( $fixed_deposit ) : ?>inputmode="decimal" data-yo-money-input data-yo-money-raw="<?php echo esc_attr( (string) $settings['payments']['deposit_amount'] ); ?>"<?php else : ?>min="0" max="100" step="any"<?php endif; ?> value="<?php echo esc_attr( $fixed_deposit ? Currency::format_number( $settings['payments']['deposit_amount'], $settings['company']['currency'] ) : (string) $settings['payments']['deposit_amount'] ); ?>" /></div>
								</div>
								<p class="description"><?php echo esc_html__( 'Fixed deposits use the default currency and require matching service currencies. Use a percentage deposit when services use multiple currencies.', 'yo-booking' ); ?></p>
							</td>
						</tr>
						</tbody></table>
					</section>

					<section class="yo-card yo-settings-panel" data-settings-section="payment-methods">
						<div class="yo-settings-panel__header"><h2><?php echo esc_html__( 'Payment methods', 'yo-booking' ); ?></h2><p><?php echo esc_html__( 'Select available methods and provide offline payment details.', 'yo-booking' ); ?></p></div>
						<table class="form-table" role="presentation"><tbody>
						<tr data-payment-field="enabled">
							<th scope="row"><?php echo esc_html__( 'Payment methods', 'yo-booking' ); ?></th>
							<td>
								<fieldset class="yo-checkbox-list">
									<?php foreach ( $payment_providers as $provider_id => $provider ) : ?>
										<label><input name="payment_methods[]" type="checkbox" value="<?php echo esc_attr( $provider_id ); ?>" <?php checked( in_array( $provider_id, $settings['payments']['methods'], true ) ); ?> /> <?php echo esc_html( $provider->title() ); ?></label>
									<?php endforeach; ?>
								</fieldset>
							</td>
						</tr>
						<tr data-payment-field="enabled">
							<th scope="row"><label for="yo_booking_default_payment_method"><?php echo esc_html__( 'Default payment method', 'yo-booking' ); ?></label></th>
							<td><select name="payment_default_method" id="yo_booking_default_payment_method"><?php foreach ( $payment_providers as $provider_id => $provider ) : ?><option value="<?php echo esc_attr( $provider_id ); ?>" <?php selected( $settings['payments']['default_method'], $provider_id ); ?>><?php echo esc_html( $provider->title() ); ?></option><?php endforeach; ?></select></td>
						</tr>
						<tr data-payment-field="enabled">
							<th scope="row"><label for="yo_booking_local_title"><?php echo esc_html__( 'Pay locally', 'yo-booking' ); ?></label></th>
							<td><div class="yo-form-stack"><input name="payment_local_title" id="yo_booking_local_title" type="text" class="regular-text" value="<?php echo esc_attr( $settings['payments']['local_title'] ); ?>" /><textarea name="payment_local_instructions" class="large-text" rows="3" aria-label="<?php echo esc_attr__( 'Pay locally instructions', 'yo-booking' ); ?>"><?php echo esc_textarea( $settings['payments']['local_instructions'] ); ?></textarea></div></td>
						</tr>
						<tr data-payment-field="enabled">
							<th scope="row"><label for="yo_booking_bank_transfer_title"><?php echo esc_html__( 'Bank transfer', 'yo-booking' ); ?></label></th>
							<td>
								<div class="yo-form-stack">
									<input name="payment_bank_transfer_title" id="yo_booking_bank_transfer_title" type="text" class="regular-text" value="<?php echo esc_attr( $settings['payments']['bank_transfer_title'] ); ?>" />
									<textarea name="payment_bank_transfer_instructions" class="large-text" rows="3" aria-label="<?php echo esc_attr__( 'Bank transfer instructions', 'yo-booking' ); ?>"><?php echo esc_textarea( $settings['payments']['bank_transfer_instructions'] ); ?></textarea>
									<div class="yo-form-row">
										<div class="yo-form-field"><label for="yo_booking_bank_name"><?php echo esc_html__( 'Bank name', 'yo-booking' ); ?></label><input name="payment_bank_name" id="yo_booking_bank_name" type="text" value="<?php echo esc_attr( $settings['payments']['bank_name'] ); ?>" /></div>
										<div class="yo-form-field"><label for="yo_booking_bank_account_name"><?php echo esc_html__( 'Account name', 'yo-booking' ); ?></label><input name="payment_bank_account_name" id="yo_booking_bank_account_name" type="text" value="<?php echo esc_attr( $settings['payments']['bank_account_name'] ); ?>" /></div>
										<div class="yo-form-field"><label for="yo_booking_bank_account_number"><?php echo esc_html__( 'Account number', 'yo-booking' ); ?></label><input name="payment_bank_account_number" id="yo_booking_bank_account_number" type="text" value="<?php echo esc_attr( $settings['payments']['bank_account_number'] ); ?>" /></div>
										<div class="yo-form-field"><label for="yo_booking_bank_routing_number"><?php echo esc_html__( 'Routing number', 'yo-booking' ); ?></label><input name="payment_bank_routing_number" id="yo_booking_bank_routing_number" type="text" value="<?php echo esc_attr( $settings['payments']['bank_routing_number'] ); ?>" /></div>
										<div class="yo-form-field"><label for="yo_booking_bank_iban"><?php echo esc_html__( 'IBAN', 'yo-booking' ); ?></label><input name="payment_bank_iban" id="yo_booking_bank_iban" type="text" value="<?php echo esc_attr( $settings['payments']['bank_iban'] ); ?>" /></div>
										<div class="yo-form-field"><label for="yo_booking_bank_swift"><?php echo esc_html__( 'SWIFT/BIC', 'yo-booking' ); ?></label><input name="payment_bank_swift" id="yo_booking_bank_swift" type="text" value="<?php echo esc_attr( $settings['payments']['bank_swift'] ); ?>" /></div>
									</div>
								</div>
							</td>
						</tr>
						</tbody></table>
					</section>
					<?php endif; ?>
					<?php if ( 'general' === $active_tab ) : ?>
					<section class="yo-card yo-settings-panel" data-settings-section="data-management">
						<div class="yo-settings-panel__header"><h2><?php echo esc_html__( 'Data management', 'yo-booking' ); ?></h2><p><?php echo esc_html__( 'Set log retention periods and what happens when the plugin is deleted.', 'yo-booking' ); ?></p></div>
						<table class="form-table" role="presentation"><tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Data retention', 'yo-booking' ); ?></th>
							<td>
								<div class="yo-form-row">
									<div class="yo-form-field"><label for="yo_booking_notification_retention"><?php echo esc_html__( 'Email delivery logs', 'yo-booking' ); ?></label><div class="yo-form-unit"><input id="yo_booking_notification_retention" name="notification_log_retention_days" type="number" min="0" max="3650" value="<?php echo esc_attr( (string) $settings['advanced']['notification_log_retention_days'] ); ?>" /><span><?php echo esc_html__( 'days', 'yo-booking' ); ?></span></div></div>
									<div class="yo-form-field"><label for="yo_booking_webhook_retention"><?php echo esc_html__( 'Webhook deliveries', 'yo-booking' ); ?></label><div class="yo-form-unit"><input id="yo_booking_webhook_retention" name="webhook_delivery_retention_days" type="number" min="0" max="3650" value="<?php echo esc_attr( (string) $settings['advanced']['webhook_delivery_retention_days'] ); ?>" /><span><?php echo esc_html__( 'days', 'yo-booking' ); ?></span></div></div>
									<div class="yo-form-field"><label for="yo_booking_audit_retention"><?php echo esc_html__( 'Audit logs', 'yo-booking' ); ?></label><div class="yo-form-unit"><input id="yo_booking_audit_retention" name="audit_log_retention_days" type="number" min="0" max="3650" value="<?php echo esc_attr( (string) $settings['advanced']['audit_log_retention_days'] ); ?>" /><span><?php echo esc_html__( 'days', 'yo-booking' ); ?></span></div></div>
								</div>
								<p class="description"><?php echo esc_html__( 'Use 0 to retain a log indefinitely. Appointments, customers, and payments are never removed by automatic cleanup.', 'yo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Uninstall policy', 'yo-booking' ); ?></th>
							<td>
								<label>
									<input name="remove_data_on_uninstall" type="checkbox" value="1" <?php checked( ! empty( $settings['advanced']['remove_data_on_uninstall'] ) ); ?> />
									<?php echo esc_html__( 'Remove all Yo Booking tables and settings when the plugin is deleted.', 'yo-booking' ); ?>
								</label>
								<p class="description"><?php echo esc_html__( 'Disabled by default. When disabled, booking data remains in the database after uninstall.', 'yo-booking' ); ?></p>
							</td>
						</tr>
						</tbody></table>
					</section>
					<?php endif; ?>
				</div>

				<?php submit_button( __( 'Save Settings', 'yo-booking' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Preserve old bookmarks while keeping advanced pages out of the submenu.
	 *
	 * @return void
	 */
	public function redirect_legacy_settings_pages() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map  = array(
			'yo-booking-integrations' => 'integrations',
			'yo-booking-audit'        => 'audit',
			'yo-booking-maintenance'  => 'maintenance',
		);

		if ( empty( $map[ $page ] ) || ! current_user_can( Capabilities::settings() ) ) {
			return;
		}

		$args = array( 'page' => 'yo-booking-settings', 'settings_tab' => $map[ $page ] );
		foreach ( array( 'action', 'edit', 'integration_tab', 'event', 's', 'paged', 'yo_booking_notice', 'yo_booking_message' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Save core settings from the admin page.
	 *
	 * @return void
	 */
	public function save_settings() {
		if ( ! current_user_can( Capabilities::settings() ) ) {
			wp_die( esc_html__( 'You do not have permission to update these settings.', 'yo-booking' ) );
		}

		check_admin_referer( 'yo_booking_save_settings' );

		$repository   = new SettingsRepository();
		$settings     = $repository->all();
		$settings_tab = isset( $_POST['settings_tab'] ) ? sanitize_key( wp_unslash( $_POST['settings_tab'] ) ) : 'general';
		$settings_tab = in_array( $settings_tab, array( 'general', 'payments' ), true ) ? $settings_tab : 'general';

		if ( 'payments' === $settings_tab ) {
			$available_payment_methods = array_keys( ( new PaymentProviderRegistry() )->all() );
			$submitted_methods         = isset( $_POST['payment_methods'] ) && is_array( $_POST['payment_methods'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['payment_methods'] ) ) : array();
			$payment_methods           = array_values( array_intersect( $available_payment_methods, $submitted_methods ) );

			if ( empty( $payment_methods ) && ! empty( $_POST['payments_enabled'] ) ) {
				$payment_methods = array( 'local' );
			}

			$default_payment_method = $this->sanitize_choice( 'payment_default_method', $available_payment_methods, 'local' );
			if ( ! in_array( $default_payment_method, $payment_methods, true ) && ! empty( $payment_methods ) ) {
				$default_payment_method = $payment_methods[0];
			}

			$current_currency   = Currency::normalize( $settings['company']['currency'] );
			$current_currency   = $current_currency ? $current_currency : 'USD';
			$submitted_currency = isset( $_POST['company_currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['company_currency'] ) ) ) : '';
			$currency_choices   = Currency::choices( $current_currency );
			$settings['company']['currency'] = isset( $currency_choices[ $submitted_currency ] ) ? $submitted_currency : $current_currency;

			$settings['payments']['enabled']                 = ! empty( $_POST['payments_enabled'] );
				$settings['payments']['provider']                = $default_payment_method;
				$settings['payments']['methods']                 = $payment_methods;
				$settings['payments']['default_method']          = $default_payment_method;
				$settings['payments']['currency_position']       = $this->sanitize_choice( 'payment_currency_position', array( 'currency', 'left', 'left_space', 'right', 'right_space' ), 'currency' );
				$settings['payments']['thousand_separator']      = $this->sanitize_choice( 'payment_thousand_separator', array( 'locale', 'comma', 'period', 'space', 'apostrophe', 'none' ), 'locale' );
				$settings['payments']['decimal_separator']       = $this->sanitize_choice( 'payment_decimal_separator', array( 'locale', 'comma', 'period' ), 'locale' );
				$settings['payments']['number_of_decimals']      = $this->sanitize_choice( 'payment_number_of_decimals', array( 'currency', '0', '1', '2', '3', '4' ), 'currency' );
				$settings['payments']['collection_mode']         = $this->sanitize_choice( 'payment_collection_mode', array( 'none', 'full', 'deposit' ), 'none' );
			$settings['payments']['deposit_type']            = $this->sanitize_choice( 'payment_deposit_type', array( 'percent', 'fixed' ), 'percent' );
			$deposit_amount                                  = isset( $_POST['payment_deposit_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_deposit_amount'] ) ) : '0';
			$settings['payments']['deposit_amount']          = 'fixed' === $settings['payments']['deposit_type']
				? Currency::parse_number( $deposit_amount, $settings['company']['currency'] )
				: $this->bounded_float( 'payment_deposit_amount', 0, 100, 0 );
			$settings['payments']['local_title']             = isset( $_POST['payment_local_title'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_local_title'] ) ) : __( 'Pay locally', 'yo-booking' );
			$settings['payments']['local_instructions']      = isset( $_POST['payment_local_instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['payment_local_instructions'] ) ) : '';
			$settings['payments']['bank_transfer_title']     = isset( $_POST['payment_bank_transfer_title'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_bank_transfer_title'] ) ) : __( 'Bank transfer', 'yo-booking' );
			$settings['payments']['bank_transfer_instructions'] = isset( $_POST['payment_bank_transfer_instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['payment_bank_transfer_instructions'] ) ) : '';
			$settings['payments']['bank_name']               = isset( $_POST['payment_bank_name'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_bank_name'] ) ) : '';
			$settings['payments']['bank_account_name']       = isset( $_POST['payment_bank_account_name'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_bank_account_name'] ) ) : '';
			$settings['payments']['bank_account_number']     = isset( $_POST['payment_bank_account_number'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_bank_account_number'] ) ) : '';
			$settings['payments']['bank_routing_number']     = isset( $_POST['payment_bank_routing_number'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_bank_routing_number'] ) ) : '';
			$settings['payments']['bank_iban']               = isset( $_POST['payment_bank_iban'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_bank_iban'] ) ) : '';
			$settings['payments']['bank_swift']              = isset( $_POST['payment_bank_swift'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_bank_swift'] ) ) : '';
		} else {
			$company_logo_id = isset( $_POST['company_logo_id'] ) ? absint( wp_unslash( $_POST['company_logo_id'] ) ) : 0;
			$settings['company']['name']    = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '';
			$settings['company']['logo_id'] = $company_logo_id && wp_attachment_is_image( $company_logo_id ) ? $company_logo_id : 0;
			$settings['company']['email']   = isset( $_POST['company_email'] ) ? sanitize_email( wp_unslash( $_POST['company_email'] ) ) : '';
			$settings['company']['phone']   = isset( $_POST['company_phone'] ) ? PhoneNumber::normalize( sanitize_text_field( wp_unslash( $_POST['company_phone'] ) ) ) : '';
			$settings['company']['address'] = isset( $_POST['company_address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['company_address'] ) ) : '';
			$settings['company']['timezone'] = DateTimeFormatter::timezone_name();

			$settings['booking']['slot_interval_minutes'] = $this->bounded_absint( 'slot_interval_minutes', 5, 240, 15 );
			$settings['booking']['lead_time_minutes']     = $this->bounded_absint( 'lead_time_minutes', 0, 43200, 0 );
			$settings['booking']['booking_window_days']   = $this->bounded_absint( 'booking_window_days', 1, 730, 90 );
			$settings['booking']['default_status']        = $this->sanitize_choice( 'default_status', array( 'pending', 'confirmed' ), 'pending' );
			$settings['booking']['default_phone_country'] = PhoneNumber::country( isset( $_POST['default_phone_country'] ) ? sanitize_key( wp_unslash( $_POST['default_phone_country'] ) ) : '' );
			$settings['booking']['allow_guest_booking']   = ! empty( $_POST['allow_guest_booking'] );
			$settings['booking']['allow_staff_selection'] = ! empty( $_POST['allow_staff_selection'] );
			$settings['booking']['require_email']         = ! empty( $_POST['require_email'] );
			$settings['booking']['require_phone']         = ! empty( $_POST['require_phone'] );
			$settings['booking']['cancellation_window_hours'] = $this->bounded_absint( 'cancellation_window_hours', 0, 8760, 24 );
			$settings['booking']['reschedule_window_hours']   = $this->bounded_absint( 'reschedule_window_hours', 0, 8760, 24 );
			$settings['privacy']['marketing_consent_required'] = ! empty( $_POST['marketing_consent_required'] );

			$settings['advanced']['remove_data_on_uninstall']       = ! empty( $_POST['remove_data_on_uninstall'] );
			$settings['advanced']['notification_log_retention_days'] = $this->bounded_absint( 'notification_log_retention_days', 0, 3650, 90 );
			$settings['advanced']['webhook_delivery_retention_days'] = $this->bounded_absint( 'webhook_delivery_retention_days', 0, 3650, 90 );
			$settings['advanced']['audit_log_retention_days']        = $this->bounded_absint( 'audit_log_retention_days', 0, 3650, 365 );
		}

		unset( $settings['payments']['require_payment_to_hold'], $settings['privacy']['store_ip_address'] );
		$repository->save( $settings );
		( new AuditLogRepository() )->record(
			'settings.updated',
			'settings',
			0,
			'payments' === $settings_tab ? __( 'Payment settings updated', 'yo-booking' ) : __( 'Booking settings updated', 'yo-booking' )
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'yo-booking-settings',
					'settings_tab'     => $settings_tab,
					'settings-updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Read a bounded integer from POST.
	 *
	 * @param string $key Setting key.
	 * @param int    $min Minimum value.
	 * @param int    $max Maximum value.
	 * @param int    $default Default value.
	 * @return int
	 */
	private function bounded_absint( $key, $min, $max, $default ) {
		// The public save handler verifies the form nonce before calling this helper.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$value = isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : $default;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return max( $min, min( $max, $value ) );
	}

	/**
	 * Read a bounded decimal from POST.
	 *
	 * @param string $key Setting key.
	 * @param float  $min Minimum value.
	 * @param float  $max Maximum value.
	 * @param float  $default Default value.
	 * @return float
	 */
	private function bounded_float( $key, $min, $max, $default ) {
		// The public save handler verifies the form nonce before calling this helper.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$value = isset( $_POST[ $key ] ) ? (float) preg_replace( '/[^0-9.\-]/', '', sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : $default;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return max( $min, min( $max, $value ) );
	}

	/**
	 * Sanitize a posted value against a list of choices.
	 *
	 * @param string $key Setting key.
	 * @param array  $allowed Allowed values.
	 * @param string $default Default value.
	 * @return string
	 */
	private function sanitize_choice( $key, array $allowed, $default ) {
		// The public save handler verifies the form nonce before calling this helper.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$value = isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : $default;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return in_array( $value, $allowed, true ) ? $value : $default;
	}
}
