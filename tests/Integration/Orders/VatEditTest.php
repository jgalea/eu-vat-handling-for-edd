<?php

namespace VatrightEdd\Tests\Integration\Orders;

use EDD\Orders\Order;
use VatrightEdd\Domain\Correction;
use VatrightEdd\Domain\Scheme;
use VatrightEdd\Ledger\Fx;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Orders\OrderFinaliser;
use VatrightEdd\Orders\VatEdit;
use VatrightEdd\Reports\EcSalesList;
use VatrightEdd\Reports\OssReport;
use VatrightEdd\Tests\Support\LedgerTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/LedgerTestCase.php';

class VatEditTest extends LedgerTestCase {

	/** @var VatEdit */
	private $edit;

	public function set_up() {
		parent::set_up();
		$this->edit = new VatEdit( $this->ledger );
	}

	/**
	 * A completed consumer sale to France, charged French VAT under OSS.
	 */
	private function consumer_order(): Order {
		$this->settings( array( 'oss_registered' => true ) );
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->dated( $order, '2026-03-10 10:00:00' );
		$this->complete( $order );
		$order = $this->fresh( $order );
		$this->assertSame( Scheme::OSS_DESTINATION, DecisionStore::get( (int) $order->id )->scheme() );
		return $order;
	}

	/**
	 * @return array{decision: \VatrightEdd\Domain\Decision, rebooked: bool}
	 */
	private function edit_ok( Order $order, string $number, string $country, string $treatment, string $reason = 'Customer sent a valid certificate' ): array {
		$result = $this->edit->apply( (int) $order->id, $number, $country, $treatment, $reason, get_current_user_id() );
		$this->assertNotWPError( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $result;
	}

	public function test_correcting_to_reverse_charge_records_number_country_and_treatment(): void {
		$order = $this->consumer_order();

		$result   = $this->edit_ok( $order, 'de 114 103 379', 'DE', Correction::REVERSE_CHARGE );
		$decision = DecisionStore::get( (int) $order->id );

		$this->assertTrue( $result['rebooked'] );
		$this->assertSame( Scheme::REVERSE_CHARGE_SERVICES, $decision->scheme() );
		$this->assertTrue( $decision->is_vat_exempt() );
		$this->assertSame( 'DE114103379', $decision->to_array()['vat_number']['canonical'] );
		$this->assertSame( 'DE', $decision->to_array()['evidence']['resolved'] );
		$this->assertContains( Correction::FLAG, $decision->flags() );
		$this->assertSame( 'DE114103379', edd_get_order_meta( (int) $order->id, DecisionStore::NUMBER_META, true ) );
		$this->assertSame( '1', (string) edd_get_order_meta( (int) $order->id, DecisionStore::REVERSE_CHARGED_META, true ), 'Recurring sees the order as reverse charged.' );
	}

	public function test_the_amounts_on_the_order_are_not_touched_and_nothing_is_refunded(): void {
		$order = $this->consumer_order();
		$tax   = (float) $order->tax;
		$total = (float) $order->total;

		$this->edit_ok( $order, 'DE114103379', 'DE', Correction::REVERSE_CHARGE );

		$fresh = $this->fresh( $order );
		$this->assertSame( $tax, (float) $fresh->tax );
		$this->assertSame( $total, (float) $fresh->total );
		$this->assertSame( 'complete', $fresh->status );
		$this->assertSame( array(), edd_get_order_refunds( (int) $order->id ) );
		$this->assertContains( OrderFinaliser::TAX_DIFFERS_FLAG, DecisionStore::get( (int) $order->id )->flags(), 'The tax still charged no longer fits a reverse charge, and the order says so.' );
	}

	public function test_the_log_keeps_every_earlier_entry_and_adds_who_when_what_and_why(): void {
		$order = $this->consumer_order();
		$first = DecisionStore::log( (int) $order->id );
		$user  = get_current_user_id();

		$this->edit_ok( $order, 'DE114103379', 'DE', Correction::REVERSE_CHARGE, 'Certificate received 2026-10-20' );

		$log = DecisionStore::log( (int) $order->id );
		$this->assertCount( count( $first ) + 1, $log );
		$this->assertSame( $first, array_slice( $log, 0, count( $first ) ), 'History is never overwritten.' );

		$entry = $log[ count( $log ) - 1 ];
		$this->assertSame( Correction::TRIGGER, $entry['trigger'] );
		$this->assertSame( $user, $entry['actor_user_id'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $entry['at'] );
		$this->assertSame( 'Certificate received 2026-10-20', $entry['detail']['reason'] );
		$this->assertSame( array( '', 'DE114103379' ), $entry['detail']['changes']['vat_number'] );
		$this->assertSame( array( 'FR', 'DE' ), $entry['detail']['changes']['country'] );
		$this->assertSame( array( 'oss_destination', 'reverse_charge' ), $entry['detail']['changes']['treatment'] );
		$this->assertSame( $entry['previous_id'], $first[ count( $first ) - 1 ]['decision_id'] );
	}

	public function test_a_second_edit_is_logged_again_even_if_only_the_country_changes(): void {
		$order = $this->consumer_order();
		$this->edit_ok( $order, '', 'FR', Correction::ORIGIN, 'First' );
		$before = count( DecisionStore::log( (int) $order->id ) );

		$this->edit_ok( $order, '', 'ES', Correction::ORIGIN, 'Second' );

		$log = DecisionStore::log( (int) $order->id );
		$this->assertCount( $before + 1, $log, 'The country moved but the tax country did not; it is still logged.' );
		$this->assertSame( array( 'FR', 'ES' ), $log[ count( $log ) - 1 ]['detail']['changes']['country'] );
		$this->assertArrayNotHasKey( 'treatment', $log[ count( $log ) - 1 ]['detail']['changes'] );
	}

	public function test_every_treatment_can_be_chosen(): void {
		foreach ( array( Correction::DOMESTIC, Correction::OSS_DESTINATION, Correction::ORIGIN, Correction::EXPORT ) as $treatment ) {
			$order = $this->consumer_order();
			$this->edit_ok( $order, '', 'NO' === $treatment ? 'US' : 'DE', $treatment );

			$this->assertSame( $treatment, Correction::treatment_of( DecisionStore::get( (int) $order->id )->scheme() ), $treatment );
		}
		$order = $this->consumer_order();
		$this->edit_ok( $order, 'DE114103379', 'DE', Correction::REVERSE_CHARGE );
		$this->assertSame( Correction::REVERSE_CHARGE, Correction::treatment_of( DecisionStore::get( (int) $order->id )->scheme() ) );
	}

	public function test_the_sale_rows_follow_and_the_reports_move_the_sale(): void {
		$order = $this->consumer_order();
		$oss   = new OssReport( new Fx( $this->ecb ) );
		$esl   = new EcSalesList();
		$this->assertSame( 'oss_destination', $this->rows( (int) $order->id )[0]['scheme'] );
		$this->assertSame( array(), $esl->build( 2026, 1 ), 'A consumer sale is not on the EC Sales List.' );
		$this->assertSame( array( 'FR' ), array_column( $oss->build( 2026, 1 )['rows'], 'member_state' ), 'It is on the OSS return.' );

		$this->edit_ok( $order, 'DE114103379', 'DE', Correction::REVERSE_CHARGE );

		$rows = $this->rows( (int) $order->id );
		$this->assertCount( 1, $rows, 'The sale rows were replaced, not doubled.' );
		$this->assertSame( 'reverse_charge_services', $rows[0]['scheme'] );
		$this->assertSame( 'DE114103379', $rows[0]['customer_vat'] );
		$this->assertSame( 'DE', $rows[0]['customer_country'] );
		$this->assertSame( '2026-Q1', $rows[0]['period'], 'The sale stays in its own period.' );

		$list = $esl->build( 2026, 1 );
		$this->assertCount( 1, $list );
		$this->assertSame( 'DE114103379', $list[0]['customer_vat'] );
		$this->assertSame( 100.0, $list[0]['net_eur'] );
		$this->assertSame( array(), $oss->build( 2026, 1 )['rows'], 'The sale left the OSS return.' );
	}

	public function test_refund_rows_are_written_again_against_the_new_decision(): void {
		$order     = $this->consumer_order();
		$item      = $this->fresh( $order )->get_items()[0];
		$refund_id = edd_refund_order(
			(int) $order->id,
			array(
				array(
					'order_item_id' => $item->id,
					'quantity'      => 1,
					'subtotal'      => 50.0,
					'tax'           => 10.0,
				),
			)
		);
		$this->assertIsInt( $refund_id );

		$this->edit_ok( $order, 'DE114103379', 'DE', Correction::REVERSE_CHARGE );

		$rows = $this->rows( (int) $order->id );
		$this->assertCount( 2, $rows );
		$this->assertSame( array( 'reverse_charge_services', 'reverse_charge_services' ), array_column( $rows, 'scheme' ) );
		$this->assertSame( array( 'sale', 'refund' ), array_column( $rows, 'entry_type' ) );
		$this->assertSame( $refund_id, (int) $rows[1]['refund_id'] );
	}

	public function test_an_order_not_yet_in_the_ledger_is_recorded_but_not_booked(): void {
		$this->settings( array( 'oss_registered' => true ) );
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->assertSame( 0, $this->ledger->count_rows( (int) $order->id ) );

		$result = $this->edit_ok( $order, 'DE114103379', 'DE', Correction::REVERSE_CHARGE );

		$this->assertFalse( $result['rebooked'] );
		$this->assertSame( 0, $this->ledger->count_rows( (int) $order->id ), 'An unpaid order does not enter the ledger through an edit.' );
		$this->assertSame( Scheme::REVERSE_CHARGE_SERVICES, DecisionStore::get( (int) $order->id )->scheme() );
	}

	public function test_a_revoked_order_keeps_its_ledger_rows(): void {
		$order = $this->consumer_order();
		$this->status( $order, 'revoked' );
		$before = $this->rows( (int) $order->id );
		$this->assertContains( 'void', array_column( $before, 'entry_type' ) );

		$result = $this->edit_ok( $order, 'DE114103379', 'DE', Correction::REVERSE_CHARGE );

		$this->assertFalse( $result['rebooked'] );
		$this->assertSame( $before, $this->rows( (int) $order->id ) );
	}

	public function test_a_reason_is_required(): void {
		$order = $this->consumer_order();
		$log   = DecisionStore::log( (int) $order->id );

		foreach ( array( '', '   ' ) as $reason ) {
			$result = $this->edit->apply( (int) $order->id, 'DE114103379', 'DE', Correction::REVERSE_CHARGE, $reason, 1 );
			$this->assertWPError( $result );
			$this->assertSame( 'reason_required', $result->get_error_code() );
		}
		$this->assertSame( $log, DecisionStore::log( (int) $order->id ) );
		$this->assertSame( Scheme::OSS_DESTINATION, DecisionStore::get( (int) $order->id )->scheme() );
	}

	public function test_bad_input_is_refused_and_nothing_changes(): void {
		$order  = $this->consumer_order();
		$cases  = array(
			'number'          => array( '!!', 'DE', Correction::DOMESTIC, 'bad_number' ),
			'country'         => array( '', 'ZZ', Correction::DOMESTIC, 'bad_country' ),
			'treatment'       => array( '', 'DE', 'free-lunch', 'bad_treatment' ),
			'reverse, no vat' => array( '', 'DE', Correction::REVERSE_CHARGE, 'number_required' ),
		);
		$before = DecisionStore::log( (int) $order->id );

		foreach ( $cases as $label => $case ) {
			$result = $this->edit->apply( (int) $order->id, $case[0], $case[1], $case[2], 'because', 1 );
			$this->assertWPError( $result, $label );
			$this->assertSame( $case[3], $result->get_error_code(), $label );
		}
		$this->assertSame( $before, DecisionStore::log( (int) $order->id ) );
		$this->assertCount( 1, $this->rows( (int) $order->id ) );
		$this->assertSame( 'oss_destination', $this->rows( (int) $order->id )[0]['scheme'] );
	}

	public function test_saving_what_is_already_recorded_changes_nothing(): void {
		$order = $this->consumer_order();
		$log   = DecisionStore::log( (int) $order->id );

		$result = $this->edit->apply( (int) $order->id, '', 'FR', Correction::OSS_DESTINATION, 'No change', 1 );

		$this->assertWPError( $result );
		$this->assertSame( 'unchanged', $result->get_error_code() );
		$this->assertSame( $log, DecisionStore::log( (int) $order->id ) );
	}

	public function test_a_refund_order_or_a_missing_order_cannot_be_edited(): void {
		$order     = $this->consumer_order();
		$refund_id = edd_refund_order( (int) $order->id );
		$this->assertIsInt( $refund_id );

		foreach ( array( $refund_id, 0, 999999 ) as $id ) {
			$result = $this->edit->apply( $id, 'DE114103379', 'DE', Correction::REVERSE_CHARGE, 'Why', 1 );
			$this->assertWPError( $result );
			$this->assertSame( 'no_order', $result->get_error_code() );
		}
	}

	public function test_an_order_without_a_decision_cannot_be_edited(): void {
		$order = $this->consumer_order();
		edd_delete_order_meta( (int) $order->id, DecisionStore::META );

		$result = $this->edit->apply( (int) $order->id, 'DE114103379', 'DE', Correction::REVERSE_CHARGE, 'Why', 1 );

		$this->assertWPError( $result );
		$this->assertSame( 'no_decision', $result->get_error_code() );
	}

	public function test_a_correction_that_fits_the_tax_clears_the_mismatch_flag(): void {
		$order  = $this->consumer_order();
		$record = DecisionStore::get( (int) $order->id )->with_flag( OrderFinaliser::TAX_DIFFERS_FLAG );
		DecisionStore::apply( (int) $order->id, $record, 'test' );

		$this->edit_ok( $order, '', 'DE', Correction::OSS_DESTINATION, 'Moved to Germany' );

		$this->assertNotContains( OrderFinaliser::TAX_DIFFERS_FLAG, DecisionStore::get( (int) $order->id )->flags() );
	}

	public function test_a_pending_order_stops_being_rechecked_once_an_admin_decided(): void {
		$this->settings( array( 'outage_policy' => 'charge' ) );
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::OUTAGE_DE, 'DE' );
		$order = $this->place_order( 'DE' );
		$this->assertContains( 'validation_pending', DecisionStore::get( (int) $order->id )->flags() );

		$this->edit_ok( $order, 'DE114103379', 'DE', Correction::REVERSE_CHARGE );

		$this->assertNotContains( 'validation_pending', DecisionStore::get( (int) $order->id )->flags() );
	}

	public function test_an_order_note_records_the_correction(): void {
		$order = $this->consumer_order();

		$this->edit_ok( $order, 'DE114103379', 'DE', Correction::REVERSE_CHARGE, 'Certificate on file' );

		$notes = implode(
			' | ',
			array_map(
				static function ( $note ) {
					return (string) $note->content;
				},
				(array) edd_get_payment_notes( (int) $order->id )
			)
		);
		$this->assertStringContainsString( 'EU VAT record corrected by', $notes );
		$this->assertStringContainsString( 'Certificate on file', $notes );
	}
}
