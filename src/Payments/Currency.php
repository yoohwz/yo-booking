<?php
/**
 * Currency metadata and formatting.
 *
 * @package YoBooking
 */

namespace YoBooking\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Provides supported currency metadata and commerce choices.
 */
final class Currency {
	/**
	 * Return every currency understood by the plugin, including legacy commerce codes.
	 *
	 * @return array
	 */
	public static function all() {
		$currencies = array_merge( CurrencyCatalog::all(), self::commerce_supplements() );
		$symbols    = array(
			'AED' => array( 'symbol' => 'AED' ),
			'AUD' => array( 'symbol' => 'A$', 'separator' => '' ),
			'BRL' => array( 'symbol' => 'R$', 'separator' => '' ),
			'CAD' => array( 'symbol' => 'C$', 'separator' => '' ),
			'CHF' => array( 'symbol' => 'CHF' ),
			'CNY' => array( 'symbol' => 'CN¥', 'separator' => '' ),
			'CZK' => array( 'symbol' => 'Kč', 'position' => 'after' ),
			'DKK' => array( 'symbol' => 'kr', 'position' => 'after' ),
			'EUR' => array( 'symbol' => '€', 'position' => 'after' ),
			'GBP' => array( 'symbol' => '£', 'separator' => '' ),
			'HKD' => array( 'symbol' => 'HK$', 'separator' => '' ),
			'HUF' => array( 'symbol' => 'Ft', 'position' => 'after' ),
			'IDR' => array( 'symbol' => 'Rp' ),
			'ILS' => array( 'symbol' => '₪', 'separator' => '' ),
			'INR' => array( 'symbol' => '₹', 'separator' => '' ),
			'JPY' => array( 'symbol' => '¥', 'separator' => '' ),
			'KRW' => array( 'symbol' => '₩', 'separator' => '' ),
			'MXN' => array( 'symbol' => 'MX$', 'separator' => '' ),
			'MYR' => array( 'symbol' => 'RM' ),
			'NOK' => array( 'symbol' => 'kr', 'position' => 'after' ),
			'NZD' => array( 'symbol' => 'NZ$', 'separator' => '' ),
			'PLN' => array( 'symbol' => 'zł', 'position' => 'after' ),
			'RON' => array( 'symbol' => 'lei', 'position' => 'after' ),
			'SAR' => array( 'symbol' => 'SAR' ),
			'SEK' => array( 'symbol' => 'kr', 'position' => 'after' ),
			'SGD' => array( 'symbol' => 'S$', 'separator' => '' ),
			'THB' => array( 'symbol' => '฿', 'separator' => '' ),
			'TRY' => array( 'symbol' => '₺', 'separator' => '' ),
			'USD' => array( 'symbol' => '$', 'separator' => '' ),
			'VND' => array( 'symbol' => '₫', 'position' => 'after' ),
			'ZAR' => array( 'symbol' => 'R', 'separator' => '' ),
		);

		foreach ( $currencies as $code => $currency ) {
			$currencies[ $code ] = array_merge(
				array( 'symbol' => $code, 'position' => 'before', 'separator' => ' ' ),
				$currency,
				isset( $symbols[ $code ] ) ? $symbols[ $code ] : array()
			);
		}

		return apply_filters( 'yo_booking_currencies', $currencies );
	}

	/**
	 * Return currencies offered for new settings and services.
	 *
	 * The baseline matches the WooCommerce 10.6 checkout currency list. The full
	 * catalog remains available internally so existing records keep working.
	 *
	 * @return array
	 */
	public static function selectable() {
		$currencies = array_diff_key( self::all(), array_fill_keys( self::non_commerce_codes(), true ) );
		ksort( $currencies );

		return apply_filters( 'yo_booking_selectable_currencies', $currencies );
	}

	/**
	 * Return selectable currencies while preserving a previously stored code.
	 *
	 * @param string $current Previously stored currency code.
	 * @return array
	 */
	public static function choices( $current = '' ) {
		$currencies = self::selectable();
		$current    = self::normalize( $current );
		$all        = self::all();

		if ( $current && ! isset( $currencies[ $current ] ) && isset( $all[ $current ] ) ) {
			$currencies[ $current ] = $all[ $current ];
			ksort( $currencies );
		}

		return $currencies;
	}

	/** @param string $code Raw code. @return string */
	public static function normalize_selectable( $code ) {
		$code = strtoupper( sanitize_text_field( $code ) );
		return isset( self::selectable()[ $code ] ) ? $code : '';
	}

	/** @param string $code Raw code. @return string */
	public static function normalize( $code ) {
		$code = strtoupper( sanitize_text_field( $code ) );
		return isset( self::all()[ $code ] ) ? $code : '';
	}

