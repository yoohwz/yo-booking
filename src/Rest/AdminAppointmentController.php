<?php
/**
 * Admin appointment REST API.
 *
 * @package YoBooking
 */

namespace YoBooking\Rest;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use YoBooking\Payments\Currency;
use YoBooking\Payments\Money;
use YoBooking\Payments\PaymentManager;
use YoBooking\Repositories\AppointmentRepository;
use YoBooking\Repositories\CustomerRepository;
use YoBooking\Repositories\NotificationLogRepository;
use YoBooking\Repositories\PaymentRepository;
use YoBooking\Settings\Repository as SettingsRepository;
use YoBooking\Support\DateTimeFormatter;
use YoBooking\Support\Capabilities;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Provides capability-protected appointment operations for wp-admin.
 */
final class AdminAppointmentController {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register admin routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$permission = array( $this, 'permission' );

		register_rest_route( 'yo-booking/v1', '/admin/appointments', array( 'methods' => 'GET', 'callback' => array( $this, 'events' ), 'permission_callback' => $permission ) );
		register_rest_route( 'yo-booking/v1', '/admin/appointments/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( $this, 'appointment' ), 'permission_callback' => $permission ) );
		register_rest_route( 'yo-booking/v1', '/admin/appointments/(?P<id>\d+)/status', array( 'methods' => 'POST', 'callback' => array( $this, 'update_status' ), 'permission_callback' => $permission ) );
		register_rest_route( 'yo-booking/v1', '/admin/appointments/(?P<id>\d+)/payment', array( 'methods' => 'POST', 'callback' => array( $this, 'update_payment' ), 'permission_callback' => $permission ) );
		register_rest_route( 'yo-booking/v1', '/admin/appointments/(?P<id>\d+)/note', array( 'methods' => 'POST', 'callback' => array( $this, 'update_note' ), 'permission_callback' => $permission ) );
		register_rest_route( 'yo-booking/v1', '/admin/appointments/(?P<id>\d+)/reschedule', array( 'methods' => 'POST', 'callback' => array( $this, 'reschedule' ), 'permission_callback' => $permission ) );
		register_rest_route( 'yo-booking/v1', '/admin/appointments/bulk-status', array( 'methods' => 'POST', 'callback' => array( $this, 'bulk_status' ), 'permission_callback' => $permission ) );
		register_rest_route( 'yo-booking/v1', '/admin/customers', array( 'methods' => 'GET', 'callback' => array( $this, 'customers' ), 'permission_callback' => array( $this, 'customer_permission' ) ) );
	}

	/**
	 * Require booking management capability.
	 *
	 * @return bool
	 */
	public function permission() {
		return current_user_can( Capabilities::appointments() );
	}

	/** @return bool */
	public function customer_permission() {
		return current_user_can( Capabilities::manage() );
	}

