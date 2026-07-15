<?php
/**
 * Integer-based money arithmetic.
 *
 * @package YoBooking
 */

namespace YoBooking\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Converts decimal strings to minor units without floating-point arithmetic.
 */
final class Money {
	/** @param mixed $amount Amount. @param string $currency Currency code. @return int */
	public static function to_minor( $amount, $currency ) {
		$decimals = Currency::decimals( $currency );
		$value    = trim( (string) $amount );
		$value    = preg_replace( '/[^0-9,\.\-]/', '', $value );

		if ( '' === $value || '-' === $value ) {
			return 0;
		}

		$negative = 0 === strpos( $value, '-' );
		$value    = str_replace( '-', '', $value );
		$dot      = strrpos( $value, '.' );
		$comma    = strrpos( $value, ',' );
		$position = false;

		if ( false !== $dot && false !== $comma ) {
			$position = max( $dot, $comma );
		} elseif ( false !== $dot ) {
			$position = $dot;
		} elseif ( false !== $comma ) {
			$position = $comma;
		}

		$whole    = false === $position ? $value : substr( $value, 0, $position );
		$fraction = false === $position ? '' : substr( $value, $position + 1 );
		$whole    = preg_replace( '/\D/', '', $whole );
		$fraction = preg_replace( '/\D/', '', $fraction );
		$whole    = '' === $whole ? '0' : ltrim( $whole, '0' );
		$whole    = '' === $whole ? '0' : $whole;
		$round_up = isset( $fraction[ $decimals ] ) && (int) $fraction[ $decimals ] >= 5;
		$fraction = substr( str_pad( $fraction, $decimals, '0' ), 0, $decimals );
		$factor   = 10 ** $decimals;
		$minor    = ( (int) $whole * $factor ) + ( $decimals ? (int) $fraction : 0 ) + ( $round_up ? 1 : 0 );

		return $negative ? 0 : max( 0, $minor );
	}

	/** @param int $minor Minor units. @param string $currency Currency code. @return string */
	public static function from_minor( $minor, $currency ) {
		$minor    = max( 0, (int) $minor );
		$decimals = Currency::decimals( $currency );
		$factor   = 10 ** $decimals;
		$whole    = intdiv( $minor, $factor );
		$fraction = $minor % $factor;

		return $decimals ? $whole . '.' . str_pad( (string) $fraction, $decimals, '0', STR_PAD_LEFT ) : (string) $whole;
	}

	/** @param mixed $amount Amount. @param string $currency Currency code. @return string */
	public static function normalize( $amount, $currency ) {
		return self::from_minor( self::to_minor( $amount, $currency ), $currency );
	}

	/**
	 * Calculate a percentage with half-up integer rounding.
	 *
	 * @param int $minor Base minor amount.
	 * @param mixed $percent Percentage with up to two decimals.
	 * @return int
	 */
	public static function percentage( $minor, $percent ) {
		$basis_points = self::to_minor( $percent, 'USD' );
		$basis_points = max( 0, min( 10000, $basis_points ) );
		return intdiv( ( max( 0, (int) $minor ) * $basis_points ) + 5000, 10000 );
	}
}
