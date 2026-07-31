<?php
/**
 * Isolated release gate for schema upgrades and streaming backup restore.
 *
 * Run with: wp eval-file tests/release-clone-gate.php
 *
 * @package YoBooking
 */

use YoBooking\Database\Migrator;
use YoBooking\Maintenance\BackupService;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

global $wpdb;

$source_prefix = $wpdb->prefix;
$gate_prefix   = 'yb_release_gate_';
$source_tables = Migrator::managed_tables();
$gate_tables   = array();
$archive_path  = '';

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$drop_gate_tables = static function () use ( $wpdb, $gate_prefix ) {
	$like   = $wpdb->esc_like( $gate_prefix ) . '%';
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
	foreach ( $tables as $table ) {
		if ( 0 === strpos( $table, $gate_prefix ) ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		}
	}
};

try {
	$drop_gate_tables();

	$source_options = $source_prefix . 'options';
	$gate_options   = $gate_prefix . 'options';
	$wpdb->query( "CREATE TABLE `{$gate_options}` LIKE `{$source_options}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->query( "INSERT INTO `{$gate_options}` SELECT * FROM `{$source_options}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery

	foreach ( $source_tables as $source_table ) {
		$suffix     = substr( $source_table, strlen( $source_prefix ) );
		$gate_table = $gate_prefix . $suffix;
		$gate_tables[] = $gate_table;
		$wpdb->query( "CREATE TABLE `{$gate_table}` LIKE `{$source_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( "INSERT INTO `{$gate_table}` SELECT * FROM `{$source_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	$wpdb->set_prefix( $gate_prefix );
	wp_cache_flush();

	$now       = gmdate( 'Y-m-d H:i:s' );
	$customers = Migrator::table_name( 'customers' );
	$services  = Migrator::table_name( 'services' );
	$staff     = Migrator::table_name( 'staff' );
	$bookings  = Migrator::table_name( 'appointments' );

	$wpdb->insert( $customers, array( 'name' => 'Release Gate Customer', 'email' => 'release-gate@example.com', 'created_at' => $now, 'updated_at' => $now ) );
	$customer_id = (int) $wpdb->insert_id;
	$wpdb->insert( $services, array( 'name' => 'Release Gate Service', 'slug' => 'release-gate-service', 'duration_minutes' => 60, 'price' => '125.0000', 'currency' => 'USD', 'created_at' => $now, 'updated_at' => $now ) );
	$service_id = (int) $wpdb->insert_id;
	$wpdb->insert( $staff, array( 'name' => 'Release Gate Staff', 'slug' => 'release-gate-staff', 'email' => 'release-gate-staff@example.com', 'created_at' => $now, 'updated_at' => $now ) );
	$staff_id = (int) $wpdb->insert_id;
	$wpdb->insert(
		$bookings,
		array(
			'uuid'            => wp_generate_uuid4(),
			'customer_id'     => $customer_id,
			'service_id'      => $service_id,
			'staff_id'        => $staff_id,
			'start_at'        => '2030-01-10 10:00:00',
			'end_at'          => '2030-01-10 11:00:00',
			'timezone'        => 'UTC',
			'status'          => 'confirmed',
			'total_amount'    => '125.0000',
			'currency'        => 'USD',
			'payment_status'  => 'pending',
			'created_at'      => $now,
			'updated_at'      => $now,
		)
	);
	$fixture_id = (int) $wpdb->insert_id;
	$assert( $fixture_id > 0, 'Could not create an upgrade fixture in the cloned tables.' );

	$counts_before = array();
	foreach ( Migrator::managed_tables() as $table ) {
		$counts_before[ $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	$payments = Migrator::table_name( 'payments' );
	$logs     = Migrator::table_name( 'notification_logs' );
	$wpdb->query( "ALTER TABLE `{$bookings}` DROP INDEX `staff_schedule`, DROP INDEX `service_schedule`, DROP INDEX `customer_schedule`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->query( "ALTER TABLE `{$customers}` DROP COLUMN `phone_country`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->query( "ALTER TABLE `{$staff}` DROP COLUMN `phone_country`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->query( "ALTER TABLE `{$bookings}` DROP COLUMN `customer_name_snapshot`, DROP COLUMN `customer_email_snapshot`, DROP COLUMN `customer_phone_snapshot`, DROP COLUMN `customer_phone_country_snapshot`, DROP COLUMN `service_name_snapshot`, DROP COLUMN `staff_name_snapshot`, DROP COLUMN `action_token_version`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->query( "ALTER TABLE `{$payments}` DROP INDEX `provider_transaction_key`, DROP COLUMN `provider_transaction_key`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->query( "ALTER TABLE `{$logs}` DROP INDEX `occurrence_key`, DROP COLUMN `occurrence_key`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	update_option( 'yo_booking_schema_version', '2026071306', false );
	delete_option( 'yo_booking_migration_lock' );

	( new Migrator() )->maybe_upgrade();
	$assert( Migrator::SCHEMA_VERSION === get_option( 'yo_booking_schema_version' ), 'Schema version was not upgraded.' );

	$columns = $wpdb->get_col( "DESCRIBE `{$bookings}`", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	foreach ( array( 'customer_name_snapshot', 'customer_email_snapshot', 'customer_phone_snapshot', 'customer_phone_country_snapshot', 'service_name_snapshot', 'staff_name_snapshot', 'action_token_version' ) as $column ) {
		$assert( in_array( $column, $columns, true ), "Missing migrated appointment column: {$column}." );
	}
	$assert( in_array( 'phone_country', $wpdb->get_col( "DESCRIBE `{$customers}`", 0 ), true ), 'Missing migrated customer phone_country column.' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$assert( in_array( 'phone_country', $wpdb->get_col( "DESCRIBE `{$staff}`", 0 ), true ), 'Missing migrated staff phone_country column.' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$indexes = $wpdb->get_col( "SHOW INDEX FROM `{$bookings}`", 2 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	foreach ( array( 'staff_schedule', 'service_schedule', 'customer_schedule' ) as $index ) {
		$assert( in_array( $index, $indexes, true ), "Missing migrated appointment index: {$index}." );
	}

	$fixture = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$bookings}` WHERE id = %d", $fixture_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$assert( 'Release Gate Customer' === $fixture->customer_name_snapshot, 'Customer snapshot was not backfilled.' );
	$assert( 'Release Gate Service' === $fixture->service_name_snapshot, 'Service snapshot was not backfilled.' );
	$assert( 'Release Gate Staff' === $fixture->staff_name_snapshot, 'Staff snapshot was not backfilled.' );

	$notification_rows = $wpdb->get_results( 'SELECT notification_key, subject, heading, body FROM `' . Migrator::table_name( 'notifications' ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	foreach ( $notification_rows as $notification ) {
		foreach ( array( 'subject', 'heading', 'body' ) as $field ) {
			$value = trim( (string) $notification->{$field} );
			$assert( '' !== $value && '0' !== $value, "Notification {$notification->notification_key} has an invalid {$field}." );
		}
	}

	foreach ( Migrator::managed_tables() as $table ) {
		$count_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$assert( $counts_before[ $table ] === $count_after, "Upgrade changed row count for {$table}." );
	}

	$backup       = new BackupService();
	$archive_path = $backup->create_archive_file( 'ReleaseGate-Backup-2026!' );
	$assert( ! is_wp_error( $archive_path ) && is_readable( $archive_path ), 'Streaming backup creation failed.' );
	$header = json_decode( (string) file( $archive_path )[0], true );
	$assert( 2 === (int) ( $header['version'] ?? 0 ), 'Backup did not use streaming format v2.' );

	$wpdb->update( $services, array( 'name' => 'Corrupted Gate Value' ), array( 'id' => $service_id ) );
	$wpdb->delete( $bookings, array( 'id' => $fixture_id ) );
	$restore = $backup->restore_archive_file( $archive_path, 'ReleaseGate-Backup-2026!' );
	$assert( ! is_wp_error( $restore ), is_wp_error( $restore ) ? $restore->get_error_message() : 'Streaming restore failed.' );
	$assert( 'Release Gate Service' === $wpdb->get_var( $wpdb->prepare( "SELECT name FROM `{$services}` WHERE id = %d", $service_id ) ), 'Restored service data does not match the backup.' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$bookings}` WHERE id = %d", $fixture_id ) ), 'Restored appointment is missing.' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery

	WP_CLI::success( 'Release clone gate passed: non-destructive upgrade and streaming backup v2 round-trip.' );
} catch ( Throwable $exception ) {
	WP_CLI::error( 'Release clone gate failed: ' . $exception->getMessage(), false );
	WP_CLI::halt( 1 );
} finally {
	if ( $archive_path && is_file( $archive_path ) ) {
		wp_delete_file( $archive_path );
	}
	$wpdb->set_prefix( $source_prefix );
	wp_cache_flush();
	$drop_gate_tables();
}
