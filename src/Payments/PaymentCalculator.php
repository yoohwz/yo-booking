<?php
/**
 * Payment amount calculator.
 *
 * @package YoBooking
 */

namespace YoBooking\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Calculates full/deposit/manual payment amounts.
 */
final class PaymentCalculator {
	/**
	 * Build a payment summary for an appointment row.
	 *
	 * @param object $appointment Appointment row.
	 * @return array
	 */
	public function summary( $appointment ) {
		$currency      = Currency::normalize( isset( $appointment->currency ) ? $appointment->currency : '' );
		$currency      = $currency ? $currency : 'USD';
		$total_minor   = Money::to_minor( isset( $appointment->total_amount ) ? $appointment->total_amount : 0, $currency );
		$paid_minor    = Money::to_minor( isset( $appointment->paid_amount ) ? $appointment->paid_amount : 0, $currency );
		$refund_minor  = Money::to_minor( isset( $appointment->refunded_amount ) ? $appointment->refunded_amount : 0, $currency );
		$balance_minor = Money::to_minor( isset( $appointment->balance_amount ) ? $appointment->balance_amount : Money::from_minor( $total_minor, $currency ), $currency );
		$snapshot_due  = Money::to_minor( isset( $appointment->payment_due_amount ) ? $appointment->payment_due_amount : 0, $currency );
		$net_paid      = max( 0, $paid_minor - $refund_minor );
		$due_minor     = min( $balance_minor, max( 0, $snapshot_due - $net_paid ) );
		$mode          = isset( $appointment->payment_collection_mode ) ? sanitize_key( $appointment->payment_collection_mode ) : 'none';
		$method_id     = isset( $appointment->payment_method ) ? sanitize_key( $appointment->payment_method ) : '';
		$provider      = ( new PaymentProviderRegistry() )->get( $method_id, false );
		$title         = isset( $appointment->payment_method_title ) && $appointment->payment_method_title ? (string) $appointment->payment_method_title : ( $provider ? $provider->title() : '' );
		$instructions  = isset( $appointment->payment_instructions ) ? (string) $appointment->payment_instructions : '';

		return array(
			'enabled'             => in_array( $mode, array( 'full', 'deposit' ), true ) && $total_minor > 0,
			'provider'            => $method_id,
			'provider_title'      => $title,
			'collection_mode'     => $mode,
			'subtotal_amount'     => Money::normalize( isset( $appointment->subtotal_amount ) ? $appointment->subtotal_amount : $appointment->total_amount, $currency ),
			'discount_amount'     => Money::normalize( isset( $appointment->discount_amount ) ? $appointment->discount_amount : 0, $currency ),
			'tax_amount'          => Money::normalize( isset( $appointment->tax_amount ) ? $appointment->tax_amount : 0, $currency ),
			'total_amount'        => Money::from_minor( $total_minor, $currency ),
			'payment_due_amount'  => Money::from_minor( $snapshot_due, $currency ),
			'amount_due'          => Money::from_minor( $due_minor, $currency ),
			'paid_amount'         => Money::from_minor( $paid_minor, $currency ),
			'refunded_amount'     => Money::from_minor( $refund_minor, $currency ),
			'balance_amount'      => Money::from_minor( $balance_minor, $currency ),
			'currency'            => $currency,
			'total_display'       => Currency::format( Money::from_minor( $total_minor, $currency ), $currency ),
			'amount_due_display'  => Currency::format( Money::from_minor( $due_minor, $currency ), $currency ),
			'paid_display'        => Currency::format( Money::from_minor( $paid_minor, $currency ), $currency ),
			'refunded_display'    => Currency::format( Money::from_minor( $refund_minor, $currency ), $currency ),
			'balance_display'     => Currency::format( Money::from_minor( $balance_minor, $currency ), $currency ),
			'payment_status'      => isset( $appointment->payment_status ) ? (string) $appointment->payment_status : 'pending',
			'payment_reference'   => isset( $appointment->payment_reference ) ? (string) $appointment->payment_reference : '',
			'reference'           => isset( $appointment->payment_reference ) ? (string) $appointment->payment_reference : '',
			'instructions'        => $instructions,
		);
	}
}
