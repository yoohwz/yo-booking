<?php
/** Verify and clean the multi-process booking/payment release gate. */

use YoBooking\Database\Migrator;

defined( 'ABSPATH' ) || exit;

global $wpdb;
$fixture_path = '/private/tmp/yo-booking-release-concurrency.json';
$fixture      = json_decode( (string) file_get_contents( $fixture_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
if ( ! is_array( $fixture ) ) {
	WP_CLI::error( 'Concurrency fixture is missing.' );
}

$appointments = Migrator::table_name( 'appointments' );
$payments     = Migrator::table_name( 'payments' );
$customers    = Migrator::table_name( 'customers' );
$logs         = Migrator::table_name( 'notification_logs' );
$meta         = Migrator::table_name( 'appointment_meta' );
$rules        = Migrator::table_name( 'availability_rules' );
$links        = Migrator::table_name( 'staff_services' );
$staff        = Migrator::table_name( 'staff' );
$services     = Migrator::table_name( 'services' );
$error        = '';

try {
	$appointment_ids = array_map(
		'absint',
		$wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$appointments} WHERE service_id = %d AND staff_id = %d AND source = 'release_gate'",
				$fixture['service_id'],
				$fixture['staff_id']
			)
		)
	);
	if ( 1 !== count( $appointment_ids ) ) {
		throw new RuntimeException( 'Expected exactly one booking winner; found ' . count( $appointment_ids ) . '.' );
	}

	$payment_rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$payments} WHERE idempotency_key = %s", $fixture['idempotency_key'] ) );
	if ( 1 !== count( $payment_rows ) ) {
		throw new RuntimeException( 'Expected exactly one idempotent payment; found ' . count( $payment_rows ) . '.' );
	}
	if ( hash( 'sha256', 'release_test|' . $fixture['transaction_id'] ) !== $payment_rows[0]->provider_transaction_key ) {
		throw new RuntimeException( 'Provider transaction dedupe key is incorrect.' );
	}

	WP_CLI::success( 'Concurrency gate passed: one booking winner and one idempotent payment.' );
} catch ( Throwable $exception ) {
	$error = $exception->getMessage();
} finally {
	$appointment_ids = isset( $appointment_ids ) ? $appointment_ids : array();
	foreach ( $appointment_ids as $appointment_id ) {
		$wpdb->delete( $payments, array( 'appointment_id' => $appointment_id ) );
		$wpdb->delete( $logs, array( 'appointment_id' => $appointment_id ) );
		$wpdb->delete( $meta, array( 'appointment_id' => $appointment_id ) );
		$wpdb->delete( $appointments, array( 'id' => $appointment_id ) );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$customers} WHERE email LIKE %s", '%-' . $fixture['suffix'] . '@example.test' ) );
	$wpdb->delete( $rules, array( 'owner_type' => 'staff', 'owner_id' => $fixture['staff_id'] ) );
	$wpdb->delete( $links, array( 'staff_id' => $fixture['staff_id'] ) );
	$wpdb->delete( $staff, array( 'id' => $fixture['staff_id'] ) );
	$wpdb->delete( $services, array( 'id' => $fixture['service_id'] ) );
	wp_delete_file( $fixture_path );
}

if ( $error ) {
	WP_CLI::error( 'Concurrency gate failed: ' . $error );
}
