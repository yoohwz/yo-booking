<?php
/**
 * Optional payment provider capability contract.
 *
 * @package YoBooking
 */

namespace YoBooking\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by add-ons that restrict currencies, countries, or amounts.
 */
interface PaymentProviderCapabilitiesInterface {
	/**
	 * Check currency support.
	 *
	 * @param string $currency ISO 4217 currency.
	 * @return bool
	 */
	public function supports_currency( $currency );

	/**
	 * Check country support.
	 *
	 * @param string $country ISO 3166-1 alpha-2 country.
	 * @return bool
	 */
	public function supports_country( $country );

	/**
	 * Return the provider minimum amount.
	 *
	 * @param string $currency ISO 4217 currency.
	 * @return string|null
	 */
	public function minimum_amount( $currency );

	/**
	 * Return the provider maximum amount.
	 *
	 * @param string $currency ISO 4217 currency.
	 * @return string|null
	 */
	public function maximum_amount( $currency );
}
