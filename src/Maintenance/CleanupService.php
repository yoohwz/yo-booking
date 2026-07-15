<?php
/**
 * Scheduled data retention cleanup.
 *
 * @package YoBooking
 */

namespace YoBooking\Maintenance;

use YoBooking\Database\Migrator;
use YoBooking\Settings\Repository as SettingsRepository;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Deletes expired operational logs while preserving booking records.
 */
final class CleanupService {
	const HOOK = 'yo_booking_daily_cleanup';

	/** @return void */
	public function boot() {
		add_action( 'init', array( $this, 'ensure_schedule' ) );
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/** @return void */
	public function ensure_schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/** @return array */
	public function run() {
		global $wpdb;
		$settings = ( new SettingsRepository() )->all();
		$policies = $this->policies( $settings );
		$deleted  = array();

		foreach ( $policies as $key => $policy ) {
			$deleted[ $key ] = 0;
			if ( $policy['days'] <= 0 ) {
				continue;
			}
			$table  = Migrator::table_name( $policy['table'] );
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $policy['days'] * DAY_IN_SECONDS ) );
			$sql    = "DELETE FROM {$table} WHERE {$policy['date_column']} < %s";
			if ( $policy['where'] ) {
				$sql .= ' AND ' . $policy['where'];
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->query( $wpdb->prepare( $sql, $cutoff ) );
			$deleted[ $key ] = false === $result ? 0 : (int) $result;
		}

		update_option( 'yo_booking_last_cleanup', array( 'ran_at' => current_time( 'mysql', true ), 'deleted' => $deleted ), false );
		do_action( 'yo_booking_cleanup_completed', $deleted );

		return $deleted;
	}

	/** @return array */
	public function counts() {
		global $wpdb;
		$settings = ( new SettingsRepository() )->all();
		$counts   = array();
		foreach ( $this->policies( $settings ) as $key => $policy ) {
			$counts[ $key ] = 0;
			if ( $policy['days'] <= 0 ) continue;
			$table  = Migrator::table_name( $policy['table'] );
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $policy['days'] * DAY_IN_SECONDS ) );
			$sql    = "SELECT COUNT(*) FROM {$table} WHERE {$policy['date_column']} < %s";
			if ( $policy['where'] ) $sql .= ' AND ' . $policy['where'];
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$counts[ $key ] = (int) $wpdb->get_var( $wpdb->prepare( $sql, $cutoff ) );
		}
		return $counts;
	}

	/** @param array $settings Settings. @return array */
	private function policies( array $settings ) {
		return array(
			'notification_logs' => array( 'table' => 'notification_logs', 'date_column' => 'created_at', 'days' => absint( $settings['advanced']['notification_log_retention_days'] ), 'where' => "status IN ('sent','failed','skipped')" ),
			'webhook_deliveries' => array( 'table' => 'webhook_deliveries', 'date_column' => 'created_at', 'days' => absint( $settings['advanced']['webhook_delivery_retention_days'] ), 'where' => "status IN ('delivered','failed')" ),
			'audit_logs' => array( 'table' => 'audit_logs', 'date_column' => 'created_at', 'days' => absint( $settings['advanced']['audit_log_retention_days'] ), 'where' => '' ),
		);
	}
}
