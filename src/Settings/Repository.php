<?php
/**
 * Settings persistence.
 *
 * @package YoBooking
 */

namespace YoBooking\Settings;

use YoBooking\Support\DateTimeFormatter;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes Yo Booking settings.
 */
final class Repository {
	/**
	 * Option name used for structured settings.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'yo_booking_settings';

	/**
	 * Return all settings merged with defaults.
	 *
	 * @return array
	 */
	public function all() {
		$settings = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings = $this->merge_defaults( Defaults::settings(), $settings );
		$settings['company']['timezone'] = DateTimeFormatter::timezone_name();

		return $settings;
	}

	/**
	 * Return one setting by dot notation.
	 *
	 * @param string $key Setting key such as booking.slot_interval_minutes.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$settings = $this->all();
		$segments = explode( '.', $key );
		$value    = $settings;

		foreach ( $segments as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $default;
			}

			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * Store one setting by dot notation.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $value Setting value.
	 * @return void
	 */
	public function set( $key, $value ) {
		$settings = $this->all();
		$segments = explode( '.', $key );
		$cursor   = &$settings;

		foreach ( $segments as $segment ) {
			if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
				$cursor[ $segment ] = array();
			}

			$cursor = &$cursor[ $segment ];
		}

		$cursor = $value;

		$this->save( $settings );
	}

	/**
	 * Save the full settings tree.
	 *
	 * @param array $settings Settings to save.
	 * @return void
	 */
	public function save( array $settings ) {
		update_option( self::OPTION_NAME, $this->merge_defaults( Defaults::settings(), $settings ), 'no' );
	}

	/**
	 * Create the settings option if it does not exist and merge new defaults.
	 *
	 * @return void
	 */
	public function seed_defaults() {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, Defaults::settings(), '', 'no' );
			return;
		}

		$this->save( $this->all() );
	}

	/**
	 * Recursively merge stored values over defaults.
	 *
	 * @param array $defaults Default values.
	 * @param array $settings Stored values.
	 * @return array
	 */
	private function merge_defaults( array $defaults, array $settings ) {
		foreach ( $settings as $key => $value ) {
			if ( is_array( $value ) && isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) ) {
				$defaults[ $key ] = $this->merge_defaults( $defaults[ $key ], $value );
				continue;
			}

			$defaults[ $key ] = $value;
		}

		return $defaults;
	}
}
