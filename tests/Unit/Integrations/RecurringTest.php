<?php

namespace VatrightEdd\Tests\Unit\Integrations;

use PHPUnit\Framework\TestCase;
use VatrightEdd\Integrations\Recurring;

class RecurringTest extends TestCase {

	public function test_vat_inside_a_gross(): void {
		$this->assertSame( 18.0, Recurring::vat_inside( 118.0, 18.0, 2 ) );
		$this->assertSame( 19.67, Recurring::vat_inside( 118.0, 20.0, 2 ) );
		$this->assertSame( 5.21, Recurring::vat_inside( 100.0, 5.5, 2 ) );
	}

	public function test_no_rate_means_no_vat(): void {
		$this->assertSame( 0.0, Recurring::vat_inside( 100.0, 0.0, 2 ) );
		$this->assertSame( 0.0, Recurring::vat_inside( 100.0, -3.0, 2 ) );
	}

	public function test_a_non_empty_gross_is_not_vat_free_at_a_real_rate(): void {
		$this->assertGreaterThan( 0.0, Recurring::vat_inside( 1.0, 18.0, 2 ) );
	}
}
