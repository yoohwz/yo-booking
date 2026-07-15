<?php
/**
 * Phase 10 admin operations smoke test for WP-CLI.
 *
 * @package YoBooking
 */

defined( 'ABSPATH' ) || exit;
defined( 'YO_BOOKING_RUNNING_TESTS' ) || define( 'YO_BOOKING_RUNNING_TESTS', true );

$service_repository       = new YoBooking\Repositories\ServiceRepository();
$staff_repository         = new YoBooking\Repositories\StaffRepository();
$staff_service_repository = new YoBooking\Repositories\StaffServiceRepository();
$rule_repository          = new YoBooking\Repositories\AvailabilityRuleRepository();
$appointment_repository   = new YoBooking\Repositories\AppointmentRepository();
$customer_repository      = new YoBooking\Repositories\CustomerRepository();
$log_repository           = new YoBooking\Repositories\NotificationLogRepository();
$payment_repository       = new YoBooking\Repositories\PaymentRepository();
$controller               = new YoBooking\Rest\AdminAppointmentController();
$settings                 = ( new YoBooking\Settings\Repository() )->all();
$timezone_name            = ! empty( $settings['company']['timezone'] ) ? $settings['company']['timezone'] : 'UTC';
$timezone                 = new DateTimeZone( $timezone_name );
$suffix                   = gmdate( 'YmdHis' );
$service_id               = 0;
$staff_id                 = 0;
$appointment_ids          = array();
$customer_ids             = array();
$error                    = '';

$fail = static function ( $message ) {
	throw new RuntimeException( $message );
};

$guard = static function ( $result, $label ) use ( $fail ) {
	if ( is_wp_error( $result ) ) {
		$fail( $label . ': ' . $result->get_error_message() );
	}
	if ( ! absint( $result ) ) {
		$fail( $label . ': missing ID' );
	}
	return absint( $result );
};

$request = static function ( $method, array $params = array(), array $json = array() ) {
	$request = new WP_REST_Request( $method );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	if ( ! empty( $json ) ) {
		$request->set_body( wp_json_encode( $json ) );
		$request->set_header( 'content-type', 'application/json' );
	}
	return $request;
};

