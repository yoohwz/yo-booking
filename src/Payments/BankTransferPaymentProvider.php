<?php
/**
 * Bank transfer provider.
 *
 * @package YoBooking
 */

namespace YoBooking\Payments;

use YoBooking\Settings\Repository as SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Collects payment by direct bank transfer.
 */
final class BankTransferPaymentProvider implements PaymentProviderInterface {
	/** @return string */
	public function id() {
		return 'bank_transfer';
	}

	/** @return string */
	public function title() {
		return (string) ( new SettingsRepository() )->get( 'payments.bank_transfer_title', __( 'Bank transfer', 'yo-booking' ) );
	}

	/** @return string */
	public function instructions() {
		$settings = ( new SettingsRepository() )->all();
		$payments = isset( $settings['payments'] ) ? $settings['payments'] : array();
		$lines    = array();
		$intro    = isset( $payments['bank_transfer_instructions'] ) ? trim( (string) $payments['bank_transfer_instructions'] ) : '';

		if ( $intro ) {
			$lines[] = $intro;
		}

		$fields = array(
			'bank_name'           => __( 'Bank', 'yo-booking' ),
			'bank_account_name'   => __( 'Account name', 'yo-booking' ),
			'bank_account_number' => __( 'Account number', 'yo-booking' ),
			'bank_routing_number' => __( 'Routing number', 'yo-booking' ),
			'bank_iban'           => __( 'IBAN', 'yo-booking' ),
			'bank_swift'          => __( 'SWIFT/BIC', 'yo-booking' ),
		);

		foreach ( $fields as $key => $label ) {
			$value = isset( $payments[ $key ] ) ? trim( (string) $payments[ $key ] ) : '';
			if ( $value ) {
				$lines[] = $label . ': ' . $value;
			}
		}

		return implode( "\n", $lines );
	}
}