	/** @param string $code Currency code. @return int */
	public static function decimals( $code ) {
		$code = self::normalize( $code );
		$all  = self::all();
		return $code && isset( $all[ $code ]['decimals'] ) ? max( 0, min( 4, absint( $all[ $code ]['decimals'] ) ) ) : 2;
	}

	/**
	 * Format a normalized decimal amount using the WordPress locale.
	 *
	 * @param string $amount Decimal amount.
	 * @param string $code Currency code.
	 * @return string
	 */
	public static function format( $amount, $code ) {
		$code             = self::normalize( $code );
		$all              = self::all();
		$currency         = $code && isset( $all[ $code ] ) ? $all[ $code ] : array();
		$options          = self::formatting_options();
		$number           = self::format_number( $amount, $code );
		$symbol           = isset( $currency['symbol'] ) ? $currency['symbol'] : $code;
		$position         = $options['currency_position'];
		$after            = 'currency' === $position ? isset( $currency['position'] ) && 'after' === $currency['position'] : in_array( $position, array( 'right', 'right_space' ), true );
		$spacing          = 'currency' === $position ? ( isset( $currency['separator'] ) ? $currency['separator'] : ' ' ) : ( in_array( $position, array( 'left_space', 'right_space' ), true ) ? ' ' : '' );

		return $after ? $number . $spacing . $symbol : $symbol . $spacing . $number;
	}

	/**
	 * Format only the editable numeric portion of a monetary amount.
	 *
	 * @param string $amount Decimal amount.
	 * @param string $code Currency code.
	 * @return string
	 */
	public static function format_number( $amount, $code ) {
		$options          = self::formatting_options();
		$storage_decimals = self::decimals( $code );
		$decimals         = 'currency' === $options['number_of_decimals'] ? $storage_decimals : (int) $options['number_of_decimals'];
		$minor            = Money::to_minor( $amount, $code );
		$display_minor    = self::convert_precision( $minor, $storage_decimals, $decimals );
		$factor           = 10 ** $decimals;
		$integer          = (string) intdiv( $display_minor, $factor );
		$fraction         = $decimals ? str_pad( (string) ( $display_minor % $factor ), $decimals, '0', STR_PAD_LEFT ) : '';
		$separators       = self::resolved_separators( $options );
		$thousand         = $separators['thousand'];
		$decimal          = $separators['decimal'];

		// An identical grouping and decimal separator is ambiguous in an input.
		if ( $thousand === $decimal ) {
			$thousand = '';
		}

		$integer = $thousand ? preg_replace( '/\B(?=(\d{3})+(?!\d))/', $thousand, $integer ) : $integer;

		return $integer . ( $decimals ? $decimal . $fraction : '' );
	}

	/**
	 * Parse a number formatted with the configured money separators.
	 *
	 * @param mixed  $value Submitted formatted value.
	 * @param string $code Currency code.
	 * @return string Normalized storage amount.
	 */
	public static function parse_number( $value, $code ) {
		$raw_prefix = '__yo_booking_raw__:';
		$value      = trim( (string) $value );

		if ( 0 === strpos( $value, $raw_prefix ) ) {
			return Money::normalize( substr( $value, strlen( $raw_prefix ) ), $code );
		}

		$options    = self::formatting_options();
		$separators = self::resolved_separators( $options );
		$thousand   = $separators['thousand'];
		$decimal    = $separators['decimal'];
		$value      = str_replace( array( "\xc2\xa0", "\xe2\x80\xaf" ), ' ', $value );

		if ( $thousand && $thousand !== $decimal ) {
			$value = str_replace( $thousand, '', $value );
		}

		if ( '.' !== $decimal ) {
			$value = str_replace( $decimal, '.', $value );
		}

		return Money::normalize( $value, $code );
	}

	/**
	 * Return validated display options without changing stored currency precision.
	 *
	 * @return array
	 */
	private static function formatting_options() {
		$defaults = array(
			'currency_position'  => 'currency',
			'thousand_separator' => 'locale',
			'decimal_separator'  => 'locale',
			'number_of_decimals' => 'currency',
		);

		if ( ! function_exists( 'get_option' ) ) {
			return $defaults;
		}

		$settings = get_option( 'yo_booking_settings', array() );
		$stored   = is_array( $settings ) && isset( $settings['payments'] ) && is_array( $settings['payments'] ) ? $settings['payments'] : array();
		$options  = array_merge( $defaults, array_intersect_key( $stored, $defaults ) );

		$options['currency_position'] = in_array( $options['currency_position'], array( 'currency', 'left', 'left_space', 'right', 'right_space' ), true ) ? $options['currency_position'] : $defaults['currency_position'];
		$options['thousand_separator'] = in_array( $options['thousand_separator'], array( 'locale', 'comma', 'period', 'space', 'apostrophe', 'none' ), true ) ? $options['thousand_separator'] : $defaults['thousand_separator'];
		$options['decimal_separator']  = in_array( $options['decimal_separator'], array( 'locale', 'comma', 'period' ), true ) ? $options['decimal_separator'] : $defaults['decimal_separator'];
		$options['number_of_decimals'] = in_array( (string) $options['number_of_decimals'], array( 'currency', '0', '1', '2', '3', '4' ), true ) ? (string) $options['number_of_decimals'] : $defaults['number_of_decimals'];

		return $options;
	}

