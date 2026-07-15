<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package YoBooking
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall removes only plugin-managed tables and options.

$yo_booking_settings    = get_option( 'yo_booking_settings', array() );
$yo_booking_remove_data = is_array( $yo_booking_settings ) && ! empty( $yo_booking_settings['advanced']['remove_data_on_uninstall'] );

if ( ! $yo_booking_remove_data ) {
	return;
}

defined( 'YO_BOOKING_PATH' ) || define( 'YO_BOOKING_PATH', plugin_dir_path( __FILE__ ) );

require_once YO_BOOKING_PATH . 'src/Autoloader.php';

YoBooking\Autoloader::register();
YoBooking\Installer\RoleManager::uninstall();

global $wpdb;

foreach ( YoBooking\Database\Migrator::managed_tables() as $yo_booking_table_name ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$yo_booking_table_name}" );
}

$yo_booking_like = $wpdb->esc_like( 'yo_booking_' ) . '%';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$yo_booking_like
	)
);
