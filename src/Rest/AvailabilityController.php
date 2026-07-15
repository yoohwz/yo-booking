<?php
/**
 * Availability REST API.
 *
 * @package YoBooking
 */

namespace YoBooking\Rest;

use YoBooking\Availability\AvailabilityEngine;
use YoBooking\Support\DateTimeFormatter;
use YoBooking\Support\RateLimiter;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Registers availability endpoints.
 */
final class AvailabilityController {
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
			'/availability/dates',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'dates' ),
					'permission_callback' => array( $this, 'authorize_dates' ),
				'args'                => $this->common_args(
					array(
						'from' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'to'   => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					)
				),
			)
		);

		register_rest_route(
			'yo-booking/v1',
			'/availability/times',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'times' ),
					'permission_callback' => array( $this, 'authorize_times' ),
				'args'                => $this->common_args(
					array(
						'date' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					)
				),
			)
		);
	}

	/** @return true|\WP_Error */
	public function authorize_dates() {
		return ( new RateLimiter() )->consume( 'availability_dates', 30, MINUTE_IN_SECONDS );
	}

	/** @return true|\WP_Error */
	public function authorize_times() {
		return ( new RateLimiter() )->consume( 'availability_times', 120, MINUTE_IN_SECONDS );
	}

	/**
	 * Return dates with available slots.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|\WP_Error
	 */
	public function dates( WP_REST_Request $request ) {
		$result = ( new AvailabilityEngine() )->available_dates( $this->request_args( $request ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $result['dates'] as &$date ) {
			$date['date_display'] = DateTimeFormatter::local_date( $date['date'] );
		}
		unset( $date );

		return $result;
	}

	/**
	 * Return available times for one date.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|\WP_Error
	 */
	public function times( WP_REST_Request $request ) {
		$result = ( new AvailabilityEngine() )->available_times( $this->request_args( $request ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $result['slots'] as &$slot ) {
			$slot['start_display'] = DateTimeFormatter::local_time( substr( $slot['start'], 11, 5 ) );
			$slot['end_display']   = DateTimeFormatter::local_time( substr( $slot['end'], 11, 5 ) );
		}
		unset( $slot );

		return $result;
	}

	/**
	 * Common REST args.
	 *
	 * @param array $extra Extra args.
	 * @return array
	 */
	private function common_args( array $extra = array() ) {
		return array_merge(
			array(
				'service_id' => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
				'staff_id'   => array(
					'type'              => 'integer',
					'required'          => false,
					'sanitize_callback' => 'absint',
				),
			),
			$extra
		);
	}

	/**
	 * Normalize request args for the engine.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array
	 */
	private function request_args( WP_REST_Request $request ) {
		return array(
			'service_id' => absint( $request->get_param( 'service_id' ) ),
			'staff_id'   => absint( $request->get_param( 'staff_id' ) ),
			'timezone'   => DateTimeFormatter::timezone_name(),
			'date'       => sanitize_text_field( (string) $request->get_param( 'date' ) ),
			'from'       => sanitize_text_field( (string) $request->get_param( 'from' ) ),
			'to'         => sanitize_text_field( (string) $request->get_param( 'to' ) ),
		);
	}
}
