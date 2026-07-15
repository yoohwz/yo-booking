<?php
/**
 * Verify that Yo Booking follows WordPress date and time settings.
 */

defined( 'ABSPATH' ) || exit;

use YoBooking\Settings\Repository as SettingsRepository;
use YoBooking\Support\DateTimeFormatter;

$options = array( 'timezone_string', 'gmt_offset', 'date_format', 'time_format' );
$before  = array();

foreach ( $options as $option ) {
	$before[ $option ] = get_option( $option );
}

$stored_settings = get_option( SettingsRepository::OPTION_NAME, array() );
$failures        = array();

try {
	update_option( 'timezone_string', 'America/New_York' );
	update_option( 'date_format', 'd/m/Y' );
	update_option( 'time_format', 'g:i a' );

	$settings = is_array( $stored_settings ) ? $stored_settings : array();
	if ( ! isset( $settings['company'] ) || ! is_array( $settings['company'] ) ) {
		$settings['company'] = array();
	}
	$settings['company']['timezone'] = 'Asia/Tokyo';
	update_option( SettingsRepository::OPTION_NAME, $settings );

	$effective = ( new SettingsRepository() )->all();
	if ( 'America/New_York' !== $effective['company']['timezone'] ) {
		$failures[] = 'Settings repository did not inherit the WordPress timezone.';
	}

	$timestamp = ( new DateTimeImmutable( '2026-01-15 15:30:00', new DateTimeZone( 'UTC' ) ) )->getTimestamp();
	$expected  = wp_date( 'd/m/Y g:i a', $timestamp, wp_timezone() );
	if ( $expected !== DateTimeFormatter::utc( '2026-01-15 15:30:00' ) ) {
		$failures[] = 'UTC datetime was not rendered with the WordPress timezone and formats.';
	}

	$expected_time = wp_date( 'g:i a', ( new DateTimeImmutable( '2000-01-01 15:05:00', wp_timezone() ) )->getTimestamp(), wp_timezone() );
	if ( $expected_time !== DateTimeFormatter::local_time( '15:05' ) ) {
		$failures[] = 'Local time was not rendered with the WordPress time format.';
	}
} finally {
	foreach ( $before as $option => $value ) {
		update_option( $option, $value );
	}
	update_option( SettingsRepository::OPTION_NAME, $stored_settings );
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		echo 'FAIL: ' . $failure . PHP_EOL;
	}
	exit( 1 );
}

echo 'PASS: Yo Booking follows WordPress timezone, date format, and time format.' . PHP_EOL;
