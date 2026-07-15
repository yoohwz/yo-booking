<?php
/**
 * Base repository helpers.
 *
 * @package YoBooking
 */

namespace YoBooking\Repositories;

use YoBooking\Database\Migrator;
use YoBooking\Payments\Currency;
use YoBooking\Payments\Money;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Shared table repository utilities.
 */
abstract class BaseRepository {
	/**
	 * Logical table suffix.
	 *
	 * @var string
	 */
	protected $table_suffix = '';

	/**
	 * Return the physical table name.
	 *
	 * @return string
	 */
	protected function table() {
		return Migrator::table_name( $this->table_suffix );
	}

	/**
	 * Return a GMT timestamp for database writes.
	 *
	 * @return string
	 */
	protected function now() {
		return current_time( 'mysql', true );
	}

	/**
	 * Find one row by ID.
	 *
	 * @param int $id Row ID.
	 * @return object|null
	 */
	public function find( $id ) {
		global $wpdb;

		$id = absint( $id );

		if ( ! $id ) {
			return null;
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Delete one row by ID.
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;

		$id = absint( $id );

		if ( ! $id ) {
			return false;
		}

		return false !== $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Create a unique slug for a table with a slug column.
	 *
	 * @param string $value Source value.
	 * @param int    $exclude_id Existing row ID to exclude.
	 * @param string $fallback Fallback base.
	 * @return string
	 */
	protected function unique_slug( $value, $exclude_id = 0, $fallback = 'item' ) {
		$base = sanitize_title( $value );

		if ( '' === $base ) {
			$base = $fallback;
		}

		$slug   = $base;
		$suffix = 2;

		while ( $this->slug_exists( $slug, $exclude_id ) ) {
			$slug = $base . '-' . $suffix;
			++$suffix;
		}

		return $slug;
	}

	/**
	 * Check whether a slug exists in the current table.
	 *
	 * @param string $slug Slug value.
	 * @param int    $exclude_id Existing row ID to exclude.
	 * @return bool
	 */
	protected function slug_exists( $slug, $exclude_id = 0 ) {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE slug = %s AND id <> %d LIMIT 1",
				$slug,
				absint( $exclude_id )
			)
		);

		return ! empty( $row_id );
	}

	/**
	 * Normalize an active/inactive status.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	protected function status( $status ) {
		$status = sanitize_key( $status );

		return in_array( $status, array( 'active', 'inactive' ), true ) ? $status : 'active';
	}

	/**
	 * Normalize a money value to a database-safe decimal string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	protected function money( $value, $currency = 'USD' ) {
		return Money::normalize( $value, Currency::normalize( $currency ) ? $currency : 'USD' );
	}

	/**
	 * Normalize a three-letter currency.
	 *
	 * @param string $currency Raw currency.
	 * @return string
	 */
	protected function currency( $currency ) {
		return Currency::normalize( $currency );
	}
}
