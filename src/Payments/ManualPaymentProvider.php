<?php
/**
 * Manual/offline payment provider.
 *
 * @package YoBooking
 */

namespace YoBooking\Payments;

use YoBooking\Settings\Repository as SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Represents offline/manual payment collection.
 */
final class ManualPaymentProvider implements PaymentProviderInterface {
	/**
	 * Return provider ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'manual';
	}

	/**
	 * Return provider title.
	 *
	 * @return string
	 */
	public function title() {
		$title = ( new SettingsRepository() )->get( 'payments.manual_title', __( 'Manual payment', 'yo-booking' ) );

		return $title ? (string) $title : __( 'Manual payment', 'yo-booking' );
	}

	/**
	 * Return customer-facing payment instructions.
	 *
	 * @return string
	 */
	public function instructions() {
		return (string) ( new SettingsRepository() )->get( 'payments.manual_instructions', '' );
	}
}
