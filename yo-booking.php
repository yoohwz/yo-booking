<?php
/**
 * Plugin Name: Yo Booking
 * Plugin URI: https://yoohw.com
 * Description: Appointment scheduling, availability, customer, notification, payment, and self-service tools for WordPress.
 * Version: 2.0.0
 * Author: YoOhw.com
 * Author URI: https://yoohw.com
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Text Domain: yo-booking
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package YoBooking
 */

defined( 'ABSPATH' ) || exit;

define( 'YO_BOOKING_VERSION', '2.0.0' );
define( 'YO_BOOKING_MIN_PHP', '7.4' );
define( 'YO_BOOKING_FILE', __FILE__ );
define( 'YO_BOOKING_PATH', plugin_dir_path( __FILE__ ) );
define( 'YO_BOOKING_URL', plugin_dir_url( __FILE__ ) );
define( 'YO_BOOKING_BASENAME', plugin_basename( __FILE__ ) );

require_once YO_BOOKING_PATH . 'src/Autoloader.php';

YoBooking\Autoloader::register();

register_activation_hook( __FILE__, array( 'YoBooking\Installer\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'YoBooking\Installer\Deactivator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		YoBooking\Plugin::instance()->boot();
	}
);
