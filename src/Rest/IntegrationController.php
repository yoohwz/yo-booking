<?php
/**
 * Server-to-server integration REST API.
 *
 * @package YoBooking
 */

namespace YoBooking\Rest;

use YoBooking\Integrations\ApiKeyAuthenticator;
use YoBooking\Repositories\AppointmentRepository;
use YoBooking\Repositories\CustomerRepository;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Provides scoped API-key access for external systems.
 */
final class IntegrationController {
	/** @return void */
	public function boot() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/** @return void */
	public function register_routes() {
		register_rest_route( 'yo-booking/v1', '/integrations/appointments', array( 'methods' => 'GET', 'callback' => array( $this, 'appointments' ), 'permission_callback' => array( $this, 'can_read_appointments' ) ) );
		register_rest_route( 'yo-booking/v1', '/integrations/appointments/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( $this, 'appointment' ), 'permission_callback' => array( $this, 'can_read_appointments' ) ) );
		register_rest_route( 'yo-booking/v1', '/integrations/appointments/(?P<id>\d+)/status', array( 'methods' => 'POST', 'callback' => array( $this, 'update_status' ), 'permission_callback' => array( $this, 'can_write_appointments' ) ) );
		register_rest_route( 'yo-booking/v1', '/integrations/customers', array( 'methods' => 'GET', 'callback' => array( $this, 'customers' ), 'permission_callback' => array( $this, 'can_read_customers' ) ) );
	}

	/** @param WP_REST_Request $request Request. @return bool|WP_Error */
	public function can_read_appointments( WP_REST_Request $request ) {
		return $this->permission( $request, 'read_appointments' );
	}

	/** @param WP_REST_Request $request Request. @return bool|WP_Error */
	public function can_write_appointments( WP_REST_Request $request ) {
		return $this->permission( $request, 'write_appointments' );
	}

	/** @param WP_REST_Request $request Request. @return bool|WP_Error */
	public function can_read_customers( WP_REST_Request $request ) {
		return $this->permission( $request, 'read_customers' );
	}

	/** @param WP_REST_Request $request Request. @return array */
	public function appointments( WP_REST_Request $request ) {
		$page = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = max( 1, min( 100, absint( $request->get_param( 'per_page' ) ) ? absint( $request->get_param( 'per_page' ) ) : 25 ) );
		$args = array(
			'status' => sanitize_key( $request->get_param( 'status' ) ), 'payment_status' => sanitize_key( $request->get_param( 'payment_status' ) ),
			'service_id' => absint( $request->get_param( 'service_id' ) ), 'staff_id' => absint( $request->get_param( 'staff_id' ) ),
			'from' => sanitize_text_field( $request->get_param( 'from' ) ), 'to' => sanitize_text_field( $request->get_param( 'to' ) ),
		);
		$repository = new AppointmentRepository();
		$total = $repository->count_matching( $args );
		$items = array_map( array( $this, 'appointment_payload' ), $repository->all( array_merge( $args, array( 'limit' => $per_page, 'offset' => ( $page - 1 ) * $per_page ) ) ) );
		return array( 'appointments' => $items, 'pagination' => array( 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => (int) ceil( $total / $per_page ) ) );
	}

	/** @param WP_REST_Request $request Request. @return array|WP_Error */
	public function appointment( WP_REST_Request $request ) {
		$appointment = ( new AppointmentRepository() )->find_with_details( absint( $request['id'] ) );
		return $appointment ? array( 'appointment' => $this->appointment_payload( $appointment ) ) : new WP_Error( 'yo_booking_appointment_not_found', __( 'Appointment not found.', 'yo-booking' ), array( 'status' => 404 ) );
	}

	/** @param WP_REST_Request $request Request. @return array|WP_Error */
	public function update_status( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();
		$status = isset( $params['status'] ) ? sanitize_key( $params['status'] ) : '';
		$reason = isset( $params['reason'] ) ? sanitize_text_field( $params['reason'] ) : '';
		$result = ( new AppointmentRepository() )->update_status( absint( $request['id'] ), $status, $reason );
		return is_wp_error( $result ) ? $result : array( 'updated' => true, 'status' => $status );
	}

	/** @param WP_REST_Request $request Request. @return array */
	public function customers( WP_REST_Request $request ) {
		$search = sanitize_text_field( $request->get_param( 'search' ) );
		$limit  = max( 1, min( 100, absint( $request->get_param( 'per_page' ) ) ? absint( $request->get_param( 'per_page' ) ) : 25 ) );
		$items = array_map(
			static function ( $customer ) {
				return array( 'id' => (int) $customer->id, 'name' => $customer->name, 'email' => $customer->email, 'phone' => $customer->phone, 'phone_country' => $customer->phone_country, 'timezone' => $customer->timezone, 'marketing_consent' => (bool) $customer->marketing_consent );
			},
			( new CustomerRepository() )->search( $search, $limit )
		);
		return array( 'customers' => $items );
	}

	/** @param WP_REST_Request $request Request. @param string $capability Required capability. @return bool|WP_Error */
	private function permission( WP_REST_Request $request, $capability ) {
		$key = ( new ApiKeyAuthenticator() )->authenticate( $request, $capability );
		return is_wp_error( $key ) ? $key : true;
	}

	/** @param object $appointment Appointment. @return array */
	private function appointment_payload( $appointment ) {
		return array(
			'id' => (int) $appointment->id, 'uuid' => $appointment->uuid, 'status' => $appointment->status,
			'start_at' => gmdate( 'c', strtotime( $appointment->start_at . ' UTC' ) ), 'end_at' => gmdate( 'c', strtotime( $appointment->end_at . ' UTC' ) ), 'timezone' => $appointment->timezone,
			'service' => array( 'id' => (int) $appointment->service_id, 'name' => $appointment->service_name ), 'staff' => array( 'id' => (int) $appointment->staff_id, 'name' => $appointment->staff_name ),
			'customer' => array( 'id' => (int) $appointment->customer_id, 'name' => $appointment->customer_name, 'email' => $appointment->customer_email, 'phone' => $appointment->customer_phone, 'phone_country' => $appointment->customer_phone_country ),
			'payment' => array(
				'reference' => isset( $appointment->payment_reference ) ? $appointment->payment_reference : '',
				'method' => isset( $appointment->payment_method ) ? $appointment->payment_method : '',
				'method_title' => isset( $appointment->payment_method_title ) ? $appointment->payment_method_title : '',
				'status' => $appointment->payment_status,
				'subtotal' => isset( $appointment->subtotal_amount ) ? (string) $appointment->subtotal_amount : '0',
				'discount' => isset( $appointment->discount_amount ) ? (string) $appointment->discount_amount : '0',
				'tax' => isset( $appointment->tax_amount ) ? (string) $appointment->tax_amount : '0',
				'total' => (string) $appointment->total_amount,
				'paid' => isset( $appointment->paid_amount ) ? (string) $appointment->paid_amount : '0',
				'refunded' => isset( $appointment->refunded_amount ) ? (string) $appointment->refunded_amount : '0',
				'balance' => isset( $appointment->balance_amount ) ? (string) $appointment->balance_amount : (string) $appointment->total_amount,
				'currency' => $appointment->currency,
			),
		);
	}
}
