<?php
/**
 * Capability helpers.
 *
 * @package YoBooking
 */

namespace YoBooking\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes plugin capability names.
 */
final class Capabilities {
	/** @return string */
	public static function manage() {
		return (string) apply_filters( 'yo_booking_manage_capability', 'yo_booking_manage' );
	}

	/** @return string */
	public static function appointments() {
		return 'yo_booking_manage_appointments';
	}

	/** @return string */
	public static function reports() {
		return 'yo_booking_view_reports';
	}

	/** @return string */
	public static function settings() {
		return 'yo_booking_manage_settings';
	}

	/** @return string */
	public static function export() {
		return 'yo_booking_export';
	}

	/** @return array */
	public static function all() {
		return array( self::manage(), self::appointments(), self::reports(), self::settings(), self::export() );
	}
}
