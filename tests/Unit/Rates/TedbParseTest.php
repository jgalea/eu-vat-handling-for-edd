<?php

namespace VatrightEdd\Tests\Unit\Rates;

use DOMDocument;
use DOMXPath;
use VatrightEdd\Rates\Snapshot;
use VatrightEdd\Rates\Tedb;
use VatrightEdd\Tests\Support\FixtureTransport;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/FixtureTransport.php';

class TedbParseTest extends TestCase {

	/** @var string */
	private $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/vatright-tedb-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/*' ) ?: array() as $file ) { // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		rmdir( $this->dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	private function fixture( string $name ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/tedb/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	private function tedb_answering( int $status, string $body ): Tedb {
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$this->dir . '/tedb.json',
			(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				array(
					'request'  => array(
						'method'        => 'POST',
						'url'           => Tedb::ENDPOINT,
						'body_contains' => '<t:situationOn>2026-10-17</t:situationOn>',
					),
					'response' => array(
						'status' => $status,
						'body'   => $body,
					),
				)
			)
		);
		return new Tedb( new FixtureTransport( $this->dir ) );
	}

	public function test_parses_recorded_response_for_all_27_states(): void {
		$set = Tedb::parse( $this->fixture( 'response.xml' ), '2026-10-17' );

		$this->assertCount( 27, $set->country_codes() );
		$this->assertSame( 19.0, $set->standard( 'DE' ) );
		$this->assertSame( 'tedb', $set->source() );
		$this->assertSame( '2026-10-17', $set->situation_on() );
	}

	public function test_response_with_a_dtd_is_refused(): void {
		$xml = preg_replace( '/^(<\?xml[^>]*\?>)?/', '$1<!DOCTYPE r [<!ENTITY a "aaaa">]>', $this->fixture( 'response.xml' ), 1 );

		$this->assertStringContainsString( '<!DOCTYPE', (string) $xml );
		$this->assertCount( 0, Tedb::parse( (string) $xml, '2026-10-17' )->country_codes() );
	}

	public function test_greece_is_keyed_gr_not_el(): void {
		$set = Tedb::parse( $this->fixture( 'response.xml' ), '2026-10-17' );

		$this->assertTrue( $set->has( 'GR' ) );
		$this->assertFalse( $set->has( 'EL' ) );
		$this->assertSame( 24.0, $set->standard( 'GR' ) );
	}

	public function test_canary_islands_row_does_not_become_spains_standard_rate(): void {
		$set = Tedb::parse( $this->fixture( 'response.xml' ), '2026-10-17' );

		$this->assertSame( 21.0, $set->standard( 'ES' ) );
	}

	public function test_exempt_zero_and_regional_rows_are_not_reduced_rates(): void {
		$set = Tedb::parse( $this->fixture( 'response.xml' ), '2026-10-17' );

		$rates = array_column( $set->reduced( 'FR' ), 'rate' );
		$this->assertSame( array( 10.0, 5.5, 2.1 ), $rates );
		foreach ( $set->country_codes() as $country ) {
			foreach ( $set->reduced( $country ) as $reduced ) {
				$this->assertGreaterThan( 0, $reduced['rate'], $country );
			}
		}
	}

	public function test_reduced_rates_carry_their_categories(): void {
		$set = Tedb::parse( $this->fixture( 'response.xml' ), '2026-10-17' );

		$german = $set->reduced( 'DE' );
		$this->assertCount( 1, $german );
		$this->assertSame( 7.0, $german[0]['rate'] );
		$this->assertSame( 'reduced', $german[0]['type'] );
		$this->assertContains( 'FOODSTUFFS', $german[0]['categories'] );
		$this->assertContains( 'NEWSPAPERS', $german[0]['categories'] );
	}

	public function test_snapshot_has_all_27(): void {
		$set = Snapshot::load();

		$this->assertCount( 27, $set->country_codes() );
		foreach ( Tedb::MEMBER_STATES as $country ) {
			$this->assertGreaterThan( 0, $set->standard( $country ), $country );
		}
		$this->assertSame( 19.0, $set->standard( 'DE' ) );
	}

	public function test_request_body_is_namespace_qualified_and_asks_for_el(): void {
		$doc = new DOMDocument();
		$this->assertTrue( $doc->loadXML( Tedb::request_body( '2026-10-17' ) ) );
		$xpath = new DOMXPath( $doc );
		$xpath->registerNamespace( 't', Tedb::NS_TYPES );

		$codes = array();
		foreach ( $xpath->query( '//t:memberStates/t:isoCode' ) ?: array() as $node ) { // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
			$codes[] = $node->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
		$this->assertCount( 27, $codes );
		$this->assertContains( 'EL', $codes );
		$this->assertNotContains( 'GR', $codes );
		$this->assertSame( '2026-10-17', $xpath->evaluate( 'string(//t:situationOn)' ) );
	}

	public function test_fetch_returns_rate_set_from_a_successful_response(): void {
		$set = $this->tedb_answering( 200, $this->fixture( 'response.xml' ) )->fetch( '2026-10-17' );

		$this->assertNotNull( $set );
		$this->assertSame( 19.0, $set->standard( 'DE' ) );
	}

	public function test_fault_response_returns_null(): void {
		$this->assertNull( $this->tedb_answering( 500, $this->fixture( 'fault.xml' ) )->fetch( '2026-10-17' ) );
	}

	public function test_malformed_body_returns_null(): void {
		$this->assertNull( $this->tedb_answering( 200, '<html>maintenance' )->fetch( '2026-10-17' ) );
	}

	public function test_transport_failure_returns_null(): void {
		$tedb = new Tedb( new FixtureTransport( $this->dir ) );

		$this->assertNull( $tedb->fetch( '2026-10-17' ) );
	}

	public function test_partial_response_returns_null(): void {
		$xml = preg_replace( '#<vatRateResults><memberState>ES</memberState>.*?</vatRateResults>#s', '', $this->fixture( 'response.xml' ) );
		$this->assertIsString( $xml );
		$this->assertNotSame( $this->fixture( 'response.xml' ), $xml );

		$this->assertNull( $this->tedb_answering( 200, $xml )->fetch( '2026-10-17' ) );
	}
}
