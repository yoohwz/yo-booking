<?php
/**
 * Maintenance and disaster recovery admin page.
 *
 * @package YoBooking
 */

namespace YoBooking\Admin;

use YoBooking\Maintenance\BackupService;
use YoBooking\Maintenance\CleanupService;
use YoBooking\Repositories\AuditLogRepository;
use YoBooking\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Provides retention cleanup and encrypted snapshot controls.
 */
final class MaintenancePage extends AbstractAdminPage {
	/** @return void */
	public function boot() {
		add_action( 'admin_post_yo_booking_run_cleanup', array( $this, 'run_cleanup' ) );
		add_action( 'admin_post_yo_booking_download_backup', array( $this, 'download_backup' ) );
		add_action( 'admin_post_yo_booking_restore_backup', array( $this, 'restore_backup' ) );
	}

	/** @return void */
	public function render() {
		$this->render_screen( false );
	}

	/** @return void */
	public function render_embedded() {
		$this->render_screen( true );
	}

	/** @param bool $embedded Whether rendered inside Settings. @return void */
	private function render_screen( $embedded ) {
		$this->ensure_capability( Capabilities::settings() );
		$counts = ( new CleanupService() )->counts();
		$last   = get_option( 'yo_booking_last_cleanup', array() );
		?>
		<div class="<?php echo esc_attr( $embedded ? 'yo-settings-view' : 'wrap yo-booking-admin' ); ?>">
			<?php if ( $embedded ) : ?><div class="yo-settings-section-header"><div><h2><?php echo esc_html__( 'Maintenance', 'yo-booking' ); ?></h2><p><?php echo esc_html__( 'Manage log retention and encrypted same-site disaster recovery snapshots.', 'yo-booking' ); ?></p></div></div><?php else : ?><?php $this->render_page_header( __( 'Maintenance', 'yo-booking' ), __( 'Manage log retention and encrypted same-site disaster recovery snapshots.', 'yo-booking' ) ); ?><?php endif; ?>
			<?php $this->render_notice(); ?>
			<div class="yo-grid yo-grid--stats">
				<?php $this->stat( __( 'Expired email logs', 'yo-booking' ), $counts['notification_logs'] ); ?>
				<?php $this->stat( __( 'Expired webhooks', 'yo-booking' ), $counts['webhook_deliveries'] ); ?>
				<?php $this->stat( __( 'Expired audit logs', 'yo-booking' ), $counts['audit_logs'] ); ?>
				<?php $this->stat( __( 'Last cleanup', 'yo-booking' ), ! empty( $last['ran_at'] ) ? $last['ran_at'] : __( 'Never', 'yo-booking' ) ); ?>
			</div>
			<section class="yo-section"><div class="yo-section-header"><h2><?php echo esc_html__( 'Retention cleanup', 'yo-booking' ); ?></h2></div><div class="yo-card"><p><?php echo esc_html__( 'Cleanup removes only expired operational logs according to Settings. Booking, customer, service, staff, and payment records are preserved.', 'yo-booking' ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="yo_booking_run_cleanup" /><?php wp_nonce_field( 'yo_booking_run_cleanup' ); ?><?php submit_button( __( 'Run cleanup now', 'yo-booking' ), 'secondary', 'submit', false ); ?></form></div></section>
			<div class="yo-grid yo-grid--2 yo-section">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yo-card"><?php wp_nonce_field( 'yo_booking_download_backup' ); ?><input type="hidden" name="action" value="yo_booking_download_backup" /><h2><?php echo esc_html__( 'Create encrypted backup', 'yo-booking' ); ?></h2><p><?php echo esc_html__( 'Downloads all managed tables and settings in a password-encrypted snapshot for this WordPress installation.', 'yo-booking' ); ?></p><p><label for="yo-backup-password"><?php echo esc_html__( 'Backup password', 'yo-booking' ); ?></label><input class="widefat" id="yo-backup-password" name="password" type="password" minlength="12" required autocomplete="new-password" /></p><?php submit_button( __( 'Download backup', 'yo-booking' ), 'primary', 'submit', false ); ?></form>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yo-card"><?php wp_nonce_field( 'yo_booking_restore_backup' ); ?><input type="hidden" name="action" value="yo_booking_restore_backup" /><h2><?php echo esc_html__( 'Restore encrypted backup', 'yo-booking' ); ?></h2><p><?php echo esc_html__( 'Restore replaces all current Yo Booking tables and settings. No automatic backup is created before restore.', 'yo-booking' ); ?></p><p><label for="yo-restore-file"><?php echo esc_html__( 'Backup file', 'yo-booking' ); ?></label><input class="widefat" id="yo-restore-file" name="backup_file" type="file" accept=".yobk,application/json" required /></p><p><label for="yo-restore-password"><?php echo esc_html__( 'Backup password', 'yo-booking' ); ?></label><input class="widefat" id="yo-restore-password" name="password" type="password" minlength="12" required autocomplete="current-password" /></p><p><label><input type="checkbox" name="confirm_restore" value="1" required /><?php echo esc_html__( 'I understand that current Yo Booking data will be replaced.', 'yo-booking' ); ?></label></p><?php submit_button( __( 'Restore backup', 'yo-booking' ), 'delete', 'submit', false ); ?></form>
			</div>
		</div>
		<?php
	}

	/** @return void */
	public function run_cleanup() {
		$this->ensure_capability( Capabilities::settings() ); check_admin_referer( 'yo_booking_run_cleanup' );
		$deleted = ( new CleanupService() )->run();
		// translators: %d: number of records removed by retention cleanup.
		( new AuditLogRepository() )->record( 'maintenance.cleanup', 'maintenance', 0, sprintf( __( 'Retention cleanup removed %d records', 'yo-booking' ), array_sum( $deleted ) ), $deleted );
		$this->redirect( 'yo-booking-settings', 'saved', array( 'settings_tab' => 'maintenance' ) );
	}

	/** @return void */
	public function download_backup() {
		$this->ensure_capability( Capabilities::settings() ); check_admin_referer( 'yo_booking_download_backup' );
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be transformed.
		( new AuditLogRepository() )->record( 'maintenance.backup_created', 'maintenance', 0, __( 'Encrypted backup created', 'yo-booking' ) );
		$archive = ( new BackupService() )->create_archive_file( $password );
		if ( is_wp_error( $archive ) ) wp_die( esc_html( $archive->get_error_message() ) );
		nocache_headers(); header( 'Content-Type: application/octet-stream' ); header( 'Content-Disposition: attachment; filename="yo-booking-' . gmdate( 'Y-m-d-His' ) . '.yobk"' ); header( 'X-Content-Type-Options: nosniff' ); readfile( $archive ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		wp_delete_file( $archive );
		exit;
	}

	/** @return void */
	public function restore_backup() {
		$this->ensure_capability( Capabilities::settings() ); check_admin_referer( 'yo_booking_restore_backup' );
		if ( empty( $_POST['confirm_restore'] ) ) $this->redirect_result( 'yo-booking-settings', new \WP_Error( 'yo_booking_restore_confirmation_required', __( 'Confirm that current booking data will be replaced.', 'yo-booking' ) ), array( 'settings_tab' => 'maintenance' ) );
		$file = isset( $_FILES['backup_file'] ) && is_array( $_FILES['backup_file'] ) ? wp_unslash( $_FILES['backup_file'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each scalar is validated below.
		$tmp_name = isset( $file['tmp_name'] ) ? sanitize_text_field( $file['tmp_name'] ) : '';
		$error    = isset( $file['error'] ) ? absint( $file['error'] ) : UPLOAD_ERR_NO_FILE;
		$size     = isset( $file['size'] ) ? absint( $file['size'] ) : 0;
		if ( ! $tmp_name || UPLOAD_ERR_OK !== $error || ! $size || $size > 100 * MB_IN_BYTES || ! is_uploaded_file( $tmp_name ) ) {
			$this->redirect_result( 'yo-booking-settings', new \WP_Error( 'yo_booking_restore_upload_invalid', __( 'Upload a valid backup file smaller than 100 MB.', 'yo-booking' ) ), array( 'settings_tab' => 'maintenance' ) );
		}
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be transformed.
		$result = ( new BackupService() )->restore_archive_file( $tmp_name, $password );
		if ( ! is_wp_error( $result ) ) ( new AuditLogRepository() )->record( 'maintenance.backup_restored', 'maintenance', 0, __( 'Encrypted backup restored', 'yo-booking' ), $result );
		$this->redirect_result( 'yo-booking-settings', is_wp_error( $result ) ? $result : 1, array( 'settings_tab' => 'maintenance' ) );
	}

	/** @param string $label Label. @param mixed $value Value. @return void */
	private function stat( $label, $value ) { ?><div class="yo-card yo-stat"><span class="yo-stat__label"><?php echo esc_html( $label ); ?></span><strong class="yo-stat__value"><?php echo esc_html( (string) $value ); ?></strong></div><?php }
}
