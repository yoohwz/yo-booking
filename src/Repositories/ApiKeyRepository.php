<?php
/**
 * Integration API key repository.
 *
 * @package YoBooking
 */

namespace YoBooking\Repositories;

use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Stores hashed server-to-server credentials.
 */
final class ApiKeyRepository extends BaseRepository {
	/** @var string */
	protected $table_suffix = 'api_keys';

	/** @return array */
	public static function capabilities() {
		return array(
			'read_appointments'  => __( 'Read appointments', 'yo-booking' ),
			'write_appointments' => __( 'Update appointment status', 'yo-booking' ),
			'read_customers'     => __( 'Read customers', 'yo-booking' ),
		);
	}

	/** @return array */
	public function all() {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC,id DESC" );
	}

	/** @param array $data Key metadata. @return array|WP_Error */
	public function create_key( array $data ) {
		global $wpdb;
		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 191 ) : substr( $name, 0, 191 );
		$capabilities = isset( $data['capabilities'] ) && is_array( $data['capabilities'] ) ? array_values( array_intersect( array_keys( self::capabilities() ), array_map( 'sanitize_key', $data['capabilities'] ) ) ) : array();
		if ( ! $name || ! $capabilities ) {
			return new WP_Error( 'yo_booking_api_key_invalid', __( 'A key name and at least one capability are required.', 'yo-booking' ) );
		}
		$raw    = 'yb_live_' . wp_generate_password( 40, false, false );
		$prefix = substr( $raw, 0, 16 );
		$now    = $this->now();
		$expires_at = null;
		if ( ! empty( $data['expires_at'] ) ) {
			$date = sanitize_text_field( $data['expires_at'] );
			$parts = preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches ) ? $matches : array();
			if ( ! $parts || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) || strtotime( $date . ' 23:59:59 UTC' ) <= time() ) {
				return new WP_Error( 'yo_booking_api_key_expiry_invalid', __( 'API key expiration must be a valid future date.', 'yo-booking' ) );
			}
			$expires_at = $date . ' 23:59:59';
		}
		$inserted = $wpdb->insert(
			$this->table(),
			array( 'name' => $name, 'key_prefix' => $prefix, 'key_hash' => $this->hash( $raw ), 'capabilities' => wp_json_encode( $capabilities ), 'status' => 'active', 'expires_at' => $expires_at, 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		return false === $inserted ? new WP_Error( 'yo_booking_database_error', $wpdb->last_error ) : array( 'id' => (int) $wpdb->insert_id, 'key' => $raw, 'prefix' => $prefix );
	}

	/** @param string $raw Raw key. @return object|WP_Error */
	public function authenticate( $raw ) {
		global $wpdb;
		$raw = trim( (string) $raw );
		if ( 0 !== strpos( $raw, 'yb_live_' ) || strlen( $raw ) < 24 ) {
			return new WP_Error( 'yo_booking_api_key_invalid', __( 'Invalid API key.', 'yo-booking' ), array( 'status' => 401 ) );
		}
		$table = $this->table();
		$prefix = substr( $raw, 0, 16 );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$key = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE key_prefix=%s LIMIT 1", $prefix ) );
		if ( ! $key || 'active' !== $key->status || ! hash_equals( (string) $key->key_hash, $this->hash( $raw ) ) || ( $key->expires_at && strtotime( $key->expires_at . ' UTC' ) < time() ) ) {
			return new WP_Error( 'yo_booking_api_key_invalid', __( 'Invalid or expired API key.', 'yo-booking' ), array( 'status' => 401 ) );
		}
		$wpdb->update( $this->table(), array( 'last_used_at' => $this->now() ), array( 'id' => (int) $key->id ), array( '%s' ), array( '%d' ) );
		$key->capabilities = json_decode( (string) $key->capabilities, true );
		return $key;
	}

	/** @param int $id Key ID. @return bool */
	public function revoke( $id ) {
		global $wpdb;
		return false !== $wpdb->update( $this->table(), array( 'status' => 'revoked', 'updated_at' => $this->now() ), array( 'id' => absint( $id ) ), array( '%s', '%s' ), array( '%d' ) );
	}

	/** @param string $raw Raw key. @return string */
	private function hash( $raw ) {
		return hash_hmac( 'sha256', (string) $raw, wp_salt( 'auth' ) );
	}
}
