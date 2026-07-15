<?php
/**
 * Phase 13 public API, privacy, cron lock, and Site Health smoke test.
 *
 * @package YoBooking
 */

defined( 'ABSPATH' ) || exit;
defined( 'YO_BOOKING_RUNNING_TESTS' ) || define( 'YO_BOOKING_RUNNING_TESTS', true );

global $wpdb;

$suffix         = gmdate( 'YmdHis' );
$service_id     = 0;
$staff_id       = 0;
$customer_id    = 0;
$appointment_id = 0;
$payment_id     = 0;
$log_id         = 0;
$error          = '';
$rate_scope     = 'phase13_' . strtolower( $suffix );
$rate_subject   = 'security-smoke';
$services       = new YoBooking\Repositories\ServiceRepository();
$staff          = new YoBooking\Repositories\StaffRepository();
$customers      = new YoBooking\Repositories\CustomerRepository();
$payments       = new YoBooking\Repositories\PaymentRepository();
$logs           = new YoBooking\Repositories\NotificationLogRepository();
$fail           = static function ( $message ) { throw new RuntimeException( $message ); };
$limiter        = new YoBooking\Support\RateLimiter();

try {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	if ( ! $admins ) $fail( 'administrator user is required' );
	wp_set_current_user( (int) $admins[0]->ID );

	add_filter( 'yo_booking_rate_limit_during_tests', '__return_true' );
	if ( true !== $limiter->consume( $rate_scope, 2, 60, $rate_subject ) || true !== $limiter->consume( $rate_scope, 2, 60, $rate_subject ) ) {
		$fail( 'rate limiter rejected allowed requests' );
	}
	$limited = $limiter->consume( $rate_scope, 2, 60, $rate_subject );
	if ( ! is_wp_error( $limited ) || 429 !== (int) $limited->get_error_data()['status'] ) {
		$fail( 'rate limiter did not return HTTP 429' );
	}

	$controller = new YoBooking\Rest\BookingController();
	$invalid_request = new WP_REST_Request( 'POST', '/yo-booking/v1/booking/appointments' );
	$invalid_request->set_header( 'X-WP-Nonce', 'invalid' );
	$invalid_nonce = $controller->authorize_booking_write( $invalid_request );
	if ( ! is_wp_error( $invalid_nonce ) || 403 !== (int) $invalid_nonce->get_error_data()['status'] ) {
		$fail( 'booking write accepted an invalid nonce' );
	}

	$service_id = $services->save( array( 'name' => 'Phase 13 Service ' . $suffix, 'duration_minutes' => 30, 'price' => 100, 'currency' => 'USD' ) );
	$staff_id   = $staff->save( array( 'name' => 'Phase 13 Staff ' . $suffix, 'email' => 'private-staff-' . $suffix . '@example.test' ) );
	if ( is_wp_error( $service_id ) || is_wp_error( $staff_id ) ) $fail( 'service/staff fixtures failed' );
	( new YoBooking\Repositories\StaffServiceRepository() )->sync_for_staff( $staff_id, array( $service_id ) );

	$staff_request = new WP_REST_Request( 'GET', '/yo-booking/v1/booking/staff' );
	$staff_request->set_param( 'service_id', $service_id );
	$staff_response = $controller->staff( $staff_request );
	if ( empty( $staff_response['staff'][0] ) || array_key_exists( 'email', $staff_response['staff'][0] ) ) {
		$fail( 'public staff response exposes email or is incomplete' );
	}

	$email = 'phase13-customer-' . $suffix . '@example.test';
	$customer_id = $customers->save( array( 'name' => 'Phase 13 Customer', 'email' => $email, 'phone' => '+15550131313', 'notes' => 'Private customer note', 'marketing_consent' => 1, 'timezone' => 'UTC' ) );
	if ( is_wp_error( $customer_id ) ) $fail( 'customer fixture failed' );
	$appointments_table = YoBooking\Database\Migrator::table_name( 'appointments' );
	$now = current_time( 'mysql', true );
	$wpdb->insert(
		$appointments_table,
		array( 'uuid' => wp_generate_uuid4(), 'customer_id' => $customer_id, 'service_id' => $service_id, 'staff_id' => $staff_id, 'start_at' => '2026-08-01 09:00:00', 'end_at' => '2026-08-01 09:30:00', 'timezone' => 'UTC', 'status' => 'completed', 'source' => 'test', 'customer_note' => 'Erase this customer note', 'internal_note' => 'Erase this internal note', 'subtotal_amount' => 100, 'total_amount' => 100, 'currency' => 'USD', 'payment_status' => 'paid', 'created_at' => $now, 'updated_at' => $now ),
		array( '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s' )
	);
	$appointment_id = (int) $wpdb->insert_id;
	$payment_id = $payments->create( array( 'appointment_id' => $appointment_id, 'amount' => 100, 'currency' => 'USD', 'status' => 'paid', 'transaction_id' => 'PHASE13-TX', 'note' => 'Private payment note' ) );
	$log_id = $logs->create( array( 'notification_key' => 'phase13', 'event' => 'test', 'appointment_id' => $appointment_id, 'recipient_email' => $email, 'subject' => 'Private subject', 'status' => 'sent' ) );

	$privacy = new YoBooking\Privacy\PrivacyManager();
	$registered_exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
	$registered_erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
	if ( empty( $registered_exporters['yo-booking']['callback'] ) || empty( $registered_erasers['yo-booking']['callback'] ) ) {
		$fail( 'WordPress privacy callbacks are not registered' );
	}
	$export = $privacy->export( $email, 1 );
	$export_json = wp_json_encode( $export['data'] );
	if ( false === strpos( $export_json, 'Phase 13 Customer' ) || false === strpos( $export_json, 'PHASE13-TX' ) || empty( $export['done'] ) ) {
		$fail( 'personal data export is incomplete' );
	}
	$erased = $privacy->erase( $email, 1 );
	$customer = $customers->find( $customer_id );
	$appointment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$appointments_table} WHERE id = %d", $appointment_id ) );
	$payment = $payments->find( $payment_id );
	if ( empty( $erased['items_removed'] ) || '' !== $customer->email || '' !== $customer->phone || 0 !== (int) $customer->marketing_consent || '' !== $appointment->customer_note || '' !== $appointment->internal_note || '' !== $payment->note || $logs->find( $log_id ) ) {
		$fail( 'personal data erasure did not anonymize all expected fields' );
	}

	set_transient( YoBooking\Notifications\NotificationService::REMINDER_LOCK, time(), 60 );
	if ( 0 !== ( new YoBooking\Notifications\NotificationService() )->send_due_reminders() ) {
		$fail( 'reminder worker ignored an active lock' );
	}
	delete_transient( YoBooking\Notifications\NotificationService::REMINDER_LOCK );

	$health = new YoBooking\Diagnostics\SiteHealth();
	if ( 'good' !== $health->database_test()['status'] ) {
		$fail( 'Site Health database test failed' );
	}
	$debug = $health->debug_information( array() );
	if ( empty( $debug['yo-booking']['fields']['schema_version']['value'] ) ) {
		$fail( 'Site Health debug information is incomplete' );
	}
} catch ( Throwable $exception ) {
	$error = $exception->getMessage();
} finally {
	$limiter->clear( $rate_scope, $rate_subject );
	remove_filter( 'yo_booking_rate_limit_during_tests', '__return_true' );
	delete_transient( YoBooking\Notifications\NotificationService::REMINDER_LOCK );
	if ( $appointment_id ) {
		$logs->delete_for_appointment( $appointment_id );
		$payments->delete_for_appointment( $appointment_id );
		$wpdb->delete( YoBooking\Database\Migrator::table_name( 'appointments' ), array( 'id' => $appointment_id ), array( '%d' ) );
	}
	if ( $customer_id ) $customers->delete( $customer_id );
	if ( $customer_id ) $wpdb->delete( YoBooking\Database\Migrator::table_name( 'audit_logs' ), array( 'object_type' => 'customer', 'object_id' => $customer_id ), array( '%s', '%d' ) );
	if ( $staff_id && ! is_wp_error( $staff_id ) ) $staff->delete( $staff_id );
	if ( $service_id && ! is_wp_error( $service_id ) ) $services->delete( $service_id );
}

if ( $error ) {
	echo 'FAIL: ' . $error . "\n";
	exit( 1 );
}

echo "phase13_security_privacy_smoke=pass\n";
