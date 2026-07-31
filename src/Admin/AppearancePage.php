<?php
/**
 * Frontend appearance admin page.
 *
 * @package YoBooking
 */

namespace YoBooking\Admin;

use YoBooking\Frontend\Appearance;
use YoBooking\Repositories\AuditLogRepository;
use YoBooking\Settings\Defaults;
use YoBooking\Settings\Repository as SettingsRepository;
use YoBooking\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the public booking interface presentation.
 */
final class AppearancePage extends AbstractAdminPage {
	/** @return void */
	public function boot() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_yo_booking_save_appearance', array( $this, 'save' ) );
		add_action( 'admin_post_yo_booking_reset_appearance', array( $this, 'reset' ) );
	}

	/** @return void */
	public function register_menu() {
		add_submenu_page(
			'yo-booking',
			__( 'Appearance', 'yo-booking' ),
			__( 'Appearance', 'yo-booking' ),
			Capabilities::settings(),
			'yo-booking-appearance',
			array( $this, 'render' )
		);
	}

	/** @return void */
	public function render() {
		$this->ensure_capability( Capabilities::settings() );
		$appearance = Appearance::settings();
		?>
		<div class="wrap yo-booking-admin yo-appearance-page">
			<?php $this->render_page_header( __( 'Appearance', 'yo-booking' ), __( 'Customize the frontend booking form, customer portal, and appointment management screens.', 'yo-booking' ) ); ?>
			<?php $this->render_notice(); ?>
			<div class="yo-appearance-layout">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yo-editor yo-appearance-form" data-appearance-form>
					<input type="hidden" name="action" value="yo_booking_save_appearance" />
					<?php wp_nonce_field( 'yo_booking_save_appearance' ); ?>

					<section class="yo-appearance-section">
						<h2><?php echo esc_html__( 'Brand colors', 'yo-booking' ); ?></h2>
						<p><?php echo esc_html__( 'Set the core colors used by the public booking interface.', 'yo-booking' ); ?></p>
						<div class="yo-appearance-presets" aria-label="<?php echo esc_attr__( 'Appearance presets', 'yo-booking' ); ?>"><span><?php echo esc_html__( 'Presets', 'yo-booking' ); ?></span><div class="yo-segmented"><button class="button" type="button" data-appearance-preset="clean"><?php echo esc_html__( 'Clean', 'yo-booking' ); ?></button><button class="button" type="button" data-appearance-preset="minimal"><?php echo esc_html__( 'Minimal', 'yo-booking' ); ?></button><button class="button" type="button" data-appearance-preset="compact"><?php echo esc_html__( 'Compact', 'yo-booking' ); ?></button></div></div>
						<div class="yo-color-grid">
							<?php $this->color_field( 'primary_color', __( 'Primary', 'yo-booking' ), $appearance['primary_color'], '--yo-booking-primary' ); ?>
							<?php $this->color_field( 'accent_color', __( 'Accent', 'yo-booking' ), $appearance['accent_color'], '--yo-booking-accent' ); ?>
							<?php $this->color_field( 'background_color', __( 'Background', 'yo-booking' ), $appearance['background_color'], '--yo-booking-bg' ); ?>
							<?php $this->color_field( 'surface_color', __( 'Surface', 'yo-booking' ), $appearance['surface_color'], '--yo-booking-surface' ); ?>
							<?php $this->color_field( 'text_color', __( 'Text', 'yo-booking' ), $appearance['text_color'], '--yo-booking-text' ); ?>
							<?php $this->color_field( 'muted_color', __( 'Muted text', 'yo-booking' ), $appearance['muted_color'], '--yo-booking-muted' ); ?>
							<?php $this->color_field( 'border_color', __( 'Border', 'yo-booking' ), $appearance['border_color'], '--yo-booking-border' ); ?>
							<?php $this->color_field( 'button_text_color', __( 'Button text', 'yo-booking' ), $appearance['button_text_color'], '--yo-booking-button-text' ); ?>
						</div>
						<p class="yo-contrast-status" data-contrast-status role="status"></p>
					</section>

					<section class="yo-appearance-section">
						<h2><?php echo esc_html__( 'Layout', 'yo-booking' ); ?></h2>
						<div class="yo-form-row">
							<div class="yo-form-field"><label for="yo_booking_appearance_width"><?php echo esc_html__( 'Maximum width', 'yo-booking' ); ?></label><div class="yo-form-unit"><input id="yo_booking_appearance_width" name="max_width" type="number" min="560" max="1200" step="10" value="<?php echo esc_attr( (string) $appearance['max_width'] ); ?>" data-appearance-variable="--yo-booking-max-width" data-appearance-unit="px" /><span>px</span></div></div>
							<div class="yo-form-field"><label for="yo_booking_appearance_radius"><?php echo esc_html__( 'Corner radius', 'yo-booking' ); ?></label><div class="yo-form-unit"><input id="yo_booking_appearance_radius" name="border_radius" type="number" min="0" max="8" value="<?php echo esc_attr( (string) $appearance['border_radius'] ); ?>" data-appearance-variable="--yo-booking-radius" data-appearance-unit="px" /><span>px</span></div></div>
							<div class="yo-form-field"><label for="yo_booking_appearance_density"><?php echo esc_html__( 'Density', 'yo-booking' ); ?></label><select id="yo_booking_appearance_density" name="density" data-appearance-class="density"><option value="comfortable" <?php selected( $appearance['density'], 'comfortable' ); ?>><?php echo esc_html__( 'Comfortable', 'yo-booking' ); ?></option><option value="compact" <?php selected( $appearance['density'], 'compact' ); ?>><?php echo esc_html__( 'Compact', 'yo-booking' ); ?></option></select></div>
							<div class="yo-form-field"><label for="yo_booking_appearance_shadow"><?php echo esc_html__( 'Panel shadow', 'yo-booking' ); ?></label><select id="yo_booking_appearance_shadow" name="shadow" data-appearance-class="shadow"><option value="subtle" <?php selected( $appearance['shadow'], 'subtle' ); ?>><?php echo esc_html__( 'Subtle', 'yo-booking' ); ?></option><option value="none" <?php selected( $appearance['shadow'], 'none' ); ?>><?php echo esc_html__( 'None', 'yo-booking' ); ?></option></select></div>
						</div>
					</section>

					<section class="yo-appearance-section">
						<h2><?php echo esc_html__( 'Booking experience', 'yo-booking' ); ?></h2>
						<div class="yo-form-row">
							<div class="yo-form-field"><label for="yo_booking_appearance_booking_title"><?php echo esc_html__( 'Booking title', 'yo-booking' ); ?></label><input id="yo_booking_appearance_booking_title" class="regular-text" name="booking_title" maxlength="80" value="<?php echo esc_attr( $appearance['booking_title'] ); ?>" data-appearance-title /></div>
							<div class="yo-form-field"><label for="yo_booking_appearance_portal_title"><?php echo esc_html__( 'Customer portal title', 'yo-booking' ); ?></label><input id="yo_booking_appearance_portal_title" class="regular-text" name="portal_title" maxlength="80" value="<?php echo esc_attr( $appearance['portal_title'] ); ?>" /></div>
							<div class="yo-form-field"><label for="yo_booking_appearance_manage_title"><?php echo esc_html__( 'Manage appointment title', 'yo-booking' ); ?></label><input id="yo_booking_appearance_manage_title" class="regular-text" name="manage_title" maxlength="80" value="<?php echo esc_attr( $appearance['manage_title'] ); ?>" /></div>
						</div>
						<div class="yo-checkbox-list yo-appearance-toggles">
							<label><input name="show_progress" type="checkbox" value="1" <?php checked( $appearance['show_progress'] ); ?> data-appearance-progress /><?php echo esc_html__( 'Show booking progress steps', 'yo-booking' ); ?></label>
							<label><input name="show_service_prices" type="checkbox" value="1" <?php checked( $appearance['show_service_prices'] ); ?> data-appearance-prices /><?php echo esc_html__( 'Show service prices', 'yo-booking' ); ?></label>
							<label><input name="show_service_details" type="checkbox" value="1" <?php checked( $appearance['show_service_details'] ); ?> data-appearance-details /><?php echo esc_html__( 'Show service descriptions', 'yo-booking' ); ?></label>
						</div>
					</section>

					<section class="yo-appearance-section yo-appearance-shortcodes">
						<h2><?php echo esc_html__( 'Shortcodes', 'yo-booking' ); ?></h2>
						<p><?php echo esc_html__( 'Add a Shortcode block to any page, then paste the shortcode for the interface you want to display.', 'yo-booking' ); ?></p>
						<div class="yo-shortcode-list">
							<div class="yo-shortcode-row">
								<div class="yo-shortcode-copy">
									<strong><?php echo esc_html__( 'Booking form', 'yo-booking' ); ?></strong>
									<span><?php echo esc_html__( 'Let customers choose a service, staff member, date, and time.', 'yo-booking' ); ?></span>
								</div>
								<div class="yo-shortcode-control">
									<code>[yo-booking]</code>
									<button type="button" class="button yo-copy-feedback yo-shortcode-copy-button" data-copy-value="[yo-booking]" aria-label="<?php echo esc_attr__( 'Copy booking form shortcode', 'yo-booking' ); ?>" title="<?php echo esc_attr__( 'Copy shortcode', 'yo-booking' ); ?>"><span class="fi fi-rr-copy" aria-hidden="true"></span></button>
									<span class="screen-reader-text" data-copy-status aria-live="polite"></span>
								</div>
							</div>
							<div class="yo-shortcode-row">
								<div class="yo-shortcode-copy">
									<strong><?php echo esc_html__( 'Customer portal', 'yo-booking' ); ?></strong>
									<span><?php echo esc_html__( 'Let signed-in customers view, reschedule, and cancel their appointments.', 'yo-booking' ); ?></span>
								</div>
								<div class="yo-shortcode-control">
									<code>[yo-booking-portal]</code>
									<button type="button" class="button yo-copy-feedback yo-shortcode-copy-button" data-copy-value="[yo-booking-portal]" aria-label="<?php echo esc_attr__( 'Copy customer portal shortcode', 'yo-booking' ); ?>" title="<?php echo esc_attr__( 'Copy shortcode', 'yo-booking' ); ?>"><span class="fi fi-rr-copy" aria-hidden="true"></span></button>
									<span class="screen-reader-text" data-copy-status aria-live="polite"></span>
								</div>
							</div>
						</div>
					</section>

					<div class="yo-editor__footer">
						<?php submit_button( __( 'Save appearance', 'yo-booking' ), 'primary', 'submit', false ); ?>
						<button class="button" type="submit" name="reset_appearance" value="1" formnovalidate><?php echo esc_html__( 'Reset defaults', 'yo-booking' ); ?></button>
					</div>
				</form>

				<aside class="yo-appearance-preview-wrap" data-preview-wrap>
					<div class="yo-appearance-preview-heading">
						<h2><?php echo esc_html__( 'Live preview', 'yo-booking' ); ?></h2>
						<div class="yo-appearance-preview-controls">
							<label><span class="screen-reader-text"><?php echo esc_html__( 'Preview step', 'yo-booking' ); ?></span><select data-preview-step data-step-label="<?php echo esc_attr__( 'Step', 'yo-booking' ); ?>" data-step-of-label="<?php echo esc_attr__( 'of', 'yo-booking' ); ?>" data-booking-label="<?php echo esc_attr__( 'Book appointment', 'yo-booking' ); ?>"><option value="1"><?php echo esc_html__( 'Service', 'yo-booking' ); ?></option><option value="2"><?php echo esc_html__( 'Staff', 'yo-booking' ); ?></option><option value="3"><?php echo esc_html__( 'Date', 'yo-booking' ); ?></option><option value="4"><?php echo esc_html__( 'Time', 'yo-booking' ); ?></option><option value="5"><?php echo esc_html__( 'Details', 'yo-booking' ); ?></option><option value="6"><?php echo esc_html__( 'Review', 'yo-booking' ); ?></option></select></label>
							<div class="yo-segmented" aria-label="<?php echo esc_attr__( 'Preview device', 'yo-booking' ); ?>"><button type="button" class="button is-active" data-preview-device="desktop" aria-pressed="true"><?php echo esc_html__( 'Desktop', 'yo-booking' ); ?></button><button type="button" class="button" data-preview-device="mobile" aria-pressed="false"><?php echo esc_html__( 'Mobile', 'yo-booking' ); ?></button></div>
						</div>
					</div>
					<div class="yo-booking-app yo-booking-ready yo-booking-density-<?php echo esc_attr( $appearance['density'] ); ?> yo-booking-shadow-<?php echo esc_attr( $appearance['shadow'] ); ?>" data-appearance-preview style="<?php echo esc_attr( Appearance::preview_style() ); ?>">
						<div class="yo-booking-panel">
							<div class="yo-booking-header">
								<h2 data-appearance-preview-title><?php echo esc_html( $appearance['booking_title'] ); ?></h2>
								<ol class="yo-booking-progress" data-appearance-preview-progress <?php echo $appearance['show_progress'] ? '' : 'hidden'; ?>><li class="is-active" data-preview-progress-step="1"><?php echo esc_html__( 'Service', 'yo-booking' ); ?></li><li data-preview-progress-step="2"><?php echo esc_html__( 'Staff', 'yo-booking' ); ?></li><li data-preview-progress-step="3"><?php echo esc_html__( 'Date', 'yo-booking' ); ?></li><li data-preview-progress-step="4"><?php echo esc_html__( 'Time', 'yo-booking' ); ?></li><li data-preview-progress-step="5"><?php echo esc_html__( 'Details', 'yo-booking' ); ?></li><li data-preview-progress-step="6"><?php echo esc_html__( 'Review', 'yo-booking' ); ?></li></ol>
								<div class="yo-booking-progress-compact" data-appearance-preview-progress <?php echo $appearance['show_progress'] ? '' : 'hidden'; ?>><span data-preview-progress-label><?php echo esc_html__( 'Step 1 of 6: Service', 'yo-booking' ); ?></span><span class="yo-booking-progress-track"><span class="yo-booking-progress-bar" data-preview-progress-bar style="width:16.67%"></span></span></div>
							</div>
							<div class="yo-booking-body">
								<section data-preview-step-panel="1">
									<h3 class="yo-booking-step-title"><?php echo esc_html__( 'Choose a service', 'yo-booking' ); ?></h3>
									<p class="yo-booking-step-description"><?php echo esc_html__( 'Select the appointment you would like to book.', 'yo-booking' ); ?></p>
									<div class="yo-booking-card-grid"><button type="button" class="yo-booking-card is-selected"><span class="yo-booking-card-title"><?php echo esc_html__( 'Consultation', 'yo-booking' ); ?></span><span class="yo-booking-card-meta">45 min<span data-appearance-preview-price <?php echo $appearance['show_service_prices'] ? '' : 'hidden'; ?>> · USD 75</span></span><span class="yo-booking-card-desc" data-appearance-preview-detail <?php echo $appearance['show_service_details'] ? '' : 'hidden'; ?>><?php echo esc_html__( 'A focused appointment tailored to the customer.', 'yo-booking' ); ?></span></button><button type="button" class="yo-booking-card"><span class="yo-booking-card-title"><?php echo esc_html__( 'Follow-up session', 'yo-booking' ); ?></span><span class="yo-booking-card-meta">30 min<span data-appearance-preview-price <?php echo $appearance['show_service_prices'] ? '' : 'hidden'; ?>> · USD 45</span></span><span class="yo-booking-card-desc" data-appearance-preview-detail <?php echo $appearance['show_service_details'] ? '' : 'hidden'; ?>><?php echo esc_html__( 'Continue from a previous appointment.', 'yo-booking' ); ?></span></button></div>
								</section>
								<section data-preview-step-panel="2" hidden>
									<h3 class="yo-booking-step-title"><?php echo esc_html__( 'Choose a staff member', 'yo-booking' ); ?></h3>
									<p class="yo-booking-step-description"><?php echo esc_html__( 'Select who you would like to meet.', 'yo-booking' ); ?></p>
									<div class="yo-booking-card-grid"><button type="button" class="yo-booking-card is-selected"><span class="yo-booking-card-title"><?php echo esc_html__( 'Olivia Carter', 'yo-booking' ); ?></span><span class="yo-booking-card-meta"><?php echo esc_html__( 'Senior consultant', 'yo-booking' ); ?></span></button><button type="button" class="yo-booking-card"><span class="yo-booking-card-title"><?php echo esc_html__( 'James Wilson', 'yo-booking' ); ?></span><span class="yo-booking-card-meta"><?php echo esc_html__( 'Wellness specialist', 'yo-booking' ); ?></span></button></div>
								</section>
								<section data-preview-step-panel="3" hidden>
									<h3 class="yo-booking-step-title"><?php echo esc_html__( 'Choose a date', 'yo-booking' ); ?></h3>
									<p class="yo-booking-step-description"><?php echo esc_html__( 'Available dates are shown below.', 'yo-booking' ); ?></p>
									<div class="yo-booking-date-grid"><button type="button" class="yo-booking-date is-today is-selected"><strong><?php echo esc_html__( 'Today', 'yo-booking' ); ?></strong><span><?php echo esc_html__( '31 July', 'yo-booking' ); ?></span></button><button type="button" class="yo-booking-date"><strong><?php echo esc_html__( 'Saturday', 'yo-booking' ); ?></strong><span><?php echo esc_html__( '1 August', 'yo-booking' ); ?></span></button><button type="button" class="yo-booking-date"><strong><?php echo esc_html__( 'Monday', 'yo-booking' ); ?></strong><span><?php echo esc_html__( '3 August', 'yo-booking' ); ?></span></button></div>
								</section>
								<section data-preview-step-panel="4" hidden>
									<h3 class="yo-booking-step-title"><?php echo esc_html__( 'Choose a time', 'yo-booking' ); ?></h3>
									<p class="yo-booking-step-description"><?php echo esc_html__( 'Times are displayed in your local timezone.', 'yo-booking' ); ?></p>
									<div class="yo-booking-time-grid"><button type="button" class="yo-booking-time is-selected">09:00</button><button type="button" class="yo-booking-time">10:30</button><button type="button" class="yo-booking-time">13:00</button><button type="button" class="yo-booking-time">15:30</button></div>
								</section>
								<section data-preview-step-panel="5" hidden>
									<h3 class="yo-booking-step-title"><?php echo esc_html__( 'Your details', 'yo-booking' ); ?></h3>
									<p class="yo-booking-step-description"><?php echo esc_html__( 'Tell us how we can contact you.', 'yo-booking' ); ?></p>
									<div class="yo-booking-form"><label class="yo-booking-field"><span><?php echo esc_html__( 'Name', 'yo-booking' ); ?></span><input type="text" value="Emily Johnson" /></label><label class="yo-booking-field"><span><?php echo esc_html__( 'Email', 'yo-booking' ); ?></span><input type="email" value="emily@example.test" /></label><label class="yo-booking-field"><span><?php echo esc_html__( 'Phone', 'yo-booking' ); ?></span><input type="tel" value="+1 212 555 0201" data-yo-phone /></label></div>
								</section>
								<section data-preview-step-panel="6" hidden>
									<h3 class="yo-booking-step-title"><?php echo esc_html__( 'Review your booking', 'yo-booking' ); ?></h3>
									<p class="yo-booking-step-description"><?php echo esc_html__( 'Confirm the details before booking.', 'yo-booking' ); ?></p>
									<dl class="yo-booking-summary"><dt><?php echo esc_html__( 'Service', 'yo-booking' ); ?></dt><dd><?php echo esc_html__( 'Consultation', 'yo-booking' ); ?></dd><dt><?php echo esc_html__( 'Staff', 'yo-booking' ); ?></dt><dd><?php echo esc_html__( 'Olivia Carter', 'yo-booking' ); ?></dd><dt><?php echo esc_html__( 'Date and time', 'yo-booking' ); ?></dt><dd><?php echo esc_html__( '31 July at 09:00', 'yo-booking' ); ?></dd><dt><?php echo esc_html__( 'Total', 'yo-booking' ); ?></dt><dd>USD 75</dd></dl>
								</section>
								<div class="yo-booking-actions"><button type="button" class="yo-booking-secondary" data-preview-prev disabled><?php echo esc_html__( 'Back', 'yo-booking' ); ?></button><button type="button" class="yo-booking-primary" data-preview-next><?php echo esc_html__( 'Continue', 'yo-booking' ); ?></button></div>
							</div>
						</div>
					</div>
				</aside>
			</div>
		</div>
		<?php
	}

	/** @return void */
	public function save() {
		$this->ensure_capability( Capabilities::settings() );
		check_admin_referer( 'yo_booking_save_appearance' );

		$repository = new SettingsRepository();
		$settings   = $repository->all();
		$defaults   = Defaults::settings()['appearance'];
		$data       = wp_unslash( $_POST );

		if ( ! empty( $data['reset_appearance'] ) ) {
			$this->reset();
		}

		foreach ( Appearance::color_keys() as $key ) {
			$color = isset( $data[ $key ] ) ? sanitize_hex_color( $data[ $key ] ) : '';
			$settings['appearance'][ $key ] = $color ? $color : $defaults[ $key ];
		}

		$settings['appearance']['max_width']             = min( 1200, max( 560, isset( $data['max_width'] ) ? absint( $data['max_width'] ) : $defaults['max_width'] ) );
		$settings['appearance']['border_radius']         = min( 8, isset( $data['border_radius'] ) ? absint( $data['border_radius'] ) : $defaults['border_radius'] );
		$settings['appearance']['density']              = $this->choice( $data, 'density', array( 'comfortable', 'compact' ), $defaults['density'] );
		$settings['appearance']['shadow']               = $this->choice( $data, 'shadow', array( 'none', 'subtle' ), $defaults['shadow'] );
		$settings['appearance']['show_progress']        = ! empty( $data['show_progress'] );
		$settings['appearance']['show_service_prices']  = ! empty( $data['show_service_prices'] );
		$settings['appearance']['show_service_details'] = ! empty( $data['show_service_details'] );

		foreach ( array( 'booking_title', 'portal_title', 'manage_title' ) as $key ) {
			$value = isset( $data[ $key ] ) ? sanitize_text_field( $data[ $key ] ) : '';
			$settings['appearance'][ $key ] = '' !== $value ? $value : $defaults[ $key ];
		}

		$repository->save( $settings );
		( new AuditLogRepository() )->record( 'appearance.updated', 'settings', 0, __( 'Frontend appearance updated', 'yo-booking' ) );
		$this->redirect( 'yo-booking-appearance' );
	}

	/** @return void */
	public function reset() {
		$this->ensure_capability( Capabilities::settings() );
		check_admin_referer( 'yo_booking_save_appearance' );
		$repository = new SettingsRepository();
		$settings   = $repository->all();
		$settings['appearance'] = Defaults::settings()['appearance'];
		$repository->save( $settings );
		( new AuditLogRepository() )->record( 'appearance.reset', 'settings', 0, __( 'Frontend appearance reset to defaults', 'yo-booking' ) );
		$this->redirect( 'yo-booking-appearance' );
	}

	/** @param string $name Field name. @param string $label Label. @param string $value Value. @param string $variable CSS variable. @return void */
	private function color_field( $name, $label, $value, $variable ) {
		?><label class="yo-color-field" for="yo_booking_appearance_<?php echo esc_attr( $name ); ?>"><span><?php echo esc_html( $label ); ?></span><span class="yo-color-control"><input id="yo_booking_appearance_<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" type="color" value="<?php echo esc_attr( $value ); ?>" data-appearance-variable="<?php echo esc_attr( $variable ); ?>" /><code data-color-value><?php echo esc_html( $value ); ?></code></span></label><?php
	}

	/** @param array $data Posted data. @param string $key Key. @param array $allowed Allowed values. @param string $default Default. @return string */
	private function choice( array $data, $key, array $allowed, $default ) {
		$value = isset( $data[ $key ] ) ? sanitize_key( $data[ $key ] ) : '';
		return in_array( $value, $allowed, true ) ? $value : $default;
	}
}
