<?php
/**
 * WordPress-aware date and time formatting.
 *
 * @package YoBooking
 */

namespace YoBooking\Support;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps all user-facing dates aligned with Settings > General.
 */
final class DateTimeFormatter {
	/** @return string */
	public static function timezone_name() {
		$name = wp_timezone_string();

		return $name ? $name : 'UTC';
	}

	/** @return DateTimeZone */
	public static function timezone() {
		return wp_timezone();
	}

	/** @return string */
	public static function date_format() {
		$format = (string) get_option( 'date_format', 'F j, Y' );

		return $format ? $format : 'F j, Y';
	}

	/** @return string */
	public static function time_format() {
		$format = (string) get_option( 'time_format', 'g:i a' );

		return $format ? $format : 'g:i a';
	}

	/** @return string */
	public static function datetime_format() {
		return self::date_format() . ' ' . self::time_format();
	}

	/**
	 * Format a Unix timestamp in the site timezone.
	 *
	 * @param int    $timestamp Unix timestamp.
	 * @param string $type date, time, or datetime.
	 * @return string
	 */
	public static function timestamp( $timestamp, $type = 'datetime' ) {
		$format = 'date' === $type ? self::date_format() : ( 'time' === $type ? self::time_format() : self::datetime_format() );

		return wp_date( $format, (int) $timestamp, self::timezone() );
	}

	/**
	 * Format a UTC database datetime in the site timezone.
	 *
	 * @param string $value UTC MySQL datetime.
	 * @param string $type date, time, or datetime.
	 * @return string
	 */
	public static function utc( $value, $type = 'datetime' ) {
		if ( ! $value ) {
			return '';
		}

		try {
			$date = new DateTimeImmutable( (string) $value, new DateTimeZone( 'UTC' ) );
			return self::timestamp( $date->getTimestamp(), $type );
		} catch ( Exception $exception ) {
			return (string) $value;
		}
	}

	/**
	 * Format a Y-m-d value as a site-local date.
	 *
	 * @param string $value Local machine date.
	 * @return string
	 */
	public static function local_date( $value ) {
		if ( ! $value ) {
			return '';
		}

		try {
			$date = new DateTimeImmutable( (string) $value . ' 12:00:00', self::timezone() );
			return wp_date( self::date_format(), $date->getTimestamp(), self::timezone() );
		} catch ( Exception $exception ) {
			return (string) $value;
		}
	}

	/**
	 * Format an H:i value with the site's time format.
	 *
	 * @param string $value Local machine time.
	 * @return string
	 */
	public static function local_time( $value ) {
		if ( ! $value ) {
			return '';
		}

		try {
			$date = new DateTimeImmutable( '2000-01-01 ' . (string) $value, self::timezone() );
			return wp_date( self::time_format(), $date->getTimestamp(), self::timezone() );
		} catch ( Exception $exception ) {
			return (string) $value;
		}
	}
}
