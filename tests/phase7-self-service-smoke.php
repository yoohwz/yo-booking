<?php
/**
 * Phase 7 self-service cancel/reschedule smoke test for WP-CLI.
 *
 * Run with:
 * wp eval-file wp-content/plugins/yo-booking/tests/phase7-self-service-smoke.php
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
$original_settings        = $settings;
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
	$date = new DateTimeImmutable( '+7 days', $timezone );

	for ( $i = 0; $i < 30; $i++ ) {
		if ( in_array( (int) $date->format( 'w' ), array( 1, 2, 3, 4, 5 ), true ) ) {
			return $date;
		}

		$date = $date->add( new DateInterval( 'P1D' ) );
	}

	return $date;
};

$rest_request = static function ( $method, $route, $params = array() ) {
	$request = new WP_REST_Request( $method, $route );

	if ( 'GET' === $method ) {
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
	} else {
		$request->set_body_params( $params );
	}

	return rest_do_request( $request );
};

try {
	$settings['notifications']['enabled'] = false;
	$settings_repository->save( $settings );

	$date = $next_weekday();

	$service_id = $guard(
		$service_repository->save(
			array(
				'name'             => 'Self Service Smoke Service ' . $suffix,
				'duration_minutes' => 60,
				'price'            => '90.00',
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
				'name'   => 'Self Service Smoke Staff ' . $suffix,
				'email'  => 'self-service-staff-' . $suffix . '@example.test',
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
				'start_time'            => '09:00',
				'end_time'              => '12:00',
				'slot_interval_minutes' => 60,
			),
		),
		$timezone_name
	);

	$appointment_id = $guard(
		$appointment_repository->save(
			array(
				'service_id'       => $service_id,
				'staff_id'         => $staff_id,
				'customer_name'    => 'Self Service Smoke Customer ' . $suffix,
				'customer_email'   => 'self-service-customer-' . $suffix . '@example.test',
				'customer_phone'   => '+15550000007',
				'date'             => $date->format( 'Y-m-d' ),
				'start_time'       => '09:00',
				'duration_minutes' => 60,
				'timezone'         => $timezone_name,
				'status'           => 'confirmed',
				'source'           => 'frontend',
			)
		),
		'create appointment'
	);

	$appointment = $appointment_repository->find_with_details( $appointment_id );
	$customer_id = (int) $appointment->customer_id;
	$manage_token     = YoBooking\Support\ActionToken::generate( $appointment, 'manage' );
	$reschedule_token = YoBooking\Support\ActionToken::generate( $appointment, 'reschedule' );
	$cancel_token     = YoBooking\Support\ActionToken::generate( $appointment, 'cancel' );

	do_action( 'rest_api_init' );

	$bad_response = $rest_request(
		'GET',
		'/yo-booking/v1/booking/appointments/' . $appointment->uuid . '/manage',
		array( 'token' => 'bad-token' )
	);

	if ( ! $bad_response->is_error() ) {
		$fail( 'invalid token was not rejected' );
	}

	$manage_response = $rest_request(
		'GET',
		'/yo-booking/v1/booking/appointments/' . $appointment->uuid . '/manage',
		array( 'token' => $manage_token, 'action' => 'manage' )
	);

	if ( $manage_response->is_error() ) {
		$fail( 'manage endpoint failed: ' . $manage_response->as_error()->get_error_message() );
	}

	$manage_data = $manage_response->get_data();
	if ( empty( $manage_data['appointment']['can_cancel'] ) || empty( $manage_data['appointment']['can_reschedule'] ) ) {
		$fail( 'self-service action windows were unexpectedly closed' );
	}

	$reschedule_response = $rest_request(
		'POST',
		'/yo-booking/v1/booking/appointments/' . $appointment->uuid . '/reschedule',
		array(
			'token'      => $reschedule_token,
			'action'     => 'reschedule',
			'date'       => $date->format( 'Y-m-d' ),
			'start_time' => '10:00',
			'staff_id'   => $staff_id,
		)
	);

	if ( $reschedule_response->is_error() ) {
		$fail( 'reschedule endpoint failed: ' . $reschedule_response->as_error()->get_error_message() );
	}

	$rescheduled = $appointment_repository->find_with_details( $appointment_id );
	$rescheduled_local = ( new DateTimeImmutable( $rescheduled->start_at, new DateTimeZone( 'UTC' ) ) )->setTimezone( $timezone );

	if ( '10:00' !== $rescheduled_local->format( 'H:i' ) ) {
		$fail( 'appointment was not rescheduled to the requested time' );
	}

	$cancel_response = $rest_request(
		'POST',
		'/yo-booking/v1/booking/appointments/' . $appointment->uuid . '/cancel',
		array(
			'token'  => $cancel_token,
			'action' => 'cancel',
			'reason' => 'Phase 7 smoke cancellation',
		)
	);

	if ( $cancel_response->is_error() ) {
		$fail( 'cancel endpoint failed: ' . $cancel_response->as_error()->get_error_message() );
	}

	if ( 'cancelled' !== $appointment_repository->find( $appointment_id )->status ) {
		$fail( 'appointment was not cancelled' );
	}

	$closed_response = $rest_request(
		'POST',
		'/yo-booking/v1/booking/appointments/' . $appointment->uuid . '/reschedule',
		array(
			'token'      => $reschedule_token,
			'action'     => 'reschedule',
			'date'       => $date->format( 'Y-m-d' ),
			'start_time' => '11:00',
			'staff_id'   => $staff_id,
		)
	);

	if ( ! $closed_response->is_error() ) {
		$fail( 'cancelled appointment was still reschedulable' );
	}
} catch ( Throwable $exception ) {
	$error = $exception->getMessage();
} finally {
	global $wpdb;

	$settings_repository->save( $original_settings );

	if ( $appointment_id ) {
		$log_repository->delete_for_appointment( $appointment_id );
		$payment_repository->delete_for_appointment( $appointment_id );
		$wpdb->delete( YoBooking\Database\Migrator::table_name( 'appointments' ), array( 'id' => $appointment_id ), array( '%d' ) );
	}

	if ( $customer_id ) {
		$customer_repository->delete( $customer_id );
	}

	if ( $staff_id ) {
		$staff_service_repository->sync_for_staff( $staff_id, array() );
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

echo "phase7_self_service_smoke=pass\n";
echo 'appointment_id=' . $appointment_id . "\n";
