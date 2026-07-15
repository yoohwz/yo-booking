<?php
/**
 * Staff row-level access helpers.
 *
 * @package YoBooking
 */

namespace YoBooking\Support;

use YoBooking\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves staff ownership for restricted booking users.
 */
final class StaffAccess {
	/**
	 * Check whether the current user is restricted to one staff profile.
	 *
	 * @return bool
	 */
	public static function restricted() {
		return is_user_logged_in()
			&& current_user_can( Capabilities::appointments() )
			&& ! current_user_can( Capabilities::manage() );
	}

	/**
	 * Return the current user's active staff ID.
	 *
	 * @return int
	 */
	public static function current_staff_id() {
		global $wpdb;
		if ( ! self::restricted() ) {
			return 0;
		}
		$table = Migrator::table_name( 'staff' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorization checks must use current ownership data.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d AND status = 'active' LIMIT 1", get_current_user_id() ) );
	}

	/**
	 * Check whether the current user owns an appointment.
	 *
	 * @param int $appointment_id Appointment ID.
	 * @return bool
	 */
	public static function can_access_appointment( $appointment_id ) {
		global $wpdb;
		if ( ! self::restricted() ) {
			return true;
		}
		$staff_id = self::current_staff_id();
		if ( ! $staff_id ) {
			return false;
		}
		$table = Migrator::table_name( 'appointments' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorization checks must use current ownership data.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND staff_id = %d LIMIT 1", absint( $appointment_id ), $staff_id ) );
	}
}
