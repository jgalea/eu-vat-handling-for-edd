<?php

namespace VatrightEdd\Tests\Integration\Ledger;

use VatrightEdd\Domain\Rule;
use VatrightEdd\Domain\Scheme;
use VatrightEdd\Ledger\LedgerWriter;
use VatrightEdd\Ledger\ThresholdTracker;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Rates\RateResolver;
use VatrightEdd\Scheduler;
use VatrightEdd\Tests\Support\LedgerTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/LedgerTestCase.php';

class LedgerWriterTest extends LedgerTestCase {

	public function test_a_completed_origin_sale_writes_one_row(): void {
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->dated( $order, '2026-03-10 10:00:00' );
		$this->assertSame( array(), $this->rows( (int) $order->id ), 'Nothing is written before the order completes.' );

		$this->complete( $order );

		$rows = $this->rows( (int) $order->id );
		$this->assertCount( 1, $rows );
		$row = $rows[0];
		$this->assertSame( 'sale', $row['entry_type'] );
		$this->assertSame( Scheme::ORIGIN, $row['scheme'] );
		$this->assertSame( Rule::B2C_ORIGIN_BELOW_THRESHOLD, $row['rule'] );
		$this->assertSame( 'FR', $row['customer_country'] );
		$this->assertSame( 18.0, (float) $row['rate_percent'] );
		$this->assertSame( 100.0, (float) $row['net'] );
		$this->assertSame( 18.0, (float) $row['vat'] );
		$this->assertSame( 1.0, (float) $row['eur_rate'] );
		$this->assertSame( 100.0, (float) $row['net_eur'] );
		$this->assertSame( '2026-Q1', $row['period'] );
		$this->assertSame( '2026-Q1', $row['relates_to_period'] );
		$this->assertSame( 'live', $row['source'] );
		$this->assertNull( $row['refund_id'] );
		$this->assertSame( DecisionStore::get( (int) $order->id )->id(), $row['decision_id'] );
	}

	public function test_the_period_follows_the_shop_timezone(): void {
		update_option( 'timezone_string', 'Europe/Malta' );
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->dated( $order, '2026-03-31 23:30:00' );
		$this->complete( $order );

		$this->assertSame( '2026-Q2', $this->rows( (int) $order->id )[0]['period'] );
	}

	public function test_a_destination_sale_is_booked_in_the_customers_country(): void {
		$this->settings( array( 'oss_registered' => true ) );
		$this->buy( $this->download(), 'DE' );
		$order = $this->place_order( 'DE' );
		$this->dated( $order, '2026-06-30 23:00:00' );
		$this->complete( $order );

		$row = $this->rows( (int) $order->id )[0];
		$this->assertSame( Scheme::OSS_DESTINATION, $row['scheme'] );
		$this->assertSame( 'DE', $row['place_of_supply'] );
		$this->assertSame( 19.0, (float) $row['rate_percent'] );
		$this->assertSame( '2026-Q2', $row['period'] );
	}

	public function test_a_reverse_charged_sale_carries_the_number_and_no_vat(): void {
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		$order = $this->place_order( 'DE' );
		$this->dated( $order, '2026-03-10 10:00:00' );
		$this->complete( $order );

		$row = $this->rows( (int) $order->id )[0];
		$this->assertSame( Scheme::REVERSE_CHARGE_SERVICES, $row['scheme'] );
		$this->assertSame( self::VALID_DE, $row['customer_vat'] );
		$this->assertSame( 0.0, (float) $row['vat'] );
		$this->assertSame( 0.0, (float) $row['rate_percent'] );
		$this->assertSame( 100.0, (float) $row['net'] );
	}

	public function test_an_order_built_already_complete_is_recorded(): void {
		$this->order_status = 'complete';
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );

