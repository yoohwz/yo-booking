<?php
/**
 * Phase 5 frontend booking flow smoke test for WP-CLI.
 *
 * Run with:
 * wp eval-file wp-content/plugins/yo-booking/tests/phase5-frontend-booking-smoke.php
 *
 * @package YoBooking
 */

defined( 'ABSPATH' ) || exit;
defined( 'YO_BOOKING_RUNNING_TESTS' ) || define( 'YO_BOOKING_RUNNING_TESTS', true );

$settings_repository      = new YoBooking\Settings\Repository();
$service_repository       = new YoBooking\Repositories\ServiceRepository();
$staff_repository         = new YoBooking\Repositories\StaffRepository();
$staff_service_repository = new YoBooking\Repositories\StaffServiceRepository();
$rule_repository          = new YoBooking\Repositories\AvailabilityRuleRepository();
$appointment_repository   = new YoBooking\Repositories\AppointmentRepository();
$customer_repository      = new YoBooking\Repositories\CustomerRepository();
$log_repository           = new YoBooking\Repositories\NotificationLogRepository();
$payment_repository       = new YoBooking\Repositories\PaymentRepository();
$settings                 = $settings_repository->all();
$timezone_name            = ! empty( $settings['company']['timezone'] ) ? $settings['company']['timezone'] : 'UTC';
$timezone                 = new DateTimeZone( $timezone_name );
$suffix                   = gmdate( 'YmdHis' );
$service_id               = 0;
$staff_id                 = 0;
$appointment_id           = 0;
$customer_id              = 0;
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

$next_weekday = static function () use ( $timezone ) {
	$date = new DateTimeImmutable( 'tomorrow', $timezone );

	for ( $i = 0; $i < 30; $i++ ) {
		if ( in_array( (int) $date->format( 'w' ), array( 1, 2, 3, 4, 5 ), true ) ) {
			return $date;
		}

		$date = $date->add( new DateInterval( 'P1D' ) );
	}

	return $date;
};

$rest_request = static function ( $method, $route, $params = array(), $nonce = '' ) {
	$request = new WP_REST_Request( $method, $route );

	if ( 'GET' === $method ) {
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
	} else {
		$request->set_header( 'X-WP-Nonce', $nonce );
		$request->set_body_params( $params );
	}

	return rest_do_request( $request );
};

