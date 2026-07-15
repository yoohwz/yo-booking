<?php
/**
 * Unit tests for integer money arithmetic.
 *
 * @package YoBooking
 */

use PHPUnit\Framework\TestCase;
use YoBooking\Payments\Currency;
use YoBooking\Payments\Money;

/** Verifies ISO precision and integer-only calculations. */
final class MoneyTest extends TestCase {
	/** Verify precision metadata, rounding, and storage round trips. */
	public function test_iso_precision_and_round_trip() {
		$this->assertSame( 0, Currency::decimals( 'JPY' ) );
		$this->assertSame( 3, Currency::decimals( 'KWD' ) );
		$this->assertSame( 4, Currency::decimals( 'CLF' ) );
		$this->assertSame( 12346, Money::to_minor( '123.456', 'USD' ) );
		$this->assertSame( '123.46', Money::from_minor( 12346, 'USD' ) );
	}

	/** Verify percentage calculations use half-up integer rounding. */
	public function test_percentage_uses_integer_half_up_rounding() {
		$this->assertSame( 3333, Money::percentage( 9999, '33.33' ) );
		$this->assertSame( 5000, Money::percentage( 9999, '50' ) );
	}

	/** Verify negative transaction input is rejected. */
	public function test_negative_amounts_are_not_accepted() {
		$this->assertSame( 0, Money::to_minor( '-10.00', 'USD' ) );
	}
}
