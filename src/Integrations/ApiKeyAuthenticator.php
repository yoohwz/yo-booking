<?php
/**
 * REST API key authentication helper.
 *
 * @package YoBooking
 */

namespace YoBooking\Integrations;

use YoBooking\Repositories\ApiKeyRepository;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticates bearer keys and enforces integration scopes.
 */
final class ApiKeyAuthenticator {
	/** @param WP_REST_Request $request Request. @param string $capability Required scope. @return object|WP_Error */
	public function authenticate( WP_REST_Request $request, $capability ) {
		$header = trim( (string) $request->get_header( 'authorization' ) );
		if ( ! preg_match( '/^Bearer\s+(.+)$/i', $header, $matches ) ) {
			return new WP_Error( 'yo_booking_api_key_missing', __( 'A bearer API key is required.', 'yo-booking' ), array( 'status' => 401 ) );
		}
		$key = ( new ApiKeyRepository() )->authenticate( $matches[1] );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		if ( ! is_array( $key->capabilities ) || ! in_array( $capability, $key->capabilities, true ) ) {
			return new WP_Error( 'yo_booking_api_key_forbidden', __( 'This API key does not have the required capability.', 'yo-booking' ), array( 'status' => 403 ) );
		}
		return $key;
	}
}
