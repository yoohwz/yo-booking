<?php
/**
 * Integration event catalog.
 *
 * @package YoBooking
 */

namespace YoBooking\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Defines outbound event names.
 */
final class Events {
	/** @return array */
	public static function all() {
		return array(
			'appointment.created'        => __( 'Appointment created', 'yo-booking' ),
			'appointment.updated'        => __( 'Appointment updated', 'yo-booking' ),
			'appointment.status_changed' => __( 'Appointment status changed', 'yo-booking' ),
			'appointment.rescheduled'    => __( 'Appointment rescheduled', 'yo-booking' ),
			'payment.status_changed'     => __( 'Payment status changed', 'yo-booking' ),
			'payment.transaction_recorded' => __( 'Payment transaction recorded', 'yo-booking' ),
			'payment.refunded'          => __( 'Payment refunded', 'yo-booking' ),
		);
	}

	/** @param array $events Raw event keys. @return array */
	public static function sanitize( array $events ) {
		return array_values( array_intersect( array_keys( self::all() ), array_map( 'sanitize_text_field', $events ) ) );
	}
}
