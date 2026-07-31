<?php
/**
 * Staff repository.
 *
 * @package YoBooking
 */

namespace YoBooking\Repositories;

use YoBooking\Support\PhoneNumber;
use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Stores staff members.
 */
final class StaffRepository extends BaseRepository {
	/**
	 * Logical table suffix.
	 *
	 * @var string
	 */
	protected $table_suffix = 'staff';

	/**
	 * List staff.
	 *
	 * @return array
	 */
	public function all() {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC, name ASC" );
	}

	/**
	 * List active staff members by IDs.
	 *
	 * @param array $ids Staff IDs.
	 * @return array
	 */
	public function active_by_ids( array $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$table        = $this->table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder count matches the normalized ID array.
				"SELECT * FROM {$table} WHERE status = 'active' AND id IN ({$placeholders}) ORDER BY sort_order ASC, name ASC",
				$ids
			)
		);
	}

	/**
	 * Save a staff member.
	 *
	 * @param array $data Raw staff data.
	 * @return int|WP_Error
	 */
	public function save( array $data ) {
		global $wpdb;

		$id    = isset( $data['id'] ) ? absint( $data['id'] ) : 0;
		$name  = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		$email = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'yo_booking_staff_name_required', __( 'Staff name is required.', 'yo-booking' ) );
		}

		if ( '' !== $email && ! is_email( $email ) ) {
			return new WP_Error( 'yo_booking_staff_email_invalid', __( 'Staff email is invalid.', 'yo-booking' ) );
		}

		if ( ! empty( $data['phone'] ) && ! PhoneNumber::is_valid( $data['phone'] ) ) {
			return new WP_Error( 'yo_booking_staff_phone_invalid', __( 'Staff phone must be a valid international number.', 'yo-booking' ) );
		}

		$color = isset( $data['color'] ) ? sanitize_hex_color( $data['color'] ) : '';
		$now   = $this->now();

		$record = array(
			'user_id'    => isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0,
			'name'       => $name,
			'slug'       => $this->unique_slug( ! empty( $data['slug'] ) ? $data['slug'] : $name, $id, 'staff' ),
			'email'      => $email,
			'phone'      => isset( $data['phone'] ) ? PhoneNumber::normalize( $data['phone'] ) : '',
			'phone_country' => PhoneNumber::country( isset( $data['phone_country'] ) ? $data['phone_country'] : '' ),
			'bio'        => isset( $data['bio'] ) ? wp_kses_post( $data['bio'] ) : '',
			'avatar_id'  => isset( $data['avatar_id'] ) ? absint( $data['avatar_id'] ) : 0,
			'color'      => $color ? $color : '',
			'sort_order' => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
			'status'     => $this->status( isset( $data['status'] ) ? $data['status'] : 'active' ),
			'updated_at' => $now,
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' );

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
	 * Delete a staff member and detach service mappings.
	 *
	 * @param int $id Staff ID.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;

		$id = absint( $id );

		if ( ! $id ) {
			return false;
		}
		$appointments = \YoBooking\Database\Migrator::table_name( 'appointments' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$appointments} WHERE staff_id = %d", $id ) ) ) {
			return false !== $wpdb->update( $this->table(), array( 'status' => 'inactive', 'updated_at' => $this->now() ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
		}

		$wpdb->delete(
			\YoBooking\Database\Migrator::table_name( 'staff_services' ),
			array( 'staff_id' => $id ),
			array( '%d' )
		);
		$wpdb->delete(
			\YoBooking\Database\Migrator::table_name( 'availability_rules' ),
			array(
				'owner_type' => 'staff',
				'owner_id'   => $id,
			),
			array( '%s', '%d' )
		);
		$wpdb->delete(
			\YoBooking\Database\Migrator::table_name( 'availability_exceptions' ),
			array(
				'owner_type' => 'staff',
				'owner_id'   => $id,
			),
			array( '%s', '%d' )
		);

		return parent::delete( $id );
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
