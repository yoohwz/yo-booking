<?php
/**
 * Phone number helpers.
 *
 * @package YoBooking
 */

namespace YoBooking\Support;

use YoBooking\Settings\Repository as SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes phone values produced by intl-tel-input.
 */
final class PhoneNumber {
	/**
	 * Normalize an international phone number to E.164.
	 *
	 * Existing non-international values are retained for backwards compatibility.
	 *
	 * @param mixed $value Raw phone value.
	 * @return string
	 */
	public static function normalize( $value ) {
		$value = sanitize_text_field( (string) $value );
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( 0 === strpos( $value, '00' ) ) {
			$value = '+' . substr( $value, 2 );
		}

		if ( '+' === substr( $value, 0, 1 ) ) {
			$digits = preg_replace( '/\D+/', '', substr( $value, 1 ) );
			return $digits ? '+' . substr( $digits, 0, 15 ) : '';
		}

		return $value;
	}

	/**
	 * Check whether a non-empty value is a valid E.164-shaped number.
	 *
	 * @param mixed $value Phone value.
	 * @return bool
	 */
	public static function is_valid( $value ) {
		$value = self::normalize( $value );
		return '' === $value || 1 === preg_match( '/^\+[1-9]\d{6,14}$/', $value );
	}

	/**
	 * Sanitize an ISO-2 country code.
	 *
	 * @param mixed $value Country code.
	 * @return string
	 */
	public static function country( $value ) {
		$value = strtolower( sanitize_key( (string) $value ) );
		return 1 === preg_match( '/^[a-z]{2}$/', $value ) ? $value : '';
	}

	/**
	 * Return the configured country, then the country from the WordPress locale.
	 *
	 * @return string
	 */
	public static function default_country() {
		$configured = self::country( ( new SettingsRepository() )->get( 'booking.default_phone_country', '' ) );
		if ( $configured ) {
			return $configured;
		}

		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

		if ( preg_match( '/[_-]([A-Za-z]{2})(?:$|[.@])/', (string) $locale, $matches ) ) {
			return strtolower( $matches[1] );
		}

		return '';
	}
}
