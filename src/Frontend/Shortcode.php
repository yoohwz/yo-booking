<?php
/**
 * Frontend booking shortcode shell.
 *
 * @package YoBooking
 */

namespace YoBooking\Frontend;

use YoBooking\Support\PhoneNumber;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the public booking entry point.
 */
final class Shortcode {
	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
		add_shortcode( 'yo-booking', array( $this, 'render' ) );
		add_shortcode( 'yo-booking-portal', array( $this, 'render_portal' ) );
	}

	/**
	 * Register frontend assets.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style(
			'yo-booking-intl-tel-input',
			YO_BOOKING_URL . 'assets/vendor/intl-tel-input/css/intlTelInput.min.css',
			array(),
			'29.0.5'
		);

		wp_register_style(
			'yo-booking-phone-input',
			YO_BOOKING_URL . 'assets/css/phone-input.css',
			array( 'yo-booking-intl-tel-input' ),
			YO_BOOKING_VERSION
		);

		wp_register_style(
			'yo-booking-frontend',
			YO_BOOKING_URL . 'assets/css/frontend.css',
			array( 'yo-booking-phone-input' ),
			filemtime( YO_BOOKING_PATH . 'assets/css/frontend.css' )
		);

		wp_register_script(
			'yo-booking-intl-tel-input',
			YO_BOOKING_URL . 'assets/vendor/intl-tel-input/js/intlTelInputWithUtils.min.js',
			array(),
			'29.0.5',
			true
		);

		wp_register_script(
			'yo-booking-phone-input',
			YO_BOOKING_URL . 'assets/js/phone-input.js',
			array( 'yo-booking-intl-tel-input' ),
			YO_BOOKING_VERSION,
			true
		);

		wp_register_script(
			'yo-booking-frontend',
			YO_BOOKING_URL . 'assets/js/frontend.js',
			array( 'yo-booking-phone-input' ),
			filemtime( YO_BOOKING_PATH . 'assets/js/frontend.js' ),
			true
		);

		wp_localize_script(
			'yo-booking-phone-input',
			'YoBookingPhoneConfig',
			array(
				'defaultCountry' => PhoneNumber::default_country(),
				'invalidMessage' => __( 'Enter a valid phone number.', 'yo-booking' ),
				'rememberCountry' => true,
			)
		);

		wp_localize_script(
			'yo-booking-frontend',
			'YoBookingFrontend',
			array(
				'restUrl'  => esc_url_raw( rest_url( 'yo-booking/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'timezone' => wp_timezone_string() ? wp_timezone_string() : 'UTC',
				'today'    => current_time( 'Y-m-d' ),
				'appearance' => Appearance::frontend_config(),
				'i18n'     => self::frontend_strings(),
			)
		);

		wp_add_inline_style( 'yo-booking-frontend', Appearance::inline_css() );
	}

	/**
	 * Register the Gutenberg block.
	 *
	 * @return void
	 */
	public function register_block() {
		register_block_type(
			YO_BOOKING_PATH . 'block',
			array(
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * Enqueue block editor script.
	 *
	 * @return void
	 */
	public function enqueue_block_editor_assets() {
		wp_enqueue_script(
			'yo-booking-block-editor',
			YO_BOOKING_URL . 'block/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			filemtime( YO_BOOKING_PATH . 'block/block.js' ),
			true
		);
	}

	/**
	 * Render the booking app mount point.
	 *
	 * @param array|string $attributes Shortcode attributes.
	 * @return string
	 */
	public function render( $attributes = array() ) {
		$attributes = shortcode_atts(
			array(),
			(array) $attributes,
			'yo-booking'
		);

		wp_enqueue_style( 'yo-booking-frontend' );
		wp_enqueue_script( 'yo-booking-frontend' );

		return sprintf(
			'<div class="yo-booking-app" data-yo-booking-root="1" role="region"><div class="yo-booking-loading" role="status" aria-live="polite">%s</div></div>',
			esc_html__( 'Loading booking options...', 'yo-booking' )
		);
	}

	/**
	 * Render the logged-in customer portal mount point.
	 *
	 * @param array|string $attributes Shortcode attributes.
	 * @return string
	 */
	public function render_portal( $attributes = array() ) {
		$attributes = shortcode_atts(
			array(),
			(array) $attributes,
			'yo-booking-portal'
		);

		wp_enqueue_style( 'yo-booking-frontend' );
		wp_enqueue_script( 'yo-booking-frontend' );

		return sprintf(
			'<div class="yo-booking-app" data-yo-booking-root="1" data-yo-booking-mode="portal" role="region"><div class="yo-booking-loading" role="status" aria-live="polite">%s</div></div>',
			esc_html__( 'Loading your appointments...', 'yo-booking' )
		);
	}

	/** @return array */
	public static function frontend_strings() {
		return array(
			'Request failed.' => __( 'Request failed.', 'yo-booking' ),
			'Service' => __( 'Service', 'yo-booking' ), 'Staff' => __( 'Staff', 'yo-booking' ), 'Date' => __( 'Date', 'yo-booking' ), 'Time' => __( 'Time', 'yo-booking' ),
			'Details' => __( 'Details', 'yo-booking' ), 'Review' => __( 'Review', 'yo-booking' ), 'Name' => __( 'Name', 'yo-booking' ), 'Email' => __( 'Email', 'yo-booking' ), 'Phone' => __( 'Phone', 'yo-booking' ), 'Note' => __( 'Note', 'yo-booking' ),
			'Manage appointment' => __( 'Manage appointment', 'yo-booking' ), 'My appointments' => __( 'My appointments', 'yo-booking' ), 'Book an appointment' => __( 'Book an appointment', 'yo-booking' ), 'Booking progress' => __( 'Booking progress', 'yo-booking' ),
			'Loading...' => __( 'Loading...', 'yo-booking' ), 'Loading appointment...' => __( 'Loading appointment...', 'yo-booking' ), 'Loading your appointments...' => __( 'Loading your appointments...', 'yo-booking' ),
			'No services are available.' => __( 'No services are available.', 'yo-booking' ), 'No dates are available.' => __( 'No dates are available.', 'yo-booking' ), 'No times are available.' => __( 'No times are available.', 'yo-booking' ),
			'Choose a service' => __( 'Choose a service', 'yo-booking' ), 'Select the appointment type that best fits your needs.' => __( 'Select the appointment type that best fits your needs.', 'yo-booking' ),
			'Choose a staff member' => __( 'Choose a staff member', 'yo-booking' ), 'Select a preferred staff member or choose the first available option.' => __( 'Select a preferred staff member or choose the first available option.', 'yo-booking' ),
			'Choose a date' => __( 'Choose a date', 'yo-booking' ), 'Available dates are shown in the site timezone.' => __( 'Available dates are shown in the site timezone.', 'yo-booking' ), 'Today' => __( 'Today', 'yo-booking' ),
			// translators: %s: site timezone name.
			'Choose a time' => __( 'Choose a time', 'yo-booking' ), 'Times shown in %s' => __( 'Times shown in %s', 'yo-booking' ), 'Your details' => __( 'Your details', 'yo-booking' ), 'We will use these details to confirm and manage your appointment.' => __( 'We will use these details to confirm and manage your appointment.', 'yo-booking' ),
			'Review your booking' => __( 'Review your booking', 'yo-booking' ), 'Confirm the details below before submitting your appointment.' => __( 'Confirm the details below before submitting your appointment.', 'yo-booking' ), 'Booking details' => __( 'Booking details', 'yo-booking' ), 'Customer details' => __( 'Customer details', 'yo-booking' ), 'Total' => __( 'Total', 'yo-booking' ),
			// translators: 1: current step number, 2: total step count, 3: step label.
			'This field is required.' => __( 'This field is required.', 'yo-booking' ), 'Enter a valid email address.' => __( 'Enter a valid email address.', 'yo-booking' ), 'Enter a valid phone number.' => __( 'Enter a valid phone number.', 'yo-booking' ), 'Step %1$d of %2$d: %3$s' => __( 'Step %1$d of %2$d: %3$s', 'yo-booking' ),
			'Any available staff' => __( 'Any available staff', 'yo-booking' ), 'First matching appointment slot' => __( 'First matching appointment slot', 'yo-booking' ),
			'Name, email, and phone are required.' => __( 'Name, email, and phone are required.', 'yo-booking' ), 'Confirming...' => __( 'Confirming...', 'yo-booking' ), 'Confirm booking' => __( 'Confirm booking', 'yo-booking' ),
			'Appointment received' => __( 'Appointment received', 'yo-booking' ), 'Book another appointment' => __( 'Book another appointment', 'yo-booking' ), 'Appointment updated' => __( 'Appointment updated', 'yo-booking' ),
			'Your appointment has been received.' => __( 'Your appointment has been received.', 'yo-booking' ),
			'Upcoming' => __( 'Upcoming', 'yo-booking' ), 'Past' => __( 'Past', 'yo-booking' ), 'No upcoming appointments.' => __( 'No upcoming appointments.', 'yo-booking' ), 'No past appointments.' => __( 'No past appointments.', 'yo-booking' ),
			'Payment' => __( 'Payment', 'yo-booking' ), 'Payment method' => __( 'Payment method', 'yo-booking' ), 'Select a payment method.' => __( 'Select a payment method.', 'yo-booking' ), 'Status' => __( 'Status', 'yo-booking' ), 'Reschedule' => __( 'Reschedule', 'yo-booking' ), 'Cancel' => __( 'Cancel', 'yo-booking' ), 'Back' => __( 'Back', 'yo-booking' ),
			'Deposit due' => __( 'Deposit due', 'yo-booking' ), 'Payment due' => __( 'Payment due', 'yo-booking' ), 'No payment is due at booking.' => __( 'No payment is due at booking.', 'yo-booking' ),
			'Cancelling...' => __( 'Cancelling...', 'yo-booking' ), 'Cancel appointment' => __( 'Cancel appointment', 'yo-booking' ), 'This appointment can no longer be cancelled online.' => __( 'This appointment can no longer be cancelled online.', 'yo-booking' ), 'Reason' => __( 'Reason', 'yo-booking' ),
			'Review the appointment and optionally tell us why you are cancelling.' => __( 'Review the appointment and optionally tell us why you are cancelling.', 'yo-booking' ),
			'This appointment can no longer be rescheduled online.' => __( 'This appointment can no longer be rescheduled online.', 'yo-booking' ), 'Choose a new date' => __( 'Choose a new date', 'yo-booking' ), 'Choose a new time' => __( 'Choose a new time', 'yo-booking' ),
			'Rescheduling...' => __( 'Rescheduling...', 'yo-booking' ), 'Confirm new time' => __( 'Confirm new time', 'yo-booking' ), 'Current' => __( 'Current', 'yo-booking' ), 'New date' => __( 'New date', 'yo-booking' ), 'New time' => __( 'New time', 'yo-booking' ),
			'Review your new time' => __( 'Review your new time', 'yo-booking' ), 'Confirm the updated appointment details below.' => __( 'Confirm the updated appointment details below.', 'yo-booking' ),
			'Cancelled by customer.' => __( 'Cancelled by customer.', 'yo-booking' ), 'Your appointment has been cancelled.' => __( 'Your appointment has been cancelled.', 'yo-booking' ), 'Your appointment has been rescheduled.' => __( 'Your appointment has been rescheduled.', 'yo-booking' ),
			'Pending' => __( 'Pending', 'yo-booking' ), 'Confirmed' => __( 'Confirmed', 'yo-booking' ), 'Cancelled' => __( 'Cancelled', 'yo-booking' ), 'Completed' => __( 'Completed', 'yo-booking' ), 'No show' => __( 'No show', 'yo-booking' ), 'Rescheduled' => __( 'Rescheduled', 'yo-booking' ),
			'Unpaid' => __( 'Unpaid', 'yo-booking' ), 'Authorized' => __( 'Authorized', 'yo-booking' ), 'Failed' => __( 'Failed', 'yo-booking' ), 'Paid' => __( 'Paid', 'yo-booking' ), 'Partially paid' => __( 'Partially paid', 'yo-booking' ), 'Partially refunded' => __( 'Partially refunded', 'yo-booking' ), 'Refunded' => __( 'Refunded', 'yo-booking' ),
			// translators: %s: booking reference or payment status; %d: slot count or duration in minutes.
			'Reference #%s' => __( 'Reference #%s', 'yo-booking' ), 'Payment reference: %s' => __( 'Payment reference: %s', 'yo-booking' ), '%d slots' => __( '%d slots', 'yo-booking' ), '%d min' => __( '%d min', 'yo-booking' ), 'Payment: %s' => __( 'Payment: %s', 'yo-booking' ),
			'Sign in to book an appointment.' => __( 'Sign in to book an appointment.', 'yo-booking' ), 'I agree to receive marketing communications.' => __( 'I agree to receive marketing communications.', 'yo-booking' ), 'Marketing consent is required.' => __( 'Marketing consent is required.', 'yo-booking' ),
		);
	}
}
