<?php
/**
 * Immutable booking payment snapshot builder.
 *
 * @package YoBooking
 */

namespace YoBooking\Payments;

use YoBooking\Database\Migrator;
use YoBooking\Settings\Repository as SettingsRepository;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Captures pricing and payment terms at booking time.
 */
final class PaymentSnapshot {
	/**
	 * Build a snapshot for a service and selected method.
	 *
	 * @param object $service Service row.
	 * @param string $method_id Payment method ID.
	 * @return array
	 */
	public function build( $service, $method_id ) {
		$quote    = $this->quote( $service );
		$registry = new PaymentProviderRegistry();
		$provider = $registry->get( $method_id );

		if ( ! $provider ) {
			$provider = $registry->get( $registry->default_id() );
		}

		return array(
			'subtotal_amount'        => $quote['subtotal_amount'],
			'discount_amount'        => $quote['discount_amount'],
			'tax_amount'             => $quote['tax_amount'],
			'total_amount'           => $quote['total_amount'],
			'currency'               => $quote['currency'],
			'payment_method'         => $provider ? $provider->id() : '',
			'payment_method_title'   => $provider ? $provider->title() : '',
			'payment_collection_mode' => $quote['collection_mode'],
			'payment_instructions'   => $provider ? $provider->instructions() : '',
			'payment_reference'      => $this->reference(),
			'payment_due_amount'     => $quote['amount_due'],
			'paid_amount'            => Money::from_minor( 0, $quote['currency'] ),
			'refunded_amount'        => Money::from_minor( 0, $quote['currency'] ),
			'balance_amount'         => $quote['total_amount'],
			'payment_status'         => 'pending',
		);
	}

	/**
	 * Calculate customer-facing payment terms before an appointment is created.
	 *
	 * @param object $service Service row.
	 * @return array
	 */
	public function quote( $service ) {
		$settings = ( new SettingsRepository() )->all();
		$currency = Currency::normalize( isset( $service->currency ) ? $service->currency : '' );
		$currency = $currency ? $currency : Currency::normalize( $settings['company']['currency'] );
		$currency = $currency ? $currency : 'USD';
		$subtotal = Money::to_minor( isset( $service->price ) ? $service->price : 0, $currency );
		$totals   = apply_filters(
			'yo_booking_payment_totals',
			array(
				'subtotal' => Money::from_minor( $subtotal, $currency ),
				'discount' => Money::from_minor( 0, $currency ),
				'tax'      => Money::from_minor( 0, $currency ),
			),
			$service,
			$currency
		);
		$subtotal = Money::to_minor( isset( $totals['subtotal'] ) ? $totals['subtotal'] : 0, $currency );
		$discount = min( $subtotal, Money::to_minor( isset( $totals['discount'] ) ? $totals['discount'] : 0, $currency ) );
		$tax      = Money::to_minor( isset( $totals['tax'] ) ? $totals['tax'] : 0, $currency );
		$total    = max( 0, $subtotal - $discount + $tax );
		$mode     = ! empty( $settings['payments']['enabled'] ) ? sanitize_key( $settings['payments']['collection_mode'] ) : 'none';
		$mode     = $total && in_array( $mode, array( 'full', 'deposit' ), true ) ? $mode : 'none';
		$due      = 'full' === $mode ? $total : 0;

		if ( 'deposit' === $mode ) {
			if ( 'fixed' === sanitize_key( $settings['payments']['deposit_type'] ) ) {
				$due = min( $total, Money::to_minor( $settings['payments']['deposit_amount'], $currency ) );
			} else {
				$due = min( $total, Money::percentage( $total, $settings['payments']['deposit_amount'] ) );
			}
		}

		$remaining = max( 0, $total - $due );

		return array(
			'enabled'             => in_array( $mode, array( 'full', 'deposit' ), true ) && $due > 0,
			'collection_mode'     => $mode,
			'subtotal_amount'     => Money::from_minor( $subtotal, $currency ),
			'discount_amount'     => Money::from_minor( $discount, $currency ),
			'tax_amount'          => Money::from_minor( $tax, $currency ),
			'total_amount'        => Money::from_minor( $total, $currency ),
			'amount_due'          => Money::from_minor( $due, $currency ),
			'remaining_amount'    => Money::from_minor( $remaining, $currency ),
			'currency'            => $currency,
			'total_display'       => Currency::format( Money::from_minor( $total, $currency ), $currency ),
			'amount_due_display'  => Currency::format( Money::from_minor( $due, $currency ), $currency ),
			'remaining_display'   => Currency::format( Money::from_minor( $remaining, $currency ), $currency ),
		);
	}

	/** @return string */
	private function reference() {
		global $wpdb;
		$table = Migrator::table_name( 'appointments' );

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$random    = strtoupper( substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 8 ) );
			$reference = 'YB-' . gmdate( 'Ymd' ) . '-' . $random;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE payment_reference = %s LIMIT 1", $reference ) );
			if ( ! $exists ) {
				return $reference;
			}
		}

		return 'YB-' . strtoupper( str_replace( '-', '', wp_generate_uuid4() ) );
	}
}
