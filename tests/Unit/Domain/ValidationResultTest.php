<?php

namespace VatrightEdd\Tests\Unit\Domain;

use VatrightEdd\Domain\ValidationResult;
use PHPUnit\Framework\TestCase;

class ValidationResultTest extends TestCase {

	private function full(): ValidationResult {
		return new ValidationResult( ValidationResult::VALID, 'vies', '2026-10-17T11:59:58Z', 'ACME GmbH', 'Street 1', 'WAPIAAAA', false, 'detail' );
	}

	public function test_constants(): void {
		$this->assertSame( 'valid', ValidationResult::VALID );
		$this->assertSame( 'invalid', ValidationResult::INVALID );
		$this->assertSame( 'unavailable', ValidationResult::UNAVAILABLE );
		$this->assertSame( 'unchecked', ValidationResult::UNCHECKED );
		$this->assertSame( 'format_invalid', ValidationResult::FORMAT_INVALID );
	}

	public function test_to_array_matches_the_record_keys(): void {
		$this->assertSame(
			array(
				'status'       => 'valid',
				'registry'     => 'vies',
				'checked_at'   => '2026-10-17T11:59:58Z',
				'from_cache'   => false,
				'name'         => 'ACME GmbH',
				'address'      => 'Street 1',
				'consultation' => 'WAPIAAAA',
				'detail'       => 'detail',
			),
			$this->full()->to_array()
		);
	}

	public function test_round_trips_through_json(): void {
		$original = $this->full();
		$decoded  = json_decode( json_encode( $original->to_array() ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertEquals( $original, ValidationResult::from_array( $decoded ) );
	}

	public function test_optional_fields_default_to_empty(): void {
		$result = new ValidationResult( ValidationResult::UNCHECKED, 'none', '2026-10-17T00:00:00Z' );
		$this->assertNull( $result->name() );
		$this->assertNull( $result->address() );
		$this->assertNull( $result->consultation() );
		$this->assertFalse( $result->from_cache() );
		$this->assertSame( '', $result->detail() );
		$this->assertEquals( $result, ValidationResult::from_array( $result->to_array() ) );
	}

	public function test_with_from_cache_returns_a_copy(): void {
		$original = $this->full();
		$cached   = $original->with_from_cache( true );
		$this->assertFalse( $original->from_cache() );
		$this->assertTrue( $cached->from_cache() );
		$this->assertSame( 'ACME GmbH', $cached->name() );
	}
}