	/**
	 * Return FullCalendar events.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function events( WP_REST_Request $request ) {
		$timezone_name = DateTimeFormatter::timezone_name();
		try {
			$calendar_timezone = new DateTimeZone( $timezone_name );
		} catch ( Exception $exception ) {
			$calendar_timezone = new DateTimeZone( 'UTC' );
		}
		$args = array(
			'from'           => $this->local_mysql_to_utc( $request->get_param( 'start' ), $calendar_timezone ),
			'to'             => $this->local_mysql_to_utc( $request->get_param( 'end' ), $calendar_timezone ),
			'status'         => sanitize_key( $request->get_param( 'status' ) ),
			'service_id'     => absint( $request->get_param( 'service_id' ) ),
			'staff_id'       => absint( $request->get_param( 'staff_id' ) ),
			'payment_status' => sanitize_key( $request->get_param( 'payment_status' ) ),
			'limit'          => 500,
		);
		$events = array();

		foreach ( ( new AppointmentRepository() )->all( $args ) as $appointment ) {
			$color    = sanitize_hex_color( $appointment->service_color ? $appointment->service_color : $appointment->staff_color );
			$start    = ( new DateTimeImmutable( $appointment->start_at, new DateTimeZone( 'UTC' ) ) )->setTimezone( $calendar_timezone );
			$end      = ( new DateTimeImmutable( $appointment->end_at, new DateTimeZone( 'UTC' ) ) )->setTimezone( $calendar_timezone );
			$events[] = array(
				'id'              => (string) $appointment->id,
				'title'           => trim( $appointment->customer_name . ' - ' . $appointment->service_name, ' -' ),
				'start'           => $start->format( 'Y-m-d\TH:i:s' ),
				'end'             => $end->format( 'Y-m-d\TH:i:s' ),
				'backgroundColor' => $color ? $color : '#2271b1',
				'borderColor'     => $color ? $color : '#2271b1',
				'editable'        => in_array( $appointment->status, AppointmentRepository::blocking_statuses(), true ),
				'extendedProps'   => array(
					'status'         => $appointment->status,
					'paymentStatus'  => $appointment->payment_status,
					'service'        => $appointment->service_name,
					'staff'          => $appointment->staff_name,
					'customer'       => $appointment->customer_name,
				),
			);
		}

		return $events;
	}

	/**
	 * Return one appointment with operational history.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function appointment( WP_REST_Request $request ) {
		$appointment = ( new AppointmentRepository() )->find_with_details( absint( $request['id'] ) );

		if ( ! $appointment ) {
			return new WP_Error( 'yo_booking_appointment_not_found', __( 'Appointment not found.', 'yo-booking' ), array( 'status' => 404 ) );
		}

		$payments = array_map( array( $this, 'payment_payload' ), ( new PaymentRepository() )->for_appointment( (int) $appointment->id ) );
		$logs     = array_map( array( $this, 'log_payload' ), ( new NotificationLogRepository() )->for_appointment( (int) $appointment->id ) );

		return array(
			'appointment' => $this->appointment_payload( $appointment ),
			'payment'     => ( new PaymentManager() )->summary_for_appointment( $appointment ),
			'payments'    => $payments,
			'notifications' => $logs,
		);
	}

	/**
	 * Update one lifecycle status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function update_status( WP_REST_Request $request ) {
		$params = $this->params( $request );
		$result = ( new AppointmentRepository() )->update_status( absint( $request['id'] ), isset( $params['status'] ) ? $params['status'] : '', isset( $params['reason'] ) ? $params['reason'] : '' );

		return is_wp_error( $result ) ? $result : array( 'updated' => true, 'status' => sanitize_key( $params['status'] ) );
	}

	/**
	 * Update payment status and add an audit record.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function update_payment( WP_REST_Request $request ) {
		$appointments = new AppointmentRepository();
		$appointment  = $appointments->find( absint( $request['id'] ) );

		if ( ! $appointment ) {
			return new WP_Error( 'yo_booking_appointment_not_found', __( 'Appointment not found.', 'yo-booking' ), array( 'status' => 404 ) );
		}

		$params = $this->params( $request );
		$status = isset( $params['status'] ) ? sanitize_key( $params['status'] ) : 'pending';
		$amount = isset( $params['amount'] ) && isset( $params['amount_format'] ) && 'localized' === $params['amount_format']
			? Currency::parse_number( $params['amount'], $appointment->currency )
			: Money::normalize( isset( $params['amount'] ) ? $params['amount'] : $appointment->total_amount, $appointment->currency );
		$note   = isset( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : __( 'Updated from appointment details.', 'yo-booking' );
		$result = ( new PaymentRepository() )->mark_appointment(
			(int) $appointment->id,
			$status,
			$amount,
			$appointment->currency,
			$note,
			array(
				'transaction_id' => isset( $params['transaction_id'] ) ? sanitize_text_field( $params['transaction_id'] ) : '',
				'idempotency_key' => isset( $params['idempotency_key'] ) ? sanitize_text_field( $params['idempotency_key'] ) : '',
			)
		);

		$updated = $appointments->find( (int) $appointment->id );
		return is_wp_error( $result ) ? $result : array( 'updated' => true, 'status' => $updated->payment_status );
	}

	/**
	 * Update internal note.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function update_note( WP_REST_Request $request ) {
		$params = $this->params( $request );
		$result = ( new AppointmentRepository() )->update_internal_note( absint( $request['id'] ), isset( $params['note'] ) ? $params['note'] : '' );

		return is_wp_error( $result ) ? $result : array( 'updated' => true );
	}

	/**
	 * Reschedule through the repository conflict checks.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function reschedule( WP_REST_Request $request ) {
		$repository  = new AppointmentRepository();
		$appointment = $repository->find_with_details( absint( $request['id'] ) );

		if ( ! $appointment ) {
			return new WP_Error( 'yo_booking_appointment_not_found', __( 'Appointment not found.', 'yo-booking' ), array( 'status' => 404 ) );
		}

		$params = $this->params( $request );

		try {
			$timezone = DateTimeFormatter::timezone();
			if ( ! empty( $params['local_start'] ) ) {
				$start_local = new DateTimeImmutable( sanitize_text_field( $params['local_start'] ), $timezone );
				$end_local   = ! empty( $params['local_end'] ) ? new DateTimeImmutable( sanitize_text_field( $params['local_end'] ), $timezone ) : null;
			} else {
				$start_local = ( new DateTimeImmutable( isset( $params['start'] ) ? $params['start'] : '', new DateTimeZone( 'UTC' ) ) )->setTimezone( $timezone );
				$end_local   = ! empty( $params['end'] ) ? ( new DateTimeImmutable( $params['end'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $timezone ) : null;
			}
		} catch ( Exception $exception ) {
			return new WP_Error( 'yo_booking_appointment_time_invalid', __( 'A valid appointment date and time are required.', 'yo-booking' ), array( 'status' => 400 ) );
		}

		$duration = $end_local ? max( 5, (int) round( ( $end_local->getTimestamp() - $start_local->getTimestamp() ) / 60 ) ) : max( 5, (int) round( ( strtotime( $appointment->end_at . ' UTC' ) - strtotime( $appointment->start_at . ' UTC' ) ) / 60 ) );
		$result   = $repository->save(
			array(
				'id'               => (int) $appointment->id,
				'service_id'       => (int) $appointment->service_id,
				'staff_id'         => (int) $appointment->staff_id,
				'customer_id'      => (int) $appointment->customer_id,
				'customer_name'    => (string) $appointment->customer_name,
				'customer_email'   => (string) $appointment->customer_email,
				'customer_phone'   => (string) $appointment->customer_phone,
				'customer_phone_country' => (string) $appointment->customer_phone_country,
				'date'             => $start_local->format( 'Y-m-d' ),
				'start_time'       => $start_local->format( 'H:i' ),
				'duration_minutes' => $duration,
				'timezone'         => $timezone->getName(),
				'status'           => (string) $appointment->status,
				'payment_status'   => (string) $appointment->payment_status,
				'payment_method'   => (string) $appointment->payment_method,
				'total_amount'     => (string) $appointment->total_amount,
				'currency'         => (string) $appointment->currency,
				'customer_note'    => (string) $appointment->customer_note,
				'internal_note'    => (string) $appointment->internal_note,
				'source'           => (string) $appointment->source,
			)
		);

		return is_wp_error( $result ) ? $result : array( 'updated' => true, 'appointment' => $this->appointment_payload( $repository->find_with_details( (int) $appointment->id ) ) );
	}

	/**
	 * Apply one status to multiple appointments.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function bulk_status( WP_REST_Request $request ) {
		$params = $this->params( $request );
		$ids    = isset( $params['ids'] ) && is_array( $params['ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', $params['ids'] ) ) ) ) : array();
		$status = isset( $params['status'] ) ? sanitize_key( $params['status'] ) : '';

		if ( empty( $ids ) ) {
			return new WP_Error( 'yo_booking_bulk_empty', __( 'Select at least one appointment.', 'yo-booking' ), array( 'status' => 400 ) );
		}

		$repository = new AppointmentRepository();
		$updated    = array();
		$errors     = array();

		foreach ( $ids as $id ) {
			$result = $repository->update_status( $id, $status );
			if ( is_wp_error( $result ) ) {
				$errors[ $id ] = $result->get_error_message();
			} else {
				$updated[] = $id;
			}
		}

		return array( 'updated' => $updated, 'errors' => $errors, 'status' => $status );
	}

	/**
	 * Search customer profiles.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function customers( WP_REST_Request $request ) {
		$customers = array();

		foreach ( ( new CustomerRepository() )->search( $request->get_param( 'search' ), 20 ) as $customer ) {
			$customers[] = array( 'id' => (int) $customer->id, 'name' => $customer->name, 'email' => $customer->email, 'phone' => $customer->phone, 'phone_country' => $customer->phone_country, 'timezone' => $customer->timezone );
		}

		return array( 'customers' => $customers );
	}

	/**
	 * Serialize an appointment.
	 *
	 * @param object $appointment Appointment row.
	 * @return array
	 */
	private function appointment_payload( $appointment ) {
		return array(
			'id'             => (int) $appointment->id,
			'start'          => gmdate( 'c', strtotime( $appointment->start_at . ' UTC' ) ),
			'end'            => gmdate( 'c', strtotime( $appointment->end_at . ' UTC' ) ),
			'timezone'       => DateTimeFormatter::timezone_name(),
			'start_display'  => DateTimeFormatter::utc( $appointment->start_at ),
			'end_display'    => DateTimeFormatter::utc( $appointment->end_at ),
			'status'         => $appointment->status,
			'payment_status' => $appointment->payment_status,
			'payment_method' => isset( $appointment->payment_method ) ? $appointment->payment_method : '',
			'payment_method_title' => isset( $appointment->payment_method_title ) ? $appointment->payment_method_title : '',
			'payment_reference' => isset( $appointment->payment_reference ) ? $appointment->payment_reference : '',
			'total_amount'   => (string) $appointment->total_amount,
			'paid_amount'    => isset( $appointment->paid_amount ) ? (string) $appointment->paid_amount : '0',
			'refunded_amount' => isset( $appointment->refunded_amount ) ? (string) $appointment->refunded_amount : '0',
			'balance_amount' => isset( $appointment->balance_amount ) ? (string) $appointment->balance_amount : (string) $appointment->total_amount,
			'total_input'    => Currency::format_number( $appointment->total_amount, $appointment->currency ),
			'balance_input'  => Currency::format_number( isset( $appointment->balance_amount ) ? $appointment->balance_amount : $appointment->total_amount, $appointment->currency ),
			'currency'       => $appointment->currency,
			'total_display'  => Currency::format( $appointment->total_amount, $appointment->currency ),
			'paid_display'   => Currency::format( isset( $appointment->paid_amount ) ? $appointment->paid_amount : 0, $appointment->currency ),
			'refunded_display' => Currency::format( isset( $appointment->refunded_amount ) ? $appointment->refunded_amount : 0, $appointment->currency ),
			'balance_display' => Currency::format( isset( $appointment->balance_amount ) ? $appointment->balance_amount : $appointment->total_amount, $appointment->currency ),
			'service'        => array( 'id' => (int) $appointment->service_id, 'name' => $appointment->service_name ),
			'staff'          => array( 'id' => (int) $appointment->staff_id, 'name' => $appointment->staff_name ),
			'customer'       => array( 'id' => (int) $appointment->customer_id, 'name' => $appointment->customer_name, 'email' => $appointment->customer_email, 'phone' => $appointment->customer_phone, 'phone_country' => $appointment->customer_phone_country ),
			'customer_note'  => (string) $appointment->customer_note,
			'internal_note'  => (string) $appointment->internal_note,
		);
	}

