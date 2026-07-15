<?php
/**
 * Webhook endpoint repository.
 *
 * @package YoBooking
 */

namespace YoBooking\Repositories;

use YoBooking\Integrations\Events;
use YoBooking\Support\SecretVault;
use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Stores outbound webhook destinations.
 */
final class WebhookEndpointRepository extends BaseRepository {
	/** @var string */
	protected $table_suffix = 'webhook_endpoints';

	/** @return array */
	public function all() {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY updated_at DESC, id DESC" );
	}

	/** @param string $event Event key. @return array */
	public function active_for_event( $event ) {
		return array_values(
			array_filter(
				$this->all(),
				static function ( $endpoint ) use ( $event ) {
					$events = json_decode( (string) $endpoint->events, true );
					return 'active' === $endpoint->status && is_array( $events ) && in_array( $event, $events, true );
				}
			)
		);
	}

	/** @param array $data Endpoint data. @return int|WP_Error */
	public function save( array $data ) {
		global $wpdb;
		$id     = isset( $data['id'] ) ? absint( $data['id'] ) : 0;
		$name   = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		$url    = isset( $data['url'] ) ? esc_url_raw( $data['url'] ) : '';
		$name   = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 191 ) : substr( $name, 0, 191 );
		$events = isset( $data['events'] ) && is_array( $data['events'] ) ? Events::sanitize( $data['events'] ) : array();
		if ( ! $name || ! $url || strlen( $url ) > 500 || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'yo_booking_webhook_invalid', __( 'A valid endpoint name and public HTTPS URL are required.', 'yo-booking' ) );
		}
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return new WP_Error( 'yo_booking_webhook_https_required', __( 'Webhook endpoints must use HTTPS.', 'yo-booking' ) );
		}
		if ( ! $events ) {
			return new WP_Error( 'yo_booking_webhook_events_required', __( 'Select at least one webhook event.', 'yo-booking' ) );
		}

		$existing = $id ? $this->find( $id ) : null;
		$secret = isset( $data['secret'] ) ? sanitize_text_field( $data['secret'] ) : '';
		$record = array(
			'name'            => $name,
			'url'             => $url,
			'events'          => wp_json_encode( $events ),
			'status'          => isset( $data['status'] ) && 'inactive' === sanitize_key( $data['status'] ) ? 'inactive' : 'active',
			'timeout_seconds' => max( 3, min( 30, isset( $data['timeout_seconds'] ) ? absint( $data['timeout_seconds'] ) : 10 ) ),
			'updated_at'      => $this->now(),
		);
		if ( $secret ) {
			$record['secret_encrypted'] = SecretVault::encrypt( $secret );
			if ( ! $record['secret_encrypted'] ) {
				return new WP_Error( 'yo_booking_webhook_encryption_failed', __( 'The signing secret could not be encrypted on this server.', 'yo-booking' ) );
			}
		} elseif ( ! $existing ) {
			return new WP_Error( 'yo_booking_webhook_secret_required', __( 'A signing secret is required.', 'yo-booking' ) );
		}

		if ( $id ) {
			$updated = $wpdb->update( $this->table(), $record, array( 'id' => $id ) );
			return false === $updated ? $this->database_error() : $id;
		}
		$record['created_by'] = get_current_user_id();
		$record['created_at'] = $this->now();
		$inserted = $wpdb->insert( $this->table(), $record );
		return false === $inserted ? $this->database_error() : (int) $wpdb->insert_id;
	}

	/** @param object $endpoint Endpoint row. @return string */
	public function secret( $endpoint ) {
		return SecretVault::decrypt( isset( $endpoint->secret_encrypted ) ? $endpoint->secret_encrypted : '' );
	}

	/** @return WP_Error */
	private function database_error() {
		global $wpdb;
		return new WP_Error( 'yo_booking_database_error', $wpdb->last_error ? $wpdb->last_error : __( 'Database write failed.', 'yo-booking' ) );
	}
}
