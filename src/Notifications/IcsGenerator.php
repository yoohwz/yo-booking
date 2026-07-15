<?php
/**
 * ICS calendar attachment generator.
 *
 * @package YoBooking
 */

namespace YoBooking\Notifications;

use YoBooking\Settings\Repository as SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Creates iCalendar data for appointment emails.
 */
final class IcsGenerator {
	/**
	 * Create a temporary ICS attachment path.
	 *
	 * @param object $appointment Appointment row with details.
	 * @return string
	 */
	public function temporary_file( $appointment ) {
		$filename = 'yo-booking-' . absint( $appointment->id ) . '.ics';
		$path     = wp_tempnam( $filename );

		if ( ! $path ) {
			return '';
		}

		if ( false === file_put_contents( $path, $this->content( $appointment ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return '';
		}

		return $path;
	}

	/**
	 * Build ICS file content.
	 *
	 * @param object $appointment Appointment row with details.
	 * @return string
	 */
	public function content( $appointment ) {
		$settings = ( new SettingsRepository() )->all();
		$host     = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$uuid     = ! empty( $appointment->uuid ) ? $appointment->uuid : 'appointment-' . absint( $appointment->id );
		$summary  = ! empty( $appointment->service_name ) ? $appointment->service_name : __( 'Appointment', 'yo-booking' );
		$cancelled = isset( $appointment->status ) && 'cancelled' === $appointment->status;
		$sequence  = isset( $appointment->updated_at ) ? max( 0, strtotime( $appointment->updated_at . ' UTC' ) ) : 0;
		$desc     = trim(
			__( 'Customer', 'yo-booking' ) . ': ' . ( isset( $appointment->customer_name ) ? $appointment->customer_name : '' ) . "\n" .
			__( 'Staff', 'yo-booking' ) . ': ' . ( isset( $appointment->staff_name ) ? $appointment->staff_name : '' )
		);

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Yo Booking//Appointments//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:' . ( $cancelled ? 'CANCEL' : 'REQUEST' ),
			'BEGIN:VEVENT',
			'UID:' . $this->escape( $uuid . '@' . ( $host ? $host : 'yo-booking.local' ) ),
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			'DTSTART:' . gmdate( 'Ymd\THis\Z', strtotime( $appointment->start_at . ' UTC' ) ),
			'DTEND:' . gmdate( 'Ymd\THis\Z', strtotime( $appointment->end_at . ' UTC' ) ),
			'LAST-MODIFIED:' . gmdate( 'Ymd\THis\Z', $sequence ? $sequence : time() ),
			'SEQUENCE:' . $sequence,
			'STATUS:' . ( $cancelled ? 'CANCELLED' : ( isset( $appointment->status ) && 'confirmed' === $appointment->status ? 'CONFIRMED' : 'TENTATIVE' ) ),
			'SUMMARY:' . $this->escape( $summary ),
			'DESCRIPTION:' . $this->escape( $desc ),
			'LOCATION:' . $this->escape( isset( $settings['company']['address'] ) ? $settings['company']['address'] : '' ),
			'ORGANIZER;CN=' . $this->escape( isset( $settings['company']['name'] ) ? $settings['company']['name'] : get_bloginfo( 'name' ) ) . ':MAILTO:' . sanitize_email( isset( $settings['company']['email'] ) ? $settings['company']['email'] : get_option( 'admin_email' ) ),
		);

		if ( ! empty( $appointment->customer_email ) ) {
			$lines[] = 'ATTENDEE;CN=' . $this->escape( $appointment->customer_name ) . ';ROLE=REQ-PARTICIPANT:MAILTO:' . sanitize_email( $appointment->customer_email );
		}

		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Escape ICS text values.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function escape( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = str_replace( '\\', '\\\\', $value );
		$value = str_replace( array( "\r\n", "\r", "\n" ), '\\n', $value );
		$value = str_replace( array( ',', ';' ), array( '\\,', '\\;' ), $value );

		return $value;
	}
}
