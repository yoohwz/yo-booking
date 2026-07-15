<?php
/**
 * Frontend appearance settings.
 *
 * @package YoBooking
 */

namespace YoBooking\Frontend;

use YoBooking\Settings\Defaults;
use YoBooking\Settings\Repository as SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes appearance settings and exposes frontend-safe values.
 */
final class Appearance {
	/** @return array */
	public static function settings() {
		$defaults = Defaults::settings()['appearance'];
		$stored   = ( new SettingsRepository() )->get( 'appearance', array() );
		$values   = is_array( $stored ) ? array_merge( $defaults, $stored ) : $defaults;

		foreach ( self::color_keys() as $key ) {
			$values[ $key ] = self::normalize_hex( $values[ $key ] );
			if ( ! $values[ $key ] ) {
				$values[ $key ] = $defaults[ $key ];
			}
		}

		$values['max_width']     = min( 1200, max( 560, absint( $values['max_width'] ) ) );
		$values['border_radius'] = min( 8, absint( $values['border_radius'] ) );
		$values['density']       = in_array( $values['density'], array( 'comfortable', 'compact' ), true ) ? $values['density'] : $defaults['density'];
		$values['shadow']        = in_array( $values['shadow'], array( 'none', 'subtle' ), true ) ? $values['shadow'] : $defaults['shadow'];

		foreach ( array( 'show_progress', 'show_service_prices', 'show_service_details' ) as $key ) {
			$values[ $key ] = ! empty( $values[ $key ] );
		}

		foreach ( array( 'booking_title', 'portal_title', 'manage_title' ) as $key ) {
			$values[ $key ] = sanitize_text_field( $values[ $key ] );
			if ( '' === $values[ $key ] ) {
				$values[ $key ] = $defaults[ $key ];
			}
		}

		return $values;
	}

	/** @return array */
	public static function frontend_config() {
		$settings = self::settings();

		return array(
			'density'            => $settings['density'],
			'shadow'             => $settings['shadow'],
			'showProgress'       => $settings['show_progress'],
			'showServicePrices'  => $settings['show_service_prices'],
			'showServiceDetails' => $settings['show_service_details'],
			'bookingTitle'       => $settings['booking_title'],
			'portalTitle'        => $settings['portal_title'],
			'manageTitle'        => $settings['manage_title'],
		);
	}

	/** @return string */
	public static function inline_css() {
		return '.yo-booking-app{' . self::variable_declarations( self::settings() ) . '}';
	}

	/** @return string */
	public static function preview_style() {
		return self::variable_declarations( self::settings() );
	}

	/** @return array */
	public static function color_keys() {
		return array( 'primary_color', 'accent_color', 'background_color', 'surface_color', 'text_color', 'muted_color', 'border_color', 'button_text_color' );
	}

	/**
	 * Build safe CSS custom property declarations.
	 *
	 * @param array $settings Appearance settings.
	 * @return string
	 */
	private static function variable_declarations( array $settings ) {
		return implode(
			'',
			array(
				'--yo-booking-primary:' . $settings['primary_color'] . ';',
				'--yo-booking-primary-rgb:' . self::hex_rgb( $settings['primary_color'] ) . ';',
				'--yo-booking-accent:' . $settings['accent_color'] . ';',
				'--yo-booking-bg:' . $settings['background_color'] . ';',
				'--yo-booking-surface:' . $settings['surface_color'] . ';',
				'--yo-booking-text:' . $settings['text_color'] . ';',
				'--yo-booking-muted:' . $settings['muted_color'] . ';',
				'--yo-booking-border:' . $settings['border_color'] . ';',
				'--yo-booking-button-text:' . $settings['button_text_color'] . ';',
				'--yo-booking-radius:' . $settings['border_radius'] . 'px;',
				'--yo-booking-max-width:' . $settings['max_width'] . 'px;',
			)
		);
	}

	/** @param string $color Hex color. @return string */
	private static function hex_rgb( $color ) {
		$hex = ltrim( $color, '#' );
		return hexdec( substr( $hex, 0, 2 ) ) . ',' . hexdec( substr( $hex, 2, 2 ) ) . ',' . hexdec( substr( $hex, 4, 2 ) );
	}

	/** @param string $color Color value. @return string */
	private static function normalize_hex( $color ) {
		if ( ! is_string( $color ) ) {
			return '';
		}
		$color = sanitize_hex_color( $color );
		if ( $color && 4 === strlen( $color ) ) {
			$color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
		}
		return $color;
	}
}
