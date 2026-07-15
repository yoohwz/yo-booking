<?php
/**
 * Phase 15 retention, backup, localization, and maintenance smoke test.
 *
 * @package YoBooking
 */

defined( 'ABSPATH' ) || exit;
defined( 'YO_BOOKING_RUNNING_TESTS' ) || define( 'YO_BOOKING_RUNNING_TESTS', true );

global $wpdb;

$error             = '';
$endpoint_id       = 0;
$delivery_ids      = array();
$notification_ids  = array();
$audit_ids         = array();
$service_id        = 0;
$settings_repo     = new YoBooking\Settings\Repository();
$original_settings = $settings_repo->all();
$deliveries        = new YoBooking\Repositories\WebhookDeliveryRepository();
$endpoints         = new YoBooking\Repositories\WebhookEndpointRepository();
$notifications     = new YoBooking\Repositories\NotificationLogRepository();
$audits            = new YoBooking\Repositories\AuditLogRepository();
$services          = new YoBooking\Repositories\ServiceRepository();
$fail              = static function ( $message ) { throw new RuntimeException( $message ); };

try {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	if ( ! $admins ) $fail( 'administrator user is required' );
	wp_set_current_user( (int) $admins[0]->ID );

	$settings = $original_settings;
	$settings['advanced']['notification_log_retention_days'] = 1;
	$settings['advanced']['webhook_delivery_retention_days'] = 1;
	$settings['advanced']['audit_log_retention_days'] = 1;
	$settings_repo->save( $settings );
	$old = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );

	$notification_ids[] = $notifications->create( array( 'notification_key' => 'phase15-old', 'event' => 'test', 'status' => 'sent', 'subject' => 'Old final log' ) );
	$notification_ids[] = $notifications->create( array( 'notification_key' => 'phase15-pending', 'event' => 'test', 'status' => 'pending', 'subject' => 'Old pending log' ) );
	foreach ( $notification_ids as $id ) $wpdb->update( YoBooking\Database\Migrator::table_name( 'notification_logs' ), array( 'created_at' => $old ), array( 'id' => $id ) );

	$endpoint_id = $endpoints->save( array( 'name' => 'Phase 15 cleanup endpoint', 'url' => 'https://example.com/phase15-cleanup', 'secret' => 'whsec_phase15_cleanup', 'events' => array( 'appointment.created' ) ) );
	$delivery_ids[] = $deliveries->create( array( 'endpoint_id' => $endpoint_id, 'event' => 'appointment.created', 'object_type' => 'appointment', 'object_id' => 1501, 'payload' => array( 'test' => true ) ) );
	$delivery_ids[] = $deliveries->create( array( 'endpoint_id' => $endpoint_id, 'event' => 'appointment.created', 'object_type' => 'appointment', 'object_id' => 1502, 'payload' => array( 'test' => true ) ) );
	$wpdb->update( YoBooking\Database\Migrator::table_name( 'webhook_deliveries' ), array( 'status' => 'delivered', 'created_at' => $old ), array( 'id' => $delivery_ids[0] ) );
	$wpdb->update( YoBooking\Database\Migrator::table_name( 'webhook_deliveries' ), array( 'status' => 'retrying', 'created_at' => $old ), array( 'id' => $delivery_ids[1] ) );

	$audit_ids[] = $audits->record( 'phase15.old', 'test', 15, 'Old audit record' );
	$wpdb->update( YoBooking\Database\Migrator::table_name( 'audit_logs' ), array( 'created_at' => $old ), array( 'id' => $audit_ids[0] ) );

	$cleanup = new YoBooking\Maintenance\CleanupService();
	$counts  = $cleanup->counts();
	if ( 1 !== $counts['notification_logs'] || 1 !== $counts['webhook_deliveries'] || 1 !== $counts['audit_logs'] ) $fail( 'retention preview counts are incorrect' );
	$deleted = $cleanup->run();
	if ( 1 !== $deleted['notification_logs'] || 1 !== $deleted['webhook_deliveries'] || 1 !== $deleted['audit_logs'] ) $fail( 'retention cleanup counts are incorrect' );
	if ( $notifications->find( $notification_ids[0] ) || ! $notifications->find( $notification_ids[1] ) || $deliveries->find( $delivery_ids[0] ) || ! $deliveries->find( $delivery_ids[1] ) || $audits->find( $audit_ids[0] ) ) $fail( 'retention cleanup removed the wrong record states' );

	$notifications->delete( $notification_ids[1] );
	$deliveries->delete( $delivery_ids[1] );
	$endpoints->delete( $endpoint_id );
	$endpoint_id = 0;
	$notification_ids = array(); $delivery_ids = array(); $audit_ids = array();

	$snapshot_settings = $original_settings;
	$snapshot_settings['company']['name'] = 'Phase 15 Snapshot Company';
	$settings_repo->save( $snapshot_settings );
	$service_id = $services->save( array( 'name' => 'Phase 15 Backup Service', 'duration_minutes' => 45, 'price' => 75, 'currency' => 'USD' ) );
	if ( is_wp_error( $service_id ) ) $fail( 'backup service fixture failed' );
	$backup = new YoBooking\Maintenance\BackupService();
	$password = 'Phase15-Backup-Password!';
	$archive = $backup->create_archive( $password );
	if ( is_wp_error( $archive ) || false !== strpos( $archive, 'Phase 15 Backup Service' ) ) $fail( 'encrypted backup creation failed or leaked plaintext' );
	if ( ! is_wp_error( $backup->restore_archive( $archive, 'wrong-password-value' ) ) ) $fail( 'backup accepted an incorrect password' );

	$services->save( array( 'id' => $service_id, 'name' => 'Mutated Service', 'duration_minutes' => 15 ) );
	$mutated = $settings_repo->all(); $mutated['company']['name'] = 'Mutated Company'; $settings_repo->save( $mutated );
	$restored = $backup->restore_archive( $archive, $password );
	$restored_service = $services->find( $service_id );
	if ( is_wp_error( $restored ) || ! $restored_service || 'Phase 15 Backup Service' !== $restored_service->name || 'Phase 15 Snapshot Company' !== $settings_repo->get( 'company.name' ) || 18 !== (int) $restored['tables'] ) {
		$fail( 'encrypted backup restore did not recover the snapshot' );
	}

	$cleanup->ensure_schedule();
	if ( ! wp_next_scheduled( YoBooking\Maintenance\CleanupService::HOOK ) ) $fail( 'daily cleanup was not scheduled' );
	$health = ( new YoBooking\Diagnostics\SiteHealth() )->cleanup_test();
	if ( 'good' !== $health['status'] ) $fail( 'Site Health cleanup test failed' );

	$_GET = array(); ob_start(); ( new YoBooking\Admin\MaintenancePage() )->render(); $maintenance_html = ob_get_clean();
	ob_start(); ( new YoBooking\Admin\AdminMenu() )->render_page(); $settings_html = ob_get_clean();
	if ( false === strpos( $maintenance_html, 'Create encrypted backup' ) || false === strpos( $maintenance_html, 'confirm_restore' ) || false === strpos( $settings_html, 'notification_log_retention_days' ) ) $fail( 'maintenance/settings admin markup is incomplete' );

	( new YoBooking\Frontend\Shortcode() )->register_assets();
	global $wp_scripts;
	$localized = isset( $wp_scripts->registered['yo-booking-frontend']->extra['data'] ) ? $wp_scripts->registered['yo-booking-frontend']->extra['data'] : '';
	if ( false === strpos( $localized, 'Booking progress' ) || false === strpos( file_get_contents( YO_BOOKING_PATH . 'assets/js/frontend.js' ), "aria-current" ) ) $fail( 'frontend localization or accessibility contract is missing' );
} catch ( Throwable $exception ) {
	$error = $exception->getMessage();
} finally {
	$_GET = array();
	foreach ( $notification_ids as $id ) if ( $id && ! is_wp_error( $id ) ) $notifications->delete( $id );
	foreach ( $delivery_ids as $id ) if ( $id && ! is_wp_error( $id ) ) $deliveries->delete( $id );
	foreach ( $audit_ids as $id ) if ( $id ) $audits->delete( $id );
	if ( $endpoint_id && ! is_wp_error( $endpoint_id ) ) $endpoints->delete( $endpoint_id );
	if ( $service_id && ! is_wp_error( $service_id ) ) $services->delete( $service_id );
	$settings_repo->save( $original_settings );
	delete_option( 'yo_booking_last_cleanup' );
}

if ( $error ) { echo 'FAIL: ' . $error . "\n"; exit( 1 ); }
echo "phase15_maintenance_release_smoke=pass\n";
