<?php
/**
 * Public appointment action token helper.
 *
 * @package YoBooking
 */

namespace YoBooking\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and verifies stateless appointment action tokens.
 */
final class ActionToken {
	/**
	 * Generate a token for an appointment row with customer details.
	 *
	 * @param object $appointment Appointment row.
	 * @param string $purpose Token purpose.
	 * @return string
	 */
	public static function generate( $appointment, $purpose = 'manage' ) {
		$purpose = self::purpose( $purpose );
		$expiry  = time() + max( DAY_IN_SECONDS, (int) apply_filters( 'yo_booking_action_token_ttl', 30 * DAY_IN_SECONDS, $purpose, $appointment ) );
		$version = isset( $appointment->action_token_version ) ? max( 1, absint( $appointment->action_token_version ) ) : 1;
		$mac     = hash_hmac( 'sha256', self::payload( $appointment, $purpose, $expiry, $version ), wp_salt( 'auth' ) );
		return $expiry . '.' . $version . '.' . $mac;
	}

	/**
	 * Verify a token for an appointment row.
	 *
	 * @param object $appointment Appointment row.
	 * @param string $token Raw token.
	 * @param string $purpose Token purpose.
	 * @return bool
	 */
	public static function verify( $appointment, $token, $purpose = 'manage' ) {
		$token = sanitize_text_field( (string) $token );
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! ctype_digit( $parts[1] ) || (int) $parts[0] < time() ) {
			return false;
		}
		$expiry  = (int) $parts[0];
		$version = (int) $parts[1];
		$current = isset( $appointment->action_token_version ) ? max( 1, absint( $appointment->action_token_version ) ) : 1;
		if ( $version !== $current ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', self::payload( $appointment, self::purpose( $purpose ), $expiry, $version ), wp_salt( 'auth' ) );
		return hash_equals( $expected, $parts[2] );
	}

	/**
	 * Build the signed token payload.
	 *
	 * @param object $appointment Appointment row.
	 * @param string $purpose Token purpose.
	 * @param int    $expiry Expiration timestamp.
	 * @param int    $version Token version.
	 * @return string
	 */
	private static function payload( $appointment, $purpose, $expiry, $version ) {
		return implode(
			'|',
			array(
				isset( $appointment->uuid ) ? (string) $appointment->uuid : '',
				$purpose,
				(int) $expiry,
				(int) $version,
			)
		);
	}

	/**
	 * Normalize a token purpose.
	 *
	 * @param string $purpose Token purpose.
	 * @return string
	 */
	private static function purpose( $purpose ) {
		$purpose = sanitize_key( $purpose );
		return in_array( $purpose, array( 'cancel', 'reschedule', 'manage' ), true ) ? $purpose : 'manage';
	}
}
