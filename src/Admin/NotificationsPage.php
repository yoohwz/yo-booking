<?php
/**
 * Notifications admin page.
 *
 * @package YoBooking
 */

namespace YoBooking\Admin;

use YoBooking\Notifications\TemplateRenderer;
use YoBooking\Notifications\NotificationService;
use YoBooking\Repositories\AppointmentRepository;
use YoBooking\Repositories\AuditLogRepository;
use YoBooking\Repositories\NotificationLogRepository;
use YoBooking\Repositories\NotificationTemplateRepository;
use YoBooking\Settings\Repository as SettingsRepository;
use YoBooking\Support\Capabilities;
use YoBooking\Support\DateTimeFormatter;

defined( 'ABSPATH' ) || exit;

/**
 * Registers notification template and log management.
 */
final class NotificationsPage extends AbstractAdminPage {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_yo_booking_save_notification', array( $this, 'save_notification' ) );
		add_action( 'admin_post_yo_booking_save_notification_settings', array( $this, 'save_notification_settings' ) );
		add_action( 'admin_post_yo_booking_send_test_notification', array( $this, 'send_test_notification' ) );
		add_action( 'admin_post_yo_booking_retry_notification', array( $this, 'retry_notification' ) );
	}

	/**
	 * Register submenu page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'yo-booking',
			__( 'Notifications', 'yo-booking' ),
			__( 'Notifications', 'yo-booking' ),
			Capabilities::manage(),
			'yo-booking-notifications',
			array( $this, 'render' )
		);
	}

	/**
	 * Render notifications page.
	 *
	 * @return void
	 */
	public function render() {
		$this->ensure_capability();

		$templates_repository  = new NotificationTemplateRepository();
		$templates             = $templates_repository->all();
		$edit_id               = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing               = $edit_id ? $templates_repository->find( $edit_id ) : ( ! empty( $templates ) ? $templates[0] : null );
		$logs                  = ( new NotificationLogRepository() )->recent( 100 );
		$appointments          = ( new AppointmentRepository() )->all( array( 'limit' => 50 ) );
		$preview_appointment   = isset( $_GET['preview_appointment'] ) ? absint( $_GET['preview_appointment'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab            = isset( $_GET['notification_tab'] ) ? sanitize_key( wp_unslash( $_GET['notification_tab'] ) ) : 'templates'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$can_manage_settings   = current_user_can( Capabilities::settings() );
		$allowed_tabs          = $can_manage_settings ? array( 'templates', 'logs', 'settings' ) : array( 'templates', 'logs' );
		$active_tab            = in_array( $active_tab, $allowed_tabs, true ) ? $active_tab : 'templates';
		$settings              = ( new SettingsRepository() )->all();
		$notification_settings = $settings['notifications'];
		$company               = $settings['company'];
		$preview               = $editing ? ( new NotificationService() )->preview( (int) $editing->id, $preview_appointment ) : null;

		?>
		<div class="wrap yo-booking-admin">
			<?php $this->render_page_header( __( 'Notifications', 'yo-booking' ), __( 'Customize lifecycle emails and review delivery history.', 'yo-booking' ) ); ?>
			<?php $this->render_notice(); ?>
			<div class="yo-tabs" data-yo-tabs data-default-tab="<?php echo esc_attr( $active_tab ); ?>" role="tablist"><button type="button" data-yo-tab="templates" role="tab"><?php echo esc_html__( 'Email templates', 'yo-booking' ); ?></button><button type="button" data-yo-tab="logs" role="tab"><?php echo esc_html__( 'Delivery logs', 'yo-booking' ); ?></button><?php if ( $can_manage_settings ) : ?><button type="button" data-yo-tab="settings" role="tab"><?php echo esc_html__( 'Settings', 'yo-booking' ); ?></button><?php endif; ?></div>
			<section data-yo-panel="templates">

			<?php if ( $editing ) : ?>
				<div class="yo-notification-workspace">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yo-editor">
					<input type="hidden" name="action" value="yo_booking_save_notification" />
					<input type="hidden" name="id" value="<?php echo esc_attr( (string) $editing->id ); ?>" />
					<?php wp_nonce_field( 'yo_booking_save_notification_' . (int) $editing->id ); ?>

					<h2><?php echo esc_html__( 'Edit Template', 'yo-booking' ); ?></h2>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Template key', 'yo-booking' ); ?></th>
								<td><code><?php echo esc_html( $editing->notification_key ); ?></code></td>
							</tr>
							<tr>
								<th scope="row"><label for="yo_booking_notification_event"><?php echo esc_html__( 'Event', 'yo-booking' ); ?></label></th>
								<td><?php $this->event_select( 'yo_booking_notification_event', 'event', $editing->event ); ?></td>
							</tr>
							<tr>
								<th scope="row"><label for="yo_booking_notification_recipient"><?php echo esc_html__( 'Recipient', 'yo-booking' ); ?></label></th>
								<td><?php $this->recipient_select( 'yo_booking_notification_recipient', 'recipient_type', $editing->recipient_type ); ?></td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Enabled', 'yo-booking' ); ?></th>
								<td>
									<label>
										<input name="enabled" type="checkbox" value="1" <?php checked( ! empty( $editing->enabled ) ); ?> />
										<?php echo esc_html__( 'Send this email when the event runs.', 'yo-booking' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="yo_booking_notification_subject"><?php echo esc_html__( 'Subject', 'yo-booking' ); ?></label></th>
								<td><input id="yo_booking_notification_subject" name="subject" type="text" class="large-text" value="<?php echo esc_attr( $editing->subject ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="yo_booking_notification_heading"><?php echo esc_html__( 'Heading', 'yo-booking' ); ?></label></th>
								<td><input id="yo_booking_notification_heading" name="heading" type="text" class="large-text" value="<?php echo esc_attr( $editing->heading ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="yo_booking_notification_body"><?php echo esc_html__( 'Body', 'yo-booking' ); ?></label></th>
								<td><textarea id="yo_booking_notification_body" name="body" class="large-text code" rows="8"><?php echo esc_textarea( $editing->body ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row"><label for="yo_booking_notification_email_type"><?php echo esc_html__( 'Email type', 'yo-booking' ); ?></label></th>
								<td>
									<select id="yo_booking_notification_email_type" name="email_type">
										<option value="html" <?php selected( $editing->email_type, 'html' ); ?>><?php echo esc_html__( 'HTML', 'yo-booking' ); ?></option>
										<option value="plain" <?php selected( $editing->email_type, 'plain' ); ?>><?php echo esc_html__( 'Plain text', 'yo-booking' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Calendar attachment', 'yo-booking' ); ?></th>
								<td>
									<label>
										<input name="send_ics" type="checkbox" value="1" <?php checked( ! empty( $editing->send_ics ) ); ?> />
										<?php echo esc_html__( 'Attach an ICS calendar file.', 'yo-booking' ); ?>
									</label>
								</td>
							</tr>
							<tr data-reminder-offset-row <?php echo in_array( $editing->event, array( 'appointment.reminder', 'payment.balance_reminder' ), true ) ? '' : 'hidden'; ?>>
								<th scope="row"><label for="yo_booking_notification_offset"><?php echo esc_html__( 'Reminder offset', 'yo-booking' ); ?></label></th>
								<td>
									<input id="yo_booking_notification_offset" name="timing_offset_minutes" type="number" min="0" max="43200" step="5" value="<?php echo esc_attr( (string) $editing->timing_offset_minutes ); ?>" <?php disabled( ! in_array( $editing->event, array( 'appointment.reminder', 'payment.balance_reminder' ), true ) ); ?> />
									<span><?php echo esc_html__( 'minutes before appointment. Used by reminder templates.', 'yo-booking' ); ?></span>
								</td>
							</tr>
						</tbody>
					</table>

					<p>
						<strong><?php echo esc_html__( 'Placeholders:', 'yo-booking' ); ?></strong>
						<?php foreach ( TemplateRenderer::placeholder_names() as $placeholder ) : ?>
							<code><?php echo esc_html( $placeholder ); ?></code>
						<?php endforeach; ?>
					</p>

					<?php submit_button( __( 'Save Template', 'yo-booking' ) ); ?>
				</form>
				<aside class="yo-email-preview-panel">
					<div class="yo-section-header"><h2><?php echo esc_html__( 'Email preview', 'yo-booking' ); ?></h2><span class="yo-status yo-status--active"><?php echo esc_html( strtoupper( $editing->email_type ) ); ?></span></div>
					<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="yo-preview-picker">
						<input type="hidden" name="page" value="yo-booking-notifications" /><input type="hidden" name="edit" value="<?php echo esc_attr( (string) $editing->id ); ?>" />
						<label for="yo-booking-preview-appointment"><?php echo esc_html__( 'Preview data', 'yo-booking' ); ?></label>
						<select id="yo-booking-preview-appointment" name="preview_appointment" data-yo-auto-submit><option value="0"><?php echo esc_html__( 'Sample appointment', 'yo-booking' ); ?></option><?php foreach ( $appointments as $appointment ) : ?><option value="<?php echo esc_attr( (string) $appointment->id ); ?>" <?php selected( $preview_appointment, (int) $appointment->id ); ?>><?php echo esc_html( '#' . (int) $appointment->id . ' - ' . $appointment->customer_name . ' - ' . $appointment->service_name ); ?></option><?php endforeach; ?></select>
					</form>
					<?php if ( ! is_wp_error( $preview ) ) : ?>
						<div class="yo-email-preview"><div class="yo-email-preview__subject"><strong><?php echo esc_html__( 'Subject:', 'yo-booking' ); ?></strong> <?php echo esc_html( $preview['subject'] ); ?></div><div class="yo-email-preview__body"><?php echo 'plain' === $editing->email_type ? nl2br( esc_html( $preview['message'] ) ) : wp_kses_post( $preview['message'] ); ?></div></div>
					<?php else : ?><div class="notice notice-error inline"><p><?php echo esc_html( $preview->get_error_message() ); ?></p></div><?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yo-test-email-form">
						<input type="hidden" name="action" value="yo_booking_send_test_notification" /><input type="hidden" name="template_id" value="<?php echo esc_attr( (string) $editing->id ); ?>" /><input type="hidden" name="appointment_id" value="<?php echo esc_attr( (string) $preview_appointment ); ?>" /><?php wp_nonce_field( 'yo_booking_send_test_notification_' . (int) $editing->id ); ?>
						<label for="yo-booking-test-recipient"><?php echo esc_html__( 'Send test to', 'yo-booking' ); ?></label><div class="yo-inline-control"><input id="yo-booking-test-recipient" name="recipient" type="email" required value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" /><button type="submit" class="button"><?php echo esc_html__( 'Send test', 'yo-booking' ); ?></button></div>
					</form>
				</aside>
				</div>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Templates', 'yo-booking' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Key', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Event', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Recipient', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Enabled', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'ICS', 'yo-booking' ); ?></th>
					<th><?php echo esc_html__( 'Subject', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Actions', 'yo-booking' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $templates as $template ) : ?>
						<tr>
							<td><code><?php echo esc_html( $template->notification_key ); ?></code></td>
							<td><?php echo esc_html( $template->event ); ?></td>
							<td><?php echo esc_html( $template->recipient_type ); ?></td>
						<td><?php $this->render_status_badge( $template->enabled ? 'active' : 'inactive', $template->enabled ? __( 'Enabled', 'yo-booking' ) : __( 'Disabled', 'yo-booking' ) ); ?></td>
							<td><?php echo $template->send_ics ? esc_html__( 'Yes', 'yo-booking' ) : esc_html__( 'No', 'yo-booking' ); ?></td>
							<td><?php echo esc_html( $template->subject ); ?></td>
							<td><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=yo-booking-notifications&edit=' . absint( $template->id ) ) ); ?>"><?php echo esc_html__( 'Edit', 'yo-booking' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</section>

			<section data-yo-panel="logs" hidden>
			<h2><?php echo esc_html__( 'Email Logs', 'yo-booking' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Created', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Template', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Appointment', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Recipient', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Subject', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Actions', 'yo-booking' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $logs ) ) : ?>
					<?php $this->render_empty_row( 7, __( 'No email logs yet', 'yo-booking' ), __( 'Delivery attempts will appear after a booking notification is sent.', 'yo-booking' ) ); ?>
					<?php endif; ?>
					<?php foreach ( $logs as $log ) : ?>
						<tr>
							<td><?php echo esc_html( DateTimeFormatter::utc( $log->created_at ) ); ?></td>
							<td><code><?php echo esc_html( $log->notification_key ); ?></code></td>
							<td><?php echo esc_html( (string) $log->appointment_id ); ?></td>
							<td><?php echo esc_html( $log->recipient_email ); ?></td>
							<td>
							<?php $this->render_status_badge( $log->status ); ?>
								<?php if ( $log->error_message ) : ?>
									<br /><span class="description"><?php echo esc_html( $log->error_message ); ?></span>
								<?php endif; ?>
							</td>
						<td><?php echo esc_html( $log->subject ); ?></td>
						<td><?php if ( 'failed' === $log->status ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="yo_booking_retry_notification" /><input type="hidden" name="log_id" value="<?php echo esc_attr( (string) $log->id ); ?>" /><?php wp_nonce_field( 'yo_booking_retry_notification_' . (int) $log->id ); ?><button type="submit" class="button button-small"><?php echo esc_html__( 'Retry', 'yo-booking' ); ?></button></form><?php else : ?>-<?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</section>

			<?php if ( $can_manage_settings ) : ?>
			<section data-yo-panel="settings" hidden>
				<div class="yo-notification-settings-workspace">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yo-settings-groups yo-notification-settings-form">
						<input type="hidden" name="action" value="yo_booking_save_notification_settings" />
						<?php wp_nonce_field( 'yo_booking_save_notification_settings' ); ?>

						<section class="yo-card yo-settings-panel">
							<div class="yo-settings-panel__header"><h2><?php echo esc_html__( 'Email delivery', 'yo-booking' ); ?></h2><p><?php echo esc_html__( 'Configure the sender identity and recipients for booking emails.', 'yo-booking' ); ?></p></div>
							<table class="form-table" role="presentation"><tbody>
								<tr><th scope="row"><label for="yo_booking_from_name"><?php echo esc_html__( 'From name', 'yo-booking' ); ?></label></th><td><input name="notification_from_name" id="yo_booking_from_name" type="text" class="regular-text" value="<?php echo esc_attr( $notification_settings['from_name'] ); ?>" /></td></tr>
								<tr><th scope="row"><label for="yo_booking_from_email"><?php echo esc_html__( 'From email', 'yo-booking' ); ?></label></th><td><input name="notification_from_email" id="yo_booking_from_email" type="email" class="regular-text" value="<?php echo esc_attr( $notification_settings['from_email'] ); ?>" /></td></tr>
								<tr><th scope="row"><label for="yo_booking_admin_to"><?php echo esc_html__( 'Admin recipients', 'yo-booking' ); ?></label></th><td><input name="notification_admin_to" id="yo_booking_admin_to" type="text" class="large-text" value="<?php echo esc_attr( $notification_settings['admin_to'] ); ?>" /><p class="description"><?php echo esc_html__( 'Separate multiple email addresses with commas.', 'yo-booking' ); ?></p></td></tr>
							</tbody></table>
						</section>

						<section class="yo-card yo-settings-panel">
							<div class="yo-settings-panel__header"><h2><?php echo esc_html__( 'Email design', 'yo-booking' ); ?></h2><p><?php echo esc_html__( 'Set the core colors used by every HTML email template.', 'yo-booking' ); ?></p></div>
							<div class="yo-email-color-grid">
								<?php $this->color_field( 'yo_booking_email_background_color', 'email_background_color', __( 'Email background', 'yo-booking' ), $notification_settings['email_background_color'], '--yo-email-background' ); ?>
								<?php $this->color_field( 'yo_booking_email_surface_color', 'email_surface_color', __( 'Content background', 'yo-booking' ), $notification_settings['email_surface_color'], '--yo-email-surface' ); ?>
								<?php $this->color_field( 'yo_booking_email_primary_color', 'email_primary_color', __( 'Brand color', 'yo-booking' ), $notification_settings['email_primary_color'], '--yo-email-primary' ); ?>
								<?php $this->color_field( 'yo_booking_email_text_color', 'email_text_color', __( 'Text color', 'yo-booking' ), $notification_settings['email_text_color'], '--yo-email-text' ); ?>
								<?php $this->color_field( 'yo_booking_email_muted_color', 'email_muted_color', __( 'Muted text', 'yo-booking' ), $notification_settings['email_muted_color'], '--yo-email-muted' ); ?>
							</div>
							<div class="yo-email-footer-field">
								<label for="yo_booking_email_footer_text"><?php echo esc_html__( 'Footer text', 'yo-booking' ); ?></label>
								<textarea id="yo_booking_email_footer_text" name="email_footer_text" class="large-text" rows="3" data-yo-email-footer-text><?php echo esc_textarea( $notification_settings['email_footer_text'] ); ?></textarea>
								<p class="description"><?php echo esc_html__( 'The words “Yo Booking” are automatically linked to yoohw.com.', 'yo-booking' ); ?></p>
							</div>
						</section>

						<?php submit_button( __( 'Save email settings', 'yo-booking' ) ); ?>
					</form>

					<aside class="yo-email-style-preview-panel">
						<div class="yo-section-header"><h2><?php echo esc_html__( 'Design preview', 'yo-booking' ); ?></h2><span class="yo-status yo-status--active">HTML</span></div>
						<div class="yo-email-style-preview" data-yo-email-style-preview style="--yo-email-background:<?php echo esc_attr( $notification_settings['email_background_color'] ); ?>;--yo-email-surface:<?php echo esc_attr( $notification_settings['email_surface_color'] ); ?>;--yo-email-primary:<?php echo esc_attr( $notification_settings['email_primary_color'] ); ?>;--yo-email-text:<?php echo esc_attr( $notification_settings['email_text_color'] ); ?>;--yo-email-muted:<?php echo esc_attr( $notification_settings['email_muted_color'] ); ?>;">
							<div class="yo-email-style-preview__brand"><?php if ( ! empty( $company['logo_id'] ) && wp_attachment_is_image( $company['logo_id'] ) ) : ?><img src="<?php echo esc_url( wp_get_attachment_image_url( $company['logo_id'], 'medium' ) ); ?>" alt="<?php echo esc_attr( $company['name'] ); ?>" /><?php else : ?><strong><?php echo esc_html( $company['name'] ); ?></strong><?php endif; ?></div>
							<div class="yo-email-style-preview__card">
								<div class="yo-email-style-preview__header"><h3><?php echo esc_html__( 'Your appointment is confirmed', 'yo-booking' ); ?></h3></div>
								<div class="yo-email-style-preview__content"><p><?php echo esc_html__( 'Hello Sample Customer, your booking details are ready.', 'yo-booking' ); ?></p><p><a href="#"><?php echo esc_html__( 'Manage appointment', 'yo-booking' ); ?></a></p></div>
							</div>
							<div class="yo-email-style-preview__footer" data-yo-email-footer-preview><?php echo wp_kses_post( TemplateRenderer::footer_html( $notification_settings['email_footer_text'], 'var(--yo-email-primary)' ) ); ?></div>
						</div>
					</aside>
				</div>
			</section>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save a notification template.
	 *
	 * @return void
	 */
	public function save_notification() {
		$this->ensure_capability();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'yo_booking_save_notification_' . $id );

		$result = ( new NotificationTemplateRepository() )->save( wp_unslash( $_POST ) );

		$this->redirect_result(
			'yo-booking-notifications',
			$result,
			array( 'edit' => $id )
		);
	}

	/**
	 * Save global email delivery and design settings.
	 *
	 * @return void
	 */
	public function save_notification_settings() {
		$this->ensure_capability( Capabilities::settings() );
		check_admin_referer( 'yo_booking_save_notification_settings' );

		$repository = new SettingsRepository();
		$settings   = $repository->all();
		$colors     = array(
			'email_background_color' => '#f3f4f6',
			'email_surface_color'    => '#ffffff',
			'email_primary_color'    => '#2563eb',
			'email_text_color'       => '#1f2937',
			'email_muted_color'      => '#64748b',
		);

		$settings['notifications']['from_name']  = isset( $_POST['notification_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['notification_from_name'] ) ) : get_bloginfo( 'name' );
		$settings['notifications']['from_email'] = isset( $_POST['notification_from_email'] ) ? sanitize_email( wp_unslash( $_POST['notification_from_email'] ) ) : get_option( 'admin_email' );
		$settings['notifications']['admin_to']   = isset( $_POST['notification_admin_to'] ) ? sanitize_text_field( wp_unslash( $_POST['notification_admin_to'] ) ) : get_option( 'admin_email' );
		$settings['notifications']['email_footer_text'] = isset( $_POST['email_footer_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['email_footer_text'] ) ) : __( 'Power by Yo Booking', 'yo-booking' );

		foreach ( $colors as $key => $fallback ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_hex_color( wp_unslash( $_POST[ $key ] ) ) : '';
			$settings['notifications'][ $key ] = $value ? $value : $fallback;
		}

		$repository->save( $settings );
		( new AuditLogRepository() )->record( 'notification.settings_updated', 'settings', 0, __( 'Email notification settings updated', 'yo-booking' ) );
		$this->redirect( 'yo-booking-notifications', 'saved', array( 'notification_tab' => 'settings' ) );
	}

	/**
	 * Send a template test email.
	 *
	 * @return void
	 */
	public function send_test_notification() {
		$this->ensure_capability();
		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		check_admin_referer( 'yo_booking_send_test_notification_' . $template_id );
		$appointment_id = isset( $_POST['appointment_id'] ) ? absint( $_POST['appointment_id'] ) : 0;
		$recipient      = isset( $_POST['recipient'] ) ? sanitize_email( wp_unslash( $_POST['recipient'] ) ) : '';
		$result         = ( new NotificationService() )->send_test( $template_id, $appointment_id, $recipient );
		if ( false === $result ) {
			$result = new \WP_Error( 'yo_booking_test_email_failed', __( 'The test email could not be sent.', 'yo-booking' ) );
		}
		$this->redirect_result( 'yo-booking-notifications', is_wp_error( $result ) ? $result : 1, array( 'edit' => $template_id ) );
	}

	/**
	 * Retry one failed notification log.
	 *
	 * @return void
	 */
	public function retry_notification() {
		$this->ensure_capability();
		$log_id = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;
		check_admin_referer( 'yo_booking_retry_notification_' . $log_id );
		$result = ( new NotificationService() )->retry_log( $log_id );
		if ( false === $result ) {
			$result = new \WP_Error( 'yo_booking_notification_retry_failed', __( 'The notification retry failed.', 'yo-booking' ) );
		}
		$this->redirect_result( 'yo-booking-notifications', is_wp_error( $result ) ? $result : 1, array( 'notification_tab' => 'logs' ) );
	}

	/**
	 * Render event select.
	 *
	 * @param string $id Field ID.
	 * @param string $name Field name.
	 * @param string $selected Selected event.
	 * @return void
	 */
	private function event_select( $id, $name, $selected ) {
		?>
		<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>">
			<?php foreach ( NotificationTemplateRepository::events() as $event => $label ) : ?>
				<option value="<?php echo esc_attr( $event ); ?>" <?php selected( $selected, $event ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render recipient type select.
	 *
	 * @param string $id Field ID.
	 * @param string $name Field name.
	 * @param string $selected Selected type.
	 * @return void
	 */
	private function recipient_select( $id, $name, $selected ) {
		?>
		<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>">
			<?php foreach ( NotificationTemplateRepository::recipient_types() as $type => $label ) : ?>
				<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $selected, $type ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render one email color setting.
	 *
	 * @param string $id Field ID.
	 * @param string $name Field name.
	 * @param string $label Field label.
	 * @param string $value Current color.
	 * @param string $property Preview CSS custom property.
	 * @return void
	 */
	private function color_field( $id, $name, $label, $value, $property ) {
		?>
		<label class="yo-email-color-field" for="<?php echo esc_attr( $id ); ?>">
			<span><?php echo esc_html( $label ); ?></span>
			<?php $this->render_color_control( $id, $name, $value, $label, 'data-yo-email-color', $property ); ?>
		</label>
		<?php
	}
}
