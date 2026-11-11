<?php

namespace VatrightEdd\Tests\Unit\Orders;

use PHPUnit\Framework\TestCase;
use VatrightEdd\Orders\OrderFinaliser;

class OrderRateFitTest extends TestCase {

	public function test_the_expected_rate_fits(): void {
		$this->assertTrue( OrderFinaliser::rate_fits( 100.0, 19.0, 19.0 ) );
	}

	public function test_a_cart_taxed_at_another_countrys_rate_does_not_fit(): void {
		$this->assertFalse( OrderFinaliser::rate_fits( 100.0, 21.0, 19.0 ) );
		$this->assertFalse( OrderFinaliser::rate_fits( 100.0, 25.0, 19.0 ) );
	}

	public function test_a_cents_rounding_on_a_small_line_still_fits(): void {
		$this->assertTrue( OrderFinaliser::rate_fits( 0.99, 0.19, 19.0 ) );
	}

	public function test_a_tax_free_line_does_not_fit_a_taxed_rate(): void {
		$this->assertFalse( OrderFinaliser::rate_fits( 50.0, 0.0, 19.0 ) );
	}
}
