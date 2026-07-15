<?php
/**
 * Remove only records created by the Yo Booking operations demo seeder.
 *
 * Run with: wp eval-file wp-content/plugins/yo-booking/tools/remove-demo-data.php
 *
 * @package YoBooking
 */

defined( 'ABSPATH' ) || exit;

use YoBooking\Database\Migrator;

const YO_BOOKING_DEMO_OPTION = 'yo_booking_demo_dataset_v1';

global $wpdb;

$dataset = get_option( YO_BOOKING_DEMO_OPTION, array() );
$ids     = isset( $dataset['ids'] ) && is_array( $dataset['ids'] ) ? $dataset['ids'] : array();

if ( empty( $ids ) ) {
	echo "yo_booking_demo_remove=skipped\nreason=demo dataset marker was not found\n";
	exit( 0 );
}

$delete_ids = static function ( $table, $column, array $record_ids ) use ( $wpdb ) {
	$record_ids = array_values( array_unique( array_filter( array_map( 'absint', $record_ids ) ) ) );
	if ( empty( $record_ids ) ) {
		return 0;
	}
	$placeholders = implode( ',', array_fill( 0, count( $record_ids ), '%d' ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$column} IN ({$placeholders})", $record_ids ) );
};

$demo_appointment_ids = array_values( array_filter( array_map( 'absint', isset( $ids['appointments'] ) ? $ids['appointments'] : array() ) ) );
$demo_service_ids     = array_values( array_filter( array_map( 'absint', isset( $ids['services'] ) ? $ids['services'] : array() ) ) );
$demo_staff_ids       = array_values( array_filter( array_map( 'absint', isset( $ids['staff'] ) ? $ids['staff'] : array() ) ) );

if ( $demo_service_ids || $demo_staff_ids ) {
	$conditions = array();
	$values     = array();
	if ( $demo_service_ids ) {
		$conditions[] = 'service_id IN (' . implode( ',', array_fill( 0, count( $demo_service_ids ), '%d' ) ) . ')';
		$values       = array_merge( $values, $demo_service_ids );
	}
	if ( $demo_staff_ids ) {
		$conditions[] = 'staff_id IN (' . implode( ',', array_fill( 0, count( $demo_staff_ids ), '%d' ) ) . ')';
		$values       = array_merge( $values, $demo_staff_ids );
	}
	$sql = 'SELECT COUNT(*) FROM ' . Migrator::table_name( 'appointments' ) . ' WHERE (' . implode( ' OR ', $conditions ) . ')';
	if ( $demo_appointment_ids ) {
		$sql    .= ' AND id NOT IN (' . implode( ',', array_fill( 0, count( $demo_appointment_ids ), '%d' ) ) . ')';
		$values = array_merge( $values, $demo_appointment_ids );
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$dependent_count = (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
	if ( $dependent_count > 0 ) {
		echo "FAIL: Demo services or staff are used by {$dependent_count} booking(s) created after seeding. Reassign or remove those bookings first.\n";
		exit( 1 );
	}
}

$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
try {
	$delete_ids( Migrator::table_name( 'notification_logs' ), 'id', isset( $ids['notification_logs'] ) ? $ids['notification_logs'] : array() );
	$delete_ids( Migrator::table_name( 'payments' ), 'id', isset( $ids['payments'] ) ? $ids['payments'] : array() );
	$delete_ids( Migrator::table_name( 'audit_logs' ), 'id', isset( $ids['audit_logs'] ) ? $ids['audit_logs'] : array() );
	$delete_ids( Migrator::table_name( 'appointment_meta' ), 'appointment_id', isset( $ids['appointments'] ) ? $ids['appointments'] : array() );
	$delete_ids( Migrator::table_name( 'appointments' ), 'id', isset( $ids['appointments'] ) ? $ids['appointments'] : array() );
	$delete_ids( Migrator::table_name( 'customers' ), 'id', isset( $ids['customers'] ) ? $ids['customers'] : array() );
	$delete_ids( Migrator::table_name( 'availability_exceptions' ), 'id', isset( $ids['exceptions'] ) ? $ids['exceptions'] : array() );
	if ( $demo_staff_ids ) {
		$placeholders = implode( ',', array_fill( 0, count( $demo_staff_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM " . Migrator::table_name( 'availability_rules' ) . " WHERE owner_type = 'staff' AND owner_id IN ({$placeholders})", $demo_staff_ids ) );
	}
	$delete_ids( Migrator::table_name( 'staff_services' ), 'staff_id', isset( $ids['staff'] ) ? $ids['staff'] : array() );
	$delete_ids( Migrator::table_name( 'staff' ), 'id', isset( $ids['staff'] ) ? $ids['staff'] : array() );
	$delete_ids( Migrator::table_name( 'services' ), 'id', isset( $ids['services'] ) ? $ids['services'] : array() );
	$delete_ids( Migrator::table_name( 'service_categories' ), 'id', isset( $ids['categories'] ) ? $ids['categories'] : array() );
	$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
} catch ( Throwable $exception ) {
	$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	echo 'FAIL: ' . $exception->getMessage() . "\n";
	exit( 1 );
}

delete_option( YO_BOOKING_DEMO_OPTION );
echo "yo_booking_demo_remove=pass\n";
