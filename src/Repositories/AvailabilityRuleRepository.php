<?php
/**
 * Availability rule repository.
 *
 * @package YoBooking
 */

namespace YoBooking\Repositories;

use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Stores weekly availability rules.
 */
final class AvailabilityRuleRepository extends BaseRepository {
	/**
	 * Logical table suffix.
	 *
	 * @var string
	 */
	protected $table_suffix = 'availability_rules';

	/**
	 * List rules.
	 *
	 * @param string $owner_type Optional owner type.
	 * @param int    $owner_id Optional owner ID.
	 * @return array
	 */
	public function all( $owner_type = '', $owner_id = null ) {
		global $wpdb;

		$table = $this->table();

		if ( '' !== $owner_type && null !== $owner_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE owner_type = %s AND owner_id = %d ORDER BY weekday ASC, start_time ASC",
					$this->owner_type( $owner_type ),
					absint( $owner_id )
				)
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY owner_type ASC, owner_id ASC, weekday ASC, start_time ASC" );
	}

	/**
	 * Return rules for one owner and weekday.
	 *
	 * @param string $owner_type Owner type.
	 * @param int    $owner_id Owner ID.
	 * @param int    $weekday Weekday, Sunday 0 through Saturday 6.
	 * @param string $date Date in Y-m-d format.
	 * @return array
	 */
	public function for_owner_on_weekday( $owner_type, $owner_id, $weekday, $date ) {
		global $wpdb;

		$table      = $this->table();
		$owner_type = $this->owner_type( $owner_type );
		$owner_id   = absint( $owner_id );
		$weekday    = absint( $weekday );
		$date       = $this->date( $date );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE owner_type = %s
					AND owner_id = %d
					AND weekday = %d
					AND status = 'active'
					AND (valid_from IS NULL OR valid_from <= %s)
					AND (valid_to IS NULL OR valid_to >= %s)
				ORDER BY start_time ASC",
				$owner_type,
				$owner_id,
				$weekday,
				$date,
				$date
			)
		);
	}

	/**
	 * Return true when an owner has any active rule for a weekday.
	 *
	 * @param string $owner_type Owner type.
	 * @param int    $owner_id Owner ID.
	 * @param int    $weekday Weekday.
	 * @param string $date Date in Y-m-d format.
	 * @return bool
	 */
	public function owner_has_rules_for_weekday( $owner_type, $owner_id, $weekday, $date ) {
		return ! empty( $this->for_owner_on_weekday( $owner_type, $owner_id, $weekday, $date ) );
	}

	/** Load active global and staff rules for a complete availability request. */
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
		$params[] = sanitize_text_field( $to );
		$params[] = sanitize_text_field( $from );
		$sql = "SELECT * FROM {$table} WHERE status = 'active' AND ({$scope})
			AND (valid_from IS NULL OR valid_from <= %s) AND (valid_to IS NULL OR valid_to >= %s)
			ORDER BY owner_type, owner_id, weekday, start_time";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Save one availability rule.
	 *
	 * @param array $data Raw rule data.
	 * @return int|WP_Error
	 */
	public function save( array $data ) {
		global $wpdb;

		$id         = isset( $data['id'] ) ? absint( $data['id'] ) : 0;
		$start_time = isset( $data['start_time'] ) ? $this->time( $data['start_time'] ) : '';
		$end_time   = isset( $data['end_time'] ) ? $this->time( $data['end_time'] ) : '';

		if ( '' === $start_time || '' === $end_time || $start_time >= $end_time ) {
			return new WP_Error( 'yo_booking_availability_time_invalid', __( 'Availability start and end times are invalid.', 'yo-booking' ) );
		}

		$now    = $this->now();
		$record = array(
			'owner_type'            => $this->owner_type( isset( $data['owner_type'] ) ? $data['owner_type'] : 'global' ),
			'owner_id'              => isset( $data['owner_id'] ) ? absint( $data['owner_id'] ) : 0,
			'weekday'               => isset( $data['weekday'] ) ? max( 0, min( 6, absint( $data['weekday'] ) ) ) : 1,
			'start_time'            => $start_time,
			'end_time'              => $end_time,
			'slot_interval_minutes' => isset( $data['slot_interval_minutes'] ) ? max( 5, min( 240, absint( $data['slot_interval_minutes'] ) ) ) : 15,
			'timezone'              => isset( $data['timezone'] ) ? sanitize_text_field( $data['timezone'] ) : '',
			'valid_from'            => ! empty( $data['valid_from'] ) ? $this->date( $data['valid_from'] ) : null,
			'valid_to'              => ! empty( $data['valid_to'] ) ? $this->date( $data['valid_to'] ) : null,
			'status'                => $this->status( isset( $data['status'] ) ? $data['status'] : 'active' ),
			'updated_at'            => $now,
		);

		$formats = array( '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' );

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
	 * Replace all weekly rules for an owner.
	 *
	 * @param string $owner_type Owner type.
	 * @param int    $owner_id Owner ID.
	 * @param array  $rules Rules keyed by weekday.
	 * @param string $timezone Timezone.
	 * @return bool|WP_Error
	 */
	public function replace_weekly( $owner_type, $owner_id, array $rules, $timezone = '' ) {
		$records = array();

		foreach ( $rules as $weekday => $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}

			$ranges = isset( $rule['ranges'] ) && is_array( $rule['ranges'] ) ? $rule['ranges'] : array( $rule );
			$day_records = array();

			foreach ( $ranges as $range ) {
				$start_time = isset( $range['start_time'] ) ? $this->time( $range['start_time'] ) : '';
				$end_time   = isset( $range['end_time'] ) ? $this->time( $range['end_time'] ) : '';

				if ( '' === $start_time || '' === $end_time || $start_time >= $end_time ) {
					return new WP_Error( 'yo_booking_availability_time_invalid', __( 'Availability start and end times are invalid.', 'yo-booking' ) );
				}

				$day_records[] = array(
					'owner_type'            => $owner_type,
					'owner_id'              => $owner_id,
					'weekday'               => $weekday,
					'start_time'            => $start_time,
					'end_time'              => $end_time,
					'slot_interval_minutes' => isset( $range['slot_interval_minutes'] ) ? $range['slot_interval_minutes'] : ( isset( $rule['slot_interval_minutes'] ) ? $rule['slot_interval_minutes'] : 15 ),
					'timezone'              => $timezone,
					'status'                => 'active',
				);
			}

			usort( $day_records, static function ( $a, $b ) { return strcmp( $a['start_time'], $b['start_time'] ); } );
			$previous_end = '';

			foreach ( $day_records as $record ) {
				if ( $previous_end && $record['start_time'] < $previous_end ) {
					return new WP_Error( 'yo_booking_availability_overlap', __( 'Availability ranges on the same day cannot overlap.', 'yo-booking' ) );
				}
				$previous_end = $record['end_time'];
				$records[]    = $record;
			}
		}

		$this->delete_for_owner( $owner_type, $owner_id );

		foreach ( $records as $record ) {
			$result = $this->save( $record );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Delete all rules for an owner.
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

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : gmdate( 'Y-m-d' );
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
