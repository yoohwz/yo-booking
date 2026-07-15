<?php
/** Prepare isolated records for the multi-process release gate. */

use YoBooking\Repositories\AvailabilityRuleRepository;
use YoBooking\Repositories\ServiceRepository;
use YoBooking\Repositories\StaffRepository;
use YoBooking\Repositories\StaffServiceRepository;

defined( 'ABSPATH' ) || exit;
defined( 'YO_BOOKING_RUNNING_TESTS' ) || define( 'YO_BOOKING_RUNNING_TESTS', true );

$fixture_path = '/private/tmp/yo-booking-release-concurrency.json';
$admins       = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admins ) {
	WP_CLI::error( 'No administrator is available for the concurrency gate.' );
}
wp_set_current_user( (int) $admins[0]->ID );

$timezone = wp_timezone();
$date     = new DateTimeImmutable( '+14 days', $timezone );
while ( in_array( (int) $date->format( 'w' ), array( 0, 6 ), true ) ) {
	$date = $date->add( new DateInterval( 'P1D' ) );
}
$suffix = gmdate( 'YmdHis' ) . wp_rand( 1000, 9999 );

$service_id = ( new ServiceRepository() )->save(
	array(
		'name'             => 'Release Concurrency Service ' . $suffix,
		'duration_minutes' => 60,
		'price'            => '100.00',
		'currency'         => 'USD',
		'capacity'         => 1,
		'status'           => 'active',
	)
);
$staff_id = ( new StaffRepository() )->save(
	array(
		'name'   => 'Release Concurrency Staff ' . $suffix,
		'email'  => 'release-concurrency-staff-' . $suffix . '@example.test',
		'status' => 'active',
	)
);

if ( is_wp_error( $service_id ) || is_wp_error( $staff_id ) ) {
	WP_CLI::error( 'Could not create concurrency fixtures.' );
}

( new StaffServiceRepository() )->sync_for_staff( (int) $staff_id, array( (int) $service_id ) );
( new AvailabilityRuleRepository() )->replace_weekly(
	'staff',
	(int) $staff_id,
	array(
		(int) $date->format( 'w' ) => array(
			array(
				'enabled'               => 1,
				'start_time'            => '10:00',
				'end_time'              => '12:00',
				'slot_interval_minutes' => 60,
			),
		),
	),
	$timezone->getName()
);

$fixture = array(
	'suffix'          => $suffix,
	'service_id'      => (int) $service_id,
	'staff_id'        => (int) $staff_id,
	'date'            => $date->format( 'Y-m-d' ),
	'start_time'      => '10:00',
	'timezone'        => $timezone->getName(),
	'idempotency_key' => 'release-concurrency-payment-' . $suffix,
	'transaction_id'  => 'release-concurrency-txn-' . $suffix,
);

if ( false === file_put_contents( $fixture_path, wp_json_encode( $fixture ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	WP_CLI::error( 'Could not write the concurrency fixture file.' );
}

WP_CLI::success( 'Concurrency fixtures prepared.' );
