<?php

namespace VatrightEdd\Tests\Integration\Orders;

use EDD\Orders\Order;
use VatrightEdd\Domain\Decision;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Orders\OrderFinaliser;
use VatrightEdd\Orders\Revalidation;
use VatrightEdd\Orders\VatRefund;
use VatrightEdd\Rates\RateResolver;
use VatrightEdd\Registry\Router;
use VatrightEdd\Registry\ValidationCache;
use VatrightEdd\Registry\Validator;
use VatrightEdd\Registry\Vies;
use VatrightEdd\Settings;
use VatrightEdd\Tests\Support\LedgerTestCase;
use VatrightEdd\Tests\Support\ViesStub;

require_once dirname( __DIR__, 2 ) . '/Support/LedgerTestCase.php';

class VatRefundTest extends LedgerTestCase {

	/** @var Revalidation */
	private $reval;

	/** @var VatRefund */
	private $refund;

	/** @var array<int,array{subject:string}> */
	private $mails = array();

	public function set_up() {
		parent::set_up();
		$settings     = Settings::get();
		$validator    = new Validator( new Router( new Vies( $this->vies ) ), new ValidationCache( $settings ), $settings );
		$this->refund = new VatRefund( $this->h->finaliser );
		$this->reval  = new Revalidation( $validator, null, $this->refund );
		$this->mails  = array();
		add_filter(
			'pre_wp_mail',
			function ( $short, $atts ) {
				$this->mails[] = array( 'subject' => $atts['subject'] );
				return true;
			},
			10,
			2
		);
	}

	/**
	 * A completed order charged VAT while the registry was down, with the
	 * switch on unless the settings say otherwise.
	 *
	 * @param array<string,mixed> $settings
	 */
	private function pending_paid_order( array $settings = array(), int $downloads = 1 ): Order {
		$this->settings( array_merge( array( 'auto_refund_on_validation' => true ), $settings ) );
		for ( $i = 0; $i < $downloads; $i++ ) {
			$this->buy( $this->download( 100.0, 0 === $i ? 'standard' : RateResolver::REDUCED ), 'DE' );
		}
		$this->enter_number( self::OUTAGE_DE, 'DE' );
		$order = $this->place_order( 'DE' );
		$this->complete( $order );
		$order = $this->fresh( $order );
		$this->assertContains( 'validation_pending', DecisionStore::get( (int) $order->id )->flags(), 'The fixture order waits on the registry.' );
		$this->assertGreaterThan( 0.0, (float) $order->tax, 'The outage left VAT charged.' );
		$this->assertGreaterThan( 0, $this->ledger->count_rows( (int) $order->id, 'sale' ), 'The sale is in the ledger.' );
		return $order;
	}

	private function validate( Order $order ): void {
		$this->vies->set( '129273398', ViesStub::VALID );
		$this->reval->run( (int) $order->id, 1 );
	}

	/**
	 * @return Order[]
	 */
	private function refunds( Order $order ): array {
		return edd_get_order_refunds( (int) $order->id );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function refund_rows( Order $order ): array {
		return array_values(
			array_filter(
				$this->rows( (int) $order->id ),
				static function ( array $row ): bool {
					return 'refund' === $row['entry_type'];
				}
			)
		);
	}

	private function notes( Order $order ): string {
		return implode(
			' | ',
			array_map(
				static function ( $note ) {
					return (string) $note->content;
				},
				(array) edd_get_payment_notes( (int) $order->id )
			)
		);
	}

	public function test_with_the_setting_off_a_validated_number_only_emails(): void {
		$order = $this->pending_paid_order( array( 'auto_refund_on_validation' => false ) );
		$this->assertFalse( Settings::get()->auto_refund_on_validation(), 'The default is off.' );

		$this->validate( $order );

		$this->assertSame( array(), $this->refunds( $order ) );
		$this->assertNull( VatRefund::marker( (int) $order->id ) );
		$this->assertSame( 'complete', $this->fresh( $order )->status );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'refund may be due', $this->mails[0]['subject'] );
		$this->assertContains( 'validated_after_outage', DecisionStore::get( (int) $order->id )->flags() );
	}

	public function test_the_setting_defaults_to_off_on_a_fresh_site(): void {
		update_option( Settings::OPTION, array() );
		$this->assertFalse( Settings::get()->auto_refund_on_validation() );
		$this->assertFalse( Settings::defaults()['auto_refund_on_validation'] );
	}

