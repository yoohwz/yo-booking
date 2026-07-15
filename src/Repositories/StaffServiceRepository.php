<?php
/**
 * Staff service repository.
 *
 * @package YoBooking
 */

namespace YoBooking\Repositories;

use YoBooking\Database\Migrator;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Stores staff-service assignments.
 */
final class StaffServiceRepository extends BaseRepository {
	/**
	 * Logical table suffix.
	 *
	 * @var string
	 */
	protected $table_suffix = 'staff_services';

	/**
	 * Return rows assigned to a staff member.
	 *
	 * @param int $staff_id Staff ID.
	 * @return array
	 */
	public function for_staff( $staff_id ) {
		global $wpdb;

		$staff_id = absint( $staff_id );
		$table    = $this->table();

		if ( ! $staff_id ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE staff_id = %d", $staff_id ) );
	}

	/**
	 * Return assigned service IDs for a staff member.
	 *
	 * @param int $staff_id Staff ID.
	 * @return array
	 */
	public function service_ids_for_staff( $staff_id ) {
		$rows = $this->for_staff( $staff_id );

		return array_map(
			static function ( $row ) {
				return (int) $row->service_id;
			},
			$rows
		);
	}

	/**
	 * Return active staff IDs assigned to a service.
	 *
	 * @param int $service_id Service ID.
	 * @return array
	 */
	public function staff_ids_for_service( $service_id ) {
		global $wpdb;

		$service_id  = absint( $service_id );
		$table       = $this->table();
		$staff_table = Migrator::table_name( 'staff' );

		if ( ! $service_id ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ss.staff_id
				FROM {$table} ss
				INNER JOIN {$staff_table} s ON s.id = ss.staff_id
				WHERE ss.service_id = %d
					AND ss.enabled = 1
					AND s.status = 'active'
				ORDER BY s.sort_order ASC, s.name ASC",
				$service_id
			)
		);

		return array_map( 'absint', $ids );
	}

	/**
	 * Count assigned services for all staff members.
	 *
	 * @return array
	 */
	public function counts_by_staff() {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results( "SELECT staff_id, COUNT(*) AS total FROM {$table} WHERE enabled = 1 GROUP BY staff_id" );
		$map  = array();

		foreach ( $rows as $row ) {
			$map[ (int) $row->staff_id ] = (int) $row->total;
		}

		return $map;
	}

	/**
	 * Replace service assignments for a staff member.
	 *
	 * @param int   $staff_id Staff ID.
	 * @param array $service_ids Service IDs.
	 * @return void
	 */
	public function sync_for_staff( $staff_id, array $service_ids ) {
		global $wpdb;

		$staff_id    = absint( $staff_id );
		$service_ids = array_values( array_unique( array_filter( array_map( 'absint', $service_ids ) ) ) );

		if ( ! $staff_id ) {
			return;
		}

		$wpdb->delete( $this->table(), array( 'staff_id' => $staff_id ), array( '%d' ) );

		if ( empty( $service_ids ) ) {
			return;
		}

		$services_table = Migrator::table_name( 'services' );
		$now            = $this->now();

		foreach ( $service_ids as $service_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$services_table} WHERE id = %d", $service_id ) );

			if ( ! $exists ) {
				continue;
			}

			$wpdb->insert(
				$this->table(),
				array(
					'staff_id'          => $staff_id,
					'service_id'        => $service_id,
					'duration_minutes'  => null,
					'price'             => null,
					'enabled'           => 1,
					'created_at'        => $now,
					'updated_at'        => $now,
				),
				array( '%d', '%d', '%d', '%s', '%d', '%s', '%s' )
			);
		}
	}
}
