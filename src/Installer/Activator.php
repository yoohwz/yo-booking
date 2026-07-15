<?php
/**
 * Plugin activation.
 *
 * @package YoBooking
 */

namespace YoBooking\Installer;

use YoBooking\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Handles activation tasks.
 */
final class Activator {
	/**
	 * Activate the plugin.
	 *
	 * @return void
	 */
	public static function activate() {
		( new Migrator() )->install();
		RoleManager::install();

		if ( false === get_option( 'yo_booking_installed_at', false ) ) {
			add_option( 'yo_booking_installed_at', gmdate( 'Y-m-d H:i:s' ), '', 'no' );
		}

		do_action( 'yo_booking_activated' );
	}
}
