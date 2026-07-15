<?php
/**
 * Phase 8 customer portal smoke test for WP-CLI.
 *
 * Run with:
 * wp eval-file wp-content/plugins/yo-booking/tests/phase8-customer-portal-smoke.php
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
$user_id                  = 0;
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
	$date = new DateTimeImmutable( '+10 days', $timezone );

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
		if ( $nonce ) {
			$request->set_header( 'X-WP-Nonce', $nonce );
		}
		$request->set_body_params( $params );
	}

	return rest_do_request( $request );
};

try {
	$settings['notifications']['enabled'] = false;
	$settings_repository->save( $settings );

	$date  = $next_weekday();
	$email = 'portal-customer-' . $suffix . '@example.test';

	$user_id = wp_insert_user(
		array(
			'user_login'   => 'portal_customer_' . $suffix,
			'user_pass'    => wp_generate_password( 24, true ),
			'user_email'   => $email,
			'display_name' => 'Portal Smoke Customer ' . $suffix,
			'first_name'   => 'Portal',
			'last_name'    => 'Customer',
			'role'         => 'subscriber',
		)
	);

	if ( is_wp_error( $user_id ) ) {
		$fail( 'create user: ' . $user_id->get_error_message() );
	}

	$service_id = $guard(
		$service_repository->save(
			array(
				'name'             => 'Portal Smoke Service ' . $suffix,
				'duration_minutes' => 60,
				'price'            => '110.00',
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
				'name'   => 'Portal Smoke Staff ' . $suffix,
				'email'  => 'portal-staff-' . $suffix . '@example.test',
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
				'start_time'            => '15:00',
				'end_time'              => '17:00',
				'slot_interval_minutes' => 60,
			),
		),
		$timezone_name
	);

	do_action( 'rest_api_init' );
	wp_set_current_user( $user_id );

	$booking_response = $rest_request(
		'POST',
		'/yo-booking/v1/booking/appointments',
		array(
			'service_id'       => $service_id,
			'staff_id'         => $staff_id,
			'customer_name'    => 'Portal Smoke Customer ' . $suffix,
			'customer_email'   => $email,
			'customer_phone'   => '+15550000008',
			'date'             => $date->format( 'Y-m-d' ),
			'start_time'       => '15:00',
			'duration_minutes' => 60,
			'timezone'         => $timezone_name,
		),
		wp_create_nonce( 'wp_rest' )
	);

	if ( $booking_response->is_error() ) {
		$fail( 'booking endpoint failed: ' . $booking_response->as_error()->get_error_message() );
	}

	$appointment_id = absint( $booking_response->get_data()['appointment_id'] );
	$appointment    = $appointment_repository->find_with_details( $appointment_id );
	$customer       = $appointment ? $customer_repository->find( (int) $appointment->customer_id ) : null;
	$customer_id    = $customer ? (int) $customer->id : 0;

	if ( ! $customer || (int) $customer->user_id !== (int) $user_id ) {
		$fail( 'frontend booking did not link customer to logged-in user' );
	}

	$portal_response = $rest_request( 'GET', '/yo-booking/v1/booking/customer/appointments' );

	if ( $portal_response->is_error() ) {
		$fail( 'portal endpoint failed: ' . $portal_response->as_error()->get_error_message() );
	}

	$portal_data = $portal_response->get_data();
	$upcoming    = $portal_data['appointments']['upcoming'];

	if ( empty( $upcoming ) ) {
		$fail( 'portal did not return upcoming appointment' );
	}

	$found = null;
	foreach ( $upcoming as $row ) {
		if ( (int) $row['id'] === $appointment_id ) {
			$found = $row;
			break;
		}
	}

	if ( ! $found ) {
		$fail( 'portal upcoming list did not include the created appointment' );
	}

	if ( empty( $found['cancel_url'] ) || empty( $found['reschedule_url'] ) || false === strpos( $found['cancel_url'], 'token=' ) ) {
		$fail( 'portal action links were missing tokenized URLs' );
	}

	wp_set_current_user( 0 );
	$logged_out_response = $rest_request( 'GET', '/yo-booking/v1/booking/customer/appointments' );

	if ( ! $logged_out_response->is_error() ) {
		$fail( 'logged-out portal request was not rejected' );
	}

	if ( 'yo_booking_login_required' !== $logged_out_response->as_error()->get_error_code() || 401 !== $logged_out_response->get_status() ) {
		$fail( 'logged-out portal did not return the expected authentication error' );
	}
} catch ( Throwable $exception ) {
	$error = $exception->getMessage();
} finally {
	global $wpdb;

	wp_set_current_user( 0 );
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

	if ( $user_id ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
	}
}

if ( $error ) {
	echo 'FAIL: ' . $error . "\n";
	exit( 1 );
}

echo "phase8_customer_portal_smoke=pass\n";
echo 'appointment_id=' . $appointment_id . "\n";
