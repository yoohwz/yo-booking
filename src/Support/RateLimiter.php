<?php
/**
 * Lightweight public request rate limiter.
 *
 * @package YoBooking
 */

namespace YoBooking\Support;

use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Limits abusive public writes without storing raw network identifiers.
 */
final class RateLimiter {
	/**
	 * Consume one request from a fixed window.
	 *
	 * @param string $scope Rate-limit scope.
	 * @param int    $limit Requests per window.
	 * @param int    $window Window length in seconds.
	 * @param string $subject Optional subject identifier.
	 * @return true|WP_Error
	 */
	public function consume( $scope, $limit, $window, $subject = '' ) {
		global $wpdb;
		if ( defined( 'YO_BOOKING_RUNNING_TESTS' ) && YO_BOOKING_RUNNING_TESTS && ! apply_filters( 'yo_booking_rate_limit_during_tests', false ) ) {
			return true;
		}

		$scope  = sanitize_key( $scope );
		$limit  = max( 1, absint( $limit ) );
		$window = max( 1, absint( $window ) );
		$key    = $this->key( $scope, $subject );
		$lock   = 'yo_booking:rate:' . substr( hash( 'sha256', $key ), 0, 32 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return new WP_Error( 'yo_booking_rate_limit_busy', __( 'Too many requests. Please wait and try again.', 'yo-booking' ), array( 'status' => 429, 'retry_after' => 1 ) );
		}

		try {
			$state = get_transient( $key );
			$now   = time();
			if ( ! is_array( $state ) || empty( $state['reset'] ) || $now >= (int) $state['reset'] ) {
				$state = array( 'count' => 0, 'reset' => $now + $window );
			}

			if ( (int) $state['count'] >= $limit ) {
				return new WP_Error(
					'yo_booking_rate_limit_exceeded',
					__( 'Too many requests. Please wait and try again.', 'yo-booking' ),
					array( 'status' => 429, 'retry_after' => max( 1, (int) $state['reset'] - $now ) )
				);
			}

			$state['count'] = (int) $state['count'] + 1;
			set_transient( $key, $state, $window );

			return true;
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/** @param string $scope Rate-limit scope. @param string $subject Optional subject. @return void */
	public function clear( $scope, $subject = '' ) {
		delete_transient( $this->key( sanitize_key( $scope ), $subject ) );
	}

	/** @param string $scope Sanitized scope. @param string $subject Optional subject. @return string */
	private function key( $scope, $subject ) {
		return 'yo_booking_rl_' . substr( hash_hmac( 'sha256', $scope . '|' . $this->identifier( $subject ), wp_salt( 'nonce' ) ), 0, 32 );
	}

	/** @param string $subject Explicit subject. @return string */
	private function identifier( $subject ) {
		if ( is_user_logged_in() ) {
			$network_identifier = 'user:' . get_current_user_id();
		} else {
			$network_identifier = isset( $_SERVER['REMOTE_ADDR'] ) ? 'ip:' . sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'ip:unknown';
		}
		$identifier = $subject ? 'subject:' . sanitize_text_field( $subject ) . '|' . $network_identifier : $network_identifier;

		return (string) apply_filters( 'yo_booking_rate_limit_identifier', $identifier, $subject );
	}
}
