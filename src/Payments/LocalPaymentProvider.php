<?php
/**
 * Pay locally provider.
 *
 * @package YoBooking
 */

namespace YoBooking\Payments;

use YoBooking\Settings\Repository as SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Collects payment in person at the business location.
 */
final class LocalPaymentProvider implements PaymentProviderInterface {
	/** @return string */
	public function id() {
		return 'local';
	}

	/** @return string */
	public function title() {
		return (string) ( new SettingsRepository() )->get( 'payments.local_title', __( 'Pay locally', 'yo-booking' ) );
	}

	/** @return string */
	public function instructions() {
		return (string) ( new SettingsRepository() )->get( 'payments.local_instructions', __( 'Pay at the business location when you attend your appointment.', 'yo-booking' ) );
	}
}
