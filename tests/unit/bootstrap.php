<?php
/**
 * PHPUnit bootstrap for standalone money tests.
 *
 * @package YoBooking
 */

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
/**
 * Sanitize a test value.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function sanitize_text_field( $value ) {
	return trim( (string) $value );
}
/**
 * Return an unmodified filtered value.
 *
 * @param string $hook Hook name.
 * @param mixed  $value Value.
 * @return mixed
 */
function apply_filters( $hook, $value ) {
	return $value;
}
/**
 * Convert a value to an absolute integer.
 *
 * @param mixed $value Raw value.
 * @return int
 */
function absint( $value ) {
	return abs( (int) $value );
}
/**
 * Return a fallback option value.
 *
 * @param string $name Option name.
 * @param mixed  $fallback Fallback value.
 * @return mixed
 */
function get_option( $name, $fallback = false ) {
	return $fallback;
}
require_once ABSPATH . 'src/Payments/CurrencyCatalog.php';
require_once ABSPATH . 'src/Payments/Currency.php';
require_once ABSPATH . 'src/Payments/Money.php';
