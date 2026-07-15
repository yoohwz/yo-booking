<?php
/**
 * Notification log repository.
 *
 * @package YoBooking
 */

namespace YoBooking\Repositories;

use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Stores email delivery attempts.
 */
final class NotificationLogRepository extends BaseRepository {
	/**
	 * Logical table suffix.
	 *
	 * @var string
	 */
	protected $table_suffix = 'notification_logs';

	/**
	 * Return recent log rows.
	 *
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public function recent( $limit = 100 ) {
		global $wpdb;

		$table = $this->table();
		$limit = max( 1, min( 500, absint( $limit ) ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d", $limit ) );
	}

	/**
	 * Return delivery logs for one appointment.
	 *
	 * @param int $appointment_id Appointment ID.
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public function for_appointment( $appointment_id, $limit = 50 ) {
		global $wpdb;

		$table          = $this->table();
		$appointment_id = absint( $appointment_id );
		$limit          = max( 1, min( 100, absint( $limit ) ) );

		if ( ! $appointment_id ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE appointment_id = %d ORDER BY created_at DESC, id DESC LIMIT %d", $appointment_id, $limit ) );
	}

	/**
	 * Check whether a template has already been sent for an appointment.
	 *
	 * @param int    $appointment_id Appointment ID.
	 * @param string $notification_key Notification key.
	 * @return bool
	 */
	public function already_sent( $appointment_id, $notification_key ) {
		global $wpdb;

		$table            = $this->table();
		$appointment_id   = absint( $appointment_id );
		$notification_key = sanitize_key( $notification_key );

		if ( ! $appointment_id || '' === $notification_key ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE appointment_id = %d AND notification_key = %s AND status = %s",
				$appointment_id,
				$notification_key,
				'sent'
			)
		);

		return $count > 0;
	}

	/**
	 * Create a delivery log row.
	 *
	 * @param array $data Log data.
	 * @return int|WP_Error
	 */
	public function create( array $data ) {
		global $wpdb;

		$now    = $this->now();
		$status = $this->status_value( isset( $data['status'] ) ? $data['status'] : 'pending' );

		$record = array(
			'notification_key' => isset( $data['notification_key'] ) ? sanitize_key( $data['notification_key'] ) : '',
			'event'            => isset( $data['event'] ) ? sanitize_text_field( $data['event'] ) : '',
			'appointment_id'   => isset( $data['appointment_id'] ) ? absint( $data['appointment_id'] ) : 0,
			'recipient_type'   => isset( $data['recipient_type'] ) ? sanitize_key( $data['recipient_type'] ) : '',
			'recipient_email'  => isset( $data['recipient_email'] ) ? sanitize_text_field( $data['recipient_email'] ) : '',
			'subject'          => isset( $data['subject'] ) ? sanitize_text_field( $data['subject'] ) : '',
			'status'           => $status,
			'error_message'    => isset( $data['error_message'] ) ? sanitize_textarea_field( $data['error_message'] ) : '',
			'occurrence_key'    => ! empty( $data['occurrence_key'] ) ? sanitize_text_field( $data['occurrence_key'] ) : null,
			'sent_at'          => 'sent' === $status ? $now : null,
			'created_at'       => $now,
			'updated_at'       => $now,
		);

		$inserted = $wpdb->insert(
			$this->table(),
			$record,
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false === $inserted ? $this->database_error() : (int) $wpdb->insert_id;
	}

	/**
	 * Mark a delivery attempt as sent.
	 *
	 * @param int $id Log ID.
	 * @return bool|WP_Error
	 */
	public function mark_sent( $id ) {
		return $this->update_status( $id, 'sent', '' );
	}

	/**
	 * Mark a delivery attempt as failed.
	 *
	 * @param int    $id Log ID.
	 * @param string $message Failure details.
	 * @return bool|WP_Error
	 */
	public function mark_failed( $id, $message ) {
		return $this->update_status( $id, 'failed', $message );
	}

	/**
	 * Delete log rows for one appointment.
	 *
	 * @param int $appointment_id Appointment ID.
	 * @return void
	 */
	public function delete_for_appointment( $appointment_id ) {
		global $wpdb;

		$appointment_id = absint( $appointment_id );

		if ( ! $appointment_id ) {
			return;
		}

		$wpdb->delete( $this->table(), array( 'appointment_id' => $appointment_id ), array( '%d' ) );
	}

	/**
	 * Update delivery status.
	 *
	 * @param int    $id Log ID.
	 * @param string $status Status.
	 * @param string $message Error message.
	 * @return bool|WP_Error
	 */
	private function update_status( $id, $status, $message ) {
		global $wpdb;

		$id     = absint( $id );
		$status = $this->status_value( $status );
		$now    = $this->now();

		if ( ! $id ) {
			return new WP_Error( 'yo_booking_notification_log_not_found', __( 'Notification log not found.', 'yo-booking' ) );
		}

		$record = array(
			'status'        => $status,
			'error_message' => sanitize_textarea_field( $message ),
			'sent_at'       => 'sent' === $status ? $now : null,
			'updated_at'    => $now,
		);

		$updated = $wpdb->update(
			$this->table(),
			$record,
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false === $updated ? $this->database_error() : true;
	}

	/**
	 * Normalize status.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	private function status_value( $status ) {
		$status = sanitize_key( $status );

		return in_array( $status, array( 'pending', 'sent', 'failed', 'skipped' ), true ) ? $status : 'pending';
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
