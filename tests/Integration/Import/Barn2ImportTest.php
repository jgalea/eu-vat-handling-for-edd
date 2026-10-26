<?php

namespace VatrightEdd\Tests\Integration\Import;

use EDD\Orders\Order;
use VatrightEdd\Domain\Rule;
use VatrightEdd\Domain\Scheme;
use VatrightEdd\Import\Barn2History;
use VatrightEdd\Import\Importer;
use VatrightEdd\Ledger\Fx;
use VatrightEdd\Ledger\LedgerWriter;
use VatrightEdd\Ledger\Schema;
use VatrightEdd\Ledger\ThresholdTracker;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Tests\Support\LedgerTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/LedgerTestCase.php';

/**
 * Orders are built through the checkout, dated, then stripped of everything
 * the plugin wrote and given the meta Barn2 EDD EU VAT leaves. All dates are
 * fixed; the import window is passed in, so no result depends on today.
 */
class Barn2ImportTest extends LedgerTestCase {

	const SINCE = '2025-01-01';

	const BARN2_NAME = 'Beispiel GmbH';

	const CONSULTATION = 'WAPIAAAAY1234567';

	/** @var array<string,Order> */
	private $orders = array();

	/** @var Importer */
	private $importer;

	public function set_up() {
		parent::set_up();
		$this->importer = new Importer( $this->backfill, new Barn2History( $this->backfill ), $this->ledger, new Fx( $this->ecb ) );
		$this->orders   = array();
	}

