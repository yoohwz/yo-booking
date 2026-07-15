<?php
/**
 * Admin assets.
 *
 * @package YoBooking
 */

namespace YoBooking\Admin;

use YoBooking\Repositories\AppointmentRepository;
use YoBooking\Repositories\PaymentRepository;
use YoBooking\Repositories\ServiceRepository;
use YoBooking\Repositories\StaffRepository;
use YoBooking\Settings\Repository as SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the shared Yo Booking admin interface assets.
 */
final class AdminAssets {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue assets only on Yo Booking screens.
	 *
	 * @param string $hook_suffix Current admin hook suffix.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		wp_enqueue_style(
			'yo-booking-uicons',
			YO_BOOKING_URL . 'assets/vendor/uicons/css/regular/rounded.css',
			array(),
			'3.3.1'
		);

		wp_enqueue_style(
			'yo-booking-admin-menu',
			YO_BOOKING_URL . 'assets/css/admin-menu.css',
			array( 'yo-booking-uicons' ),
			YO_BOOKING_VERSION
		);

		if ( false === strpos( (string) $hook_suffix, 'yo-booking' ) ) {
			return;
		}

		wp_enqueue_style(
			'yo-booking-admin',
			YO_BOOKING_URL . 'assets/css/admin.css',
			array( 'yo-booking-uicons' ),
			YO_BOOKING_VERSION
		);

		wp_enqueue_script(
			'yo-booking-admin',
			YO_BOOKING_URL . 'assets/js/admin.js',
			array(),
			YO_BOOKING_VERSION,
			true
		);

		wp_localize_script(
			'yo-booking-admin',
			'YoBookingAdmin',
				array(
					'confirmDelete' => __( 'Delete this record? This action cannot be undone.', 'yo-booking' ),
					'copied'        => __( 'Copied', 'yo-booking' ),
					'copyFailed'    => __( 'Copy failed', 'yo-booking' ),
				)
		);

		if ( false !== strpos( (string) $hook_suffix, 'yo-booking-appearance' ) ) {
			wp_enqueue_style(
				'yo-booking-frontend-preview',
				YO_BOOKING_URL . 'assets/css/frontend.css',
				array(),
				YO_BOOKING_VERSION
			);
			wp_enqueue_script(
				'yo-booking-admin-appearance',
				YO_BOOKING_URL . 'assets/js/admin-appearance.js',
				array( 'yo-booking-admin' ),
				YO_BOOKING_VERSION,
				true
			);
		}

		if ( false === strpos( (string) $hook_suffix, 'yo-booking-appointments' ) ) {
			return;
		}

		wp_enqueue_script(
			'yo-booking-fullcalendar',
			YO_BOOKING_URL . 'assets/vendor/fullcalendar/index.global.min.js',
			array(),
			'6.1.21',
			true
		);

		wp_enqueue_script(
			'yo-booking-admin-calendar',
			YO_BOOKING_URL . 'assets/js/admin-calendar.js',
			array( 'yo-booking-admin', 'yo-booking-fullcalendar' ),
			YO_BOOKING_VERSION,
			true
		);

		$services = array_map(
			static function ( $service ) {
				return array( 'id' => (int) $service->id, 'name' => $service->name );
			},
			( new ServiceRepository() )->all()
		);
		$staff = array_map(
			static function ( $member ) {
				return array( 'id' => (int) $member->id, 'name' => $member->name );
			},
			( new StaffRepository() )->all()
		);

		wp_localize_script(
			'yo-booking-admin-calendar',
			'YoBookingCalendar',
			array(
				'restRoot'        => esc_url_raw( rest_url( 'yo-booking/v1/admin/' ) ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'timezone'        => ( new SettingsRepository() )->get( 'company.timezone', wp_timezone_string() ),
				'hour12'          => false !== strpos( (string) get_option( 'time_format' ), 'a' ) || false !== strpos( (string) get_option( 'time_format' ), 'A' ),
				'addUrl'          => admin_url( 'admin.php?page=yo-booking-appointments&action=new' ),
				'editUrl'         => admin_url( 'admin.php?page=yo-booking-appointments&edit=' ),
				'statuses'        => AppointmentRepository::statuses(),
				'paymentStatuses' => PaymentRepository::appointment_statuses(),
				'paymentActions'  => array(
					'paid'           => __( 'Record payment', 'yo-booking' ),
					'partially_paid' => __( 'Record partial payment', 'yo-booking' ),
					'authorized'     => __( 'Record authorization', 'yo-booking' ),
					'failed'         => __( 'Record failure', 'yo-booking' ),
					'cancelled'      => __( 'Record cancellation', 'yo-booking' ),
					'refunded'       => __( 'Record refund', 'yo-booking' ),
				),
				'services'        => $services,
				'staff'           => $staff,
				'messages'        => array(
					'loading'           => __( 'Loading appointment...', 'yo-booking' ),
					'updated'           => __( 'Appointment updated.', 'yo-booking' ),
					'confirmReschedule' => __( 'Move this appointment to the selected time?', 'yo-booking' ),
					'selectRows'        => __( 'Select at least one appointment.', 'yo-booking' ),
					'error'             => __( 'The request could not be completed.', 'yo-booking' ),
				),
			)
		);
	}
}
