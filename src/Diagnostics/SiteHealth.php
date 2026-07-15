<?php
/**
 * WordPress Site Health integration.
 *
 * @package YoBooking
 */

namespace YoBooking\Diagnostics;

use YoBooking\Database\Migrator;
use YoBooking\Notifications\NotificationService;
use YoBooking\Support\DateTimeFormatter;
use YoBooking\Maintenance\CleanupService;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Exposes booking infrastructure health to administrators.
 */
final class SiteHealth {
	/** @return void */
	public function boot() {
		add_filter( 'site_status_tests', array( $this, 'register_tests' ) );
		add_filter( 'debug_information', array( $this, 'debug_information' ) );
	}

	/** @param array $tests Existing tests. @return array */
	public function register_tests( $tests ) {
		$tests['direct']['yo_booking_database'] = array( 'label' => __( 'Yo Booking database', 'yo-booking' ), 'test' => array( $this, 'database_test' ) );
		$tests['direct']['yo_booking_cron']     = array( 'label' => __( 'Yo Booking reminder worker', 'yo-booking' ), 'test' => array( $this, 'cron_test' ) );
		$tests['direct']['yo_booking_cleanup']  = array( 'label' => __( 'Yo Booking retention cleanup', 'yo-booking' ), 'test' => array( $this, 'cleanup_test' ) );
		return $tests;
	}

	/** @return array */
	public function database_test() {
		global $wpdb;
		$missing = array();
		foreach ( Migrator::managed_tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				$missing[] = $table;
			}
		}

		if ( $missing ) {
			// translators: %d: number of missing Yo Booking database tables.
			return $this->result( 'critical', __( 'Yo Booking database tables are missing', 'yo-booking' ), sprintf( __( '%d required booking tables could not be found. Reactivate the plugin or restore the database schema.', 'yo-booking' ), count( $missing ) ), 'yo_booking_database' );
		}

		// translators: %d: number of managed Yo Booking database tables.
		return $this->result( 'good', __( 'Yo Booking database schema is complete', 'yo-booking' ), sprintf( __( 'All %d managed tables are available.', 'yo-booking' ), count( Migrator::managed_tables() ) ), 'yo_booking_database' );
	}

	/** @return array */
	public function cron_test() {
		$next = wp_next_scheduled( NotificationService::REMINDER_HOOK );
		if ( ! $next ) {
			return $this->result( 'recommended', __( 'Yo Booking reminder worker is not scheduled', 'yo-booking' ), __( 'Visit the site once or reactivate the plugin to schedule reminder processing.', 'yo-booking' ), 'yo_booking_cron' );
		}

		// translators: %s: formatted date and time of the next reminder check.
		return $this->result( 'good', __( 'Yo Booking reminder worker is scheduled', 'yo-booking' ), sprintf( __( 'The next reminder check is scheduled for %s.', 'yo-booking' ), DateTimeFormatter::timestamp( $next ) ), 'yo_booking_cron' );
	}

	/** @return array */
	public function cleanup_test() {
		$next = wp_next_scheduled( CleanupService::HOOK );
		if ( $next ) {
			// translators: %s: formatted date and time of the next retention cleanup.
			return $this->result( 'good', __( 'Yo Booking retention cleanup is scheduled', 'yo-booking' ), sprintf( __( 'The next cleanup is scheduled for %s.', 'yo-booking' ), DateTimeFormatter::timestamp( $next ) ), 'yo_booking_cleanup' );
		}

		return $this->result( 'recommended', __( 'Yo Booking retention cleanup is not scheduled', 'yo-booking' ), __( 'Visit the site once or reactivate the plugin to schedule daily cleanup.', 'yo-booking' ), 'yo_booking_cleanup' );
	}

	/** @param array $info Existing debug info. @return array */
	public function debug_information( $info ) {
		$info['yo-booking'] = array(
			'label'  => __( 'Yo Booking', 'yo-booking' ),
			'fields' => array(
				'plugin_version' => array( 'label' => __( 'Plugin version', 'yo-booking' ), 'value' => YO_BOOKING_VERSION ),
				'schema_version' => array( 'label' => __( 'Schema version', 'yo-booking' ), 'value' => get_option( 'yo_booking_schema_version', '' ) ),
				'managed_tables' => array( 'label' => __( 'Managed tables', 'yo-booking' ), 'value' => count( Migrator::managed_tables() ) ),
				'reminder_next'  => array( 'label' => __( 'Next reminder run', 'yo-booking' ), 'value' => wp_next_scheduled( NotificationService::REMINDER_HOOK ) ? DateTimeFormatter::timestamp( wp_next_scheduled( NotificationService::REMINDER_HOOK ) ) : __( 'Not scheduled', 'yo-booking' ) ),
				'cleanup_next'   => array( 'label' => __( 'Next retention cleanup', 'yo-booking' ), 'value' => wp_next_scheduled( CleanupService::HOOK ) ? DateTimeFormatter::timestamp( wp_next_scheduled( CleanupService::HOOK ) ) : __( 'Not scheduled', 'yo-booking' ) ),
			),
		);
		return $info;
	}

	/** @param string $status Status. @param string $label Label. @param string $description Description. @param string $test Test ID. @return array */
	private function result( $status, $label, $description, $test ) {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array( 'label' => __( 'Yo Booking', 'yo-booking' ), 'color' => 'blue' ),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '',
			'test'        => $test,
		);
	}
}
