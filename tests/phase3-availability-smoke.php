<?php
/**
 * Phase 3 availability smoke test for WP-CLI.
 *
 * Run with:
 * wp eval-file wp-content/plugins/yo-booking/tests/phase3-availability-smoke.php
 *
 * @package YoBooking
 */

defined( 'ABSPATH' ) || exit;
defined( 'YO_BOOKING_RUNNING_TESTS' ) || define( 'YO_BOOKING_RUNNING_TESTS', true );

$settings_repository       = new YoBooking\Settings\Repository();
$service_repository        = new YoBooking\Repositories\ServiceRepository();
$staff_repository          = new YoBooking\Repositories\StaffRepository();
$staff_service_repository  = new YoBooking\Repositories\StaffServiceRepository();
$rule_repository           = new YoBooking\Repositories\AvailabilityRuleRepository();
$exception_repository      = new YoBooking\Repositories\AvailabilityExceptionRepository();
$engine                    = new YoBooking\Availability\AvailabilityEngine();
$settings                  = $settings_repository->all();
$timezone_name             = ! empty( $settings['company']['timezone'] ) ? $settings['company']['timezone'] : 'UTC';
$timezone                  = new DateTimeZone( $timezone_name );
$suffix                    = gmdate( 'YmdHis' );
$error                     = '';

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

$next_weekday = static function ( $allowed_weekdays ) use ( $timezone ) {
	$date = new DateTimeImmutable( 'tomorrow', $timezone );

	for ( $i = 0; $i < 30; $i++ ) {
		if ( in_array( (int) $date->format( 'w' ), $allowed_weekdays, true ) ) {
			return $date->format( 'Y-m-d' );
		}

		$date = $date->add( new DateInterval( 'P1D' ) );
	}

	return $date->format( 'Y-m-d' );
};

$service_id = 0;
$staff_id   = 0;
$appointment_id = 0;