	public function test_a_validated_number_refunds_exactly_the_vat_through_edd(): void {
		$order = $this->pending_paid_order();
		$tax   = (float) $order->tax;
		$total = (float) $order->total;

		$this->validate( $order );

		$refunds = $this->refunds( $order );
		$this->assertCount( 1, $refunds );
		$this->assertSame( 'refund', $refunds[0]->type );
		$this->assertSame( -$tax, (float) $refunds[0]->total );
		$this->assertSame( -$tax, (float) $refunds[0]->tax );
		$this->assertSame( 0.0, (float) $refunds[0]->subtotal );

		$fresh = $this->fresh( $order );
		$this->assertSame( 'partially_refunded', $fresh->status );
		$this->assertSame( $tax, (float) $fresh->tax, 'The sale keeps the tax it was charged.' );
		$this->assertSame( $total, (float) $fresh->total );
		$this->assertEqualsWithDelta( $total - $tax, edd_get_order_total( (int) $order->id ), 0.001, 'What the customer paid net of the refund is the price without VAT.' );

		$marker = VatRefund::marker( (int) $order->id );
		$this->assertSame( 'refunded', $marker['state'] );
		$this->assertSame( (int) $refunds[0]->id, $marker['refund_id'] );
		$this->assertStringContainsString( 'refunded automatically', $this->notes( $order ) );
	}

	public function test_the_refund_is_in_the_decision_log_and_flags(): void {
		$order = $this->pending_paid_order();
		$this->validate( $order );

		$decision = DecisionStore::get( (int) $order->id );
		$this->assertContains( VatRefund::FLAG_REFUNDED, $decision->flags() );
		$this->assertContains( 'validated_after_outage', $decision->flags() );

		$log   = DecisionStore::log( (int) $order->id );
		$entry = $log[ count( $log ) - 1 ];
		$this->assertSame( VatRefund::TRIGGER, $entry['trigger'] );
		$this->assertSame( 0, $entry['actor_user_id'] );
		$this->assertSame( (int) $this->refunds( $order )[0]->id, $entry['detail']['refund_id'] );
		$this->assertSame( (float) $order->tax, (float) $entry['detail']['amount'] );
		$this->assertSame( 'revalidation', $log[ count( $log ) - 2 ]['trigger'], 'The earlier entries are still there.' );
	}

	public function test_the_refund_lands_in_the_ledger_in_its_own_period_and_cancels_the_vat(): void {
		$order = $this->pending_paid_order();
		$this->dated( $order, '2026-03-10 10:00:00' );
		$this->ledger->record_order( $this->fresh( $order ) );
		$sale_rows = $this->rows( (int) $order->id );
		$vat       = $this->sum( $sale_rows, 'vat' );

		$this->validate( $order );
		$refund_id = (int) $this->refunds( $order )[0]->id;
		edd_update_order( $refund_id, array( 'date_completed' => '2026-05-02 09:00:00' ) );
		$this->ledger->record_refund( (int) $order->id, $refund_id );

		$rows    = $this->rows( (int) $order->id );
		$refunds = $this->refund_rows( $order );
		$this->assertNotEmpty( $refunds );
		$this->assertSame( -$vat, $this->sum( $refunds, 'vat' ) );
		$this->assertSame( 0.0, $this->sum( $refunds, 'net' ), 'Only the VAT left the ledger.' );
		$this->assertSame( 0.0, $this->sum( $rows, 'vat' ) );
		$this->assertSame( '2026-Q2', $refunds[0]['period'] );
		$this->assertSame( '2026-Q1', $refunds[0]['relates_to_period'] );
		$this->assertCount(
			count( $sale_rows ),
			array_filter(
				$rows,
				static function ( array $row ): bool {
					return 'sale' === $row['entry_type'];
				}
			),
			'The sale rows are not edited.'
		);
	}

	public function test_the_ledger_hears_about_the_refund_without_anything_else_calling_it(): void {
		$order = $this->pending_paid_order();
		$vat   = $this->sum( $this->rows( (int) $order->id ), 'vat' );

		$this->validate( $order );

		$this->assertSame( 0.0, $this->sum( $this->rows( (int) $order->id ), 'vat' ) );
		$this->assertSame( -$vat, $this->sum( $this->refund_rows( $order ), 'vat' ) );
	}

	public function test_two_items_at_different_rates_are_each_refunded_their_own_tax(): void {
		$order = $this->pending_paid_order( array(), 2 );
		$tax   = (float) $order->tax;
		$items = $this->fresh( $order )->get_items();
		$this->assertCount( 2, $items );

		$this->validate( $order );

		$refund = $this->refunds( $order )[0];
		$this->assertSame( -$tax, (float) $refund->total );
		$taxes    = array_map(
			static function ( $item ) {
				return round( (float) $item->tax, 2 );
			},
			$refund->get_items()
		);
		$expected = array_map(
			static function ( $item ) {
				return -round( (float) $item->tax, 2 );
			},
			$items
		);
		sort( $taxes );
		sort( $expected );
		$this->assertSame( $expected, $taxes );
	}

