<?php
/**
 * Online payment gateway contract.
 *
 * @package YoBooking
 */

namespace YoBooking\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Optional contract implemented by commercial online-payment add-ons.
 */
interface OnlinePaymentGatewayInterface extends PaymentProviderInterface {
	/**
	 * Create a hosted or embedded checkout session.
	 *
	 * @param object $appointment Appointment snapshot.
	 * @param array  $context Return URL, cancel URL, and request context.
	 * @return array|\WP_Error
	 */
	public function create_checkout( $appointment, array $context = array() );

	/**
	 * Request a gateway refund without mutating the core ledger directly.
	 *
	 * @param object $appointment Appointment snapshot.
	 * @param string $amount Decimal amount in the appointment currency.
	 * @param array  $context Gateway transaction context.
	 * @return array|\WP_Error
	 */
	public function refund( $appointment, $amount, array $context = array() );

	/**
	 * Verify and normalize an incoming gateway webhook.
	 *
	 * @param string $payload Raw request body.
	 * @param array  $headers Request headers.
	 * @return array|\WP_Error Normalized transaction data for PaymentManager.
	 */
	public function handle_webhook( $payload, array $headers = array() );
}
