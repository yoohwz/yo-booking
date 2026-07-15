<?php
/**
 * Customer repository.
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
 * Stores customers.
 */
final class CustomerRepository extends BaseRepository {
	/**
	 * Logical table suffix.
	 *
	 * @var string
	 */
	protected $table_suffix = 'customers';

	/**
	 * List customers.
	 *
	 * @return array
	 */
	public function all() {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY updated_at DESC, name ASC" );
	}

	/**
	 * List customers with booking and revenue metrics.
	 *
	 * @return array
	 */
	public function all_with_stats() {
		global $wpdb;

		$table        = $this->table();
		$appointments = \YoBooking\Database\Migrator::table_name( 'appointments' );
		$currency     = $this->reporting_currency();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*,
				COUNT(a.id) AS booking_count,
				COALESCE(SUM(CASE WHEN a.currency = %s THEN GREATEST(a.paid_amount - a.refunded_amount, 0) ELSE 0 END), 0) AS paid_total,
				MAX(a.start_at) AS last_booking_at
			FROM {$table} c
			LEFT JOIN {$appointments} a ON a.customer_id = c.id
			GROUP BY c.id
			ORDER BY c.updated_at DESC, c.name ASC",
				$currency
			)
		);
	}

	/**
	 * List a page of customers with aggregate booking metrics.
	 *
	 * @param array $args Search, limit, and offset.
	 * @return array
	 */
	public function page_with_stats( array $args = array() ) {
		global $wpdb;

		$args         = wp_parse_args( $args, array( 'search' => '', 'limit' => 25, 'offset' => 0 ) );
		$table        = $this->table();
		$appointments = \YoBooking\Database\Migrator::table_name( 'appointments' );
		$limit        = max( 1, min( 100, absint( $args['limit'] ) ) );
		$offset       = absint( $args['offset'] );
		$currency     = $this->reporting_currency();
		$where        = '';
		$values       = array( $currency );

		if ( $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where    = 'WHERE c.name LIKE %s OR c.email LIKE %s OR c.phone LIKE %s';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$values[] = $limit;
		$values[] = $offset;
		$sql = "SELECT c.*,
			COUNT(a.id) AS booking_count,
			COALESCE(SUM(CASE WHEN a.currency = %s THEN GREATEST(a.paid_amount - a.refunded_amount, 0) ELSE 0 END), 0) AS paid_total,
			MAX(a.start_at) AS last_booking_at
			FROM {$table} c
			LEFT JOIN {$appointments} a ON a.customer_id = c.id
			{$where}
			GROUP BY c.id
			ORDER BY c.updated_at DESC, c.name ASC
			LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	}

	/** @return string */
	private function reporting_currency() {
		$currency = Currency::normalize( ( new SettingsRepository() )->get( 'company.currency', 'USD' ) );
		return $currency ? $currency : 'USD';
	}

	/** @param string $search Search query. @return int */
	public function count_matching( $search = '' ) {
		global $wpdb;

		$table  = $this->table();
		$search = sanitize_text_field( $search );
		if ( '' === $search ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		$like = '%' . $wpdb->esc_like( $search ) . '%';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE name LIKE %s OR email LIKE %s OR phone LIKE %s", $like, $like, $like ) );
	}

	/**
	 * Search customers for admin autocomplete.
	 *
	 * @param string $query Search text.
	 * @param int    $limit Maximum rows.
	 * @return array
	 */
	public function search( $query, $limit = 20 ) {
		global $wpdb;

		$query = sanitize_text_field( $query );
		$limit = max( 1, min( 50, absint( $limit ) ) );
		$table = $this->table();

		if ( '' === $query ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY updated_at DESC, name ASC LIMIT %d", $limit ) );
		}

		$like = '%' . $wpdb->esc_like( $query ) . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE name LIKE %s OR email LIKE %s OR phone LIKE %s ORDER BY updated_at DESC, name ASC LIMIT %d",
				$like,
				$like,
				$like,
				$limit
			)
		);
	}

	/**
	 * Find a customer by email or phone.
	 *
	 * @param string $email Customer email.
	 * @param string $phone Customer phone.
	 * @return object|null
	 */
	public function find_by_contact( $email, $phone = '' ) {
		global $wpdb;

		$email = sanitize_email( $email );
		$phone = sanitize_text_field( $phone );
		$table = $this->table();

		if ( $email ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s ORDER BY id ASC LIMIT 1", $email ) );

			if ( $row ) {
				return $row;
			}
		}

		if ( $phone ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE phone = %s ORDER BY id ASC LIMIT 1", $phone ) );
		}

		return null;
	}

	/** Record explicit consent without changing customer identity fields. */
	public function grant_marketing_consent( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}
		return false !== $wpdb->update(
			$this->table(),
			array( 'marketing_consent' => 1, 'updated_at' => $this->now() ),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/** @param int $user_id WordPress user ID. @return object|null */
	public function find_by_user_id( $user_id ) {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return null;
		}
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT 1", $user_id ) );
	}

	/**
	 * Save a customer.
	 *
	 * @param array $data Raw customer data.
	 * @return int|WP_Error
	 */
	public function save( array $data ) {
		global $wpdb;

		$id    = isset( $data['id'] ) ? absint( $data['id'] ) : 0;
		$name  = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		$email = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'yo_booking_customer_name_required', __( 'Customer name is required.', 'yo-booking' ) );
		}

		if ( '' !== $email && ! is_email( $email ) ) {
			return new WP_Error( 'yo_booking_customer_email_invalid', __( 'Customer email is invalid.', 'yo-booking' ) );
		}

		$now = $this->now();

		$record = array(
			'user_id'           => isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0,
			'name'              => $name,
			'email'             => $email,
			'phone'             => isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : '',
			'timezone'          => isset( $data['timezone'] ) ? sanitize_text_field( $data['timezone'] ) : '',
			'notes'             => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
			'marketing_consent' => ! empty( $data['marketing_consent'] ) ? 1 : 0,
			'updated_at'        => $now,
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' );

		if ( $id ) {
			$updated = $wpdb->update( $this->table(), $record, array( 'id' => $id ), $formats, array( '%d' ) );

			return false === $updated ? $this->database_error() : $id;
		}

		$record['created_at'] = $now;
		$formats[]           = '%s';

		$inserted = $wpdb->insert( $this->table(), $record, $formats );

		return false === $inserted ? $this->database_error() : (int) $wpdb->insert_id;
	}

	/** Anonymize customers that have booking history; hard-delete only unused rows. */
	public function delete( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}
		$appointments = \YoBooking\Database\Migrator::table_name( 'appointments' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$appointments} WHERE customer_id = %d", $id ) );
		if ( ! $used ) {
			return parent::delete( $id );
		}
		// translators: %d: customer database ID.
		$anonymous_name = sprintf( __( 'Anonymous customer #%d', 'yo-booking' ), $id );
		$updated = $wpdb->update(
			$this->table(),
			array( 'user_id' => 0, 'name' => $anonymous_name, 'email' => '', 'phone' => '', 'notes' => '', 'marketing_consent' => 0, 'updated_at' => $this->now() ),
			array( 'id' => $id ),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
		return false !== $updated;
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
