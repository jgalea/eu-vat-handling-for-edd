<?php

namespace VatrightEdd\Tests\Unit\Domain;

use VatrightEdd\Domain\Decision;
use VatrightEdd\Domain\LineDecision;
use VatrightEdd\Domain\Rule;
use VatrightEdd\Domain\Scheme;
use PHPUnit\Framework\TestCase;

class DecisionTest extends TestCase {

	/**
	 * @param LineDecision[]|null $lines
	 */
	private function decision( ?array $lines = null, string $scheme = Scheme::REVERSE_CHARGE_SERVICES, bool $exempt = true, string $id = 'id-1' ): Decision {
		$lines = $lines ?? array( new LineDecision( 'item-1', 'digital', 'DE', 'DE', Scheme::REVERSE_CHARGE_SERVICES ) );
		return new Decision(
			$id,
			'2026-10-17T12:00:00Z',
			'checkout',
			array(
				'country'        => 'MT',
				'oss_registered' => false,
				'threshold'      => array(
					'current_eur'  => '8123.40',
					'previous_eur' => '0.00',
					'exceeded'     => false,
					'forced'       => false,
				),
			),
			array(
				'billing'      => array(
					'country'  => 'DE',
					'postcode' => '10115',
				),
				'shipping'     => array(
					'country'  => 'DE',
					'postcode' => '10115',
				),
				'local_pickup' => false,
			),
			array(
				'raw'       => 'de 123 456 789',
				'canonical' => 'DE123456789',
				'prefix'    => 'DE',
				'country'   => 'DE',
			),
			array(
				'status'       => 'valid',
				'registry'     => 'vies',
				'checked_at'   => '2026-10-17T11:59:58Z',
				'from_cache'   => false,
				'name'         => 'ACME',
				'address'      => 'Street 1',
				'consultation' => 'WAPIAAAA',
				'detail'       => '',
			),
			array(
				'billing'        => 'DE',
				'shipping'       => 'DE',
				'ip'             => 'DE',
				'self_certified' => null,
				'resolved'       => 'DE',
				'conflict'       => false,
				'pieces'         => 3,
			),
			array(
				'outage'                   => 'charge',
				'allow_domestic_exemption' => false,
				'fixed_prices'             => false,
			),
			$lines,
			$scheme,
			Rule::RC_SERVICES_ART196,
			$exempt,
			array(),
			array( 'art196' ),
			null
		);
	}

	private function line( string $key, string $tax_country, string $scheme = Scheme::ORIGIN ): LineDecision {
		return new LineDecision( $key, 'goods', 'DE', $tax_country, $scheme );
	}

