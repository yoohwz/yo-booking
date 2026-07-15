<?php
/**
 * Service category repository.
 *
 * @package YoBooking
 */

namespace YoBooking\Repositories;

use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Stores service categories.
 */
final class ServiceCategoryRepository extends BaseRepository {
	/**
	 * Logical table suffix.
	 *
	 * @var string
	 */
	protected $table_suffix = 'service_categories';

	/**
	 * List categories.
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
	 * Save a category.
	 *
	 * @param array $data Raw category data.
	 * @return int|WP_Error
	 */
	public function save( array $data ) {
		global $wpdb;

		$id   = isset( $data['id'] ) ? absint( $data['id'] ) : 0;
		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'yo_booking_category_name_required', __( 'Category name is required.', 'yo-booking' ) );
		}

		$now    = $this->now();
		$record = array(
			'parent_id'   => isset( $data['parent_id'] ) ? absint( $data['parent_id'] ) : 0,
			'name'        => $name,
			'slug'        => $this->unique_slug( ! empty( $data['slug'] ) ? $data['slug'] : $name, $id, 'category' ),
			'description' => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '',
			'sort_order'  => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
			'status'      => $this->status( isset( $data['status'] ) ? $data['status'] : 'active' ),
			'updated_at'  => $now,
		);

		$formats = array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' );

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
	 * Delete a category and detach related rows.
	 *
	 * @param int $id Category ID.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;

		$id = absint( $id );

		if ( ! $id ) {
			return false;
		}

		$wpdb->update(
			\YoBooking\Database\Migrator::table_name( 'services' ),
			array( 'category_id' => 0 ),
			array( 'category_id' => $id ),
			array( '%d' ),
			array( '%d' )
		);
		$wpdb->update(
			$this->table(),
			array( 'parent_id' => 0 ),
			array( 'parent_id' => $id ),
			array( '%d' ),
			array( '%d' )
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
