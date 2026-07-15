<?php
/**
 * Frontend booking REST API.
 *
 * @package YoBooking
 */

namespace YoBooking\Rest;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use YoBooking\Repositories\AppointmentRepository;
use YoBooking\Repositories\CustomerRepository;
use YoBooking\Repositories\ServiceRepository;
use YoBooking\Repositories\StaffRepository;
use YoBooking\Repositories\StaffServiceRepository;
use YoBooking\Settings\Repository as SettingsRepository;
use YoBooking\Support\DateTimeFormatter;
use YoBooking\Support\ActionToken;
use YoBooking\Support\RateLimiter;
use YoBooking\Payments\PaymentManager;
use YoBooking\Payments\Currency;
use YoBooking\Payments\Money;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Registers frontend booking endpoints.
 */
final class BookingController {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'yo-booking/v1',
			'/booking/services',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'services' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'yo-booking/v1',
			'/booking/staff',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'staff' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'service_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'yo-booking/v1',
			'/booking/current-customer',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'current_customer' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'yo-booking/v1',
			'/booking/customer/appointments',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'customer_appointments' ),
				'permission_callback' => array( $this, 'logged_in_permission' ),
			)
		);

		register_rest_route(
			'yo-booking/v1',
			'/booking/appointments',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_appointment' ),
				'permission_callback' => array( $this, 'authorize_booking_write' ),
			)
		);

		register_rest_route(
			'yo-booking/v1',
			'/booking/appointments/(?P<uuid>[a-f0-9-]{36})/manage',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'manage_appointment' ),
				'permission_callback' => '__return_true',
				'args'                => $this->self_service_args(),
			)
		);

		register_rest_route(
			'yo-booking/v1',
			'/booking/appointments/(?P<uuid>[a-f0-9-]{36})/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_appointment' ),
				'permission_callback' => array( $this, 'authorize_self_service_write' ),
				'args'                => $this->self_service_args(),
			)
		);

		register_rest_route(
			'yo-booking/v1',
			'/booking/appointments/(?P<uuid>[a-f0-9-]{36})/reschedule',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reschedule_appointment' ),
				'permission_callback' => array( $this, 'authorize_self_service_write' ),
				'args'                => $this->self_service_args(),
			)
		);
	}

	/**
	 * Return active services.
	 *
	 * @return array
	 */
	public function services() {
		$services = array();

		foreach ( ( new ServiceRepository() )->active() as $service ) {
			$services[] = array(
				'id'                     => (int) $service->id,
				'name'                   => $service->name,
				'description'            => wp_strip_all_tags( (string) $service->description ),
				'duration_minutes'       => (int) $service->duration_minutes,
				'buffer_before_minutes'  => (int) $service->buffer_before_minutes,
				'buffer_after_minutes'   => (int) $service->buffer_after_minutes,
				'price'                  => (string) $service->price,
				'price_display'          => Currency::format( $service->price, $service->currency ),
				'currency'               => $service->currency,
				'capacity'               => (int) $service->capacity,
				'color'                  => $service->color,
			);
		}

		return array(
			'services' => $services,
			'payment'  => ( new PaymentManager() )->frontend_config(),
			'booking'  => array(
				'allow_guest_booking'   => ! empty( ( new SettingsRepository() )->get( 'booking.allow_guest_booking', true ) ),
				'allow_staff_selection' => ! empty( ( new SettingsRepository() )->get( 'booking.allow_staff_selection', true ) ),
				'require_email'         => ! empty( ( new SettingsRepository() )->get( 'booking.require_email', true ) ),
				'require_phone'         => ! empty( ( new SettingsRepository() )->get( 'booking.require_phone', true ) ),
				'marketing_consent_required' => ! empty( ( new SettingsRepository() )->get( 'privacy.marketing_consent_required', false ) ),
			),
		);
	}

	/**
	 * Return active staff assigned to a service.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function staff( WP_REST_Request $request ) {
		$service_id = absint( $request->get_param( 'service_id' ) );
		$ids        = ( new StaffServiceRepository() )->staff_ids_for_service( $service_id );
		$staff      = array();

		foreach ( ( new StaffRepository() )->active_by_ids( $ids ) as $member ) {
			$staff[] = array(
				'id'    => (int) $member->id,
				'name'  => $member->name,
				'bio'   => wp_strip_all_tags( (string) $member->bio ),
				'color' => $member->color,
			);
		}

		return array(
			'staff'             => $staff,
			'any_staff_allowed' => true,
		);
	}

	/**
	 * Return logged-in customer defaults for autofill.
	 *
	 * @return array
	 */
	public function current_customer() {
		if ( ! is_user_logged_in() ) {
			return array(
				'logged_in' => false,
				'customer'  => null,
			);
		}

		$user     = wp_get_current_user();
		$customers = new CustomerRepository();
		$customer  = $customers->find_by_user_id( (int) $user->ID );
		$customer  = $customer ? $customer : $customers->find_by_contact( $user->user_email, '' );
		$name     = trim( $user->first_name . ' ' . $user->last_name );

		if ( '' === $name ) {
			$name = $user->display_name;
		}

		return array(
			'logged_in' => true,
				'customer'  => array(
					'name'  => $customer ? $customer->name : $name,
					'email' => $customer ? $customer->email : $user->user_email,
					'phone' => $customer ? $customer->phone : '',
					'marketing_consent' => $customer ? ! empty( $customer->marketing_consent ) : false,
				),
		);
	}

	/**
	 * Create a frontend appointment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function create_appointment( WP_REST_Request $request ) {
		$settings = ( new SettingsRepository() )->all();

		if ( ! is_user_logged_in() && empty( $settings['booking']['allow_guest_booking'] ) ) {
			return new WP_Error( 'yo_booking_guest_booking_disabled', __( 'Guest booking is disabled.', 'yo-booking' ), array( 'status' => 403 ) );
		}

		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$email = isset( $params['customer_email'] ) ? $this->limited_text( sanitize_email( $params['customer_email'] ), 191 ) : '';
		$phone = isset( $params['customer_phone'] ) ? sanitize_text_field( $params['customer_phone'] ) : '';
		$service_id = isset( $params['service_id'] ) ? absint( $params['service_id'] ) : 0;
		$service = ( new ServiceRepository() )->find( $service_id );
		$payment_method = $service && Money::to_minor( $service->price, $service->currency ) > 0 ? ( new PaymentManager() )->validate_method( isset( $params['payment_method'] ) ? $params['payment_method'] : '', $service->currency, $service->price ) : '';

		if ( is_wp_error( $payment_method ) ) {
			return $payment_method;
		}

		if ( ! empty( $settings['booking']['require_email'] ) && '' === $email ) {
			return new WP_Error( 'yo_booking_customer_email_required', __( 'Customer email is required.', 'yo-booking' ), array( 'status' => 400 ) );
		}

		if ( '' !== $email && ! is_email( $email ) ) {
			return new WP_Error( 'yo_booking_customer_email_invalid', __( 'Customer email is invalid.', 'yo-booking' ), array( 'status' => 400 ) );
		}

		if ( ! empty( $settings['booking']['require_phone'] ) && '' === $phone ) {
			return new WP_Error( 'yo_booking_customer_phone_required', __( 'Customer phone is required.', 'yo-booking' ), array( 'status' => 400 ) );
		}

		$marketing_consent = ! empty( $params['marketing_consent'] );
		if ( ! empty( $settings['privacy']['marketing_consent_required'] ) && ! $marketing_consent ) {
			return new WP_Error( 'yo_booking_marketing_consent_required', __( 'Marketing consent is required.', 'yo-booking' ), array( 'status' => 400 ) );
		}

		$data = array(
			'service_id'       => $service_id,
			'staff_id'         => isset( $params['staff_id'] ) ? absint( $params['staff_id'] ) : 0,
			'customer_user_id' => is_user_logged_in() ? get_current_user_id() : 0,
			'customer_name'    => isset( $params['customer_name'] ) ? $this->limited_text( $params['customer_name'], 191 ) : '',
			'customer_email'   => $email,
				'customer_phone'   => $this->limited_text( $phone, 50 ),
				'marketing_consent' => $marketing_consent,
			'date'             => isset( $params['date'] ) ? sanitize_text_field( $params['date'] ) : '',
			'start_time'       => isset( $params['start_time'] ) ? sanitize_text_field( $params['start_time'] ) : '',
			'duration_minutes' => isset( $params['duration_minutes'] ) ? absint( $params['duration_minutes'] ) : 0,
			'timezone'         => DateTimeFormatter::timezone_name(),
			'status'           => $settings['booking']['default_status'],
			'payment_status'   => 'pending',
			'payment_method'   => $payment_method,
			'customer_note'    => isset( $params['customer_note'] ) ? wp_html_excerpt( wp_kses_post( $params['customer_note'] ), 2000, '' ) : '',
			'source'           => 'frontend',
		);

		$appointment_id = ( new AppointmentRepository() )->save( $data );

		if ( is_wp_error( $appointment_id ) ) {
			return $appointment_id;
		}

		$appointment = ( new AppointmentRepository() )->find_with_details( $appointment_id );

		$response = array(
			'appointment_id' => (int) $appointment_id,
			'status'         => $appointment ? $appointment->status : $settings['booking']['default_status'],
			'payment'        => $appointment ? ( new PaymentManager() )->summary_for_appointment( $appointment ) : null,
			'message'        => __( 'Your appointment has been received.', 'yo-booking' ),
		);

			if ( $appointment ) {
			$response['appointment']    = $this->appointment_payload( $appointment );
			$response['manage_url']     = $this->action_url( $appointment, 'reschedule' );
			$response['cancel_url']     = $this->action_url( $appointment, 'cancel' );
				do_action( 'yo_booking_payment_method_selected', $appointment_id, $payment_method, $appointment );
				$checkout = ( new PaymentManager() )->create_checkout(
					$appointment,
					array(
						'return_url' => $response['manage_url'],
						'cancel_url' => $response['cancel_url'],
					)
				);
				if ( is_wp_error( $checkout ) ) {
					$response['payment_error'] = $checkout->get_error_message();
				} elseif ( ! empty( $checkout['checkout_url'] ) ) {
					$response['checkout_url'] = $checkout['checkout_url'];
				}
			}

		return apply_filters( 'yo_booking_booking_response', $response, $appointment, $params );
	}

	/**
	 * Return logged-in customer booking history.
	 *
	 * @return array|WP_Error
	 */
	public function customer_appointments() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'yo_booking_login_required', __( 'You must be logged in to view appointments.', 'yo-booking' ), array( 'status' => 401 ) );
		}

		$user         = wp_get_current_user();
		$appointments = ( new AppointmentRepository() )->for_customer_user( (int) $user->ID, $user->user_email, 100 );
		$upcoming     = array();
		$past         = array();
		$now          = time();

		foreach ( $appointments as $appointment ) {
			$payload = $this->appointment_payload( $appointment );
			$payload['cancel_url']     = $this->action_url( $appointment, 'cancel' );
			$payload['reschedule_url'] = $this->action_url( $appointment, 'reschedule' );

			if ( strtotime( $appointment->start_at . ' UTC' ) >= $now && in_array( $appointment->status, array( 'pending', 'confirmed' ), true ) ) {
				$upcoming[] = $payload;
				continue;
			}

			$past[] = $payload;
		}

		return array(
			'appointments' => array(
				'upcoming' => $upcoming,
				'past'     => $past,
			),
		);
	}

	/**
	 * Return public self-service appointment details.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function manage_appointment( WP_REST_Request $request ) {
		$appointment = $this->appointment_from_request( $request, sanitize_key( (string) $request->get_param( 'action' ) ) );

		if ( is_wp_error( $appointment ) ) {
			return $appointment;
		}

		return array(
			'appointment' => $this->appointment_payload( $appointment ),
		);
	}

	/**
	 * Cancel a public self-service appointment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function cancel_appointment( WP_REST_Request $request ) {
		$appointment = $this->appointment_from_request( $request, 'cancel' );

		if ( is_wp_error( $appointment ) ) {
			return $appointment;
		}

		$allowed = $this->action_allowed( $appointment, 'cancel' );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$params = $this->request_params( $request );
		$reason = isset( $params['reason'] ) ? sanitize_text_field( $params['reason'] ) : __( 'Cancelled by customer.', 'yo-booking' );
		$result = ( new AppointmentRepository() )->update_status( (int) $appointment->id, 'cancelled', $reason );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$appointment = ( new AppointmentRepository() )->find_with_details( (int) $appointment->id );

		return array(
			'appointment' => $this->appointment_payload( $appointment ),
			'message'     => __( 'Your appointment has been cancelled.', 'yo-booking' ),
		);
	}

	/**
	 * Reschedule a public self-service appointment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function reschedule_appointment( WP_REST_Request $request ) {
		$appointments = new AppointmentRepository();
		$appointment  = $this->appointment_from_request( $request, 'reschedule' );

		if ( is_wp_error( $appointment ) ) {
			return $appointment;
		}

		$allowed = $this->action_allowed( $appointment, 'reschedule' );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$params     = $this->request_params( $request );
		$date       = isset( $params['date'] ) ? sanitize_text_field( $params['date'] ) : '';
		$start_time = isset( $params['start_time'] ) ? sanitize_text_field( $params['start_time'] ) : '';
		$staff_id   = isset( $params['staff_id'] ) ? absint( $params['staff_id'] ) : (int) $appointment->staff_id;
		$duration   = $this->appointment_duration_minutes( $appointment );

		$result = $appointments->save(
			array(
				'id'               => (int) $appointment->id,
				'service_id'       => (int) $appointment->service_id,
				'staff_id'         => $staff_id,
				'customer_id'      => (int) $appointment->customer_id,
				'customer_name'    => (string) $appointment->customer_name,
				'customer_email'   => (string) $appointment->customer_email,
				'customer_phone'   => (string) $appointment->customer_phone,
				'date'             => $date,
				'start_time'       => $start_time,
				'duration_minutes' => $duration,
				'timezone'         => (string) $appointment->timezone,
				'status'           => (string) $appointment->status,
				'payment_status'   => (string) $appointment->payment_status,
				'total_amount'     => (string) $appointment->total_amount,
				'currency'         => (string) $appointment->currency,
				'customer_note'    => (string) $appointment->customer_note,
				'internal_note'    => (string) $appointment->internal_note,
				'source'           => (string) $appointment->source,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$appointment = $appointments->find_with_details( (int) $appointment->id );

		return array(
			'appointment' => $this->appointment_payload( $appointment ),
			'message'     => __( 'Your appointment has been rescheduled.', 'yo-booking' ),
		);
	}

	/**
	 * Verify the REST nonce for booking writes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function verify_nonce( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'x_wp_nonce' );

		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/** @param WP_REST_Request $request Request. @return true|WP_Error */
	public function authorize_booking_write( WP_REST_Request $request ) {
		if ( ! $this->verify_nonce( $request ) ) {
			return new WP_Error( 'yo_booking_rest_nonce_invalid', __( 'The booking session has expired. Refresh the page and try again.', 'yo-booking' ), array( 'status' => 403 ) );
		}

		return ( new RateLimiter() )->consume( 'booking_create', 5, 10 * MINUTE_IN_SECONDS );
	}

	/** @param WP_REST_Request $request Request. @return true|WP_Error */
	public function authorize_self_service_write( WP_REST_Request $request ) {
		$uuid = sanitize_text_field( (string) $request->get_param( 'uuid' ) );
		return ( new RateLimiter() )->consume( 'self_service_write', 10, 10 * MINUTE_IN_SECONDS, $uuid );
	}

	/**
	 * Verify a logged-in REST request.
	 *
	 * @return true|WP_Error
	 */
	public function logged_in_permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'yo_booking_login_required', __( 'Please sign in to view your appointments.', 'yo-booking' ), array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * Return common self-service REST args.
	 *
	 * @return array
	 */
	private function self_service_args() {
		return array(
			'uuid'  => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'token' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'action' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}

	/**
	 * Resolve and authorize a self-service appointment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return object|WP_Error
	 */
	private function appointment_from_request( WP_REST_Request $request, $purpose = 'manage' ) {
		$uuid        = sanitize_text_field( (string) $request->get_param( 'uuid' ) );
		$appointment = ( new AppointmentRepository() )->find_with_details_by_uuid( $uuid );

		if ( ! $appointment ) {
			return new WP_Error( 'yo_booking_appointment_not_found', __( 'Appointment not found.', 'yo-booking' ), array( 'status' => 404 ) );
		}

		if ( ! ActionToken::verify( $appointment, (string) $request->get_param( 'token' ), $purpose ? $purpose : 'manage' ) ) {
			return new WP_Error( 'yo_booking_appointment_token_invalid', __( 'The appointment link is invalid.', 'yo-booking' ), array( 'status' => 403 ) );
		}

		return $appointment;
	}

	/**
	 * Normalize request JSON/body params.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private function request_params( WP_REST_Request $request ) {
		$params = $request->get_json_params();

		return is_array( $params ) ? $params : $request->get_params();
	}

	/**
	 * Return public appointment payload.
	 *
	 * @param object $appointment Appointment row with details.
	 * @return array
	 */
	private function appointment_payload( $appointment ) {
		$timezone    = DateTimeFormatter::timezone();
		$start_local = $this->local_datetime( $appointment->start_at, $timezone );
		$end_local   = $this->local_datetime( $appointment->end_at, $timezone );

		return array(
			'id'                       => (int) $appointment->id,
			'uuid'                     => (string) $appointment->uuid,
			'status'                   => (string) $appointment->status,
			'service_id'               => (int) $appointment->service_id,
			'service_name'             => (string) $appointment->service_name,
			'staff_id'                 => (int) $appointment->staff_id,
			'staff_name'               => (string) $appointment->staff_name,
			'customer_name'            => (string) $appointment->customer_name,
			'customer_email'           => (string) $appointment->customer_email,
			'customer_phone'           => (string) $appointment->customer_phone,
			'timezone'                 => $timezone->getName(),
			'date'                     => $start_local->format( 'Y-m-d' ),
			'start_time'               => $start_local->format( 'H:i' ),
			'end_time'                 => $end_local->format( 'H:i' ),
			'date_display'             => DateTimeFormatter::timestamp( $start_local->getTimestamp(), 'date' ),
			'start_time_display'       => DateTimeFormatter::timestamp( $start_local->getTimestamp(), 'time' ),
			'end_time_display'         => DateTimeFormatter::timestamp( $end_local->getTimestamp(), 'time' ),
			'datetime_display'         => DateTimeFormatter::timestamp( $start_local->getTimestamp() ),
			'duration_minutes'         => $this->appointment_duration_minutes( $appointment ),
			'total_amount'             => isset( $appointment->total_amount ) ? (string) $appointment->total_amount : '0.00',
			'currency'                 => isset( $appointment->currency ) ? (string) $appointment->currency : '',
			'payment_status'           => isset( $appointment->payment_status ) ? (string) $appointment->payment_status : 'pending',
			'payment_method'           => isset( $appointment->payment_method ) ? (string) $appointment->payment_method : '',
			'payment_reference'        => isset( $appointment->payment_reference ) ? (string) $appointment->payment_reference : '',
			'paid_amount'              => isset( $appointment->paid_amount ) ? (string) $appointment->paid_amount : '0',
			'refunded_amount'          => isset( $appointment->refunded_amount ) ? (string) $appointment->refunded_amount : '0',
			'balance_amount'           => isset( $appointment->balance_amount ) ? (string) $appointment->balance_amount : (string) $appointment->total_amount,
			'total_display'            => Currency::format( $appointment->total_amount, $appointment->currency ),
			'balance_display'          => Currency::format( isset( $appointment->balance_amount ) ? $appointment->balance_amount : $appointment->total_amount, $appointment->currency ),
			'payment'                  => ( new PaymentManager() )->summary_for_appointment( $appointment ),
			'can_cancel'               => ! is_wp_error( $this->action_allowed( $appointment, 'cancel' ) ),
			'can_reschedule'           => ! is_wp_error( $this->action_allowed( $appointment, 'reschedule' ) ),
			'cancellation_window_hours' => absint( ( new SettingsRepository() )->get( 'booking.cancellation_window_hours', 24 ) ),
			'reschedule_window_hours'   => absint( ( new SettingsRepository() )->get( 'booking.reschedule_window_hours', 24 ) ),
		);
	}

	/**
	 * Create a tokenized public action URL for an appointment.
	 *
	 * @param object $appointment Appointment row with details.
	 * @param string $action Action key.
	 * @return string
	 */
	private function action_url( $appointment, $action ) {
		return add_query_arg(
			array(
				'yo_booking_action' => sanitize_key( $action ),
				'appointment'       => rawurlencode( (string) $appointment->uuid ),
					'token'             => rawurlencode( ActionToken::generate( $appointment, $action ) ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Check whether a self-service action is currently allowed.
	 *
	 * @param object $appointment Appointment row.
	 * @param string $action Action key.
	 * @return true|WP_Error
	 */
	private function action_allowed( $appointment, $action ) {
		if ( ! in_array( $appointment->status, array( 'pending', 'confirmed' ), true ) ) {
			return new WP_Error( 'yo_booking_appointment_action_closed', __( 'This appointment can no longer be changed.', 'yo-booking' ), array( 'status' => 409 ) );
		}

		$settings = ( new SettingsRepository() )->all();
		$hours    = 'cancel' === $action ? absint( $settings['booking']['cancellation_window_hours'] ) : absint( $settings['booking']['reschedule_window_hours'] );
		$cutoff   = strtotime( $appointment->start_at . ' UTC' ) - ( $hours * HOUR_IN_SECONDS );

		if ( time() > $cutoff ) {
			return new WP_Error( 'yo_booking_appointment_action_window_closed', __( 'The change window for this appointment has closed.', 'yo-booking' ), array( 'status' => 409 ) );
		}

		return true;
	}

	/**
	 * Return appointment duration in minutes.
	 *
	 * @param object $appointment Appointment row.
	 * @return int
	 */
	private function appointment_duration_minutes( $appointment ) {
		return max( 5, (int) round( ( strtotime( $appointment->end_at . ' UTC' ) - strtotime( $appointment->start_at . ' UTC' ) ) / 60 ) );
	}

	/**
	 * Convert a UTC datetime to local time.
	 *
	 * @param string       $datetime UTC datetime.
	 * @param DateTimeZone $timezone Timezone.
	 * @return DateTimeImmutable
	 */
	private function local_datetime( $datetime, DateTimeZone $timezone ) {
		try {
			return ( new DateTimeImmutable( $datetime, new DateTimeZone( 'UTC' ) ) )->setTimezone( $timezone );
		} catch ( Exception $exception ) {
			return new DateTimeImmutable( 'now', $timezone );
		}
	}

	/**
	 * Resolve a timezone safely.
	 *
	 * @param string $timezone Timezone name.
	 * @return DateTimeZone
	 */
	private function timezone( $timezone ) {
		try {
			return new DateTimeZone( $timezone ? $timezone : 'UTC' );
		} catch ( Exception $exception ) {
			return new DateTimeZone( 'UTC' );
		}
	}

	/** @param mixed $value Raw value. @param int $length Maximum characters. @return string */
	private function limited_text( $value, $length ) {
		$value = sanitize_text_field( $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}
}