	/** @param object $payment Payment row. @return array */
	private function payment_payload( $payment ) {
		return array( 'id' => (int) $payment->id, 'kind' => $payment->kind, 'status' => $payment->status, 'amount' => (string) $payment->amount, 'amount_display' => Currency::format( $payment->amount, $payment->currency ), 'currency' => $payment->currency, 'method' => $payment->method_title, 'transaction_id' => $payment->transaction_id, 'note' => wp_strip_all_tags( (string) $payment->note ), 'created_at' => DateTimeFormatter::utc( $payment->created_at ) );
	}

	/** @param object $log Notification log row. @return array */
	private function log_payload( $log ) {
		return array( 'id' => (int) $log->id, 'event' => $log->event, 'recipient' => $log->recipient_email, 'subject' => $log->subject, 'status' => $log->status, 'error' => $log->error_message, 'created_at' => DateTimeFormatter::utc( $log->created_at ) );
	}

	/** @param WP_REST_Request $request Request. @return array */
	private function params( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		return is_array( $params ) ? $params : $request->get_params();
	}

	/**
	 * Convert a floating calendar datetime to UTC MySQL format.
	 *
	 * @param string       $value Local date.
	 * @param DateTimeZone $timezone Calendar timezone.
	 * @return string
	 */
	private function local_mysql_to_utc( $value, DateTimeZone $timezone ) {
		try {
			return ( new DateTimeImmutable( sanitize_text_field( $value ), $timezone ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $exception ) {
			return '';
		}
	}
}