try {
	$service_id = $guard(
		$service_repository->save(
			array(
				'name'                  => 'Availability Smoke Service ' . $suffix,
				'duration_minutes'      => 60,
				'buffer_before_minutes' => 0,
				'buffer_after_minutes'  => 0,
				'price'                 => '0',
				'currency'              => 'USD',
				'capacity'              => 1,
				'status'                => 'active',
			)
		),
		'create service'
	);

	$business_date = $next_weekday( array( 1, 2, 3, 4, 5 ) );
	$global_times  = $engine->available_times(
		array(
			'service_id' => $service_id,
			'date'       => $business_date,
			'timezone'   => $timezone_name,
		)
	);

	if ( is_wp_error( $global_times ) ) {
		$fail( 'global availability: ' . $global_times->get_error_message() );
	}

	if ( empty( $global_times['slots'] ) ) {
		$fail( 'global business hours returned no slots' );
	}

	$staff_id = $guard(
		$staff_repository->save(
			array(
				'name'   => 'Availability Smoke Staff ' . $suffix,
				'email'  => 'availability-smoke-' . $suffix . '@example.test',
				'status' => 'active',
			)
		),
		'create staff'
	);

	$staff_service_repository->sync_for_staff( $staff_id, array( $service_id ) );

	$staff_date    = $next_weekday( array( 1, 2, 3, 4, 5 ) );
	$staff_weekday = (int) ( new DateTimeImmutable( $staff_date, $timezone ) )->format( 'w' );

	$rule_repository->replace_weekly(
		'staff',
		$staff_id,
		array(
			$staff_weekday => array(
				'enabled'               => 1,
				'start_time'            => '10:00',
				'end_time'              => '12:00',
				'slot_interval_minutes' => 30,
			),
		),
		$timezone_name
	);

	$staff_times = $engine->available_times(
		array(
			'service_id' => $service_id,
			'staff_id'   => $staff_id,
			'date'       => $staff_date,
			'timezone'   => $timezone_name,
		)
	);

	if ( is_wp_error( $staff_times ) ) {
		$fail( 'staff availability: ' . $staff_times->get_error_message() );
	}

	$staff_starts = array_map(
		static function ( $slot ) {
			return substr( $slot['start'], 11, 5 );
		},
		$staff_times['slots']
	);

	if ( array( '10:00', '10:30', '11:00' ) !== $staff_starts ) {
		$fail( 'staff schedule override returned unexpected starts: ' . implode( ',', $staff_starts ) );
	}

	$exception_id = $guard(
		$exception_repository->save(
			array(
				'owner_type'        => 'staff',
				'owner_id'          => $staff_id,
				'exception_date'    => $staff_date,
				'start_time'        => '10:30',
				'end_time'          => '11:00',
				'availability_type' => 'blocked',
				'reason'            => 'Smoke block',
				'timezone'          => $timezone_name,
			)
		),
		'create blocked exception'
	);

	$blocked_times = $engine->available_times(
		array(
			'service_id' => $service_id,
			'staff_id'   => $staff_id,
			'date'       => $staff_date,
			'timezone'   => $timezone_name,
		)
	);

	if ( is_wp_error( $blocked_times ) ) {
		$fail( 'blocked exception: ' . $blocked_times->get_error_message() );
	}

	$blocked_starts = array_map(
		static function ( $slot ) {
			return substr( $slot['start'], 11, 5 );
		},
		$blocked_times['slots']
	);

	if ( array( '11:00' ) !== $blocked_starts ) {
		$fail( 'blocked exception returned unexpected starts: ' . implode( ',', $blocked_starts ) );
	}

	$slot        = $blocked_times['slots'][0];
	$appointments_table = YoBooking\Database\Migrator::table_name( 'appointments' );
	global $wpdb;
	$wpdb->insert(
		$appointments_table,
		array(
			'uuid'             => wp_generate_uuid4(),
			'customer_id'      => 0,
			'service_id'       => $service_id,
			'staff_id'         => $staff_id,
			'location_id'      => 0,
			'resource_id'      => 0,
			'start_at'         => $slot['start_utc'],
			'end_at'           => $slot['end_utc'],
			'timezone'         => $timezone_name,
			'status'           => 'confirmed',
			'source'           => 'admin',
			'customer_note'    => '',
			'internal_note'    => 'Smoke appointment conflict',
			'subtotal_amount'  => '0.00',
			'total_amount'     => '0.00',
			'currency'         => 'USD',
			'payment_status'   => 'unpaid',
			'created_by'       => 0,
			'created_at'       => current_time( 'mysql', true ),
			'updated_at'       => current_time( 'mysql', true ),
		),
		array( '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%d', '%s', '%s' )
	);
	$appointment_id = (int) $wpdb->insert_id;

	if ( ! $appointment_id ) {
		$fail( 'create conflict appointment: ' . $wpdb->last_error );
	}

	$conflict_times = $engine->available_times(
		array(
			'service_id' => $service_id,
			'staff_id'   => $staff_id,
			'date'       => $staff_date,
			'timezone'   => $timezone_name,
		)
	);

	if ( is_wp_error( $conflict_times ) ) {
		$fail( 'conflict check: ' . $conflict_times->get_error_message() );
	}

	if ( ! empty( $conflict_times['slots'] ) ) {
		$fail( 'appointment conflict did not block the remaining slot' );
	}

	$weekend_date = $next_weekday( array( 0, 6 ) );
	$exception_repository->save(
		array(
			'owner_type'        => 'staff',
			'owner_id'          => $staff_id,
			'exception_date'    => $weekend_date,
			'start_time'        => '13:00',
			'end_time'          => '15:00',
			'availability_type' => 'available',
			'reason'            => 'Smoke special hours',
			'timezone'          => $timezone_name,
		)
	);

	$special_times = $engine->available_times(
		array(
			'service_id' => $service_id,
			'staff_id'   => $staff_id,
			'date'       => $weekend_date,
			'timezone'   => $timezone_name,
		)
	);

	if ( is_wp_error( $special_times ) ) {
		$fail( 'special hours: ' . $special_times->get_error_message() );
	}

	$special_starts = array_map(
		static function ( $slot ) {
			return substr( $slot['start'], 11, 5 );
		},
		$special_times['slots']
	);

	if ( array( '13:00', '13:15', '13:30', '13:45', '14:00' ) !== $special_starts ) {
		$fail( 'available exception returned unexpected starts: ' . implode( ',', $special_starts ) );
	}

	do_action( 'rest_api_init' );
	$routes = rest_get_server()->get_routes();
	if ( ! isset( $routes['/yo-booking/v1/availability/dates'] ) || ! isset( $routes['/yo-booking/v1/availability/times'] ) ) {
		$fail( 'REST availability routes are not registered' );
	}
} catch ( Throwable $exception ) {
	$error = $exception->getMessage();
} finally {
	global $wpdb;

	if ( $appointment_id ) {
		$wpdb->delete( YoBooking\Database\Migrator::table_name( 'appointments' ), array( 'id' => $appointment_id ), array( '%d' ) );
	}

	if ( $staff_id ) {
		$exception_repository->delete_for_owner( 'staff', $staff_id );
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

echo "phase3_availability_smoke=pass\n";
echo 'business_date=' . $business_date . "\n";
echo 'staff_date=' . $staff_date . "\n";
echo 'weekend_date=' . $weekend_date . "\n";
