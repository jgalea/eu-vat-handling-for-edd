<?php

namespace VatrightEdd\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use VatrightEdd\Domain\Correction;
use VatrightEdd\Domain\Decision;
use VatrightEdd\Domain\LineDecision;
use VatrightEdd\Domain\Rule;
use VatrightEdd\Domain\Scheme;
use VatrightEdd\Domain\VatNumber;

class CorrectionTest extends TestCase {

	/**
	 * A German digital sale charged at origin, still waiting on the registry.
	 *
	 * @param LineDecision[]|null $lines
	 */
	private function decision( ?array $lines = null ): Decision {
		return new Decision(
			'old-id',
			'2026-10-01T10:00:00Z',
			'order_built',
			array(
				'country'        => 'MT',
				'oss_registered' => false,
				'threshold'      => array( 'exceeded' => false ),
			),
			array(
				'billing'      => array(
					'country'  => 'DE',
					'postcode' => '10115',
				),
				'shipping'     => array(
					'country'  => '',
					'postcode' => '',
				),
				'local_pickup' => false,
			),
			array(
				'raw'       => 'de 114 103 379',
				'canonical' => 'DE114103379',
				'prefix'    => 'DE',
				'country'   => 'DE',
			),
			array(
				'status'     => 'valid',
				'registry'   => 'vies',
				'checked_at' => '2026-10-01T10:00:00Z',
				'name'       => 'Example GmbH',
			),
			array(
				'billing'        => 'DE',
				'shipping'       => null,
				'ip'             => 'FR',
				'self_certified' => null,
				'resolved'       => 'DE',
				'conflict'       => true,
				'pieces'         => 1,
			),
			array(
				'outage'                   => 'charge',
				'allow_domestic_exemption' => false,
				'fixed_prices'             => false,
			),
			$lines ?? array( new LineDecision( '7', 'digital', 'MT', 'MT', Scheme::ORIGIN ) ),
			Scheme::ORIGIN,
			Rule::PENDING_CHARGED,
			false,
			array( 'validation_pending', 'single_evidence', 'rate_missing' ),
			array(),
			null
		);
	}

	private function apply( string $treatment, string $country = 'DE', ?string $number = 'DE114103379', ?Decision $from = null ): Decision {
		return Correction::apply( $from ?? $this->decision(), $treatment, $country, null === $number ? null : VatNumber::parse( $number, $country ), 'new-id', '2026-10-26T09:00:00Z' );
	}

	public function test_reverse_charge_exempts_and_places_the_supply_in_the_customer_country(): void {
		$d = $this->apply( Correction::REVERSE_CHARGE );

		$this->assertTrue( $d->is_vat_exempt() );
		$this->assertSame( Scheme::REVERSE_CHARGE_SERVICES, $d->scheme() );
		$this->assertSame( Rule::RC_SERVICES_ART196, $d->rule() );
		$this->assertSame( 'DE', $d->lines()[0]->place_of_supply() );
		$this->assertSame( 'DE', $d->lines()[0]->tax_country() );
		$this->assertSame( array( 'art196' ), $d->legal_text() );
		$this->assertSame( array( 'rate_missing', Correction::FLAG ), $d->flags(), 'Flags that described the old reasoning are dropped, the rest stay.' );
		$this->assertSame( 'new-id', $d->id() );
		$this->assertSame( Correction::TRIGGER, $d->trigger() );
	}

	public function test_reverse_charge_on_goods_uses_the_intra_community_scheme(): void {
		$d = $this->apply( Correction::REVERSE_CHARGE, 'DE', 'DE114103379', $this->decision( array( new LineDecision( '7', 'goods', 'MT', 'MT', Scheme::ORIGIN ) ) ) );

		$this->assertSame( Scheme::INTRA_EU_GOODS, $d->scheme() );
		$this->assertSame( Rule::RC_GOODS_ART138, $d->rule() );
		$this->assertSame( array( 'art138' ), $d->legal_text() );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string,3:string,4:bool}>
	 */
	public function treatments(): array {
		return array(
			'domestic'        => array( Correction::DOMESTIC, Scheme::DOMESTIC, Rule::DOMESTIC, 'MT', false ),
			'oss destination' => array( Correction::OSS_DESTINATION, Scheme::OSS_DESTINATION, Rule::B2C_DESTINATION, 'FR', false ),
			'origin'          => array( Correction::ORIGIN, Scheme::ORIGIN, Rule::B2C_ORIGIN_BELOW_THRESHOLD, 'MT', false ),
			'export'          => array( Correction::EXPORT, Scheme::EXPORT, Rule::EXPORT_NON_EU, 'FR', false ),
		);
	}