	public function test_a_reverse_charge_under_the_legacy_key_keeps_the_number_and_registry_answer(): void {
		$this->build_history();
		$this->importer->run_all( $this->since() );

		$decision = $this->decision( 'rc_legacy' );
		$this->assertSame( Scheme::REVERSE_CHARGE_SERVICES, $decision->scheme() );
		$this->assertSame( Rule::RC_SERVICES_ART196, $decision->rule() );
		$this->assertTrue( $decision->is_vat_exempt() );
		$this->assertSame( Barn2History::TRIGGER, $decision->trigger() );
		$record = $decision->to_array();
		$this->assertSame( self::VALID_DE, $record['vat_number']['canonical'] );
		$this->assertSame( 'valid', $record['validation']['status'] );
		$this->assertSame( self::BARN2_NAME, $record['validation']['name'] );
		$this->assertSame( self::CONSULTATION, $record['validation']['consultation'] );
		$this->assertSame( array( 'art196' ), $decision->legal_text() );
		foreach ( array( 'imported', 'evidence_billing_only', 'registry_as_recorded' ) as $flag ) {
			$this->assertContains( $flag, $decision->flags() );
		}
		$this->assertNotContains( 'backfilled', $decision->flags() );
		$this->assertNotContains( 'vat_number_unknown', $decision->flags() );

		$rows = $this->rows( $this->id( 'rc_legacy' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'barn2', $rows[0]['source'] );
		$this->assertSame( self::VALID_DE, $rows[0]['customer_vat'] );
		$this->assertSame( 0.0, (float) $rows[0]['vat'] );
		$this->assertSame( 'barn2', edd_get_order_meta( $this->id( 'rc_legacy' ), Barn2History::IMPORTED_META, true )['source'] );
	}

	public function test_a_reverse_charge_under_the_current_key_is_imported_the_same_way(): void {
		$this->build_history();
		$this->importer->run_all( $this->since() );

		$decision = $this->decision( 'rc_current' );
		$this->assertSame( Scheme::REVERSE_CHARGE_SERVICES, $decision->scheme() );
		$this->assertSame( self::VALID_DE, $decision->to_array()['vat_number']['canonical'] );
		$this->assertSame( 'barn2', $this->rows( $this->id( 'rc_current' ) )[0]['source'] );
	}

	public function test_an_invalid_number_that_was_charged_stays_charged_and_says_so(): void {
		$this->build_history();
		$this->importer->run_all( $this->since() );

		$decision = $this->decision( 'invalid' );
		$this->assertSame( Rule::INVALID_NUMBER, $decision->rule() );
		$this->assertSame( Scheme::OSS_DESTINATION, $decision->scheme() );
		$this->assertFalse( $decision->is_vat_exempt() );
		$this->assertSame( 'invalid', $decision->to_array()['validation']['status'] );
		$rows = $this->rows( $this->id( 'invalid' ) );
		$this->assertSame( 19.0, (float) $rows[0]['vat'] );
		$this->assertNull( $rows[0]['customer_vat'] );
	}

	public function test_orders_charged_the_destination_rate_domestic_or_outside_the_eu_are_classified_from_the_charge(): void {
		$this->build_history();
		$this->importer->run_all( $this->since() );

		$b2c = $this->decision( 'b2c' );
		$this->assertSame( Scheme::OSS_DESTINATION, $b2c->scheme() );
		$this->assertSame( Rule::B2C_DESTINATION, $b2c->rule() );
		$this->assertNull( $b2c->to_array()['vat_number'] );
		$this->assertNotContains( 'registry_as_recorded', $b2c->flags() );
		$this->assertSame( 'DE', $b2c->tax_country() );

		$domestic = $this->decision( 'domestic' );
		$this->assertSame( Scheme::DOMESTIC, $domestic->scheme() );
		$this->assertSame( 'MT', $domestic->tax_country() );
		$this->assertSame( 18.0, (float) $this->rows( $this->id( 'domestic' ) )[0]['vat'] );

		$export = $this->decision( 'non_eu' );
		$this->assertSame( Scheme::EXPORT, $export->scheme() );
		$this->assertSame( 'barn2', $this->rows( $this->id( 'non_eu' ) )[0]['source'] );
	}

	public function test_barn2_never_applied_the_store_rate_so_a_charge_at_the_stores_rate_is_still_destination(): void {
		$this->build_history();
		$order = $this->orders['b2c'];
		edd_update_order( (int) $order->id, array( 'tax_rate_id' => $this->active_rate_id( 'MT' ) ) );

		$this->assertSame( Scheme::OSS_DESTINATION, ( new Barn2History( $this->backfill ) )->decision( $this->fresh( $order ) )->scheme() );
	}

	public function test_an_order_without_barn2_meta_is_backfilled_not_imported(): void {
		$this->build_history();
		$this->importer->run_all( $this->since() );

		$decision = $this->decision( 'pre_barn2' );
		$this->assertSame( 'backfill', $decision->trigger() );
		$this->assertSame( Scheme::ORIGIN, $decision->scheme() );
		$this->assertContains( 'backfilled', $decision->flags() );
		$this->assertSame( 'backfill', $this->rows( $this->id( 'pre_barn2' ) )[0]['source'] );
		$this->assertSame( '', (string) edd_get_order_meta( $this->id( 'pre_barn2' ), Barn2History::IMPORTED_META, true ) );
	}

	public function test_a_partial_refund_writes_its_refund_row_in_the_refund_quarter_with_the_sales_source(): void {
		$this->build_history();
		$this->importer->run_all( $this->since() );

		$rows = $this->rows( $this->id( 'refunded' ) );
		$this->assertSame( array( 'sale', 'refund' ), array_column( $rows, 'entry_type' ) );
		$this->assertSame( array( 'barn2', 'barn2' ), array_column( $rows, 'source' ) );
		$this->assertSame( array( '2026-Q1', '2026-Q1' ), array_column( $rows, 'period' ) );
		$this->assertSame( 200.0, (float) $rows[0]['net'] );
		$this->assertSame( 40.0, (float) $rows[0]['vat'] );
		$this->assertSame( -100.0, (float) $rows[1]['net'] );
		$this->assertSame( -20.0, (float) $rows[1]['vat'] );
		$this->assertSame( 'partially_refunded', $this->fresh( $this->orders['refunded'] )->status );
	}

	public function test_a_renewal_is_imported_like_any_other_order(): void {
		$this->build_history();
		$this->importer->run_all( $this->since() );

		$this->assertSame( 'edd_subscription', $this->fresh( $this->orders['renewal'] )->status );
		$rows = $this->rows( $this->id( 'renewal' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( Scheme::REVERSE_CHARGE_SERVICES, $rows[0]['scheme'] );
		$this->assertSame( 'barn2', $rows[0]['source'] );
	}

	public function test_a_usd_order_converts_at_the_ecb_rate_fetched_once_for_the_whole_range(): void {
		$this->build_history();
		$this->ecb->calls = array();

		$this->importer->run_all( $this->since() );

		$usd_requests = array_filter(
			$this->ecb->calls,
			static function ( string $url ): bool {
				return false !== strpos( $url, '/D.USD.EUR.' );
			}
		);
		$this->assertCount( 1, $usd_requests );
		$row = $this->rows( $this->id( 'usd' ) )[0];
		$this->assertSame( 'USD', $row['currency'] );
		$this->assertSame( 1.1204, (float) $row['eur_rate'] );
		$this->assertSame( round( 100 / 1.1204, 4 ), (float) $row['net_eur'] );
		$this->assertSame( round( 19 / 1.1204, 4 ), (float) $row['vat_eur'] );
		$this->assertSame( '2026-Q4', $row['period'] );
	}

	public function test_orders_outside_the_window_and_unsettled_orders_are_left_alone(): void {
		$this->build_history();
		$this->importer->run_all( $this->since() );

		$this->assertSame( array(), $this->rows( $this->id( 'before_window' ) ) );
		$this->assertNull( DecisionStore::get( $this->id( 'before_window' ) ) );
		$this->assertSame( array(), $this->rows( $this->id( 'pending' ) ) );
		$this->assertNull( DecisionStore::get( $this->id( 'pending' ) ) );
	}

	public function test_the_dry_run_counts_exactly_the_rows_the_run_writes_and_writes_nothing(): void {
		$this->build_history();
		$this->assertSame( 0, $this->ledger_total_rows() );

		$plan = $this->importer->dry_run( $this->since() );

		$this->assertSame( 0, $this->ledger_total_rows(), 'The dry run wrote ledger rows.' );
		$this->assertNull( DecisionStore::get( $this->id( 'rc_legacy' ) ), 'The dry run stored a decision.' );
		$this->assertNull( Importer::state() );
		$this->assertSame( 10, $plan['orders'], 'Ten settled orders are in the window.' );
		$this->assertSame( 9, $plan['barn2_orders'] );
		$this->assertSame( 1, $plan['backfill_orders'] );
		$this->assertGreaterThan( 0, $plan['rows'] );

		$state = $this->importer->run_all( $this->since() );

		$written = $this->written_by_quarter_and_scheme();
		$this->assertSame( $plan['rows'], $this->ledger_total_rows() );
		$this->assertSame( $plan['orders'], $state['orders'] );
		$this->assertSame( $plan['rows'], $state['rows'] );
		$this->assertSame( 0, $state['failed'] );
		$this->assertSame( array_keys( $plan['quarters'] ), array_keys( $written ) );
		foreach ( $plan['quarters'] as $quarter => $counts ) {
			ksort( $counts['schemes'] );
			$this->assertSame( $counts['schemes'], $written[ $quarter ], "Rows for {$quarter} differ between the dry run and the run." );
			$this->assertSame( $counts['rows'], array_sum( $written[ $quarter ] ) );
		}
		$this->assertSame( array( '2025-Q3', '2025-Q4', '2026-Q1', '2026-Q4' ), array_keys( $plan['quarters'] ) );
	}

	public function test_a_second_run_writes_nothing(): void {
		$this->build_history();
		$this->importer->run_all( $this->since() );
		$rows   = $this->ledger_total_rows();
		$ids    = $this->decision_ids();
		$log    = count( DecisionStore::log( $this->id( 'rc_legacy' ) ) );
		$marked = edd_get_order_meta( $this->id( 'rc_legacy' ), Barn2History::IMPORTED_META, true );
		$again  = $this->importer->dry_run( $this->since() );

		$this->assertSame( 0, $again['orders'], 'The second dry run still sees work.' );
		$this->assertSame( 0, $again['rows'] );

		$state = $this->importer->run_all( $this->since() );

		$this->assertSame( 0, $state['orders'] );
		$this->assertSame( 0, $state['rows'] );
		$this->assertSame( $rows, $this->ledger_total_rows() );
		$this->assertSame( $ids, $this->decision_ids() );
		$this->assertSame( $log, count( DecisionStore::log( $this->id( 'rc_legacy' ) ) ) );
		$this->assertSame( $marked, edd_get_order_meta( $this->id( 'rc_legacy' ), Barn2History::IMPORTED_META, true ) );
	}

	public function test_the_oss_and_ecsl_totals_for_each_quarter_match_the_hand_figures(): void {
		$this->build_history();
		$this->importer->run_all( $this->since() );

		$this->assertSame( 38.0, $this->total( 'oss_destination', '2025-Q3', 'vat_eur' ), 'One invalid-number sale and one B2C sale at 19%.' );
		$this->assertSame( 200.0, $this->total( 'oss_destination', '2025-Q3', 'net_eur' ) );
		$this->assertSame( 200.0, $this->total( 'reverse_charge_services', '2025-Q3', 'net_eur' ), 'Two ECSL sales of 100.' );
		$this->assertSame( 0.0, $this->total( 'reverse_charge_services', '2025-Q3', 'vat_eur' ) );
		$this->assertSame( 18.0, $this->total( 'domestic', '2025-Q4', 'vat_eur' ) );
		$this->assertSame( 18.0, $this->total( 'origin', '2025-Q4', 'vat_eur' ) );
		$this->assertSame( 100.0, $this->total( 'export', '2025-Q4', 'net_eur' ) );
		$this->assertSame( 100.0, $this->total( 'oss_destination', '2026-Q1', 'net_eur' ), 'EUR 200 sold, EUR 100 refunded.' );
		$this->assertSame( 20.0, $this->total( 'oss_destination', '2026-Q1', 'vat_eur' ) );
		$this->assertSame( 100.0, $this->total( 'reverse_charge_services', '2026-Q1', 'net_eur' ), 'The renewal.' );
		$this->assertSame( round( 19 / 1.1204, 4 ), $this->total( 'oss_destination', '2026-Q4', 'vat_eur' ) );
	}

	public function test_the_threshold_reads_the_imported_sales(): void {
		$this->build_history();
		ThresholdTracker::flush( 2025, 2026 );
		$this->assertSame( 0.0, ( new ThresholdTracker() )->status( 2025 )->current_eur );

		$this->importer->run_all( $this->since() );

		$this->assertGreaterThan( 0.0, ( new ThresholdTracker() )->status( 2025 )->current_eur );
	}

	public function test_batches_continue_through_the_scheduler_until_the_history_is_done(): void {
		$this->build_history();
		remove_all_actions( Importer::HOOK );
		$this->importer->register();

		$this->assertTrue( $this->importer->start( $this->since() ) );
		$this->assertFalse( $this->importer->start( $this->since() ), 'A second start while one is running is refused.' );
		$state = Importer::state();
		$this->assertSame( Importer::RUNNING, $state['status'] );
		$this->assertSame( 0, $this->ledger_total_rows() );

		do_action( Importer::HOOK, 0 );

		$state = Importer::state();
		$this->assertSame( Importer::DONE, $state['status'] );
		$this->assertSame( 10, $state['orders'] );
		$this->assertGreaterThan( 0, $this->ledger_total_rows() );
		$this->assertFalse( Importer::is_running() );

		do_action( Importer::HOOK, 0 );
		$this->assertSame( 10, Importer::state()['orders'], 'A stale batch does nothing.' );
	}

	/**
	 * @return array<string,array<string,int>>
	 */
	private function written_by_quarter_and_scheme(): array {
		global $wpdb;
		$table = Schema::ledger_table();
		$found = (array) $wpdb->get_results( "SELECT period, scheme, COUNT(*) AS n FROM {$table} GROUP BY period, scheme ORDER BY period, scheme", ARRAY_A ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$by    = array();
		foreach ( $found as $row ) {
			$by[ $row['period'] ][ $row['scheme'] ] = (int) $row['n'];
		}
		foreach ( $by as $quarter => $schemes ) {
			ksort( $schemes );
			$by[ $quarter ] = $schemes;
		}
		return $by;
	}

	private function total( string $scheme, string $period, string $column ): float {
		global $wpdb;
		$table = Schema::ledger_table();
		return round( (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM({$column}) FROM {$table} WHERE scheme = %s AND period = %s", $scheme, $period ) ), 4 ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * @return array<string,string>
	 */
	private function decision_ids(): array {
		$ids = array();
		foreach ( $this->orders as $name => $order ) {
			$decision = DecisionStore::get( (int) $order->id );
			if ( null !== $decision ) {
				$ids[ $name ] = $decision->id();
			}
		}
		return $ids;
	}

	private function since(): \DateTimeImmutable {
		return new \DateTimeImmutable( self::SINCE . ' 00:00:00', wp_timezone() );
	}

	private function id( string $name ): int {
		return (int) $this->orders[ $name ]->id;
	}

	private function decision( string $name ): \VatrightEdd\Domain\Decision {
		$decision = DecisionStore::get( $this->id( $name ) );
		$this->assertNotNull( $decision, "No decision for {$name}." );
		return $decision;
	}

	/**
	 * Builds the fixture shop history. Each order is placed through the
	 * checkout, then everything the plugin wrote is removed and Barn2's meta
	 * put in its place.
	 */
	private function build_history(): void {
		$barn2 = array(
			'_edd_payment_vat_is_eu' => '1',
		);
		$rc    = array(
			'_edd_payment_vat_number'              => self::VALID_DE,
			'_edd_payment_vat_number_valid'        => '1',
			'_edd_payment_vat_company_name'        => self::BARN2_NAME,
			'_edd_payment_vat_company_address'     => 'Musterstrasse 1, 10115 Berlin',
			'_edd_payment_vat_consultation_number' => self::CONSULTATION,
		);

		$this->settings( array( 'oss_registered' => true ) );
		$this->orders['rc_legacy']  = $this->place( 'DE', '2025-08-05 10:00:00', self::VALID_DE, array_merge( $barn2, $rc, array( '_edd_payment_vat_reversed_charged' => '1' ) ) );
		$this->orders['rc_current'] = $this->place( 'DE', '2025-08-12 10:00:00', self::VALID_DE, array_merge( $barn2, $rc, array( '_edd_payment_vat_reverse_charged' => '1' ) ) );
		$this->orders['invalid']    = $this->place(
			'DE',
			'2025-08-20 10:00:00',
			null,
			array_merge(
				$barn2,
				array(
					'_edd_payment_vat_number'       => self::INVALID_DE,
					'_edd_payment_vat_number_valid' => '',
				)
			)
		);
		$this->orders['b2c']        = $this->place( 'DE', '2025-09-02 10:00:00', null, $barn2 );
		$this->orders['domestic']   = $this->place( 'MT', '2025-11-10 10:00:00', null, $barn2 );
		$this->orders['non_eu']     = $this->place( 'US', '2025-11-20 10:00:00', null, array( '_edd_payment_vat_is_eu' => '0' ) );

		$this->settings( array() );
		$this->orders['pre_barn2'] = $this->place( 'FR', '2025-12-03 10:00:00', null, array() );

		$this->settings( array( 'oss_registered' => true ) );
		$this->orders['refunded'] = $this->place_refunded( $barn2 );
		$this->orders['renewal']  = $this->place( 'DE', '2026-02-20 10:00:00', self::VALID_DE, array_merge( $barn2, $rc, array( '_edd_payment_vat_reverse_charged' => '1' ) ), 'edd_subscription' );

		$this->order_currency = 'USD';
		$this->orders['usd']  = $this->place( 'DE', '2026-10-05 10:00:00', null, $barn2 );
		$this->order_currency = 'EUR';
		$this->wipe_fx( $this->orders['usd'] );

		$this->orders['before_window'] = $this->place( 'DE', '2024-05-01 10:00:00', null, $barn2 );
		$this->orders['pending']       = $this->place( 'DE', '2025-09-10 10:00:00', null, $barn2, 'pending' );

		ThresholdTracker::flush( 2024, 2025, 2026 );
		$this->ecb->calls = array();
	}

	/**
	 * @param array<string,string> $meta
	 */
	private function place( string $country, string $when, ?string $number, array $meta, string $status = 'complete' ): Order {
		edd_empty_cart();
		$this->buy( $this->download(), $country );
		if ( null !== $number ) {
			$this->enter_number( $number, $country );
		}
		$order = $this->place_order( $country );
		$this->dated( $order, $when );
		if ( 'pending' !== $status ) {
			$this->complete( $order );
			if ( 'complete' !== $status ) {
				$this->status( $order, $status );
			}
		}
		$this->strip( $order );
		foreach ( $meta as $key => $value ) {
			edd_update_order_meta( (int) $order->id, $key, $value );
		}
		return $this->fresh( $order );
	}

	/**
	 * @param array<string,string> $meta
	 */
	private function place_refunded( array $meta ): Order {
		edd_empty_cart();
		$this->buy( $this->download(), 'FR' );
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->dated( $order, '2026-01-15 10:00:00' );
		$this->complete( $order );
		$item = $this->fresh( $order )->get_items()[1];
		edd_refund_order(
			(int) $order->id,
			array(
				array(
					'order_item_id' => $item->id,
					'quantity'      => 1,
					'subtotal'      => (float) $item->subtotal,
					'tax'           => (float) $item->tax,
				),
			)
		);
		foreach ( edd_get_order_refunds( (int) $order->id ) as $refund ) {
			$this->dated( $refund, '2026-02-15 10:00:00' );
		}
		$this->strip( $order );
		foreach ( $meta as $key => $value ) {
			edd_update_order_meta( (int) $order->id, $key, $value );
		}
		return $this->fresh( $order );
	}

	private function strip( Order $order ): void {
		global $wpdb;
		$wpdb->delete( Schema::ledger_table(), array( 'order_id' => (int) $order->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( array( DecisionStore::META, DecisionStore::LOG_META, DecisionStore::NUMBER_META, DecisionStore::REVERSE_CHARGED_META ) as $key ) {
			edd_delete_order_meta( (int) $order->id, $key );
		}
	}

	private function wipe_fx( Order $order ): void {
		global $wpdb;
		edd_delete_order_meta( (int) $order->id, LedgerWriter::FX_META );
		$wpdb->query( 'DELETE FROM ' . Schema::fx_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%vatright_edd_fx_%'" ); // phpcs:ignore WordPress.DB
		wp_cache_flush();
	}
}
