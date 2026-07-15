<?php
/**
 * Phase 14 signed webhooks and API key integration smoke test.
 *
 * @package YoBooking
 */

defined( 'ABSPATH' ) || exit;
defined( 'YO_BOOKING_RUNNING_TESTS' ) || define( 'YO_BOOKING_RUNNING_TESTS', true );

global $wpdb;

$suffix          = gmdate( 'YmdHis' );
$endpoint_id     = 0;
$delivery_ids    = array();
$api_key_ids     = array();
$appointment_id  = 0;
$customer_id     = 0;
$service_id      = 0;
$staff_id        = 0;
$captured_request = array();
$error           = '';
$endpoints       = new YoBooking\Repositories\WebhookEndpointRepository();
$deliveries      = new YoBooking\Repositories\WebhookDeliveryRepository();
$api_keys        = new YoBooking\Repositories\ApiKeyRepository();
$customers       = new YoBooking\Repositories\CustomerRepository();
$services        = new YoBooking\Repositories\ServiceRepository();
$staff           = new YoBooking\Repositories\StaffRepository();
$dispatcher      = new YoBooking\Integrations\WebhookDispatcher();
$fail            = static function ( $message ) { throw new RuntimeException( $message ); };

try {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	if ( ! $admins ) $fail( 'administrator user is required' );
	wp_set_current_user( (int) $admins[0]->ID );

	foreach ( array( 'webhook_endpoints', 'webhook_deliveries', 'api_keys' ) as $suffix_name ) {
		$table = YoBooking\Database\Migrator::table_name( $suffix_name );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) $fail( $suffix_name . ' table is missing' );
	}

	$secret = 'whsec_phase14_' . $suffix;
	$endpoint_id = $endpoints->save( array( 'name' => 'Phase 14 endpoint', 'url' => 'https://example.com/yo-booking-hook', 'secret' => $secret, 'events' => array( 'appointment.created' ), 'status' => 'active', 'timeout_seconds' => 8 ) );
	if ( is_wp_error( $endpoint_id ) || $secret !== $endpoints->secret( $endpoints->find( $endpoint_id ) ) ) $fail( 'webhook endpoint encryption failed' );

	$queued = $dispatcher->queue( 'appointment.created', 'appointment', 1414, array( 'appointment' => array( 'id' => 1414, 'status' => 'confirmed' ) ) );
	if ( 1 !== count( $queued ) ) $fail( 'webhook event was not queued' );
	$delivery_ids[] = (int) $queued[0];
	add_filter( 'pre_http_request', static function ( $preempt, $args, $url ) use ( &$captured_request ) {
		$captured_request = array( 'args' => $args, 'url' => $url );
		return array( 'headers' => array(), 'body' => 'accepted', 'response' => array( 'code' => 202, 'message' => 'Accepted' ), 'cookies' => array(), 'filename' => null );
	}, 10, 3 );
	if ( ! $dispatcher->process( $delivery_ids[0] ) ) $fail( 'successful webhook delivery failed' );
	remove_all_filters( 'pre_http_request' );
	$delivery = $deliveries->find( $delivery_ids[0] );
	$timestamp = $captured_request['args']['headers']['X-Yo-Booking-Timestamp'];
	$expected_signature = YoBooking\Integrations\WebhookDispatcher::signature( $timestamp, $delivery->payload, $secret );
	if ( 'delivered' !== $delivery->status || 202 !== (int) $delivery->response_code || $expected_signature !== $captured_request['args']['headers']['X-Yo-Booking-Signature'] || 'https://example.com/yo-booking-hook' !== $captured_request['url'] ) {
		$fail( 'webhook signature or delivered state is incorrect' );
	}

	$failed_ids = $dispatcher->queue( 'appointment.created', 'appointment', 1415, array( 'appointment' => array( 'id' => 1415 ) ) );
	$delivery_ids[] = (int) $failed_ids[0];
	add_filter( 'pre_http_request', static function () { return array( 'headers' => array(), 'body' => 'temporary outage', 'response' => array( 'code' => 503, 'message' => 'Unavailable' ), 'cookies' => array(), 'filename' => null ); } );
	if ( false !== $dispatcher->process( $delivery_ids[1] ) ) $fail( 'failed webhook unexpectedly succeeded' );
	remove_all_filters( 'pre_http_request' );
	$failed = $deliveries->find( $delivery_ids[1] );
	if ( 'retrying' !== $failed->status || 1 !== (int) $failed->attempts || ! $failed->next_attempt_at ) $fail( 'webhook retry state is incorrect' );

	$read_key = $api_keys->create_key( array( 'name' => 'Phase 14 reader', 'capabilities' => array( 'read_appointments', 'read_customers' ) ) );
	$write_key = $api_keys->create_key( array( 'name' => 'Phase 14 writer', 'capabilities' => array( 'write_appointments' ) ) );
	if ( is_wp_error( $read_key ) || is_wp_error( $write_key ) ) $fail( 'API key creation failed' );
	$api_key_ids = array( $read_key['id'], $write_key['id'] );
	if ( ! is_wp_error( $api_keys->authenticate( 'yb_live_invalid_key_value' ) ) || is_wp_error( $api_keys->authenticate( $read_key['key'] ) ) ) $fail( 'API key hash authentication failed' );

	$service_id = $services->save( array( 'name' => 'Phase 14 Service ' . $suffix, 'duration_minutes' => 30, 'price' => 50, 'currency' => 'USD' ) );
	$staff_id = $staff->save( array( 'name' => 'Phase 14 Staff ' . $suffix, 'email' => 'phase14-staff-' . $suffix . '@example.test' ) );
	$customer_id = $customers->save( array( 'name' => 'Phase 14 Customer', 'email' => 'phase14-customer-' . $suffix . '@example.test', 'phone' => '+15550141414', 'timezone' => 'UTC' ) );
	$appointments_table = YoBooking\Database\Migrator::table_name( 'appointments' );
	$now = current_time( 'mysql', true );
	$wpdb->insert( $appointments_table, array( 'uuid' => wp_generate_uuid4(), 'customer_id' => $customer_id, 'service_id' => $service_id, 'staff_id' => $staff_id, 'start_at' => '2026-08-14 09:00:00', 'end_at' => '2026-08-14 09:30:00', 'timezone' => 'UTC', 'status' => 'confirmed', 'source' => 'test', 'subtotal_amount' => 50, 'total_amount' => 50, 'currency' => 'USD', 'payment_status' => 'unpaid', 'created_at' => $now, 'updated_at' => $now ) );
	$appointment_id = (int) $wpdb->insert_id;

	$controller = new YoBooking\Rest\IntegrationController();
	$read_request = new WP_REST_Request( 'GET', '/yo-booking/v1/integrations/appointments' );
	$read_request->set_header( 'Authorization', 'Bearer ' . $read_key['key'] );
	$read_request->set_param( 'per_page', 25 );
	$read_request->set_param( 'service_id', $service_id );
	if ( true !== $controller->can_read_appointments( $read_request ) ) $fail( 'read API key was denied' );
	$list = $controller->appointments( $read_request );
	if ( empty( $list['appointments'] ) || $appointment_id !== (int) $list['appointments'][0]['id'] ) $fail( 'integration appointment list is incomplete' );
	$forbidden = $controller->can_write_appointments( $read_request );
	if ( ! is_wp_error( $forbidden ) || 403 !== (int) $forbidden->get_error_data()['status'] ) $fail( 'API key scope was not enforced' );
	$write_request = new WP_REST_Request( 'POST', '/yo-booking/v1/integrations/appointments/' . $appointment_id . '/status' );
	$write_request->set_header( 'Authorization', 'Bearer ' . $write_key['key'] );
	$write_request->set_url_params( array( 'id' => $appointment_id ) );
	$write_request->set_body_params( array( 'status' => 'no_show' ) );
	$write_permission = $controller->can_write_appointments( $write_request );
	$status_result    = true === $write_permission ? $controller->update_status( $write_request ) : $write_permission;
	if ( is_wp_error( $status_result ) ) $fail( 'scoped status update failed: ' . $status_result->get_error_code() . ' - ' . $status_result->get_error_message() );

	$_GET = array( 'integration_tab' => 'deliveries' );
	ob_start(); ( new YoBooking\Admin\IntegrationsPage() )->render(); $html = ob_get_clean(); $_GET = array();
	if ( false === strpos( $html, 'Webhook endpoints' ) || false === strpos( $html, 'Delivery logs' ) || false === strpos( $html, 'API keys' ) ) $fail( 'integration admin UI is incomplete' );
} catch ( Throwable $exception ) {
	$error = $exception->getMessage();
} finally {
	$_GET = array();
	remove_all_filters( 'pre_http_request' );
	foreach ( $delivery_ids as $id ) { wp_clear_scheduled_hook( YoBooking\Integrations\WebhookDispatcher::PROCESS_HOOK, array( $id ) ); $deliveries->delete( $id ); }
	foreach ( $api_key_ids as $id ) $api_keys->delete( $id );
	if ( $appointment_id ) $wpdb->delete( YoBooking\Database\Migrator::table_name( 'appointments' ), array( 'id' => $appointment_id ), array( '%d' ) );
	if ( $customer_id && ! is_wp_error( $customer_id ) ) $customers->delete( $customer_id );
	if ( $staff_id && ! is_wp_error( $staff_id ) ) $staff->delete( $staff_id );
	if ( $service_id && ! is_wp_error( $service_id ) ) $services->delete( $service_id );
	if ( $endpoint_id && ! is_wp_error( $endpoint_id ) ) $endpoints->delete( $endpoint_id );
}

if ( $error ) { echo 'FAIL: ' . $error . "\n"; exit( 1 ); }
echo "phase14_integrations_smoke=pass\n";