	/** @param array $options Validated options. @return array */
	private static function resolved_separators( array $options ) {
		global $wp_locale;

		$locale_thousand = $wp_locale && isset( $wp_locale->number_format['thousands_sep'] ) ? $wp_locale->number_format['thousands_sep'] : ',';
		$locale_decimal  = $wp_locale && isset( $wp_locale->number_format['decimal_point'] ) ? $wp_locale->number_format['decimal_point'] : '.';

		return array(
			'thousand' => self::separator( $options['thousand_separator'], $locale_thousand ),
			'decimal'  => self::separator( $options['decimal_separator'], $locale_decimal ),
		);
	}

	/** @param int $minor Minor units. @param int $from Source precision. @param int $to Display precision. @return int */
	private static function convert_precision( $minor, $from, $to ) {
		$minor = max( 0, (int) $minor );
		if ( $from === $to ) {
			return $minor;
		}

		$factor = 10 ** abs( $from - $to );
		return $to > $from ? $minor * $factor : intdiv( $minor + intdiv( $factor, 2 ), $factor );
	}

	/** @param string $setting Separator setting. @param string $locale Locale fallback. @return string */
	private static function separator( $setting, $locale ) {
		$separators = array(
			'comma'      => ',',
			'period'     => '.',
			'space'      => ' ',
			'apostrophe' => "'",
			'none'       => '',
		);

		return 'locale' === $setting ? $locale : ( isset( $separators[ $setting ] ) ? $separators[ $setting ] : $locale );
	}

	/**
	 * Commerce currencies not present in the current ISO List One catalog.
	 *
	 * @return array
	 */
	private static function commerce_supplements() {
		return array(
			'ANG' => array( 'name' => 'Netherlands Antillean Guilder', 'numeric' => '532', 'decimals' => 2, 'iso_minor_units' => 2 ),
			'BGN' => array( 'name' => 'Bulgarian Lev', 'numeric' => '975', 'decimals' => 2, 'iso_minor_units' => 2 ),
			'BTC' => array( 'name' => 'Bitcoin', 'numeric' => '', 'decimals' => 2, 'iso_minor_units' => 2 ),
			'BYR' => array( 'name' => 'Belarusian Ruble (old)', 'numeric' => '974', 'decimals' => 0, 'iso_minor_units' => 0 ),
			'CUC' => array( 'name' => 'Cuban Convertible Peso', 'numeric' => '931', 'decimals' => 2, 'iso_minor_units' => 2 ),
			'GGP' => array( 'name' => 'Guernsey Pound', 'numeric' => '', 'decimals' => 2, 'iso_minor_units' => 2 ),
			'HRK' => array( 'name' => 'Croatian Kuna', 'numeric' => '191', 'decimals' => 2, 'iso_minor_units' => 2 ),
			'IMP' => array( 'name' => 'Manx Pound', 'numeric' => '', 'decimals' => 2, 'iso_minor_units' => 2 ),
			'IRT' => array( 'name' => 'Iranian Toman', 'numeric' => '', 'decimals' => 0, 'iso_minor_units' => 0 ),
			'JEP' => array( 'name' => 'Jersey Pound', 'numeric' => '', 'decimals' => 2, 'iso_minor_units' => 2 ),
			'PRB' => array( 'name' => 'Transnistrian Ruble', 'numeric' => '', 'decimals' => 2, 'iso_minor_units' => 2 ),
			'SLL' => array( 'name' => 'Sierra Leonean Leone', 'numeric' => '694', 'decimals' => 2, 'iso_minor_units' => 2 ),
			'VEF' => array( 'name' => 'Venezuelan Bolivar (2008-2018)', 'numeric' => '937', 'decimals' => 2, 'iso_minor_units' => 2 ),
		);
	}

	/**
	 * ISO funds, accounting units, test codes, and newer codes not in WooCommerce.
	 *
	 * @return string[]
	 */
	private static function non_commerce_codes() {
		return array(
			'BOV', 'CHE', 'CHW', 'CLF', 'COU', 'MXV', 'SLE', 'SVC', 'USN', 'UYI', 'UYW', 'VED',
			'XAD', 'XAG', 'XAU', 'XBA', 'XBB', 'XBC', 'XBD', 'XCG', 'XDR', 'XPD', 'XPT', 'XSU',
			'XTS', 'XUA', 'XXX', 'ZWG',
		);
	}
}
