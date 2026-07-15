<?php
/** Release-blocker and reliability smoke test for WP-CLI. */

defined( 'ABSPATH' ) || exit;
defined( 'YO_BOOKING_RUNNING_TESTS' ) || define( 'YO_BOOKING_RUNNING_TESTS', true );

global $wpdb;
$service_id = 0;
$backup = '';
$error = '';
$fail = static function ( $message ) { throw new RuntimeException( $message ); };

try {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	wp_set_current_user( (int) $admins[0]->ID );
	$appointments = YoBooking\Database\Migrator::table_name( 'appointments' );
	$payments = YoBooking\Database\Migrator::table_name( 'payments' );
	$logs = YoBooking\Database\Migrator::table_name( 'notification_logs' );
	$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$appointments}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	( new YoBooking\Database\Migrator() )->install();
	$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$appointments}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	if ( $before !== $after ) $fail( 'migration changed appointment row count' );

	$appointment_columns = $wpdb->get_col( "DESCRIBE {$appointments}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	foreach ( array( 'customer_name_snapshot', 'service_name_snapshot', 'action_token_version' ) as $column ) {
		if ( ! in_array( $column, $appointment_columns, true ) ) $fail( 'missing appointment column: ' . $column );
	}
	$payment_indexes = $wpdb->get_col( "SHOW INDEX FROM {$payments}", 2 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$log_indexes = $wpdb->get_col( "SHOW INDEX FROM {$logs}", 2 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	if ( ! in_array( 'provider_transaction_key', $payment_indexes, true ) || ! in_array( 'occurrence_key', $log_indexes, true ) ) $fail( 'dedupe indexes are missing' );

	$token_row = (object) array( 'uuid' => wp_generate_uuid4(), 'action_token_version' => 1 );
	$cancel_token = YoBooking\Support\ActionToken::generate( $token_row, 'cancel' );
	$token_expiry = (int) strtok( $cancel_token, '.' );
	if ( $token_expiry < time() + DAY_IN_SECONDS || $token_expiry > time() + ( 31 * DAY_IN_SECONDS ) ) $fail( 'action token expiry is outside policy' );
	if ( ! YoBooking\Support\ActionToken::verify( $token_row, $cancel_token, 'cancel' ) || YoBooking\Support\ActionToken::verify( $token_row, $cancel_token, 'reschedule' ) ) $fail( 'purpose-bound action token failed' );
	$token_row->action_token_version = 2;
	if ( YoBooking\Support\ActionToken::verify( $token_row, $cancel_token, 'cancel' ) ) $fail( 'token version invalidation failed' );

	$services = new YoBooking\Repositories\ServiceRepository();
	$service_id = $services->save( array( 'name' => 'DST Validation Smoke', 'duration_minutes' => 30, 'price' => 0, 'currency' => 'USD' ) );
	if ( is_wp_error( $service_id ) ) $fail( $service_id->get_error_message() );
	$invalid_dst = ( new YoBooking\Repositories\AppointmentRepository() )->save(
		array( 'service_id' => $service_id, 'customer_name' => 'DST Test', 'date' => '2027-03-14', 'start_time' => '02:30', 'timezone' => 'America/New_York', 'status' => 'pending' )
	);
	if ( ! is_wp_error( $invalid_dst ) || 'yo_booking_appointment_time_invalid' !== $invalid_dst->get_error_code() ) $fail( 'nonexistent DST time was accepted' );

	$backup = ( new YoBooking\Maintenance\BackupService() )->create_archive_file( 'roadmap-test-password-2026' );
	if ( is_wp_error( $backup ) || ! is_file( $backup ) || filesize( $backup ) < 100 ) $fail( is_wp_error( $backup ) ? $backup->get_error_message() : 'stream backup was not created' );
	$handle = fopen( $backup, 'rb' );
	$header = json_decode( fgets( $handle ), true );
	fclose( $handle );
	if ( 2 !== (int) ( $header['version'] ?? 0 ) ) $fail( 'stream backup header is invalid' );
} catch ( Throwable $exception ) {
	$error = $exception->getMessage();
} finally {
	if ( $backup && is_string( $backup ) && is_file( $backup ) ) wp_delete_file( $backup );
	if ( $service_id && ! is_wp_error( $service_id ) ) ( new YoBooking\Repositories\ServiceRepository() )->delete( $service_id );
}

if ( $error ) { echo 'FAIL: ' . $error . "\n"; exit( 1 ); }
echo "roadmap_hardening_smoke=pass\n";