try {
	$date = $next_weekday();

	$service_id = $guard(
		$service_repository->save(
			array(
				'name'             => 'Frontend Smoke Service ' . $suffix,
				'duration_minutes' => 60,
				'price'            => '80.00',
				'currency'         => 'USD',
				'capacity'         => 1,
				'status'           => 'active',
			)
		),
		'create service'
	);

	$staff_id = $guard(
		$staff_repository->save(
			array(
				'name'   => 'Frontend Smoke Staff ' . $suffix,
				'email'  => 'frontend-staff-' . $suffix . '@example.test',
				'status' => 'active',
			)
		),
		'create staff'
	);

	$staff_service_repository->sync_for_staff( $staff_id, array( $service_id ) );
	$rule_repository->replace_weekly(
		'staff',
		$staff_id,
		array(
			(int) $date->format( 'w' ) => array(
				'enabled'               => 1,
				'start_time'            => '14:00',
				'end_time'              => '16:00',
				'slot_interval_minutes' => 60,
			),
		),
		$timezone_name
	);

	do_action( 'rest_api_init' );

	$services_response = $rest_request( 'GET', '/yo-booking/v1/booking/services' );
	if ( $services_response->is_error() ) {
		$fail( 'services REST failed' );
	}

	$services = $services_response->get_data()['services'];
	$found_service = false;
	foreach ( $services as $service ) {
		if ( (int) $service['id'] === $service_id ) {
			$found_service = true;
			break;
		}
	}

	if ( ! $found_service ) {
		$fail( 'created service was not returned by frontend service endpoint' );
	}

	$staff_response = $rest_request(
		'GET',
		'/yo-booking/v1/booking/staff',
		array( 'service_id' => $service_id )
	);
	if ( $staff_response->is_error() || empty( $staff_response->get_data()['staff'] ) ) {
		$fail( 'staff REST endpoint did not return assigned staff' );
	}

	$times_response = $rest_request(
		'GET',
		'/yo-booking/v1/availability/times',
		array(
			'service_id' => $service_id,
			'staff_id'   => $staff_id,
			'date'       => $date->format( 'Y-m-d' ),
			'timezone'   => $timezone_name,
		)
	);
	if ( $times_response->is_error() ) {
		$fail( 'availability times REST failed' );
	}

	$slots = $times_response->get_data()['slots'];
	if ( empty( $slots ) || '14:00' !== substr( $slots[0]['start'], 11, 5 ) ) {
		$fail( 'availability endpoint returned unexpected slots' );
	}

	$nonce = wp_create_nonce( 'wp_rest' );
	$booking_response = $rest_request(
		'POST',
		'/yo-booking/v1/booking/appointments',
		array(
			'service_id'       => $service_id,
			'staff_id'         => $staff_id,
			'customer_name'    => 'Frontend Smoke Customer ' . $suffix,
			'customer_email'   => 'frontend-customer-' . $suffix . '@example.test',
			'customer_phone'   => '+15550000005',
			'customer_note'    => 'Created by Phase 5 smoke test.',
			'date'             => $date->format( 'Y-m-d' ),
			'start_time'       => '14:00',
			'duration_minutes' => 60,
			'timezone'         => $timezone_name,
		),
		$nonce
	);

	if ( $booking_response->is_error() ) {
		$fail( 'booking REST failed: ' . $booking_response->as_error()->get_error_message() );
	}

	$booking_data    = $booking_response->get_data();
	$appointment_id  = absint( $booking_data['appointment_id'] );
	if ( empty( $booking_data['appointment']['date_display'] ) || empty( $booking_data['manage_url'] ) || empty( $booking_data['cancel_url'] ) ) {
		$fail( 'booking REST confirmation details are incomplete' );
	}
	$appointment     = $appointment_repository->find( $appointment_id );
	$customer_id     = $appointment ? absint( $appointment->customer_id ) : 0;

	if ( ! $appointment || (int) $appointment->service_id !== $service_id || (int) $appointment->staff_id !== $staff_id ) {
		$fail( 'frontend booking did not create the expected appointment' );
	}

	if ( 'frontend' !== $appointment->source ) {
		$fail( 'frontend booking source was not persisted' );
	}

	$double_response = $rest_request(
		'POST',
		'/yo-booking/v1/booking/appointments',
		array(
			'service_id'       => $service_id,
			'staff_id'         => $staff_id,
			'customer_name'    => 'Frontend Smoke Double ' . $suffix,
			'customer_email'   => 'frontend-double-' . $suffix . '@example.test',
			'customer_phone'   => '+15550000006',
			'date'             => $date->format( 'Y-m-d' ),
			'start_time'       => '14:00',
			'duration_minutes' => 60,
			'timezone'         => $timezone_name,
		),
		$nonce
	);

	if ( ! $double_response->is_error() ) {
		$fail( 'frontend double booking was not blocked' );
	}
} catch ( Throwable $exception ) {
	$error = $exception->getMessage();
} finally {
	global $wpdb;

	if ( $appointment_id ) {
		$log_repository->delete_for_appointment( $appointment_id );
		$payment_repository->delete_for_appointment( $appointment_id );
		$wpdb->delete( YoBooking\Database\Migrator::table_name( 'appointments' ), array( 'id' => $appointment_id ), array( '%d' ) );
	}

	if ( $customer_id ) {
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

echo "phase5_frontend_booking_smoke=pass\n";
echo 'date=' . $date->format( 'Y-m-d' ) . "\n";
echo 'appointment_id=' . $appointment_id . "\n";
