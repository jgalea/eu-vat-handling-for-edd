<?php

namespace VatrightEdd\Tests\Unit\Ledger;

use VatrightEdd\Ledger\Fx;
use PHPUnit\Framework\TestCase;

class FxParseTest extends TestCase {

	private function fixture( string $name ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/ecb/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_parses_recorded_ecb_csv(): void {
		$rates = Fx::parse_csv( $this->fixture( 'usd_2026-09-25_2026-10-18.csv' ) );

		$this->assertCount( 16, $rates );
		$this->assertSame( 1.1403, $rates['2026-09-25'] );
		$this->assertSame( 1.1355, $rates['2026-09-30'] );
		$this->assertSame( 1.1253, $rates['2026-10-16'] );
	}

	public function test_weekend_dates_are_absent(): void {
		$rates = Fx::parse_csv( $this->fixture( 'usd_2026-09-25_2026-10-18.csv' ) );

		$this->assertArrayNotHasKey( '2026-10-10', $rates );
		$this->assertArrayNotHasKey( '2026-10-11', $rates );
	}

	public function test_empty_body_yields_no_rates(): void {
		$this->assertSame( array(), Fx::parse_csv( $this->fixture( 'empty_weekend.csv' ) ) );
	}

	public function test_error_json_yields_no_rates(): void {
		$this->assertSame( array(), Fx::parse_csv( $this->fixture( 'unknown_currency.json' ) ) );
	}

	public function test_skips_rows_without_a_numeric_observation(): void {
		$csv = "KEY,TIME_PERIOD,OBS_VALUE\nEXR.D.USD,2026-10-01,1.1298\nEXR.D.USD,2026-10-02,\nEXR.D.USD,2026-10-05,n/a\n";

		$this->assertSame( array( '2026-10-01' => 1.1298 ), Fx::parse_csv( $csv ) );
	}
}
