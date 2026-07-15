<?php
/**
 * Phase 4 appointment lifecycle smoke test for WP-CLI.
 *
 * Run with:
 * wp eval-file wp-content/plugins/yo-booking/tests/phase4-appointment-smoke.php
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
$error                    = '';
$service_id               = 0;
$staff_id                 = 0;
$appointment_ids          = array();
$customer_ids             = array();

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

try {
	$date = $next_weekday();

	$service_id = $guard(
		$service_repository->save(
			array(
				'name'             => 'Appointment Smoke Service ' . $suffix,
				'duration_minutes' => 60,
				'price'            => '120.00',
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
				'name'   => 'Appointment Smoke Staff ' . $suffix,
				'email'  => 'appointment-staff-' . $suffix . '@example.test',
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

	$first_id = $guard(
		$appointment_repository->save(
			array(
				'service_id'       => $service_id,
				'staff_id'         => $staff_id,
				'customer_name'    => 'Appointment Smoke Customer ' . $suffix,
				'customer_email'   => 'appointment-customer-' . $suffix . '@example.test',
				'customer_phone'   => '+15550000001',
				'date'             => $date->format( 'Y-m-d' ),
				'start_time'       => '09:00',
				'duration_minutes' => 60,
				'timezone'         => $timezone_name,
				'status'           => 'confirmed',
				'internal_note'    => 'Initial note',
			)
		),
		'create first appointment'
	);
	$appointment_ids[] = $first_id;
	$first             = $appointment_repository->find( $first_id );
	$customer_ids[]    = (int) $first->customer_id;

	if ( 'confirmed' !== $first->status || (int) $first->staff_id !== $staff_id ) {
		$fail( 'first appointment persisted with unexpected status or staff' );
	}

	$duplicate = $appointment_repository->save(
		array(
			'service_id'       => $service_id,
			'staff_id'         => $staff_id,
			'customer_name'    => 'Appointment Smoke Duplicate ' . $suffix,
			'customer_email'   => 'appointment-duplicate-' . $suffix . '@example.test',
			'date'             => $date->format( 'Y-m-d' ),
			'start_time'       => '09:00',
			'duration_minutes' => 60,
			'timezone'         => $timezone_name,
			'status'           => 'pending',
		)
	);

	if ( ! is_wp_error( $duplicate ) || 'yo_booking_appointment_slot_unavailable' !== $duplicate->get_error_code() ) {
		$fail( 'double booking was not blocked' );
	}

	$appointment_repository->update_internal_note( $first_id, 'Updated internal smoke note' );
	if ( 'Updated internal smoke note' !== $appointment_repository->find( $first_id )->internal_note ) {
		$fail( 'internal note did not update' );
	}

	$status_result = $appointment_repository->update_status( $first_id, 'cancelled', 'Smoke cancellation' );
	if ( is_wp_error( $status_result ) ) {
		$fail( 'cancel first appointment: ' . $status_result->get_error_message() );
	}

	$second_id = $guard(
		$appointment_repository->save(
			array(
				'service_id'       => $service_id,
				'staff_id'         => $staff_id,
				'customer_name'    => 'Appointment Smoke Second ' . $suffix,
				'customer_email'   => 'appointment-second-' . $suffix . '@example.test',
				'date'             => $date->format( 'Y-m-d' ),
				'start_time'       => '09:00',
				'duration_minutes' => 60,
				'timezone'         => $timezone_name,
				'status'           => 'confirmed',
			)
		),
		'create second appointment after cancellation'
	);
	$appointment_ids[] = $second_id;
	$second            = $appointment_repository->find( $second_id );
	$customer_ids[]    = (int) $second->customer_id;

	$restore = $appointment_repository->update_status( $first_id, 'confirmed', '', true );
	if ( ! is_wp_error( $restore ) || 'yo_booking_appointment_slot_unavailable' !== $restore->get_error_code() ) {
		$fail( 'restoring cancelled appointment did not respect conflict check' );
	}

	$updated_second_id = $guard(
		$appointment_repository->save(
			array(
				'id'               => $second_id,
				'service_id'       => $service_id,
				'staff_id'         => $staff_id,
				'customer_id'      => (int) $second->customer_id,
				'customer_name'    => 'Appointment Smoke Second Updated ' . $suffix,
				'customer_email'   => 'appointment-second-' . $suffix . '@example.test',
				'date'             => $date->format( 'Y-m-d' ),
				'start_time'       => '10:00',
				'duration_minutes' => 60,
				'timezone'         => $timezone_name,
				'status'           => 'confirmed',
				'internal_note'    => 'Moved to 10:00',
			)
		),
		'edit second appointment'
	);

	if ( $updated_second_id !== $second_id ) {
		$fail( 'edit returned an unexpected appointment ID' );
	}

	$appointment_repository->update_status( $second_id, 'completed' );
	if ( 'completed' !== $appointment_repository->find( $second_id )->status ) {
		$fail( 'completed status did not persist' );
	}

	$rows = $appointment_repository->all(
		array(
			'staff_id' => $staff_id,
			'limit'    => 10,
		)
	);

	if ( count( $rows ) < 2 || empty( $rows[0]->service_name ) || empty( $rows[0]->customer_name ) ) {
		$fail( 'appointment listing did not include joined details' );
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

echo "phase4_appointment_smoke=pass\n";
echo 'date=' . $date->format( 'Y-m-d' ) . "\n";
echo 'first_id=' . $first_id . "\n";
echo 'second_id=' . $second_id . "\n";