		$this->assertCount( 1, $this->rows( (int) $order->id ) );
	}

	public function test_an_order_without_a_decision_writes_nothing(): void {
		edd_update_option( 'enable_taxes', false );
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->complete( $order );

		$this->assertNull( DecisionStore::get( (int) $order->id ) );
		$this->assertSame( 0, $this->ledger_total_rows() );
	}

	public function test_a_free_order_writes_nothing(): void {
		$this->buy( $this->download( 0.0 ), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->complete( $order );

		$this->assertNotNull( DecisionStore::get( (int) $order->id ) );
		$this->assertSame( 0, $this->ledger_total_rows() );
	}

	public function test_completing_twice_does_not_duplicate_rows(): void {
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->complete( $order );
		$this->ledger->record_order( $this->fresh( $order ) );
		$this->ledger->record_order( $this->fresh( $order ) );

		$this->assertCount( 1, $this->rows( (int) $order->id ) );
	}

	public function test_a_rewrite_keeps_the_source_the_rows_had(): void {
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->complete( $order );
		$this->ledger->record_order( $this->fresh( $order ), 'backfill' );
		$this->ledger->record_order( $this->fresh( $order ) );

		$this->assertSame( 'backfill', $this->rows( (int) $order->id )[0]['source'] );
	}

	public function test_the_rows_add_up_to_the_order_total_for_a_mixed_cart(): void {
		$this->settings( array( 'oss_registered' => true ) );
		$this->buy( $this->download( 100.0 ), 'FR' );
		$this->buy( $this->download( 100.0, RateResolver::REDUCED ), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->complete( $order );

		$rows = $this->rows( (int) $order->id );
		$this->assertCount( 2, $rows, 'One row per rate.' );
		$this->assertEqualsCanonicalizing( array( 5.5, 20.0 ), array_map( 'floatval', array_column( $rows, 'rate_percent' ) ) );
		$this->assertSame( round( (float) $this->fresh( $order )->total, 4 ), round( $this->sum( $rows, 'net' ) + $this->sum( $rows, 'vat' ), 4 ) );
		$this->assertSame( 225.5, $this->sum( $rows, 'net' ) + $this->sum( $rows, 'vat' ) );
	}

	public function test_a_discounted_sale_books_the_net_after_the_discount(): void {
		edd_add_discount(
			array(
				'name'        => 'Ten percent',
				'code'        => 'TEN',
				'amount_type' => 'percent',
				'amount'      => 10,
				'status'      => 'active',
				'scope'       => 'global',
			)
		);
		$this->buy( $this->download(), 'FR' );
		edd_set_cart_discount( 'TEN' );
		$this->h->decider->forget();
		$order = $this->place_order( 'FR', array( 'discount' => 'TEN' ) );
		$this->complete( $order );

		$this->assertSame( 90.0, (float) $this->fresh( $order )->subtotal - (float) $this->fresh( $order )->discount );
		$row = $this->rows( (int) $order->id )[0];
		$this->assertSame( 90.0, (float) $row['net'] );
		$this->assertSame( 16.2, (float) $row['vat'] );
		$this->assertSame( 18.0, (float) $row['rate_percent'] );
	}

	public function test_an_untaxed_fee_is_booked_at_no_rate_beside_the_taxed_item(): void {
		$this->buy( $this->download(), 'FR' );
		EDD()->fees->add_fee(
			array(
				'amount' => 10,
				'label'  => 'Handling',
				'id'     => 'handling',
				'no_tax' => true,
				'type'   => 'fee',
			)
		);
		$order = $this->place_order( 'FR' );
		$this->complete( $order );

		$this->assertSame( 128.0, (float) $this->fresh( $order )->total, 'The fee is on the order.' );
		$rows = $this->rows( (int) $order->id );
		$this->assertCount( 2, $rows );
		$this->assertSame( 110.0, $this->sum( $rows, 'net' ) );
		$this->assertSame( 18.0, $this->sum( $rows, 'vat' ) );
	}

	public function test_a_country_without_a_rate_is_marked_rate_missing(): void {
		$this->settings( array( 'oss_registered' => true ) );
		foreach ( edd_get_tax_rates( array( 'status' => 'active' ) ) as $rate ) {
			if ( 'FR' === $rate['country'] ) {
				edd_delete_tax_rate( $rate['id'] );
			}
		}
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->complete( $order );

		$row = $this->rows( (int) $order->id )[0];
		$this->assertSame( 1, (int) $row['rate_missing'] );
		$this->assertSame( 18.0, (float) $row['rate_percent'], 'The row carries the rate that was charged.' );
	}

	public function test_a_normal_sale_is_not_marked_rate_missing(): void {
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->complete( $order );

		$this->assertSame( 0, (int) $this->rows( (int) $order->id )[0]['rate_missing'] );
	}

	public function test_a_full_refund_cancels_the_sale_rows(): void {
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->dated( $order, '2026-03-10 10:00:00' );
		$this->complete( $order );

		$this->assertSame( 100.0, ( new ThresholdTracker() )->status( 2026 )->current_eur );

		$refund_id = edd_refund_order( (int) $order->id );
		$this->assertIsInt( $refund_id );
		edd_update_order( $refund_id, array( 'date_completed' => '2026-05-02 09:00:00' ) );
		$this->ledger->record_refund( (int) $order->id, $refund_id );

		$rows = $this->rows( (int) $order->id );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'refund', $rows[1]['entry_type'] );
		$this->assertSame( $refund_id, (int) $rows[1]['refund_id'] );
		$this->assertSame( -100.0, (float) $rows[1]['net'] );
		$this->assertSame( -18.0, (float) $rows[1]['vat'] );
		$this->assertSame( -100.0, (float) $rows[1]['net_eur'] );
		$this->assertSame( '2026-Q2', $rows[1]['period'] );
		$this->assertSame( '2026-Q1', $rows[1]['relates_to_period'], 'The refund is booked against the period of the sale.' );
		$this->assertSame( 0.0, $this->sum( $rows, 'net' ) );
		$this->assertSame( 0.0, $this->sum( $rows, 'vat' ) );
		$this->assertSame( 'refunded', $this->fresh( $order )->status );
		$this->assertSame( 0.0, ( new ThresholdTracker() )->status( 2026 )->current_eur, 'The threshold sees the refund.' );
	}

	public function test_a_partial_refund_of_one_item_leaves_the_rest_in_the_ledger(): void {
		$this->settings( array( 'oss_registered' => true ) );
		$this->buy( $this->download( 100.0 ), 'FR' );
		$this->buy( $this->download( 100.0, RateResolver::REDUCED ), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->dated( $order, '2026-03-10 10:00:00' );
		$this->complete( $order );

		$ebook = null;
		foreach ( $this->fresh( $order )->get_items() as $item ) {
			if ( 5.5 === round( (float) $item->tax, 2 ) ) {
				$ebook = $item;
			}
		}
		$this->assertNotNull( $ebook );
		$refund_id = edd_refund_order(
			(int) $order->id,
			array(
				array(
					'order_item_id' => $ebook->id,
					'quantity'      => 1,
					'subtotal'      => 100.0,
					'tax'           => 5.5,
				),
			)
		);
		$this->assertIsInt( $refund_id );

		$rows    = $this->rows( (int) $order->id );
		$refunds = array_values(
			array_filter(
				$rows,
				static function ( array $row ): bool {
					return 'refund' === $row['entry_type'];
				}
			)
		);
		$this->assertCount( 1, $refunds );
		$this->assertSame( -100.0, (float) $refunds[0]['net'] );
		$this->assertSame( -5.5, (float) $refunds[0]['vat'] );
		$this->assertSame( 5.5, (float) $refunds[0]['rate_percent'] );
		$this->assertSame( 100.0, $this->sum( $rows, 'net' ) );
		$this->assertSame( 20.0, $this->sum( $rows, 'vat' ) );
		$this->assertSame( 'partially_refunded', $this->fresh( $order )->status );
		$this->assertSame( 2, $this->count_type( $rows, 'sale' ), 'The refund status change does not write the sale again.' );
	}

	public function test_a_partial_refund_of_part_of_an_amount_books_that_part(): void {
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->complete( $order );
		$item = $this->fresh( $order )->get_items()[0];

		$refund_id = edd_refund_order(
			(int) $order->id,
			array(
				array(
					'order_item_id' => $item->id,
					'quantity'      => 1,
					'subtotal'      => 50.0,
					'tax'           => 9.0,
				),
			)
		);
		$this->assertIsInt( $refund_id );

		$rows = $this->rows( (int) $order->id );
		$this->assertSame( -50.0, (float) $rows[1]['net'] );
		$this->assertSame( -9.0, (float) $rows[1]['vat'] );
		$this->assertSame( 50.0, $this->sum( $rows, 'net' ) );
		$this->assertSame( 9.0, $this->sum( $rows, 'vat' ) );
	}

	public function test_a_refund_rewrite_does_not_duplicate_rows(): void {
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->complete( $order );
		$refund_id = edd_refund_order( (int) $order->id );
		$this->ledger->record_refund( (int) $order->id, (int) $refund_id );
		$this->ledger->record_refund( (int) $order->id, (int) $refund_id );

		$this->assertCount( 2, $this->rows( (int) $order->id ) );
	}

	public function test_a_revoked_order_is_cancelled_in_the_period_of_the_revoke(): void {
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->dated( $order, '2026-03-10 10:00:00' );
		$this->complete( $order );

		$this->status( $order, 'revoked' );

		$rows = $this->rows( (int) $order->id );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'void', $rows[1]['entry_type'] );
		$this->assertSame( -100.0, (float) $rows[1]['net'] );
		$this->assertSame( -18.0, (float) $rows[1]['vat'] );
		$this->assertSame( '2026-Q1', $rows[1]['relates_to_period'] );
		$this->assertSame( LedgerWriter::period_for( new \DateTimeImmutable( (string) $this->fresh( $order )->date_modified, new \DateTimeZone( 'UTC' ) ) ), $rows[1]['period'] );
		$this->assertSame( 0.0, $this->sum( $rows, 'net' ) );
	}

	public function test_revoking_twice_voids_once(): void {
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->complete( $order );
		$this->status( $order, 'revoked' );
		$this->ledger->void_order( $this->fresh( $order ) );

		$this->assertCount( 2, $this->rows( (int) $order->id ) );
	}

	public function test_an_order_that_never_completed_has_nothing_to_void(): void {
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->status( $order, 'failed' );

		$this->assertSame( 0, $this->ledger_total_rows() );
	}

	public function test_reinstating_a_revoked_order_adds_offsetting_rows_and_leaves_the_filed_ones(): void {
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->dated( $order, '2026-03-10 10:00:00' );
		$this->complete( $order );
		$this->status( $order, 'revoked' );
		$before = $this->rows( (int) $order->id );

		$this->complete( $order );

		$rows = $this->rows( (int) $order->id );
		$this->assertSame( array( 'sale', 'void', 'restore' ), array_column( $rows, 'entry_type' ) );
		$this->assertSame( $before, array_slice( $rows, 0, 2 ), 'The sale and void rows already booked are untouched.' );
		$this->assertSame( 100.0, (float) $rows[2]['net'] );
		$this->assertSame( 18.0, (float) $rows[2]['vat'] );
		$this->assertSame( '2026-Q1', $rows[2]['relates_to_period'] );
		$this->assertSame( $rows[1]['period'], $rows[2]['period'], 'Booked in the period of the event, not the original quarter.' );
		$this->assertSame( 100.0, $this->sum( $rows, 'net' ) );
	}

	public function test_revoking_again_after_a_reinstatement_voids_once_more(): void {
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->complete( $order );
		$this->status( $order, 'revoked' );
		$this->complete( $order );
		$this->status( $order, 'revoked' );

		$rows = $this->rows( (int) $order->id );
		$this->assertSame( array( 'sale', 'void', 'restore', 'void' ), array_column( $rows, 'entry_type' ) );
		$this->assertSame( 0.0, $this->sum( $rows, 'net' ) );
	}

	public function test_completing_an_order_that_was_never_revoked_adds_no_restore_rows(): void {
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->complete( $order );
		$this->ledger->restore_order( $this->fresh( $order ) );

		$this->assertSame( array( 'sale' ), array_column( $this->rows( (int) $order->id ), 'entry_type' ) );
	}

	public function test_a_usd_order_converts_at_the_ecb_rate_of_its_date(): void {
		$this->ecb_order_in_usd();
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->dated( $order, '2026-10-09 12:00:00' );
		$this->complete( $order );

		$row = $this->rows( (int) $order->id )[0];
		$this->assertSame( 'USD', $row['currency'] );
		$this->assertSame( 1.1206, (float) $row['eur_rate'] );
		$this->assertSame( round( 100 / 1.1206, 4 ), (float) $row['net_eur'] );
		$this->assertSame( round( 18 / 1.1206, 4 ), (float) $row['vat_eur'] );
		$this->assertSame(
			array(
				'currency'  => 'USD',
				'eur_rate'  => 1.1206,
				'rate_date' => '2026-10-09',
				'source'    => 'ecb',
			),
			edd_get_order_meta( (int) $order->id, LedgerWriter::FX_META, true )
		);
	}

	public function test_a_weekend_order_uses_the_last_published_rate(): void {
		$this->ecb_order_in_usd();
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->dated( $order, '2026-10-11 12:00:00' );
		$this->complete( $order );

		$this->assertSame( 1.1206, (float) $this->rows( (int) $order->id )[0]['eur_rate'] );
	}

	public function test_a_refund_converts_at_the_rate_fixed_for_the_sale(): void {
		$this->ecb_order_in_usd();
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->dated( $order, '2026-10-09 12:00:00' );
		$this->complete( $order );
		$refund_id = edd_refund_order( (int) $order->id );
		edd_update_order( (int) $refund_id, array( 'date_completed' => '2026-10-15 12:00:00' ) );
		$this->ledger->record_refund( (int) $order->id, (int) $refund_id );

		$rows = $this->rows( (int) $order->id );
		$this->assertSame( 1.1206, (float) $rows[1]['eur_rate'], 'Not the 1.1208 of the refund day.' );
		$this->assertSame( 0.0, $this->sum( $rows, 'net_eur' ) );
	}

	public function test_without_an_ecb_rate_the_euro_columns_stay_empty_and_a_retry_is_scheduled(): void {
		$this->ecb_order_in_usd();
		$this->ecb->fail = true;
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->complete( $order );

		$row = $this->rows( (int) $order->id )[0];
		$this->assertNull( $row['eur_rate'] );
		$this->assertNull( $row['net_eur'] );
		$this->assertTrue( $this->retry_scheduled( (int) $order->id ) );

		$this->ecb->fail = false;
		$this->dated( $order, '2026-10-09 12:00:00' );
		$this->ledger->on_fx_retry( (int) $order->id );

		$rows = $this->rows( (int) $order->id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 1.1206, (float) $rows[0]['eur_rate'] );
		$this->assertNotNull( $rows[0]['net_eur'] );
	}

	public function test_an_old_order_without_a_rate_is_not_retried(): void {
		$this->ecb_order_in_usd();
		$this->ecb->fail = true;
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->dated( $order, '2020-01-02 12:00:00' );
		$this->complete( $order );

		$this->assertFalse( $this->retry_scheduled( (int) $order->id ) );
	}

	public function test_crossing_the_threshold_mid_year_switches_the_next_order_to_destination_vat(): void {
		$year = (int) wp_date( 'Y' );
		$this->buy( $this->download( 9950.0 ), 'FR' );
		$big = $this->place_order( 'FR' );
		$this->dated( $big, $year . '-01-15 10:00:00' );
		$this->complete( $big );
		$this->assertSame( 9950.0, ( new ThresholdTracker() )->status( $year )->current_eur );

		edd_empty_cart();
		$this->buy( $this->download( 100.0 ), 'FR' );
		$this->assertSame( Rule::B2C_DESTINATION, $this->h->decider->current()->rule() );
		$next = $this->place_order( 'FR' );
		$this->assertSame( 20.0, (float) $next->tax );
	}

	public function test_an_order_just_below_the_threshold_keeps_origin_vat(): void {
		$year = (int) wp_date( 'Y' );
		$this->buy( $this->download( 9850.0 ), 'FR' );
		$big = $this->place_order( 'FR' );
		$this->dated( $big, $year . '-01-15 10:00:00' );
		$this->complete( $big );

		edd_empty_cart();
		$this->buy( $this->download( 100.0 ), 'FR' );
		$this->assertSame( Rule::B2C_ORIGIN_BELOW_THRESHOLD, $this->h->decider->current()->rule() );
	}

	private function retry_scheduled( int $order_id ): bool {
		if ( Scheduler::uses_action_scheduler() ) {
			return (bool) as_has_scheduled_action( LedgerWriter::FX_RETRY_HOOK, array( $order_id ), 'vatright-edd' );
		}
		return false !== wp_next_scheduled( LedgerWriter::FX_RETRY_HOOK, array( $order_id ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function count_type( array $rows, string $type ): int {
		return count(
			array_filter(
				$rows,
				static function ( array $row ) use ( $type ): bool {
					return $type === $row['entry_type'];
				}
			)
		);
	}
}
