<?php
/**
 * Default plugin settings.
 *
 * @package YoBooking
 */

namespace YoBooking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Provides default settings for a clean install.
 */
final class Defaults {
	/**
	 * Return the default settings tree.
	 *
	 * @return array
	 */
	public static function settings() {
		$timezone = wp_timezone_string();

		return array(
			'company'       => array(
				'name'     => get_bloginfo( 'name' ),
				'email'    => get_option( 'admin_email' ),
				'phone'    => '',
				'address'  => '',
				'currency' => 'USD',
				'timezone' => $timezone ? $timezone : 'UTC',
			),
			'booking'       => array(
				'slot_interval_minutes'     => 15,
				'lead_time_minutes'         => 0,
				'booking_window_days'       => 90,
				'default_status'            => 'pending',
				'allow_guest_booking'       => true,
				'allow_staff_selection'     => true,
				'cancellation_window_hours' => 24,
				'reschedule_window_hours'   => 24,
				'require_email'             => true,
				'require_phone'             => true,
			),
			'notifications' => array(
				'enabled'    => true,
				'from_name'  => get_bloginfo( 'name' ),
				'from_email' => get_option( 'admin_email' ),
				'admin_to'   => get_option( 'admin_email' ),
			),
			'payments'      => array(
				'enabled'                 => false,
				'provider'                => 'local',
				'methods'                 => array( 'local', 'bank_transfer' ),
				'default_method'          => 'local',
				'currency_position'       => 'currency',
				'thousand_separator'      => 'locale',
				'decimal_separator'       => 'locale',
				'number_of_decimals'      => 'currency',
				'collection_mode'         => 'none',
				'deposit_type'            => 'percent',
				'deposit_amount'          => 50,
				'local_title'             => __( 'Pay locally', 'yo-booking' ),
				'local_instructions'      => __( 'Pay at the business location when you attend your appointment.', 'yo-booking' ),
				'bank_transfer_title'     => __( 'Bank transfer', 'yo-booking' ),
				'bank_transfer_instructions' => __( 'Use your booking reference as the payment reference.', 'yo-booking' ),
				'bank_name'               => '',
				'bank_account_name'       => '',
				'bank_account_number'     => '',
				'bank_routing_number'     => '',
				'bank_iban'               => '',
				'bank_swift'              => '',
				'manual_title'            => __( 'Manual payment', 'yo-booking' ),
				'manual_instructions'     => __( 'Please pay offline using the instructions provided by the business.', 'yo-booking' ),
			),
			'appearance'    => array(
				'primary_color'          => '#2563eb',
				'accent_color'           => '#16a34a',
				'background_color'       => '#f7f8fb',
				'surface_color'          => '#ffffff',
				'text_color'             => '#1f2937',
				'muted_color'            => '#64748b',
				'border_color'           => '#d9dee8',
				'button_text_color'      => '#ffffff',
				'max_width'              => 920,
				'border_radius'          => 8,
				'density'               => 'comfortable',
				'shadow'                => 'subtle',
				'show_progress'         => true,
				'show_service_prices'   => true,
				'show_service_details'  => true,
				'booking_title'         => __( 'Book an appointment', 'yo-booking' ),
				'portal_title'          => __( 'My appointments', 'yo-booking' ),
				'manage_title'          => __( 'Manage appointment', 'yo-booking' ),
			),
			'privacy'       => array(
					'marketing_consent_required' => false,
			),
			'advanced'      => array(
				'remove_data_on_uninstall'          => false,
				'notification_log_retention_days'   => 90,
				'webhook_delivery_retention_days'   => 90,
				'audit_log_retention_days'          => 365,
			),
		);
	}
}
