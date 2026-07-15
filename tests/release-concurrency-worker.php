<?php
/** One worker in the multi-process booking/payment release gate. */

use YoBooking\Database\Migrator;
use YoBooking\Repositories\AppointmentRepository;
use YoBooking\Repositories\PaymentRepository;

defined( 'ABSPATH' ) || exit;
defined( 'YO_BOOKING_RUNNING_TESTS' ) || define( 'YO_BOOKING_RUNNING_TESTS', true );

$fixture_path = '/private/tmp/yo-booking-release-concurrency.json';
$mode         = sanitize_key( getenv( 'YO_BOOKING_CONCURRENCY_MODE' ) );
$worker       = sanitize_key( getenv( 'YO_BOOKING_CONCURRENCY_WORKER' ) );
$fixture      = json_decode( (string) file_get_contents( $fixture_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

if ( ! is_array( $fixture ) ) {
	WP_CLI::error( 'Concurrency fixture is missing.' );
}

if ( 'booking' === $mode ) {
	$result = ( new AppointmentRepository() )->save(
		array(
			'service_id'       => $fixture['service_id'],
			'staff_id'         => $fixture['staff_id'],
			'customer_name'    => 'Release Worker ' . $worker,
			'customer_email'   => 'release-worker-' . $worker . '-' . $fixture['suffix'] . '@example.test',
			'date'             => $fixture['date'],
			'start_time'       => $fixture['start_time'],
			'duration_minutes' => 60,
			'timezone'         => $fixture['timezone'],
			'status'           => 'confirmed',
			'source'           => 'release_gate',
		)
	);
	echo is_wp_error( $result ) ? 'BOOKING_ERROR:' . $result->get_error_code() . "\n" : 'BOOKING_OK:' . absint( $result ) . "\n";
	return;
}

if ( 'payment' === $mode ) {
	global $wpdb;
	$table = Migrator::table_name( 'appointments' );
	$appointment_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE service_id = %d AND staff_id = %d AND source = 'release_gate' ORDER BY id DESC LIMIT 1",
			$fixture['service_id'],
			$fixture['staff_id']
		)
	);
	if ( ! $appointment_id ) {
		WP_CLI::error( 'The booking winner was not found.' );
	}
	$result = ( new PaymentRepository() )->create(
		array(
			'appointment_id' => $appointment_id,
			'provider'       => 'release_test',
			'transaction_id' => $fixture['transaction_id'],
			'kind'           => 'payment',
			'amount'         => '25.00',
			'currency'       => 'USD',
			'status'         => 'paid',
			'idempotency_key' => $fixture['idempotency_key'],
			'method_title'   => 'Release test payment',
		)
	);
	echo is_wp_error( $result ) ? 'PAYMENT_ERROR:' . $result->get_error_code() . "\n" : 'PAYMENT_OK:' . absint( $result ) . "\n";
	return;
}

WP_CLI::error( 'Unknown concurrency worker mode.' );
