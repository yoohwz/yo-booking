<?php
/**
 * Notification template repository.
 *
 * @package YoBooking
 */

namespace YoBooking\Repositories;

use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Stores configurable email notification templates.
 */
final class NotificationTemplateRepository extends BaseRepository {
	/**
	 * Logical table suffix.
	 *
	 * @var string
	 */
	protected $table_suffix = 'notifications';

	/**
	 * Return supported notification events.
	 *
	 * @return array
	 */
	public static function events() {
		return array(
			'appointment.created'     => __( 'Booking created', 'yo-booking' ),
			'appointment.confirmed'   => __( 'Booking confirmed', 'yo-booking' ),
			'appointment.cancelled'   => __( 'Booking cancelled', 'yo-booking' ),
			'appointment.rescheduled' => __( 'Booking rescheduled', 'yo-booking' ),
			'appointment.completed'   => __( 'Booking completed', 'yo-booking' ),
			'appointment.reminder'    => __( 'Booking reminder', 'yo-booking' ),
			'payment.received'        => __( 'Payment received', 'yo-booking' ),
			'payment.failed'          => __( 'Payment failed', 'yo-booking' ),
			'payment.refunded'        => __( 'Payment refunded', 'yo-booking' ),
			'payment.balance_reminder' => __( 'Payment balance reminder', 'yo-booking' ),
		);
	}

	/**
	 * Return supported recipient types.
	 *
	 * @return array
	 */
	public static function recipient_types() {
		return array(
			'admin'    => __( 'Admin', 'yo-booking' ),
			'customer' => __( 'Customer', 'yo-booking' ),
			'staff'    => __( 'Staff', 'yo-booking' ),
		);
	}

	/**
	 * Return notification templates.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function all( array $args = array() ) {
		global $wpdb;

		$table  = $this->table();
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['event'] ) ) {
			$where[]  = 'event = %s';
			$params[] = $this->event( $args['event'] );
		}

		if ( isset( $args['enabled'] ) ) {
			$where[]  = 'enabled = %d';
			$params[] = ! empty( $args['enabled'] ) ? 1 : 0;
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY event ASC, recipient_type ASC, notification_key ASC';

		if ( $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( $sql );
	}

	/**
	 * Return enabled templates for one event.
	 *
	 * @param string $event Event name.
	 * @return array
	 */
	public function enabled_for_event( $event ) {
		return $this->all(
			array(
				'event'   => $event,
				'enabled' => true,
			)
		);
	}

	/**
	 * Find a template by key.
	 *
	 * @param string $key Notification key.
	 * @return object|null
	 */
	public function find_by_key( $key ) {
		global $wpdb;

		$key   = sanitize_key( $key );
		$table = $this->table();

		if ( '' === $key ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE notification_key = %s LIMIT 1", $key ) );
	}

	/**
	 * Save editable template fields.
	 *
	 * @param array $data Raw template data.
	 * @return int|WP_Error
	 */
	public function save( array $data ) {
		global $wpdb;

		$id = isset( $data['id'] ) ? absint( $data['id'] ) : 0;

		if ( ! $id || ! $this->find( $id ) ) {
			return new WP_Error( 'yo_booking_notification_not_found', __( 'Notification template not found.', 'yo-booking' ) );
		}

		$event = $this->event( isset( $data['event'] ) ? $data['event'] : '' );
		$is_reminder = in_array( $event, array( 'appointment.reminder', 'payment.balance_reminder' ), true );

		$record = array(
			'event'                 => $event,
			'recipient_type'        => $this->recipient_type( isset( $data['recipient_type'] ) ? $data['recipient_type'] : '' ),
			'enabled'               => ! empty( $data['enabled'] ) ? 1 : 0,
			'subject'               => isset( $data['subject'] ) ? sanitize_text_field( $data['subject'] ) : '',
			'heading'               => isset( $data['heading'] ) ? sanitize_text_field( $data['heading'] ) : '',
			'body'                  => isset( $data['body'] ) ? wp_kses_post( $data['body'] ) : '',
			'email_type'            => $this->email_type( isset( $data['email_type'] ) ? $data['email_type'] : 'html' ),
			'send_ics'              => ! empty( $data['send_ics'] ) ? 1 : 0,
			'timing_offset_minutes' => $is_reminder && isset( $data['timing_offset_minutes'] ) ? max( 0, min( 43200, absint( $data['timing_offset_minutes'] ) ) ) : 0,
			'updated_at'            => $this->now(),
		);

		$updated = $wpdb->update(
			$this->table(),
			$record,
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);

		return false === $updated ? $this->database_error() : $id;
	}

	/**
	 * Normalize an event key.
	 *
	 * @param string $event Raw event.
	 * @return string
	 */
	private function event( $event ) {
		$event = sanitize_text_field( $event );
		$event = preg_replace( '/[^a-z0-9_.-]/', '', strtolower( $event ) );

		return array_key_exists( $event, self::events() ) ? $event : 'appointment.created';
	}

	/**
	 * Normalize a recipient type.
	 *
	 * @param string $type Raw type.
	 * @return string
	 */
	private function recipient_type( $type ) {
		$type = sanitize_key( $type );

		return array_key_exists( $type, self::recipient_types() ) ? $type : 'admin';
	}

	/**
	 * Normalize an email type.
	 *
	 * @param string $type Raw type.
	 * @return string
	 */
	private function email_type( $type ) {
		$type = sanitize_key( $type );

		return in_array( $type, array( 'html', 'plain' ), true ) ? $type : 'html';
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
