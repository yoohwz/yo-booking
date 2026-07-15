<?php
/**
 * Webhook delivery repository.
 *
 * @package YoBooking
 */

namespace YoBooking\Repositories;

use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Stores immutable payload snapshots and delivery attempts.
 */
final class WebhookDeliveryRepository extends BaseRepository {
	/** @var string */
	protected $table_suffix = 'webhook_deliveries';

	/** @param array $data Delivery data. @return int|WP_Error */
	public function create( array $data ) {
		global $wpdb;
		$now = $this->now();
		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'endpoint_id'   => absint( $data['endpoint_id'] ),
				'event'         => sanitize_text_field( $data['event'] ),
				'object_type'   => sanitize_key( $data['object_type'] ),
				'object_id'     => absint( $data['object_id'] ),
				'payload'       => wp_json_encode( $data['payload'] ),
				'status'        => 'pending',
				'attempts'      => 0,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
		return false === $inserted ? new WP_Error( 'yo_booking_database_error', $wpdb->last_error ) : (int) $wpdb->insert_id;
	}

	/** @param int $limit Maximum rows. @return array */
	public function recent( $limit = 100 ) {
		global $wpdb;
		$table = $this->table();
		$endpoints = \YoBooking\Database\Migrator::table_name( 'webhook_endpoints' );
		$limit = max( 1, min( 500, absint( $limit ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( $wpdb->prepare( "SELECT d.*, e.name AS endpoint_name FROM {$table} d LEFT JOIN {$endpoints} e ON e.id=d.endpoint_id ORDER BY d.created_at DESC,d.id DESC LIMIT %d", $limit ) );
	}

	/** @param int $id Delivery ID. @param int $attempts Attempts. @param int $code HTTP code. @param string $body Response body. @return void */
	public function mark_delivered( $id, $attempts, $code, $body ) {
		global $wpdb;
		$now = $this->now();
		$wpdb->update( $this->table(), array( 'status' => 'delivered', 'attempts' => $attempts, 'response_code' => $code, 'response_body' => $this->response_excerpt( $body ), 'error_message' => '', 'next_attempt_at' => null, 'delivered_at' => $now, 'updated_at' => $now ), array( 'id' => absint( $id ) ) );
	}

	/** @param int $id Delivery ID. @param int $attempts Attempts. @param int $code HTTP code. @param string $body Body. @param string $error Error. @param string|null $next Next attempt. @return void */
	public function mark_failed( $id, $attempts, $code, $body, $error, $next = null ) {
		global $wpdb;
		$wpdb->update( $this->table(), array( 'status' => $next ? 'retrying' : 'failed', 'attempts' => $attempts, 'response_code' => $code, 'response_body' => $this->response_excerpt( $body ), 'error_message' => sanitize_textarea_field( $error ), 'next_attempt_at' => $next, 'updated_at' => $this->now() ), array( 'id' => absint( $id ) ) );
	}

	/** @param int $id Delivery ID. @return bool */
	public function reset( $id ) {
		global $wpdb;
		return false !== $wpdb->update( $this->table(), array( 'status' => 'pending', 'attempts' => 0, 'response_code' => 0, 'response_body' => '', 'error_message' => '', 'next_attempt_at' => null, 'delivered_at' => null, 'updated_at' => $this->now() ), array( 'id' => absint( $id ) ) );
	}

	/** @param string $body Response body. @return string */
	private function response_excerpt( $body ) {
		$body = wp_strip_all_tags( (string) $body );
		return function_exists( 'mb_substr' ) ? mb_substr( $body, 0, 2000 ) : substr( $body, 0, 2000 );
	}
}
