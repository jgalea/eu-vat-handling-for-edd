<?php

namespace VatrightEdd\Tests\Integration\Rates;

use VatrightEdd\Ledger\Schema;
use VatrightEdd\Rates\RateDiff;
use VatrightEdd\Rates\RateSet;
use VatrightEdd\Rates\RateSync;
use VatrightEdd\Rates\Tedb;
use VatrightEdd\Tests\Support\FixtureTransport;
use VatrightEdd\Tests\Support\IntegrationTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/FixtureTransport.php';

class RateSyncTest extends IntegrationTestCase {

	/** @var RateSync */
	private $sync;

	/** @var string */
	private $fixture_dir;

	public function set_up() {
		parent::set_up();
		global $wpdb;
		Schema::install();
		$wpdb->query( 'DELETE FROM ' . Schema::rate_rows_table() ); // phpcs:ignore WordPress.DB
		foreach ( edd_get_tax_rates() as $rate ) {
			edd_delete_tax_rate( $rate['id'] );
		}
		delete_option( RateSync::OPTION_LATEST );
		delete_option( RateSync::OPTION_PENDING );

		$this->fixture_dir = sys_get_temp_dir() . '/vatright-edd-sync-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->fixture_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		$this->sync = new RateSync( new Tedb( new FixtureTransport( $this->fixture_dir ) ) );
	}

	public function tear_down() {
		foreach ( glob( $this->fixture_dir . '/*' ) ?: array() as $file ) { // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		rmdir( $this->fixture_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		parent::tear_down();
	}

	private function tedb_xml(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/tedb/response.xml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	private function tedb_set(): RateSet {
		return Tedb::parse( $this->tedb_xml(), '2026-10-17' );
	}

	private function apply_all( RateSet $set, bool $overwrite = false ): int {
		$diff = $this->sync->plan( $set );
		return $this->sync->apply( $diff, array_keys( $diff->entries() ), $overwrite );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function active_country_rates(): array {
		$active = array();
		foreach ( edd_get_tax_rates() as $rate ) {
			if ( 'active' === $rate['status'] && 'country' === $rate['scope'] ) {
				$active[ $rate['country'] ] = $rate;
			}
		}
		return $active;
	}

	private function add_manual_rate( string $country, float $amount ): int {
		return (int) edd_add_tax_rate(
			array(
				'name'   => $country,
				'amount' => $amount,
				'scope'  => 'country',
				'status' => 'active',
			)
		);
	}

	public function test_applying_the_tedb_fixture_creates_the_27_standard_rates(): void {
		$this->assertSame( 27, $this->apply_all( $this->tedb_set() ) );

		$active = $this->active_country_rates();
		$this->assertCount( 27, $active );
		$this->assertSame( 19.0, $active['DE']['rate'] );
		$this->assertSame( 20.0, $active['FR']['rate'] );
		$this->assertSame( 24.0, $active['GR']['rate'] );
	}

	public function test_a_manual_rate_is_kept_as_inactive_history_and_the_import_is_tracked(): void {
		global $wpdb;
		$manual = $this->add_manual_rate( 'DE', 18 );
		$set    = $this->tedb_set();
		$this->assertSame( RateDiff::EDITED, $this->sync->plan( $set )->entries()['DE|']['status'] );

		$this->assertSame( 27, $this->apply_all( $set, true ) );

		$inactive = array_filter(
			edd_get_tax_rates(),
			static function ( array $rate ) use ( $manual ): bool {
				return $rate['id'] === $manual && 'inactive' === $rate['status'];
			}
		);
		$this->assertCount( 1, $inactive );
		$active = $this->active_country_rates();
		$this->assertSame( 19.0, $active['DE']['rate'] );
		$this->assertNotSame( $manual, $active['DE']['id'] );

		$table = Schema::rate_rows_table();
		$this->assertSame( 27, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore WordPress.DB
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE edd_rate_id = %d", $active['DE']['id'] ) ); // phpcs:ignore WordPress.DB
		$this->assertNotNull( $row );
		$this->assertSame( 'DE', $row->country );
		$this->assertSame( 19.0, (float) $row->imported_rate );
		$this->assertSame( 'tedb', $row->source );
		$this->assertSame( '2026-10-17', $row->source_date );
		$this->assertNull( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE edd_rate_id = %d", $manual ) ) ); // phpcs:ignore WordPress.DB
	}

	public function test_an_edited_rate_is_left_alone_without_the_overwrite_flag(): void {
		$this->add_manual_rate( 'DE', 18 );

		$this->assertSame( 26, $this->apply_all( $this->tedb_set() ) );
		$this->assertSame( 18.0, $this->active_country_rates()['DE']['rate'] );
	}

	public function test_a_second_apply_with_nothing_changed_is_a_no_op(): void {
		$set = $this->tedb_set();
		$this->apply_all( $set, true );
		$before = count( edd_get_tax_rates() );

		$this->assertSame( 0, $this->sync->plan( $set )->pending_count() );
		$this->assertSame( 0, $this->apply_all( $set, true ) );
		$this->assertCount( $before, edd_get_tax_rates() );
		$this->assertSame( 0, (int) get_option( RateSync::OPTION_PENDING ) );
	}

	public function test_a_rate_change_from_tedb_replaces_an_untouched_import(): void {
		$this->apply_all( $this->tedb_set() );
		$changed                                = $this->tedb_set()->to_array();
		$changed['countries']['DE']['standard'] = 21.0;
		$set                                    = RateSet::from_array( $changed );

		$diff = $this->sync->plan( $set );
		$this->assertSame( RateDiff::CHANGE, $diff->entries()['DE|']['status'] );
		$this->assertSame( 1, $diff->pending_count() );

		$this->assertSame( 1, $this->apply_all( $set ) );
		$this->assertSame( 21.0, $this->active_country_rates()['DE']['rate'] );
		$this->assertSame( 0, $this->sync->plan( $set )->pending_count() );
	}

	public function test_only_accepted_keys_are_applied(): void {
		$diff = $this->sync->plan( $this->tedb_set() );

		$this->assertSame( 1, $this->sync->apply( $diff, array( 'DE|' ), false ) );
		$this->assertSame( array( 'DE' ), array_keys( $this->active_country_rates() ) );
		$this->assertSame( 26, (int) get_option( RateSync::OPTION_PENDING ) );
	}

	public function test_regional_rates_are_not_treated_as_the_country_rate(): void {
		edd_add_tax_rate(
			array(
				'name'        => 'US',
				'description' => 'CA',
				'amount'      => 7.25,
				'scope'       => 'region',
				'status'      => 'active',
			)
		);

		$this->assertSame( 27, $this->apply_all( $this->tedb_set() ) );
		$this->assertArrayNotHasKey( 'US', $this->active_country_rates() );
	}

	public function test_check_fetches_and_records_the_pending_count(): void {
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$this->fixture_dir . '/tedb.json',
			(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				array(
					'request'  => array(
						'method' => 'POST',
						'url'    => Tedb::ENDPOINT,
					),
					'response' => array(
						'status' => 200,
						'body'   => $this->tedb_xml(),
					),
				)
			)
		);

		$this->sync->check();

		$this->assertSame( 27, (int) get_option( RateSync::OPTION_PENDING ) );
		$this->assertSame( 19.0, $this->sync->latest()->standard( 'DE' ) );
	}

	public function test_a_failed_fetch_keeps_the_bundled_snapshot(): void {
		$this->assertFalse( $this->sync->refresh() );
		$this->assertSame( 'tedb', $this->sync->latest()->source() );
		$this->assertCount( 27, $this->sync->latest()->country_codes() );
	}
}