try {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	if ( empty( $admins ) ) {
		$fail( 'administrator user is required' );
	}
	wp_set_current_user( (int) $admins[0]->ID );
	if ( ! $controller->permission() ) {
		$fail( 'admin permission callback rejected administrator' );
	}

	$date = new DateTimeImmutable( 'tomorrow', $timezone );
	for ( $i = 0; $i < 30 && ! in_array( (int) $date->format( 'w' ), array( 1, 2, 3, 4, 5 ), true ); $i++ ) {
		$date = $date->add( new DateInterval( 'P1D' ) );
	}

	$service_id = $guard( $service_repository->save( array( 'name' => 'Admin Operations Service ' . $suffix, 'duration_minutes' => 60, 'price' => '150.00', 'currency' => 'USD', 'status' => 'active', 'color' => '#2271b1' ) ), 'create service' );
	$staff_id   = $guard( $staff_repository->save( array( 'name' => 'Admin Operations Staff ' . $suffix, 'email' => 'admin-operations-staff-' . $suffix . '@example.test', 'status' => 'active' ) ), 'create staff' );
	$staff_service_repository->sync_for_staff( $staff_id, array( $service_id ) );
	$rule_repository->replace_weekly(
		'staff',
		$staff_id,
		array(
			(int) $date->format( 'w' ) => array( 'enabled' => 1, 'start_time' => '09:00', 'end_time' => '13:00', 'slot_interval_minutes' => 60 ),
		),
		$timezone_name
	);

	foreach ( array( '09:00', '10:00' ) as $index => $start_time ) {
		$id = $guard(
			$appointment_repository->save(
				array(
					'service_id' => $service_id,
					'staff_id' => $staff_id,
					'customer_name' => 'Admin Operations Customer ' . $index . ' ' . $suffix,
					'customer_email' => 'admin-operations-' . $index . '-' . $suffix . '@example.test',
					'date' => $date->format( 'Y-m-d' ),
					'start_time' => $start_time,
					'duration_minutes' => 60,
					'timezone' => $timezone_name,
					'status' => 'confirmed',
					'total_amount' => '150.00',
					'currency' => 'USD',
				)
			),
			'create appointment'
		);
		$appointment_ids[] = $id;
		$customer_ids[] = (int) $appointment_repository->find( $id )->customer_id;
	}

	$range_start = $date->format( 'Y-m-d' ) . 'T00:00:00';
	$range_end   = $date->format( 'Y-m-d' ) . 'T23:59:59';
	$events      = $controller->events( $request( 'GET', array( 'start' => $range_start, 'end' => $range_end, 'timezone' => $timezone_name, 'staff_id' => $staff_id ) ) );
	if ( 2 !== count( $events ) || empty( $events[0]['extendedProps']['status'] ) ) {
		$fail( 'calendar events payload is incomplete' );
	}

	$detail = $controller->appointment( $request( 'GET', array( 'id' => $appointment_ids[0] ) ) );
	if ( is_wp_error( $detail ) || (int) $detail['appointment']['id'] !== $appointment_ids[0] || ! isset( $detail['payments'], $detail['notifications'] ) ) {
		$fail( 'appointment drawer payload is incomplete' );
	}

	$search = $controller->customers( $request( 'GET', array( 'search' => 'Admin Operations Customer 0' ) ) );
	if ( empty( $search['customers'] ) ) {
		$fail( 'customer autocomplete did not return a match' );
	}

	$note = $controller->update_note( $request( 'POST', array( 'id' => $appointment_ids[0] ), array( 'note' => 'Phase 10 drawer note' ) ) );
	if ( is_wp_error( $note ) || 'Phase 10 drawer note' !== $appointment_repository->find( $appointment_ids[0] )->internal_note ) {
		$fail( 'drawer note update failed' );
	}

	$payment = $controller->update_payment( $request( 'POST', array( 'id' => $appointment_ids[0] ), array( 'status' => 'paid', 'amount' => '150.00' ) ) );
	if ( is_wp_error( $payment ) || 'paid' !== $appointment_repository->find( $appointment_ids[0] )->payment_status ) {
		$fail( 'drawer payment update failed' );
	}

	$conflict = $controller->reschedule( $request( 'POST', array( 'id' => $appointment_ids[1] ), array( 'local_start' => $date->format( 'Y-m-d' ) . 'T09:00:00', 'local_end' => $date->format( 'Y-m-d' ) . 'T10:00:00', 'timezone' => $timezone_name ) ) );
	if ( ! is_wp_error( $conflict ) || 'yo_booking_appointment_slot_unavailable' !== $conflict->get_error_code() ) {
		$fail( 'drag reschedule conflict was not rejected' );
	}

	$moved = $controller->reschedule( $request( 'POST', array( 'id' => $appointment_ids[1] ), array( 'local_start' => $date->format( 'Y-m-d' ) . 'T11:00:00', 'local_end' => $date->format( 'Y-m-d' ) . 'T12:00:00', 'timezone' => $timezone_name ) ) );
	if ( is_wp_error( $moved ) || '11:00' !== ( new DateTimeImmutable( $appointment_repository->find( $appointment_ids[1] )->start_at, new DateTimeZone( 'UTC' ) ) )->setTimezone( $timezone )->format( 'H:i' ) ) {
		$fail( 'valid drag reschedule did not persist' );
	}

	$bulk = $controller->bulk_status( $request( 'POST', array(), array( 'ids' => $appointment_ids, 'status' => 'completed' ) ) );
	if ( is_wp_error( $bulk ) || 2 !== count( $bulk['updated'] ) ) {
		$fail( 'bulk status update failed' );
	}
} catch ( Throwable $exception ) {
	$error = $exception->getMessage();
} finally {
	global $wpdb;
	foreach ( $appointment_ids as $appointment_id ) {
		$log_repository->delete_for_appointment( $appointment_id );
		$payment_repository->delete_for_appointment( $appointment_id );
		$wpdb->delete( YoBooking\Database\Migrator::table_name( 'appointments' ), array( 'id' => absint( $appointment_id ) ), array( '%d' ) );
	}
	foreach ( array_unique( array_filter( $customer_ids ) ) as $customer_id ) {
		$customer_repository->delete( $customer_id );
	}
	if ( $staff_id ) {
		$rule_repository->delete_for_owner( 'staff', $staff_id );
		$staff_repository->delete( $staff_id );
	}
	if ( $service_id ) {
		$service_repository->delete( $service_id );
	}
}

if ( $error ) {
	echo 'FAIL: ' . $error . "\n";
	exit( 1 );
}

echo "phase10_admin_operations_smoke=pass\n";
echo 'date=' . $date->format( 'Y-m-d' ) . "\n";
