<?php
/**
 * ISO 4217 catalog and precision smoke test.
 *
 * @package YoBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( (string) $value );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return isset( $GLOBALS['yo_booking_test_options'][ $name ] ) ? $GLOBALS['yo_booking_test_options'][ $name ] : $default;
	}
}

require_once dirname( __DIR__ ) . '/src/Payments/CurrencyCatalog.php';
require_once dirname( __DIR__ ) . '/src/Payments/Currency.php';
require_once dirname( __DIR__ ) . '/src/Payments/Money.php';

$catalog    = YoBooking\Payments\CurrencyCatalog::all();
$currencies = YoBooking\Payments\Currency::all();
$selectable = YoBooking\Payments\Currency::selectable();
$fail       = static function ( $message ) {
	fwrite( STDERR, 'FAIL: ' . $message . "\n" );
	exit( 1 );
};

if ( 178 !== count( $catalog ) ) {
	$fail( 'expected 178 current ISO 4217 List One codes' );
}

if ( 163 !== count( $selectable ) ) {
	$fail( 'expected 163 WooCommerce-compatible selectable currencies' );
}

foreach ( array( 'AED', 'AFN', 'BHD', 'CLF', 'EUR', 'GBP', 'JPY', 'USD', 'VED', 'XCG', 'XDR', 'XAU', 'ZMW', 'ZWG' ) as $code ) {
	if ( ! isset( $currencies[ $code ] ) ) {
		$fail( 'missing currency code ' . $code );
	}
}

if ( isset( $catalog['BGN'] ) ) {
	$fail( 'BGN must remain outside the current ISO catalog' );
}

foreach ( array( 'ANG', 'BGN', 'BTC', 'BYR', 'CUC', 'GGP', 'HRK', 'IMP', 'IRT', 'JEP', 'PRB', 'SLL', 'VEF' ) as $code ) {
	if ( ! isset( $selectable[ $code ] ) ) {
		$fail( 'missing WooCommerce-compatible currency code ' . $code );
	}
}

foreach ( array( 'BOV', 'CLF', 'USN', 'XAU', 'XDR', 'XTS', 'XXX' ) as $code ) {
	if ( isset( $selectable[ $code ] ) ) {
		$fail( 'non-commerce currency must not be selectable: ' . $code );
	}
}

if ( 'USD' !== YoBooking\Payments\Currency::normalize_selectable( 'usd' ) || '' !== YoBooking\Payments\Currency::normalize_selectable( 'XAU' ) ) {
	$fail( 'selectable currency normalization failed' );
}

if ( ! isset( YoBooking\Payments\Currency::choices( 'XAU' )['XAU'] ) ) {
	$fail( 'stored non-commerce currency was not preserved in choices' );
}

$precisions = array( 'JPY' => 0, 'USD' => 2, 'BHD' => 3, 'CLF' => 4, 'XAU' => 4 );
foreach ( $precisions as $code => $expected ) {
	if ( $expected !== YoBooking\Payments\Currency::decimals( $code ) ) {
		$fail( 'incorrect precision for ' . $code );
	}
}

if ( 'usd' === YoBooking\Payments\Currency::normalize( 'usd' ) || 'USD' !== YoBooking\Payments\Currency::normalize( 'usd' ) ) {
	$fail( 'currency normalization failed' );
}

if ( 1235 !== YoBooking\Payments\Money::to_minor( '1.2345', 'BHD' ) || 12346 !== YoBooking\Payments\Money::to_minor( '1.23456', 'CLF' ) ) {
	$fail( 'currency precision rounding failed' );
}

if ( '$1,234.50' !== YoBooking\Payments\Currency::format( '1234.5', 'USD' ) || '1,234.50 €' !== YoBooking\Payments\Currency::format( '1234.5', 'EUR' ) || 'AED 1,234.50' !== YoBooking\Payments\Currency::format( '1234.5', 'AED' ) ) {
	$fail( 'currency symbol spacing or placement failed' );
}

$GLOBALS['yo_booking_test_options']['yo_booking_settings'] = array(
	'payments' => array(
		'currency_position'  => 'right_space',
		'thousand_separator' => 'period',
		'decimal_separator'  => 'comma',
		'number_of_decimals' => '3',
	),
);

if ( '1.234,500 $' !== YoBooking\Payments\Currency::format( '1234.5', 'USD' ) ) {
	$fail( 'custom currency display settings failed' );
}

$GLOBALS['yo_booking_test_options']['yo_booking_settings']['payments']['number_of_decimals'] = '2';
if ( '1,24 BHD' !== YoBooking\Payments\Currency::format( '1.235', 'BHD' ) ) {
	$fail( 'display precision rounding failed' );
}

echo "currency_catalog_smoke=pass\n";
echo 'currency_count=' . count( $selectable ) . "\n";
