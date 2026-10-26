<?php

namespace VatrightEdd\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use VatrightEdd\Import\OssAdvice;

class OssAdviceTest extends TestCase {

	/**
	 * @return array<string,array{string,string,string,bool,bool}>
	 */
	public function charges(): array {
		return array(
			'consumer in another EU country, destination rate' => array( 'DE', 'MT', 'DE', false, true ),
			'consumer in another EU country, a third rate' => array( 'DE', 'MT', 'FR', false, true ),
			'Greek consumer, destination rate'             => array( 'GR', 'MT', 'GR', false, true ),
			'consumer charged the store country rate'      => array( 'DE', 'MT', 'MT', false, false ),
			'consumer in the store country'                => array( 'MT', 'MT', 'MT', false, false ),
			'consumer in Monaco counts as France'          => array( 'MC', 'FR', 'FR', false, false ),
			'consumer outside the EU'                      => array( 'US', 'MT', 'US', false, false ),
			'customer with a VAT number is not a consumer' => array( 'DE', 'MT', 'DE', true, false ),
			'no tax charged'                               => array( 'DE', 'MT', '', false, false ),
			'no billing country'                           => array( '', 'MT', 'DE', false, false ),
		);
	}

	/**
	 * @dataProvider charges
	 */
	public function test_destination_charge( string $billing, string $store, string $charged, bool $has_number, bool $expected ): void {
		$this->assertSame( $expected, OssAdvice::destination_charge( $billing, $store, $charged, $has_number ) );
	}

	public function test_a_barn2_union_oss_registration_is_a_reason_on_its_own(): void {
		$this->assertSame( OssAdvice::SETTING, OssAdvice::reason( true, false, false ) );
	}

	public function test_charged_orders_are_a_reason_when_barn2_has_no_registration_setting(): void {
		$this->assertSame( OssAdvice::ORDERS, OssAdvice::reason( false, true, false ) );
	}

	public function test_the_setting_is_named_first_when_both_apply(): void {
		$this->assertSame( OssAdvice::SETTING, OssAdvice::reason( true, true, false ) );
	}

	public function test_no_signal_gives_no_advice(): void {
		$this->assertNull( OssAdvice::reason( false, false, false ) );
	}

	public function test_a_store_already_registered_is_not_advised(): void {
		$this->assertNull( OssAdvice::reason( true, true, true ) );
	}
}