	/**
	 * @dataProvider treatments
	 */
	public function test_each_other_treatment_sets_scheme_rule_and_tax_country( string $treatment, string $scheme, string $rule, string $tax_country, bool $exempt ): void {
		$d = $this->apply( $treatment, 'FR', null );

		$this->assertSame( $scheme, $d->scheme() );
		$this->assertSame( $rule, $d->rule() );
		$this->assertSame( $tax_country, $d->lines()[0]->tax_country() );
		$this->assertSame( $exempt, $d->is_vat_exempt() );
		$this->assertSame( array(), $d->legal_text() );
		$this->assertSame( 'FR', $d->to_array()['evidence']['resolved'] );
		$this->assertFalse( $d->to_array()['evidence']['conflict'] );
	}

	public function test_a_changed_number_drops_the_old_registry_answer_and_an_unchanged_one_keeps_it(): void {
		$kept    = $this->apply( Correction::REVERSE_CHARGE );
		$changed = $this->apply( Correction::REVERSE_CHARGE, 'DE', 'DE129273398' );
		$none    = $this->apply( Correction::DOMESTIC, 'MT', null );

		$this->assertSame( 'valid', $kept->to_array()['validation']['status'] );
		$this->assertNull( $changed->to_array()['validation'] );
		$this->assertSame( 'DE129273398', $changed->to_array()['vat_number']['canonical'] );
		$this->assertNull( $none->to_array()['vat_number'] );
		$this->assertNull( $none->to_array()['validation'] );
	}

	public function test_several_lines_keep_their_keys_and_supply_types(): void {
		$d = $this->apply(
			Correction::REVERSE_CHARGE,
			'DE',
			'DE114103379',
			$this->decision(
				array(
					new LineDecision( '7', 'digital', 'MT', 'MT', Scheme::ORIGIN ),
					new LineDecision( '8', 'goods', 'MT', 'MT', Scheme::ORIGIN ),
				)
			)
		);

		$this->assertSame(
			array( '7', '8' ),
			array_map(
				static function ( LineDecision $l ): string {
					return $l->key();
				},
				$d->lines()
			)
		);
		$this->assertSame( Scheme::MIXED, $d->scheme() );
		$this->assertSame( array( 'art196', 'art138' ), $d->legal_text() );
	}

	public function test_the_flag_is_not_added_twice_and_the_original_is_untouched(): void {
		$original = $this->decision();
		$once     = $this->apply( Correction::DOMESTIC, 'MT', null, $original );
		$twice    = $this->apply( Correction::ORIGIN, 'FR', null, $once );

		$this->assertSame( 1, count( array_keys( $twice->flags(), Correction::FLAG, true ) ) );
		$this->assertSame( Scheme::ORIGIN, $original->scheme(), 'Applying a correction returns a new decision.' );
		$this->assertContains( 'validation_pending', $original->flags() );
	}

	public function test_treatment_of_maps_schemes_back_and_leaves_unpickable_ones_empty(): void {
		$this->assertSame( Correction::REVERSE_CHARGE, Correction::treatment_of( Scheme::REVERSE_CHARGE_SERVICES ) );
		$this->assertSame( Correction::REVERSE_CHARGE, Correction::treatment_of( Scheme::INTRA_EU_GOODS ) );
		$this->assertSame( Correction::DOMESTIC, Correction::treatment_of( Scheme::DOMESTIC ) );
		$this->assertSame( '', Correction::treatment_of( Scheme::MIXED ) );
		$this->assertSame( '', Correction::treatment_of( Scheme::OUTSIDE_TERRITORY ) );
	}

	public function test_the_result_round_trips_through_json(): void {
		$d    = $this->apply( Correction::REVERSE_CHARGE );
		$json = json_encode( $d->to_array() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertSame( $d->to_array(), Decision::from_array( json_decode( $json, true ) )->to_array() );
	}
}
