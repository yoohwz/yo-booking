<?php
/**
 * Frontend appearance normalization smoke test.
 */

use YoBooking\Frontend\Appearance;
use YoBooking\Settings\Repository as SettingsRepository;

defined( 'ABSPATH' ) || exit;

$repository = new SettingsRepository();
$before     = get_option( SettingsRepository::OPTION_NAME, array() );
$settings   = $repository->all();
$failures   = array();

try {
	$settings['appearance'] = array(
		'primary_color'         => 'not-a-color',
		'accent_color'          => '#123456',
		'background_color'      => '#f0f0f0',
		'surface_color'         => '#ffffff',
		'text_color'            => '#111111',
		'muted_color'           => '#666666',
		'border_color'          => '#dddddd',
		'button_text_color'     => '#ffffff',
		'max_width'             => 9000,
		'border_radius'         => 99,
		'density'              => 'invalid',
		'shadow'               => 'none',
		'show_progress'        => false,
		'show_service_prices'  => false,
		'show_service_details' => true,
		'booking_title'        => '<b>Schedule now</b>',
		'portal_title'         => 'Bookings',
		'manage_title'         => 'Manage booking',
	);
	$repository->save( $settings );
	$appearance = Appearance::settings();
	$config     = Appearance::frontend_config();
	$css        = Appearance::inline_css();

	if ( '#2563eb' !== $appearance['primary_color'] ) $failures[] = 'Invalid primary color was not reset.';
	if ( 1200 !== $appearance['max_width'] ) $failures[] = 'Maximum width was not bounded.';
	if ( 8 !== $appearance['border_radius'] ) $failures[] = 'Border radius was not bounded.';
	if ( 'comfortable' !== $appearance['density'] ) $failures[] = 'Invalid density was not reset.';
	if ( 'Schedule now' !== $config['bookingTitle'] ) $failures[] = 'Booking title was not sanitized.';
	if ( false !== $config['showProgress'] || false !== $config['showServicePrices'] ) $failures[] = 'Visibility settings were not preserved.';
	if ( false === strpos( $css, '--yo-booking-max-width:1200px;' ) ) $failures[] = 'Frontend width CSS variable is missing.';
	if ( false === strpos( $css, '--yo-booking-accent:#123456;' ) ) $failures[] = 'Frontend accent CSS variable is missing.';
	if ( false === strpos( $css, '--yo-booking-primary-rgb:37,99,235;' ) ) $failures[] = 'Frontend primary RGB variable is missing.';
} finally {
	update_option( SettingsRepository::OPTION_NAME, $before );
}

if ( $failures ) {
	foreach ( $failures as $failure ) echo 'FAIL: ' . $failure . PHP_EOL;
	exit( 1 );
}

echo 'appearance_smoke=pass' . PHP_EOL;