	public function test_running_it_again_never_refunds_twice(): void {
		$order = $this->pending_paid_order();
		$this->validate( $order );
		$this->assertCount( 1, $this->refunds( $order ) );

		$this->assertSame( VatRefund::ALREADY, $this->refund->refund_if_enabled( (int) $order->id ) );
		$this->reval->run( (int) $order->id, 1 );
		$this->reval->run( (int) $order->id, 2 );

		$this->assertCount( 1, $this->refunds( $order ) );
	}

	public function test_a_stale_run_that_still_sees_the_order_pending_cannot_refund_twice(): void {
		$order = $this->pending_paid_order();
		$this->validate( $order );

		// A second cron run that read the decision before the first one stored its answer.
		$record          = DecisionStore::get( (int) $order->id )->to_array();
		$record['flags'] = array( 'validation_pending' );
		DecisionStore::apply( (int) $order->id, Decision::from_array( $record ), 'test' );
		$this->assertContains( 'validation_pending', DecisionStore::get( (int) $order->id )->flags() );

		$this->reval->run( (int) $order->id, 1 );

		$this->assertCount( 1, $this->refunds( $order ), 'The marker on the order stops the repeat.' );
	}

	public function test_a_second_run_while_the_first_is_refunding_does_nothing(): void {
		$order  = $this->pending_paid_order();
		$inner  = array();
		$nested = function () use ( $order, &$inner ) {
			$inner[] = $this->refund->refund_if_enabled( (int) $order->id );
		};
		add_action( 'edd_refund_order', $nested, 5 );

		$this->validate( $order );

		remove_action( 'edd_refund_order', $nested, 5 );
		$this->assertSame( array( VatRefund::BUSY ), $inner, 'The overlapping run was turned away by the lock.' );
		$this->assertCount( 1, $this->refunds( $order ) );
	}

	public function test_a_held_lock_blocks_the_refund_and_a_dead_one_does_not(): void {
		$order = $this->pending_paid_order();
		$key   = VatRefund::LOCK_PREFIX . $order->id;

		add_option( $key, time(), '', false );
		$this->assertSame( VatRefund::BUSY, $this->refund->refund_if_enabled( (int) $order->id ) );
		$this->assertSame( array(), $this->refunds( $order ), 'Nothing is refunded while another run holds the lock.' );
		$this->assertNull( VatRefund::marker( (int) $order->id ), 'A turned-away run leaves no trace.' );

		update_option( $key, time() - VatRefund::LOCK_TTL - 10 );
		$this->validate( $order );
		$this->assertCount( 1, $this->refunds( $order ), 'A lock older than the ttl belonged to a run that died.' );
		$this->assertFalse( get_option( $key ), 'The lock is released afterwards.' );
	}

	public function test_the_lock_is_released_when_the_run_throws(): void {
		$order = $this->pending_paid_order();
		$throw = static function () {
			throw new \RuntimeException( 'boom' );
		};
		add_action( 'edd_refund_order', $throw, 5 );

		$this->validate( $order );

		remove_action( 'edd_refund_order', $throw, 5 );
		$this->assertFalse( get_option( VatRefund::LOCK_PREFIX . $order->id ) );
		$this->assertSame( 'failed', VatRefund::marker( (int) $order->id )['state'] );
	}

	public function test_an_earlier_partial_refund_skips_and_flags(): void {
		$order = $this->pending_paid_order();
		$item  = $this->fresh( $order )->get_items()[0];
		$early = edd_refund_order(
			(int) $order->id,
			array(
				array(
					'order_item_id' => $item->id,
					'quantity'      => 1,
					'subtotal'      => 10.0,
					'tax'           => 1.9,
				),
			)
		);
		$this->assertIsInt( $early );

		$this->validate( $order );

		$this->assertCount( 1, $this->refunds( $order ), 'No second refund was added.' );
		$this->assertSame( -11.9, (float) $this->refunds( $order )[0]->total );
		$this->assertSame( 'skipped', VatRefund::marker( (int) $order->id )['state'] );
		$this->assertSame( 'already_refunded', VatRefund::marker( (int) $order->id )['reason'] );
		$this->assertContains( VatRefund::FLAG_SKIPPED, DecisionStore::get( (int) $order->id )->flags() );
		$this->assertStringContainsString( 'not refunded automatically', $this->notes( $order ) );
		$this->assertCount( 1, $this->mails, 'The merchant is told to act.' );
		$this->assertStringContainsString( 'refund may be due', $this->mails[0]['subject'] );
	}

