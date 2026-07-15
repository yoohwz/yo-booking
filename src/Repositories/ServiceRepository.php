<?php
/**
 * Service repository.
 *
 * @package YoBooking
 */

namespace YoBooking\Repositories;

use YoBooking\Payments\Currency;
use YoBooking\Settings\Repository as SettingsRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Stores services.
 */
final class ServiceRepository extends BaseRepository {
	/**
	 * Logical table suffix.
	 *
	 * @var string
	 */
	protected $table_suffix = 'services';

	/**
	 * List services.
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
	 * List active services for booking.
	 *
	 * @return array
	 */
	public function active() {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'active' ORDER BY sort_order ASC, name ASC" );
	}

	/**
	 * Count services.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Save a service.
	 *
	 * @param array $data Raw service data.
	 * @return int|WP_Error
	 */
	public function save( array $data ) {
		global $wpdb;

		$id   = isset( $data['id'] ) ? absint( $data['id'] ) : 0;
		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'yo_booking_service_name_required', __( 'Service name is required.', 'yo-booking' ) );
		}

		$duration = isset( $data['duration_minutes'] ) ? absint( $data['duration_minutes'] ) : 60;
		$duration = max( 5, min( 1440, $duration ) );
		$capacity = isset( $data['capacity'] ) ? absint( $data['capacity'] ) : 1;
		$capacity = max( 1, min( 999, $capacity ) );
		$color    = isset( $data['color'] ) ? sanitize_hex_color( $data['color'] ) : '';
		$now      = $this->now();
		$currency = Currency::normalize( isset( $data['currency'] ) ? $data['currency'] : ( new SettingsRepository() )->get( 'company.currency', 'USD' ) );

		if ( ! $currency ) {
			return new WP_Error( 'yo_booking_service_currency_invalid', __( 'Select a supported currency.', 'yo-booking' ) );
		}

		$record = array(
			'category_id'           => isset( $data['category_id'] ) ? absint( $data['category_id'] ) : 0,
			'name'                  => $name,
			'slug'                  => $this->unique_slug( ! empty( $data['slug'] ) ? $data['slug'] : $name, $id, 'service' ),
			'description'           => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '',
			'duration_minutes'      => $duration,
			'buffer_before_minutes' => isset( $data['buffer_before_minutes'] ) ? absint( $data['buffer_before_minutes'] ) : 0,
			'buffer_after_minutes'  => isset( $data['buffer_after_minutes'] ) ? absint( $data['buffer_after_minutes'] ) : 0,
			'price'                 => $this->money( isset( $data['price'] ) ? $data['price'] : 0, $currency ),
			'currency'              => $currency,
			'capacity'              => $capacity,
			'color'                 => $color ? $color : '',
			'sort_order'            => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
			'status'                => $this->status( isset( $data['status'] ) ? $data['status'] : 'active' ),
			'updated_at'            => $now,
		);

		$formats = array( '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s' );

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
	 * Delete a service and detach staff mappings.
	 *
	 * @param int $id Service ID.
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
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$appointments} WHERE service_id = %d", $id ) ) ) {
			return false !== $wpdb->update( $this->table(), array( 'status' => 'inactive', 'updated_at' => $this->now() ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
		}

		$wpdb->delete(
			\YoBooking\Database\Migrator::table_name( 'staff_services' ),
			array( 'service_id' => $id ),
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
