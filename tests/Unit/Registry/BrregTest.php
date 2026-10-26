<?php

namespace VatrightEdd\Tests\Unit\Registry;

use VatrightEdd\Domain\ValidationResult;
use VatrightEdd\Domain\VatNumber;
use VatrightEdd\Registry\Brreg;
use VatrightEdd\Tests\Support\FixtureTransport;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/FixtureTransport.php';

class BrregTest extends TestCase {

	private function brreg(): Brreg {
		return new Brreg( new FixtureTransport( dirname( __DIR__, 2 ) . '/fixtures/brreg' ) );
	}

	private function no( string $number ): VatNumber {
		return VatNumber::parse( 'NO' . $number . 'MVA', 'NO' );
	}

	public function test_supports_no_only(): void {
		$this->assertTrue( $this->brreg()->supports( $this->no( '923609016' ) ) );
		$this->assertFalse( $this->brreg()->supports( VatNumber::parse( 'CHE101654423', 'CH' ) ) );
	}

	public function test_vat_registered_is_valid_with_name(): void {
		$result = $this->brreg()->check( $this->no( '923609016' ), null );
		$this->assertSame( ValidationResult::VALID, $result->status() );
		$this->assertSame( 'EQUINOR ASA', $result->name() );
		$this->assertSame( 'brreg', $result->registry() );
	}

	public function test_registered_company_outside_vat_register_is_invalid(): void {
		$result = $this->brreg()->check( $this->no( '916823525' ), null );
		$this->assertSame( ValidationResult::INVALID, $result->status() );
		$this->assertSame( 'not_vat_registered', $result->detail() );
	}

	public function test_unknown_number_is_invalid(): void {
		$result = $this->brreg()->check( $this->no( '999999999' ), null );
		$this->assertSame( ValidationResult::INVALID, $result->status() );
		$this->assertSame( 'not_found', $result->detail() );
	}

	/**
	 * @dataProvider failures
	 */
	public function test_failures_are_unavailable( string $number, string $detail ): void {
		$result = $this->brreg()->check( $this->no( $number ), null );
		$this->assertSame( ValidationResult::UNAVAILABLE, $result->status() );
		$this->assertSame( $detail, $result->detail() );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function failures(): array {
		return array(
			'http 500'  => array( '100000001', 'http_500' ),
			'timeout'   => array( '100000002', 'transport_error' ),
			'html body' => array( '100000003', 'malformed_response' ),
		);
	}
}