	public function test_a_gateway_that_edd_cannot_refund_through_is_skipped(): void {
		$order = $this->pending_paid_order();
		edd_update_order( (int) $order->id, array( 'gateway' => 'stripe' ) );

		$this->validate( $order );

		$this->assertSame( array(), $this->refunds( $order ) );
		$this->assertSame( 'gateway_unsupported', VatRefund::marker( (int) $order->id )['reason'] );
		$this->assertContains( VatRefund::FLAG_SKIPPED, DecisionStore::get( (int) $order->id )->flags() );
		$this->assertSame( 'complete', $this->fresh( $order )->status );
	}

	public function test_a_gateway_added_through_the_filter_is_refunded(): void {
		$order = $this->pending_paid_order();
		edd_update_order( (int) $order->id, array( 'gateway' => 'bank_transfer' ) );
		add_filter(
			'vatright_edd_auto_refund_gateways',
			static function ( $gateways ) {
				$gateways[] = 'bank_transfer';
				return $gateways;
			}
		);

		$this->validate( $order );

		$this->assertCount( 1, $this->refunds( $order ) );
	}

	public function test_tax_that_does_not_match_the_decision_is_skipped(): void {
		$order  = $this->pending_paid_order();
		$record = DecisionStore::get( (int) $order->id )->with_flag( OrderFinaliser::TAX_DIFFERS_FLAG );
		DecisionStore::apply( (int) $order->id, $record, 'test' );

		$this->validate( $order );

		$this->assertSame( array(), $this->refunds( $order ) );
		$this->assertSame( 'tax_differs_from_decision', VatRefund::marker( (int) $order->id )['reason'] );
	}

	public function test_an_order_whose_item_taxes_do_not_add_up_to_its_tax_is_skipped(): void {
		$order = $this->pending_paid_order();
		edd_update_order( (int) $order->id, array( 'tax' => (float) $order->tax + 5.0 ) );

		$this->validate( $order );

		$this->assertSame( array(), $this->refunds( $order ) );
		$this->assertSame( 'tax_differs_from_decision', VatRefund::marker( (int) $order->id )['reason'] );
	}

	public function test_an_order_with_no_tax_is_not_refunded(): void {
		$order = $this->pending_paid_order();
		edd_update_order( (int) $order->id, array( 'tax' => 0.0 ) );

		$this->validate( $order );

		$this->assertSame( array(), $this->refunds( $order ) );
		$this->assertSame( 'no_tax_charged', VatRefund::marker( (int) $order->id )['reason'] );
	}

	public function test_a_number_that_would_not_exempt_the_order_today_is_not_refunded(): void {
		$order = $this->pending_paid_order();
		edd_update_order_address( (int) $order->get_address()->id, array( 'country' => 'FR' ) );

		$this->validate( $order );

		$this->assertSame( array(), $this->refunds( $order ) );
		$this->assertSame( 'not_exempt_now', VatRefund::marker( (int) $order->id )['reason'] );
	}

	public function test_an_invalid_number_refunds_nothing(): void {
		$order = $this->pending_paid_order();

		$this->vies->set( '129273398', ViesStub::INVALID );
		$this->reval->run( (int) $order->id, 1 );

		$this->assertSame( array(), $this->refunds( $order ) );
		$this->assertNull( VatRefund::marker( (int) $order->id ) );
	}

	public function test_a_registry_still_down_refunds_nothing_and_keeps_the_order_pending(): void {
		$order = $this->pending_paid_order();

		$this->reval->run( (int) $order->id, 1 );

		$this->assertSame( array(), $this->refunds( $order ) );
		$this->assertContains( 'validation_pending', DecisionStore::get( (int) $order->id )->flags() );
	}

	public function test_a_renewal_edd_will_not_refund_is_skipped(): void {
		$order = $this->pending_paid_order();
		edd_update_order( (int) $order->id, array( 'status' => 'edd_subscription' ) );

		$this->validate( $order );

		$this->assertSame( array(), $this->refunds( $order ) );
		$this->assertSame( 'not_refundable', VatRefund::marker( (int) $order->id )['reason'] );
	}

