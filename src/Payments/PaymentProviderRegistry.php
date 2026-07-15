<?php
/**
 * Payment provider registry.
 *
 * @package YoBooking
 */

namespace YoBooking\Payments;

use YoBooking\Settings\Repository as SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Discovers built-in and add-on payment providers.
 */
final class PaymentProviderRegistry {
	/**
	 * Return every registered provider keyed by ID.
	 *
	 * Add-ons may append providers with the yo_booking_payment_providers filter.
	 *
	 * @return PaymentProviderInterface[]
	 */
	public function all() {
		$providers = array(
			new LocalPaymentProvider(),
			new BankTransferPaymentProvider(),
		);
		$providers = apply_filters( 'yo_booking_payment_providers', $providers );
		$indexed   = array();

		if ( ! is_array( $providers ) ) {
			return $indexed;
		}

		foreach ( $providers as $provider ) {
			if ( ! $provider instanceof PaymentProviderInterface ) {
				continue;
			}

			$id = sanitize_key( $provider->id() );
			if ( $id ) {
				$indexed[ $id ] = $provider;
			}
		}

		return $indexed;
	}

	/** @return PaymentProviderInterface[] */
	public function enabled() {
		$all      = $this->all();
		$settings = ( new SettingsRepository() )->all();
		$ids      = isset( $settings['payments']['methods'] ) && is_array( $settings['payments']['methods'] ) ? $settings['payments']['methods'] : array( 'local', 'bank_transfer' );
		$enabled  = array();

		foreach ( array_map( 'sanitize_key', $ids ) as $id ) {
			if ( isset( $all[ $id ] ) ) {
				$enabled[ $id ] = $all[ $id ];
			}
		}

		return $enabled;
	}

	/**
	 * Resolve a provider.
	 *
	 * @param string $id Provider ID.
	 * @param bool   $enabled_only Restrict to enabled providers.
	 * @return PaymentProviderInterface|null
	 */
	public function get( $id, $enabled_only = true ) {
		$providers = $enabled_only ? $this->enabled() : $this->all();
		$id        = sanitize_key( $id );

		return isset( $providers[ $id ] ) ? $providers[ $id ] : null;
	}

	/**
	 * Validate provider capabilities for a booking context.
	 *
	 * @param string $id Provider ID.
	 * @param string $currency ISO 4217 currency.
	 * @param mixed  $amount Decimal amount.
	 * @param string $country Optional ISO country.
	 * @return true|\WP_Error
	 */
	public function supports( $id, $currency, $amount = 0, $country = '' ) {
		$provider = $this->get( $id );
		$currency = Currency::normalize( $currency );
		if ( ! $provider || ! $currency ) {
			return new \WP_Error( 'yo_booking_payment_method_invalid', __( 'Select a valid payment method.', 'yo-booking' ), array( 'status' => 400 ) );
		}

		if ( $provider instanceof PaymentProviderCapabilitiesInterface ) {
			if ( ! $provider->supports_currency( $currency ) ) {
				return new \WP_Error( 'yo_booking_payment_currency_unsupported', __( 'This payment method does not support the service currency.', 'yo-booking' ), array( 'status' => 400 ) );
			}
			if ( $country && ! $provider->supports_country( strtoupper( $country ) ) ) {
				return new \WP_Error( 'yo_booking_payment_country_unsupported', __( 'This payment method is not available in your country.', 'yo-booking' ), array( 'status' => 400 ) );
			}
			$minor = Money::to_minor( $amount, $currency );
			$min   = $provider->minimum_amount( $currency );
			$max   = $provider->maximum_amount( $currency );
			if ( null !== $min && $minor < Money::to_minor( $min, $currency ) ) {
				return new \WP_Error( 'yo_booking_payment_amount_too_small', __( 'The booking amount is below this payment method minimum.', 'yo-booking' ), array( 'status' => 400 ) );
			}
			if ( null !== $max && $minor > Money::to_minor( $max, $currency ) ) {
				return new \WP_Error( 'yo_booking_payment_amount_too_large', __( 'The booking amount exceeds this payment method maximum.', 'yo-booking' ), array( 'status' => 400 ) );
			}
		}

		return apply_filters( 'yo_booking_payment_provider_supports_context', true, $provider, $currency, $amount, $country );
	}

	/** @return string */
	public function default_id() {
		$enabled = $this->enabled();
		$default = sanitize_key( ( new SettingsRepository() )->get( 'payments.default_method', 'local' ) );

		if ( isset( $enabled[ $default ] ) ) {
			return $default;
		}

		return $enabled ? (string) array_key_first( $enabled ) : '';
	}
}
