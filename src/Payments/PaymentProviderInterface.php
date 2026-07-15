<?php
/**
 * Payment provider contract.
 *
 * @package YoBooking
 */

namespace YoBooking\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Describes a pluggable payment provider.
 */
interface PaymentProviderInterface {
	/**
	 * Return provider ID.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Return provider title.
	 *
	 * @return string
	 */
	public function title();

	/**
	 * Return customer-facing payment instructions.
	 *
	 * @return string
	 */
	public function instructions();
}