	public function test_a_renewal_edd_will_refund_is_refunded_once(): void {
		$parent  = $this->pending_paid_order();
		$renewal = $this->pending_paid_order();
		edd_update_order( (int) $renewal->id, array( 'parent' => (int) $parent->id ) );

		$this->validate( $renewal );
		$this->reval->run( (int) $renewal->id, 1 );

		$this->assertCount( 1, $this->refunds( $renewal ) );
		$this->assertSame( array(), $this->refunds( $parent ) );
	}

	public function test_without_a_logged_in_user_the_refund_window_still_applies(): void {
		$order = $this->pending_paid_order();
		edd_update_order(
			(int) $order->id,
			array(
				'date_completed'  => '2020-01-01 10:00:00',
				'date_refundable' => '2020-01-31 10:00:00',
			)
		);
		wp_set_current_user( 0 );

		$this->validate( $order );

		$this->assertSame( array(), $this->refunds( $order ) );
		$this->assertSame( 'not_refundable', VatRefund::marker( (int) $order->id )['reason'] );
	}

	public function test_inside_the_window_it_refunds_when_cron_runs_without_a_user(): void {
		$order = $this->pending_paid_order();
		wp_set_current_user( 0 );

		$this->validate( $order );

		$this->assertCount( 1, $this->refunds( $order ) );
	}

	public function test_edd_refusing_the_refund_is_flagged_and_not_retried(): void {
		$order = $this->pending_paid_order();
		add_filter( 'edd_should_process_order_refund', '__return_false' );

		$this->validate( $order );

		$this->assertSame( array(), $this->refunds( $order ) );
		$this->assertSame( 'failed', VatRefund::marker( (int) $order->id )['state'] );
		$this->assertContains( VatRefund::FLAG_FAILED, DecisionStore::get( (int) $order->id )->flags() );
		$this->assertStringContainsString( 'Nothing was refunded', $this->notes( $order ) );

		remove_filter( 'edd_should_process_order_refund', '__return_false' );
		$this->reval->run( (int) $order->id, 1 );
		$this->assertSame( array(), $this->refunds( $order ), 'It does not try again by itself.' );
	}

	public function test_a_non_euro_order_is_refunded_in_its_own_currency_and_the_ledger_converts_it(): void {
		$this->ecb_order_in_usd();
		$order = $this->pending_paid_order();
		$this->assertSame( 'USD', $order->currency );
		$tax = (float) $order->tax;

		$this->validate( $order );

		$refund = $this->refunds( $order )[0];
		$this->assertSame( 'USD', $refund->currency );
		$this->assertSame( -$tax, (float) $refund->total );

		$rows = $this->rows( (int) $order->id );
		$this->assertNotEmpty( $this->refund_rows( $order ) );
		$this->assertSame( 0.0, $this->sum( $rows, 'vat' ) );
		$this->assertSame( 0.0, $this->sum( $rows, 'vat_eur' ), 'The EUR amounts cancel with the same rate the sale used.' );
		$this->assertSame( 'USD', $rows[0]['currency'] );
	}

	public function test_a_fee_with_tax_is_refunded_with_the_items(): void {
		$order = $this->pending_paid_order();
		$fee   = edd_add_order_adjustment(
			array(
				'object_id'   => (int) $order->id,
				'object_type' => 'order',
				'type'        => 'fee',
				'description' => 'Handling',
				'subtotal'    => 10.0,
				'tax'         => 1.9,
				'total'       => 11.9,
			)
		);
		$this->assertNotFalse( $fee );
		edd_update_order(
			(int) $order->id,
			array(
				'subtotal' => (float) $order->subtotal + 10.0,
				'tax'      => (float) $order->tax + 1.9,
				'total'    => (float) $order->total + 11.9,
			)
		);
		$tax = (float) $this->fresh( $order )->tax;

		$this->validate( $order );

		$this->assertCount( 1, $this->refunds( $order ) );
		$this->assertSame( -$tax, (float) $this->refunds( $order )[0]->total );
		$this->assertEqualsWithDelta( 0.0, (float) edd_get_order_adjustment( (int) $fee )->get_refundable_amounts()['tax'], 0.001 );
	}

	public function test_the_refund_email_says_it_was_refunded(): void {
		$order = $this->pending_paid_order();

		$this->validate( $order );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'VAT refunded on order', $this->mails[0]['subject'] );
	}

	public function test_the_email_setting_off_sends_nothing_for_a_refund_either(): void {
		$order = $this->pending_paid_order( array( 'notify_admin_on_revalidation' => false ) );

		$this->validate( $order );

		$this->assertCount( 1, $this->refunds( $order ) );
		$this->assertSame( array(), $this->mails );
	}
}
