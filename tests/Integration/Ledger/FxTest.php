<?php

namespace VatrightEdd\Tests\Integration\Ledger;

use VatrightEdd\Ledger\Fx;
use VatrightEdd\Ledger\Schema;
use VatrightEdd\Tests\Support\EcbFixtureTransport;
use VatrightEdd\Tests\Support\IntegrationTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/EcbFixtureTransport.php';

class FxTest extends IntegrationTestCase {

	/** @var EcbFixtureTransport */
	private $transport;

	/** @var Fx */
	private $fx;

	public function set_up() {
		parent::set_up();
		Schema::install();
		$this->transport = new EcbFixtureTransport();
		$this->fx        = new Fx( $this->transport );
	}

	public function test_eur_needs_no_lookup(): void {
		$this->assertSame( 1.0, $this->fx->rate_on_or_before( 'EUR', '2026-10-09' ) );
		$this->assertSame( array(), $this->transport->calls );
	}

	public function test_published_day_returns_its_own_rate(): void {
		$this->assertSame( 1.1206, $this->fx->rate_on_or_before( 'USD', '2026-10-09' ) );
	}

	public function test_currency_code_is_case_insensitive(): void {
		$this->assertSame( 1.1206, $this->fx->rate_on_or_before( 'usd', '2026-10-09' ) );
	}

	public function test_fx_weekend_falls_back(): void {
		$this->assertSame( 1.1206, $this->fx->rate_on_or_before( 'USD', '2026-10-11' ) );
		$this->assertSame( 1.1206, $this->fx->rate_on_or_before( 'USD', '2026-10-10' ) );
	}

	public function test_on_or_after_looks_forward_over_a_weekend(): void {
		$this->assertSame( 1.1181, $this->fx->rate_on_or_after( 'USD', '2026-10-10' ) );
	}

	public function test_period_end_uses_the_last_day_of_the_quarter(): void {
		$this->assertSame( 1.1355, $this->fx->rate_on_or_after( 'USD', '2026-09-30' ) );
		$this->assertNotSame( $this->fx->rate_on_or_after( 'USD', '2026-09-30' ), $this->fx->rate_on_or_after( 'USD', '2026-10-01' ) );
	}

	public function test_second_lookup_is_served_from_the_table(): void {
		$this->fx->rate_on_or_before( 'USD', '2026-10-11' );
		$calls = count( $this->transport->calls );

		$this->assertSame( 1.1206, $this->fx->rate_on_or_before( 'USD', '2026-10-11' ) );
		$this->assertSame( 1.1206, $this->fx->rate_on_or_before( 'USD', '2026-10-09' ) );
		$this->assertSame( $calls, count( $this->transport->calls ) );
	}

	public function test_unpublished_days_are_stored_with_a_null_rate(): void {
		$this->fx->rate_on_or_before( 'USD', '2026-10-11' );
		global $wpdb;

		$null_rows = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::fx_table() . ' WHERE rate IS NULL' ); // phpcs:ignore WordPress.DB
		$this->assertGreaterThanOrEqual( 2, $null_rows );
	}

	public function test_unknown_currency_returns_null_and_stores_nothing(): void {
		global $wpdb;

		$this->assertNull( $this->fx->rate_on_or_before( 'XXX', '2026-10-09' ) );
		$this->assertSame( '0', (string) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::fx_table() ) ); // phpcs:ignore WordPress.DB
	}

	public function test_transport_failure_returns_null(): void {
		$this->transport->fail = true;

		$this->assertNull( $this->fx->rate_on_or_before( 'USD', '2026-10-09' ) );
	}

	public function test_request_targets_the_ecb_series_for_the_window(): void {
		$this->fx->rate_on_or_before( 'USD', '2026-10-09' );

		$this->assertCount( 1, $this->transport->calls );
		$this->assertSame(
			'https://data-api.ecb.europa.eu/service/data/EXR/D.USD.EUR.SP00.A?startPeriod=2026-09-29&endPeriod=2026-10-09&format=csvdata',
			$this->transport->calls[0]
		);
	}
}
