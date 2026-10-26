<?php

namespace VatrightEdd\Tests\Integration\Ledger;

use EDD\Orders\Order;
use VatrightEdd\Domain\Rule;
use VatrightEdd\Domain\Scheme;
use VatrightEdd\Ledger\Backfill;
use VatrightEdd\Ledger\Schema;
use VatrightEdd\Ledger\ThresholdTracker;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Plugin;
use VatrightEdd\Rates\RateResolver;
use VatrightEdd\Scheduler;
use VatrightEdd\Tests\Support\LedgerTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/LedgerTestCase.php';

class BackfillTest extends LedgerTestCase {

	/** @var string */
	private $recent;

	public function set_up() {
		parent::set_up();
		$this->recent = ( (int) wp_date( 'Y' ) - 1 ) . '-06-15 10:00:00';
	}

	/**
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string,3:string}>
	 */
	public function treatments(): array {
		return array(
			'origin'      => array( 'FR', array(), Scheme::ORIGIN, Rule::B2C_ORIGIN_BELOW_THRESHOLD ),
			'destination' => array( 'DE', array( 'oss_registered' => true ), Scheme::OSS_DESTINATION, Rule::B2C_DESTINATION ),
			'domestic'    => array( 'MT', array(), Scheme::DOMESTIC, Rule::DOMESTIC ),
			'export'      => array( 'US', array(), Scheme::EXPORT, Rule::EXPORT_NON_EU ),
		);
	}

	/**
	 * @dataProvider treatments
	 * @param array<string,mixed> $settings
	 */
	public function test_an_order_from_before_activation_gets_the_rows_it_would_have_had( string $country, array $settings, string $scheme, string $rule ): void {
		$this->settings( $settings );
		$order = $this->completed_order( $country );
		$live  = $this->comparable( $this->rows( (int) $order->id ) );
		$this->assertSame( $scheme, $live[0]['scheme'], 'The live decision is the one under test.' );
		$this->wipe( $order );

		$this->assertTrue( $this->backfill->backfill_order( $this->fresh( $order ) ) );

		$this->assertSame( $live, $this->comparable( $this->rows( (int) $order->id ) ) );
		$this->assertSame( 'backfill', $this->rows( (int) $order->id )[0]['source'] );
		$decision = DecisionStore::get( (int) $order->id );
		$this->assertSame( Backfill::TRIGGER, $decision->trigger() );
		$this->assertSame( $rule, $decision->rule() );
		$this->assertContains( 'backfilled', $decision->flags() );
	}

	public function test_an_untaxed_sale_to_another_eu_country_is_a_reverse_charge_with_the_number_unknown(): void {
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		$order = $this->place_order( 'DE' );
		$this->dated( $order, $this->recent );
		$this->complete( $order );
		$this->wipe( $order );

		$this->backfill->backfill_order( $this->fresh( $order ) );

		$row = $this->rows( (int) $order->id )[0];
		$this->assertSame( Scheme::REVERSE_CHARGE_SERVICES, $row['scheme'] );
		$this->assertSame( 0.0, (float) $row['vat'] );
		$this->assertNull( $row['customer_vat'] );
		$this->assertContains( Backfill::UNKNOWN_NUMBER_FLAG, DecisionStore::get( (int) $order->id )->flags() );
	}

	public function test_a_second_run_writes_nothing(): void {
		$first  = $this->completed_order( 'FR' );
		$second = $this->completed_order( 'DE' );
		$this->wipe( $first );
		$this->wipe( $second );

		$this->backfill->run_batch( 0 );
		$rows_after_first = $this->ledger_total_rows();
		$ids              = array(
			DecisionStore::get( (int) $first->id )->id(),
			DecisionStore::get( (int) $second->id )->id(),
		);
		$log              = count( DecisionStore::log( (int) $first->id ) );
		$this->assertSame( 2, $rows_after_first, 'The first run writes the two orders.' );

		$this->backfill->run_batch( 0 );

		$this->assertSame( $rows_after_first, $this->ledger_total_rows() );
		$this->assertSame(
			$ids,
			array(
				DecisionStore::get( (int) $first->id )->id(),
				DecisionStore::get( (int) $second->id )->id(),
			)
		);
		$this->assertSame( $log, count( DecisionStore::log( (int) $first->id ) ) );
		$this->assertFalse( $this->backfill->backfill_order( $this->fresh( $first ) ) );
	}