	public function test_round_trips_v1_json(): void {
		$original = $this->decision();
		$json     = json_encode( $original->to_array() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$restored = Decision::from_array( json_decode( $json, true ) );

		$this->assertEquals( $original, $restored );
		$this->assertSame( $original->to_array(), $restored->to_array() );
	}

	public function test_json_key_set_equals_the_documented_list(): void {
		$expected = array( 'v', 'id', 'at', 'trigger', 'store', 'customer', 'vat_number', 'validation', 'evidence', 'policy', 'lines', 'scheme', 'rule', 'vat_exempt', 'flags', 'legal_text', 'blocking_error' );
		$array    = $this->decision()->to_array();

		$this->assertSame( $expected, array_keys( $array ) );
		$this->assertSame( 1, $array['v'] );
		$this->assertSame(
			array( 'key', 'supply_type', 'place_of_supply', 'tax_country', 'scheme' ),
			array_keys( $array['lines'][0] )
		);
	}

	public function test_round_trips_without_vat_number_or_validation(): void {
		$d = $this->decision();
		$a = $d->to_array();

		$a['vat_number']     = null;
		$a['validation']     = null;
		$a['blocking_error'] = 'invalid_vat_number';
		$restored            = Decision::from_array( $a );

		$this->assertSame( $a, $restored->to_array() );
		$this->assertSame( 'invalid_vat_number', $restored->blocking_error() );
	}

	public function test_accessors(): void {
		$d = $this->decision();
		$this->assertSame( 'id-1', $d->id() );
		$this->assertSame( Scheme::REVERSE_CHARGE_SERVICES, $d->scheme() );
		$this->assertSame( Rule::RC_SERVICES_ART196, $d->rule() );
		$this->assertTrue( $d->is_vat_exempt() );
		$this->assertSame( array(), $d->flags() );
		$this->assertSame( array( 'art196' ), $d->legal_text() );
		$this->assertNull( $d->blocking_error() );
	}

	public function test_line_lookup(): void {
		$d = $this->decision( array( $this->line( 'a', 'DE' ), $this->line( 'b', 'MT' ) ) );
		$this->assertSame( 'MT', $d->line( 'b' )->tax_country() );
		$this->assertNull( $d->line( 'missing' ) );
	}

	public function test_tax_country_is_uniform_or_null_when_mixed(): void {
		$uniform = $this->decision( array( $this->line( 'a', 'DE' ), $this->line( 'b', 'DE' ) ) );
		$mixed   = $this->decision( array( $this->line( 'a', 'DE' ), $this->line( 'b', 'MT' ) ) );
		$none    = $this->decision( array() );

		$this->assertSame( 'DE', $uniform->tax_country() );
		$this->assertNull( $mixed->tax_country() );
		$this->assertNull( $none->tax_country() );
	}

	public function test_with_flag_returns_a_copy_and_ignores_duplicates(): void {
		$d       = $this->decision();
		$flagged = $d->with_flag( 'validation_pending' );

		$this->assertSame( array(), $d->flags() );
		$this->assertSame( array( 'validation_pending' ), $flagged->flags() );
		$this->assertSame( array( 'validation_pending' ), $flagged->with_flag( 'validation_pending' )->flags() );
		$this->assertSame( array( 'validation_pending', 'backfilled' ), $flagged->with_flag( 'backfilled' )->flags() );
	}

	public function test_identical_decisions_do_not_differ_in_tax(): void {
		$this->assertFalse( $this->decision()->differs_in_tax_from( $this->decision( null, Scheme::REVERSE_CHARGE_SERVICES, true, 'other-id' ) ) );
	}

	public function test_flags_alone_do_not_differ_in_tax(): void {
		$d = $this->decision();
		$this->assertFalse( $d->differs_in_tax_from( $d->with_flag( 'single_evidence' ) ) );
	}

	public function test_exemption_change_differs_in_tax(): void {
		$this->assertTrue( $this->decision()->differs_in_tax_from( $this->decision( null, Scheme::REVERSE_CHARGE_SERVICES, false ) ) );
	}

	public function test_scheme_change_differs_in_tax(): void {
		$this->assertTrue( $this->decision()->differs_in_tax_from( $this->decision( null, Scheme::ORIGIN ) ) );
	}

	public function test_line_tax_country_change_differs_in_tax(): void {
		$a = $this->decision( array( $this->line( 'a', 'DE' ) ), Scheme::ORIGIN, false );
		$b = $this->decision( array( $this->line( 'a', 'MT' ) ), Scheme::ORIGIN, false );
		$this->assertTrue( $a->differs_in_tax_from( $b ) );
		$this->assertTrue( $b->differs_in_tax_from( $a ) );
	}

	public function test_line_set_change_differs_in_tax(): void {
		$one = $this->decision( array( $this->line( 'a', 'DE' ) ), Scheme::ORIGIN, false );
		$two = $this->decision( array( $this->line( 'a', 'DE' ), $this->line( 'b', 'DE' ) ), Scheme::ORIGIN, false );
		$this->assertTrue( $one->differs_in_tax_from( $two ) );
		$this->assertTrue( $two->differs_in_tax_from( $one ) );
	}

	public function test_renamed_line_key_differs_in_tax(): void {
		$a = $this->decision( array( $this->line( 'a', 'DE' ) ), Scheme::ORIGIN, false );
		$b = $this->decision( array( $this->line( 'z', 'DE' ) ), Scheme::ORIGIN, false );
		$this->assertTrue( $a->differs_in_tax_from( $b ) );
	}
}
