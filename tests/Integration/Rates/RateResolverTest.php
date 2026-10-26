<?php

namespace VatrightEdd\Tests\Integration\Rates;

use VatrightEdd\Ledger\Schema;
use VatrightEdd\Rates\RateResolver;
use VatrightEdd\Rates\RateSet;
use VatrightEdd\Rates\RateSync;
use VatrightEdd\Rates\Tedb;
use VatrightEdd\Tests\Support\FixtureTransport;
use VatrightEdd\Tests\Support\IntegrationTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/FixtureTransport.php';

class RateResolverTest extends IntegrationTestCase {

	/** @var RateResolver */
	private $resolver;

	public function set_up() {
		parent::set_up();
		Schema::install();
		foreach ( edd_get_tax_rates() as $rate ) {
			edd_delete_tax_rate( $rate['id'] );
		}
		delete_option( RateSync::OPTION_LATEST );

		$sync = new RateSync( new Tedb( new FixtureTransport( sys_get_temp_dir() ) ) );
		$diff = $sync->plan( $sync->latest() );
		$sync->apply( $diff, array_keys( $diff->entries() ), true );
		$this->resolver = new RateResolver( $sync );
	}

	public function test_an_ebook_in_france_gets_the_lowest_reduced_rate(): void {
		$this->assertSame( 5.5, $this->resolver->rate( 'FR', RateResolver::REDUCED ) );
	}

	public function test_standard_comes_from_the_edd_rate(): void {
		$this->assertSame( 20.0, $this->resolver->rate( 'FR', RateResolver::STANDARD ) );
		$this->assertSame( 19.0, $this->resolver->rate( 'DE', RateResolver::STANDARD ) );
	}

	public function test_a_changed_edd_rate_is_what_standard_returns(): void {
		edd_add_tax_rate(
			array(
				'name'   => 'FR',
				'amount' => 21,
				'scope'  => 'country',
				'status' => 'active',
			)
		);

		$this->assertSame( 21.0, $this->resolver->rate( 'FR', RateResolver::STANDARD ) );
	}

	public function test_super_reduced_uses_its_own_rate(): void {
		$this->assertSame( 4.0, $this->resolver->rate( 'ES', RateResolver::SUPER_REDUCED ) );
		$this->assertSame( 3.0, $this->resolver->rate( 'LU', RateResolver::SUPER_REDUCED ) );
	}

	public function test_super_reduced_falls_back_to_reduced_then_standard(): void {
		$this->assertSame( 7.0, $this->resolver->rate( 'DE', RateResolver::SUPER_REDUCED ) );

		$set = new RateSet(
			array(
				'DK' => array(
					'standard' => 25.0,
					'reduced'  => array(),
				),
			),
			'tedb',
			'2026-10-17'
		);
		update_option( RateSync::OPTION_LATEST, $set->to_array(), false );
		edd_add_tax_rate(
			array(
				'name'   => 'DK',
				'amount' => 25,
				'scope'  => 'country',
				'status' => 'active',
			)
		);

		$this->assertSame( 25.0, $this->resolver->rate( 'DK', RateResolver::SUPER_REDUCED ) );
		$this->assertSame( 25.0, $this->resolver->rate( 'DK', RateResolver::REDUCED ) );
	}

	public function test_unknown_category_is_treated_as_standard(): void {
		$this->assertSame( 20.0, $this->resolver->rate( 'FR', 'bogus' ) );
	}

	public function test_a_country_without_an_edd_rate_and_without_reduced_data_has_no_rate(): void {
		$this->assertNull( $this->resolver->rate( 'GB', RateResolver::STANDARD ) );
		$this->assertNull( $this->resolver->rate( 'GB', RateResolver::REDUCED ) );
	}

	public function test_the_resolved_rate_can_be_filtered(): void {
		add_filter(
			'vatright_edd_resolved_rate',
			static function ( $rate, $country, $category ) {
				return 'FR' === $country && RateResolver::REDUCED === $category ? 5.0 : $rate;
			},
			10,
			3
		);

		$this->assertSame( 5.0, $this->resolver->rate( 'FR', RateResolver::REDUCED ) );
		$this->assertSame( 7.0, $this->resolver->rate( 'DE', RateResolver::REDUCED ) );
	}
}