	public function test_orders_the_plugin_already_wrote_are_left_alone(): void {
		$order = $this->completed_order( 'FR' );
		$rows  = $this->rows( (int) $order->id );

		$this->backfill->run_batch( 0 );

		$this->assertSame( $rows, $this->rows( (int) $order->id ) );
		$this->assertSame( 'live', $rows[0]['source'] );
	}

	public function test_an_order_with_a_decision_but_no_rows_keeps_its_decision(): void {
		$order = $this->completed_order( 'FR' );
		$id    = DecisionStore::get( (int) $order->id )->id();
		$this->wipe_rows( $order );

		$this->backfill->run_batch( 0 );

		$this->assertSame( $id, DecisionStore::get( (int) $order->id )->id() );
		$this->assertCount( 1, $this->rows( (int) $order->id ) );
	}

	public function test_a_free_order_is_looked_at_once(): void {
		$this->buy( $this->download( 0.0 ), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->dated( $order, $this->recent );
		$this->complete( $order );
		$this->wipe( $order );

		$this->assertTrue( $this->backfill->pending( $this->fresh( $order ) ) );
		$this->backfill->run_batch( 0 );

		$this->assertSame( 0, $this->ledger_total_rows() );
		$this->assertFalse( $this->backfill->pending( $this->fresh( $order ) ), 'Its inferred decision marks it as seen.' );
	}

	public function test_orders_another_vat_plugin_recorded_are_left_to_the_importer(): void {
		$other = $this->completed_order( 'FR' );
		$plain = $this->completed_order( 'DE' );
		$this->wipe( $other );
		$this->wipe( $plain );
		edd_update_order_meta( (int) $other->id, '_edd_payment_vat_number', 'FR12345678901' );

		$this->backfill->run_batch( 0 );

		$this->assertSame( array(), $this->rows( (int) $other->id ) );
		$this->assertCount( 1, $this->rows( (int) $plain->id ) );
		$this->assertTrue( $this->backfill->backfill_order( $this->fresh( $other ), 'barn2' ), 'An importer can still hand the order in.' );
	}

	public function test_orders_before_the_window_are_not_selected(): void {
		$old = $this->completed_order( 'FR', '2019-02-01 10:00:00' );
		$new = $this->completed_order( 'FR' );
		$this->wipe( $old );
		$this->wipe( $new );

		$ids = array_map(
			static function ( Order $order ): int {
				return (int) $order->id;
			},
			$this->backfill->select( Backfill::default_since(), 0, 50 )
		);

		$this->assertContains( (int) $new->id, $ids );
		$this->assertNotContains( (int) $old->id, $ids );
		$this->backfill->run_batch( 0 );
		$this->assertSame( array(), $this->rows( (int) $old->id ) );
	}

	public function test_only_sales_in_a_settled_status_are_selected(): void {
		$pending = $this->place_pending( 'FR' );
		$done    = $this->completed_order( 'FR' );

		$ids = array_map(
			static function ( Order $order ): int {
				return (int) $order->id;
			},
			$this->backfill->select( Backfill::default_since(), 0, 50 )
		);

		$this->assertContains( (int) $done->id, $ids );
		$this->assertNotContains( (int) $pending->id, $ids );
	}

	public function test_a_refunded_order_gets_its_sale_and_refund_rows(): void {
		$this->settings( array( 'oss_registered' => true ) );
		$this->buy( $this->download( 100.0 ), 'FR' );
		$this->buy( $this->download( 100.0, RateResolver::REDUCED ), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->dated( $order, $this->recent );
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
		$live = $this->comparable( $this->rows( (int) $order->id ) );
		$this->assertCount( 3, $live, 'Two sale rows and the refund.' );
		$this->wipe( $order );

		$this->backfill->backfill_order( $this->fresh( $order ) );

		$this->assertSame( $live, $this->comparable( $this->rows( (int) $order->id ) ) );
		$this->assertSame( 'partially_refunded', $this->fresh( $order )->status );
	}

	public function test_a_revoked_order_gets_a_sale_and_a_void(): void {
		$order = $this->completed_order( 'FR' );
		$this->status( $order, 'revoked' );
		$this->wipe( $order );

		$this->backfill->backfill_order( $this->fresh( $order ) );

		$rows = $this->rows( (int) $order->id );
		$this->assertSame( array( 'sale', 'void' ), array_column( $rows, 'entry_type' ) );
		$this->assertSame( 0.0, $this->sum( $rows, 'net' ) );
	}

	public function test_an_importer_can_hand_in_its_own_decision_and_source(): void {
		$order = $this->completed_order( 'FR' );
		$this->wipe( $order );
		$decision = $this->backfill->infer( $this->fresh( $order ) );

		$this->backfill->backfill_order( $this->fresh( $order ), 'barn2', $decision );

		$rows = $this->rows( (int) $order->id );
		$this->assertSame( 'barn2', $rows[0]['source'] );
		$this->assertSame( $decision->id(), $rows[0]['decision_id'] );
		$this->assertSame( $decision->id(), DecisionStore::get( (int) $order->id )->id() );
	}

	public function test_refunds_inherit_the_source_of_the_sale(): void {
		$order = $this->completed_order( 'FR' );
		edd_refund_order( (int) $order->id );
		$this->wipe( $order );

		$this->backfill->backfill_order( $this->fresh( $order ), 'barn2' );

		$this->assertSame( array( 'barn2', 'barn2' ), array_column( $this->rows( (int) $order->id ), 'source' ) );
	}

	public function test_the_threshold_counts_backfilled_sales(): void {
		$order = $this->completed_order( 'FR' );
		$year  = (int) substr( $this->recent, 0, 4 );
		$this->wipe( $order );
		ThresholdTracker::flush( $year );
		$this->assertSame( 0.0, ( new ThresholdTracker() )->status( $year )->current_eur );

		$this->backfill->run_batch( 0 );

		$this->assertSame( 100.0, ( new ThresholdTracker() )->status( $year )->current_eur );
	}

	public function test_scheduling_queues_the_first_batch_once(): void {
		Backfill::schedule();
		Backfill::schedule();

		$this->assertTrue( $this->scheduled( Backfill::HOOK, array( 0 ) ) );
	}

	public function test_activation_runs_the_plugin_activation_routine(): void {
		$this->assertNotFalse( has_action( 'activate_' . plugin_basename( VATRIGHT_EDD_FILE ), array( Plugin::class, 'activate' ) ) );
	}

	private function completed_order( string $country, ?string $when = null ): Order {
		edd_empty_cart();
		$this->buy( $this->download(), $country );
		$order = $this->place_order( $country );
		$this->dated( $order, $when ?? $this->recent );
		$this->complete( $order );
		return $this->fresh( $order );
	}

	private function place_pending( string $country ): Order {
		edd_empty_cart();
		$this->buy( $this->download(), $country );
		$order = $this->place_order( $country );
		$this->dated( $order, $this->recent );
		return $order;
	}

	private function wipe_rows( Order $order ): void {
		global $wpdb;
		$wpdb->delete( Schema::ledger_table(), array( 'order_id' => (int) $order->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		ThresholdTracker::flush( (int) substr( $this->recent, 0, 4 ), 2019 );
	}

	private function wipe( Order $order ): void {
		$this->wipe_rows( $order );
		edd_delete_order_meta( (int) $order->id, DecisionStore::META );
		edd_delete_order_meta( (int) $order->id, DecisionStore::LOG_META );
		edd_delete_order_meta( (int) $order->id, DecisionStore::REVERSE_CHARGED_META );
	}

	/**
	 * What a row says about the sale, without ids, timestamps and its source.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private function comparable( array $rows ): array {
		$keep = array( 'entry_type', 'scheme', 'rule', 'supply_type', 'customer_country', 'place_of_supply', 'rate_percent', 'net', 'vat', 'currency', 'net_eur', 'vat_eur', 'period', 'relates_to_period' );
		return array_map(
			static function ( array $row ) use ( $keep ): array {
				return array_intersect_key( $row, array_flip( $keep ) );
			},
			$rows
		);
	}

	/**
	 * @param array<int,mixed> $args
	 */
	private function scheduled( string $hook, array $args ): bool {
		if ( Scheduler::uses_action_scheduler() ) {
			return (bool) as_has_scheduled_action( $hook, $args, 'vatright-edd' );
		}
		return false !== wp_next_scheduled( $hook, $args );
	}
}
