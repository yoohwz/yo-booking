<?php
/**
 * Availability exception repository.
 *
 * @package YoBooking
 */

namespace YoBooking\Repositories;

use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Stores one-off availability exceptions.
 */
final class AvailabilityExceptionRepository extends BaseRepository {
	/**
	 * Logical table suffix.
	 *
	 * @var string
	 */
	protected $table_suffix = 'availability_exceptions';

	/**
	 * List exceptions.
	 *
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public function upcoming( $limit = 100 ) {
		global $wpdb;

		$table = $this->table();
		$limit = max( 1, min( 500, absint( $limit ) ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE exception_date >= %s ORDER BY exception_date ASC, owner_type ASC, owner_id ASC, start_time ASC LIMIT %d",
				current_time( 'Y-m-d' ),
				$limit
			)
		);
	}

	/**
	 * Return exceptions for one owner and date.
	 *
	 * @param string $owner_type Owner type.
	 * @param int    $owner_id Owner ID.
	 * @param string $date Date in Y-m-d format.
	 * @return array
	 */
	public function for_owner_on_date( $owner_type, $owner_id, $date ) {
		global $wpdb;

		$table      = $this->table();
		$owner_type = $this->owner_type( $owner_type );
		$owner_id   = absint( $owner_id );
		$date       = $this->date( $date );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE owner_type = %s
					AND owner_id = %d
					AND exception_date = %s
				ORDER BY availability_type ASC, start_time ASC",
				$owner_type,
				$owner_id,
				$date
			)
		);
	}

	/** Load global and staff exceptions for a complete availability request. */
	public function for_context( array $staff_ids, $from, $to ) {
		global $wpdb;
		$table = $this->table();
		$staff_ids = array_values( array_filter( array_map( 'absint', $staff_ids ) ) );
		$scope = "(owner_type = 'global' AND owner_id = 0)";
		$params = array();
		if ( $staff_ids ) {
			$scope .= " OR (owner_type = 'staff' AND owner_id IN (" . implode( ',', array_fill( 0, count( $staff_ids ), '%d' ) ) . '))';
			$params = $staff_ids;
		}
		$params[] = sanitize_text_field( $from );
		$params[] = sanitize_text_field( $to );
		$sql = "SELECT * FROM {$table} WHERE ({$scope}) AND exception_date BETWEEN %s AND %s
			ORDER BY exception_date, owner_type, owner_id, availability_type, start_time";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Save an exception.
	 *
	 * @param array $data Raw exception data.
	 * @return int|WP_Error
	 */
	public function save( array $data ) {
		global $wpdb;

		$id                = isset( $data['id'] ) ? absint( $data['id'] ) : 0;
		$availability_type = $this->availability_type( isset( $data['availability_type'] ) ? $data['availability_type'] : 'blocked' );
		$start_time        = ! empty( $data['start_time'] ) ? $this->time( $data['start_time'] ) : null;
		$end_time          = ! empty( $data['end_time'] ) ? $this->time( $data['end_time'] ) : null;

		if ( ( null === $start_time xor null === $end_time ) || ( null !== $start_time && $start_time >= $end_time ) ) {
			return new WP_Error( 'yo_booking_exception_time_invalid', __( 'Exception start and end times are invalid.', 'yo-booking' ) );
		}

		if ( 'available' === $availability_type && ( null === $start_time || null === $end_time ) ) {
			return new WP_Error( 'yo_booking_exception_available_time_required', __( 'Available exceptions require a start and end time.', 'yo-booking' ) );
		}

		$now    = $this->now();
		$record = array(
			'owner_type'        => $this->owner_type( isset( $data['owner_type'] ) ? $data['owner_type'] : 'global' ),
			'owner_id'          => isset( $data['owner_id'] ) ? absint( $data['owner_id'] ) : 0,
			'exception_date'    => $this->date( isset( $data['exception_date'] ) ? $data['exception_date'] : '' ),
			'start_time'        => $start_time,
			'end_time'          => $end_time,
			'availability_type' => $availability_type,
			'reason'            => isset( $data['reason'] ) ? sanitize_text_field( $data['reason'] ) : '',
			'timezone'          => isset( $data['timezone'] ) ? sanitize_text_field( $data['timezone'] ) : '',
			'updated_at'        => $now,
		);

		$formats = array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( $id ) {
			$updated = $wpdb->update( $this->table(), $record, array( 'id' => $id ), $formats, array( '%d' ) );

			return false === $updated ? $this->database_error() : $id;
		}

		$record['created_at'] = $now;
		$formats[]           = '%s';

		$inserted = $wpdb->insert( $this->table(), $record, $formats );

		return false === $inserted ? $this->database_error() : (int) $wpdb->insert_id;
	}

	/**
	 * Delete all exceptions for an owner.
	 *
	 * @param string $owner_type Owner type.
	 * @param int    $owner_id Owner ID.
	 * @return void
	 */
	public function delete_for_owner( $owner_type, $owner_id ) {
		global $wpdb;

		$wpdb->delete(
			$this->table(),
			array(
				'owner_type' => $this->owner_type( $owner_type ),
				'owner_id'   => absint( $owner_id ),
			),
			array( '%s', '%d' )
		);
	}

	/**
	 * Normalize an owner type.
	 *
	 * @param string $owner_type Raw owner type.
	 * @return string
	 */
	private function owner_type( $owner_type ) {
		$owner_type = sanitize_key( $owner_type );

		return in_array( $owner_type, array( 'global', 'staff', 'service', 'location', 'resource' ), true ) ? $owner_type : 'global';
	}

	/**
	 * Normalize exception type.
	 *
	 * @param string $type Raw type.
	 * @return string
	 */
	private function availability_type( $type ) {
		$type = sanitize_key( $type );

		return in_array( $type, array( 'blocked', 'available' ), true ) ? $type : 'blocked';
	}

	/**
	 * Normalize a time value.
	 *
	 * @param string $time Raw time.
	 * @return string
	 */
	private function time( $time ) {
		$time = sanitize_text_field( $time );

		if ( preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			$time .= ':00';
		}

		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $time ) ? $time : '';
	}

	/**
	 * Normalize a date value.
	 *
	 * @param string $date Raw date.
	 * @return string
	 */
	private function date( $date ) {
		$date = sanitize_text_field( $date );

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : current_time( 'Y-m-d' );
	}

	/**
	 * Return a database error object.
	 *
	 * @return WP_Error
	 */
	private function database_error() {
		global $wpdb;

		return new WP_Error( 'yo_booking_database_error', $wpdb->last_error ? $wpdb->last_error : __( 'Database write failed.', 'yo-booking' ) );
	}
}
