<?php
/**
 * Plugin deactivation.
 *
 * @package YoBooking
 */

namespace YoBooking\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Handles deactivation tasks.
 */
final class Deactivator {
	/**
	 * Deactivate the plugin.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'yo_booking_process_reminders' );
		wp_clear_scheduled_hook( 'yo_booking_process_webhook_delivery' );
		wp_clear_scheduled_hook( 'yo_booking_daily_cleanup' );

		do_action( 'yo_booking_deactivated' );
	}
}
