<?php

namespace VatrightEdd\Tests\Integration\Orders;

use RuntimeException;
use VatrightEdd\Domain\Rule;
use VatrightEdd\Domain\Scheme;
use VatrightEdd\Ledger\ThresholdTracker;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Orders\OrderFinaliser;
use VatrightEdd\Tests\Support\OrdersTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/OrdersTestCase.php';

class OrderFinaliserTest extends OrdersTestCase {

	public function test_a_built_order_carries_the_decision_and_the_rate_that_was_charged(): void {
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );

		$this->assertSame( 18.0, (float) $order->tax );
		$decision = DecisionStore::get( (int) $order->id );
		$this->assertNotNull( $decision );
		$this->assertSame( Rule::B2C_ORIGIN_BELOW_THRESHOLD, $decision->rule() );
		$this->assertSame( OrderFinaliser::BUILT, $decision->trigger() );

		// EDD looks the rate up by billing country, which is the French row. The control is that the two rows differ.
		$this->assertNotSame( $this->active_rate_id( 'FR' ), $this->active_rate_id( 'MT' ) );
		$this->assertSame( $this->active_rate_id( 'MT' ), (int) $this->fresh( $order )->tax_rate_id );
	}

	public function test_a_destination_order_points_at_the_destination_rate(): void {
		$this->settings( array( 'oss_registered' => true ) );
		$this->buy( $this->download(), 'DE' );
		$order = $this->place_order( 'DE' );

		$this->assertSame( 19.0, (float) $order->tax );
		$this->assertSame( $this->active_rate_id( 'DE' ), (int) $this->fresh( $order )->tax_rate_id );
	}

	public function test_a_reverse_charged_order_has_no_rate_and_the_flag_recurring_reads(): void {
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		$order = $this->place_order( 'DE' );

		$this->assertSame( 0.0, (float) $order->tax );
		$decision = DecisionStore::get( (int) $order->id );
		$this->assertSame( Scheme::REVERSE_CHARGE_SERVICES, $decision->scheme() );

		$fresh = $this->fresh( $order );
		$this->assertEmpty( $fresh->tax_rate_id );
		$this->assertSame( '1', (string) edd_get_order_meta( $order->id, DecisionStore::REVERSE_CHARGED_META, true ) );
		$this->assertSame( self::VALID_DE, (string) edd_get_order_meta( $order->id, DecisionStore::NUMBER_META, true ) );
		$this->assertSame( 1, $this->vies->calls, 'The registry answer from checkout is reused, not asked for again.' );
		$this->assertSame( 'valid', $decision->to_array()['validation']['status'] );
	}

	public function test_a_charged_order_does_not_get_the_reverse_charge_flag(): void {
		$this->buy( $this->download(), 'DE' );
		$order = $this->place_order( 'DE' );

		$this->assertSame( '', (string) edd_get_order_meta( $order->id, DecisionStore::REVERSE_CHARGED_META, true ) );
	}

	public function test_the_customer_number_is_remembered_for_prefilling(): void {
		$owner = self::factory()->user->create();
		$this->customer_for( 'buyer@example.com', $owner );
		wp_set_current_user( $owner );
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		$order = $this->place_order( 'DE' );

		$this->assertSame( self::VALID_DE, (string) edd_get_customer_meta( $order->customer_id, OrderFinaliser::CUSTOMER_NUMBER_META, true ) );
		$this->assertNotSame( '', (string) edd_get_customer_meta( $order->customer_id, OrderFinaliser::CUSTOMER_VALIDATED_META, true ) );
	}

	private function customer_for( string $email, int $user_id ): int {
		return (int) edd_add_customer(
			array(
				'email'   => $email,
				'name'    => 'Owner',
				'user_id' => $user_id,
			)
		);
	}

	public function test_a_guest_order_with_the_owners_email_does_not_change_their_saved_number(): void {
		$owner = self::factory()->user->create();
		$cid   = $this->customer_for( 'buyer@example.com', $owner );
		edd_update_customer_meta( $cid, OrderFinaliser::CUSTOMER_NUMBER_META, 'FR12345678901' );
		wp_set_current_user( 0 );

		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		$order = $this->place_order( 'DE' );

		$this->assertSame( $cid, (int) $order->customer_id );
		$this->assertSame( 'FR12345678901', (string) edd_get_customer_meta( $cid, OrderFinaliser::CUSTOMER_NUMBER_META, true ) );
	}

	public function test_a_logged_in_owner_s_order_remembers_the_number(): void {
		$owner = self::factory()->user->create();
		$cid   = $this->customer_for( 'buyer@example.com', $owner );
		wp_set_current_user( $owner );

		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		$this->place_order( 'DE' );

		$this->assertSame( self::VALID_DE, (string) edd_get_customer_meta( $cid, OrderFinaliser::CUSTOMER_NUMBER_META, true ) );
	}

	public function test_a_logged_in_user_cannot_overwrite_another_customers_number(): void {
		$owner = self::factory()->user->create();
		$cid   = $this->customer_for( 'buyer@example.com', $owner );
		wp_set_current_user( self::factory()->user->create() );

		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		$this->place_order( 'DE' );

		$this->assertSame( '', (string) edd_get_customer_meta( $cid, OrderFinaliser::CUSTOMER_NUMBER_META, true ) );
	}

	public function test_an_admin_recheck_remembers_the_number(): void {
		$owner = self::factory()->user->create();
		$cid   = $this->customer_for( 'buyer@example.com', $owner );
		wp_set_current_user( 0 );
		$this->buy( $this->download(), 'DE' );
		$order = $this->place_order( 'DE' );
		$this->assertSame( '', (string) edd_get_customer_meta( $cid, OrderFinaliser::CUSTOMER_NUMBER_META, true ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->h->finaliser->recheck( (int) $order->id, self::VALID_DE, get_current_user_id() );

		$this->assertSame( self::VALID_DE, (string) edd_get_customer_meta( $cid, OrderFinaliser::CUSTOMER_NUMBER_META, true ) );
	}

	public function test_a_number_entered_for_another_country_does_not_follow_the_order(): void {
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		$order = $this->place_order( 'FR' );

		$decision = DecisionStore::get( (int) $order->id );
		$this->assertNull( $decision->to_array()['vat_number'] );
	}

	public function test_a_buy_now_order_without_checkout_checks_or_address_still_records_a_decision_from_the_ip(): void {
		$this->settings( array( 'ip_country_source' => 'cloudflare' ) );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'FR';
		remove_all_actions( 'edd_checkout_error_checks' );
		$this->buy( $this->download(), '' );
		unset( $_POST['billing_country'] );

		$order = $this->place_order( '', array(), false );

		$this->assertFalse( $this->h->decider->is_checkout(), 'The checkout checks never ran.' );
		$decision = DecisionStore::get( (int) $order->id );
		$this->assertNotNull( $decision );
		$record = $decision->to_array();
		$this->assertSame( 'FR', $record['evidence']['ip'] );
		$this->assertContains( OrderFinaliser::NO_ADDRESS_FLAG, $decision->flags() );
		$this->assertContains( 'single_evidence', $decision->flags() );
	}

	public function test_the_ip_is_not_read_from_an_admin_request(): void {
		$this->settings( array( 'ip_country_source' => 'cloudflare' ) );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'FR';
		$this->buy( $this->download(), 'DE' );
		set_current_screen( 'dashboard' );

		$order = $this->place_order( 'DE' );

		$this->assertNull( DecisionStore::get( (int) $order->id )->to_array()['evidence']['ip'] );
		set_current_screen( 'front' );
	}

	public function test_a_manual_order_is_decided_and_flagged_without_touching_its_tax(): void {
		$this->settings( array( 'ip_country_source' => 'cloudflare' ) );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'FR';
		$this->buy( $this->download(), 'DE' );
		$order  = $this->place_order( 'DE' );
		$before = (int) $this->fresh( $order )->tax_rate_id;
		edd_delete_order_meta( $order->id, DecisionStore::META );

		do_action( 'edd_post_add_manual_order', $order->id, array(), array() );

		$decision = DecisionStore::get( (int) $order->id );
		$this->assertNotNull( $decision );
		$this->assertSame( OrderFinaliser::MANUAL, $decision->trigger() );
		$this->assertContains( OrderFinaliser::MANUAL, $decision->flags() );
		$this->assertNull( $decision->to_array()['evidence']['ip'], 'The admin\'s own connection is no evidence.' );
		$this->assertSame( $before, (int) $this->fresh( $order )->tax_rate_id );
	}

	public function test_tax_that_disagrees_with_the_decision_is_flagged_and_the_rate_left_alone(): void {
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		$order = $this->place_order( 'DE' );

		// The order as an admin might edit it into one charged with VAT, while its number still stands.
		edd_update_order(
			$order->id,
			array(
				'tax'         => 19,
				'tax_rate_id' => $this->active_rate_id( 'DE' ),
			)
		);
		do_action( 'edd_updated_edited_purchase', $order->id );

		$decision = DecisionStore::get( (int) $order->id );
		$this->assertContains( OrderFinaliser::TAX_DIFFERS_FLAG, $decision->flags() );
		$this->assertSame( $this->active_rate_id( 'DE' ), (int) $this->fresh( $order )->tax_rate_id );
	}

	public function test_a_free_order_is_not_flagged(): void {
		$this->buy( $this->download( 0.0 ), 'FR' );
		$order = $this->place_order( 'FR' );

		$decision = DecisionStore::get( (int) $order->id );
		$this->assertNotNull( $decision );
		$this->assertNotContains( OrderFinaliser::TAX_DIFFERS_FLAG, $decision->flags() );
	}

	public function test_orders_are_left_alone_when_taxes_are_off(): void {
		$this->buy( $this->download(), 'FR' );
		edd_update_option( 'enable_taxes', false );
		$order = $this->place_order( 'FR' );

		$this->assertNull( DecisionStore::get( (int) $order->id ) );
	}

	public function test_a_failure_while_deciding_never_breaks_the_order(): void {
		$this->settings( array( 'ip_country_source' => 'cloudflare' ) );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'FR';
		add_filter(
			'vatright_edd_ip_country',
			static function () {
				throw new RuntimeException( 'boom' );
			}
		);
		$this->buy( $this->download(), 'FR' );
		$this->h->decider->forget();

		$order = $this->place_order( 'FR' );

		$this->assertGreaterThan( 0, $order->id );
		$this->assertNull( DecisionStore::get( (int) $order->id ) );
	}

	public function test_a_recheck_asks_the_registry_again_and_records_who_did_it(): void {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::OUTAGE_DE, 'DE' );
		$order = $this->place_order( 'DE' );

		$first = DecisionStore::get( (int) $order->id );
		$this->assertContains( 'validation_pending', $first->flags() );
		$this->assertSame( 18.0, (float) $order->tax, 'VAT was charged while the registry was down.' );
		$calls = $this->vies->calls;

		$this->vies->set( '129273398', 'valid' );
		$decision = $this->h->finaliser->recheck( (int) $order->id, '', $user );

		$this->assertGreaterThan( $calls, $this->vies->calls, 'A re-check bypasses the cache.' );
		$this->assertSame( 'valid', $decision->to_array()['validation']['status'] );
		$this->assertContains( OrderFinaliser::TAX_DIFFERS_FLAG, $decision->flags(), 'The order was charged VAT the valid number would have avoided.' );
		$this->assertSame( 18.0, (float) $this->fresh( $order )->tax, 'A re-check never changes amounts.' );

		$log = DecisionStore::log( (int) $order->id );
		$this->assertSame( array( OrderFinaliser::BUILT, OrderFinaliser::RECHECK ), array_column( $log, 'trigger' ) );
		$this->assertSame( $user, $log[1]['actor_user_id'] );
	}

	public function test_a_recheck_with_a_corrected_number_replaces_it(): void {
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::INVALID_DE, 'DE' );
		$order = $this->place_order( 'DE' );
		$this->assertSame( 'invalid', DecisionStore::get( (int) $order->id )->to_array()['validation']['status'] );

		$decision = $this->h->finaliser->recheck( (int) $order->id, 'de 114 103 379', 1 );

		$this->assertSame( self::VALID_DE, $decision->to_array()['vat_number']['canonical'] );
		$this->assertSame( self::VALID_DE, (string) edd_get_order_meta( $order->id, DecisionStore::NUMBER_META, true ) );
		$this->assertSame( '1', (string) edd_get_order_meta( $order->id, DecisionStore::REVERSE_CHARGED_META, true ) );
	}

	public function test_a_recheck_that_no_longer_reverse_charges_removes_the_flag(): void {
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		$order = $this->place_order( 'DE' );
		$this->assertSame( '1', (string) edd_get_order_meta( $order->id, DecisionStore::REVERSE_CHARGED_META, true ) );

		$this->vies->set( '114103379', 'invalid' );
		$this->h->finaliser->recheck( (int) $order->id, '', 1 );

		$this->assertSame( '', (string) edd_get_order_meta( $order->id, DecisionStore::REVERSE_CHARGED_META, true ) );
	}

	public function test_a_recheck_keeps_the_threshold_the_order_was_decided_under(): void {
		$this->ledger_sale( (int) wp_date( 'Y' ), 9950.0 );
		$this->buy( $this->download(), 'DE' );
		$order = $this->place_order( 'DE' );
		$this->assertSame( Rule::B2C_DESTINATION, DecisionStore::get( (int) $order->id )->rule(), 'This order\'s own amount crossed the threshold.' );

		// The order is not in the ledger, so without the carried verdict it would count as below the threshold again.
		$this->assertFalse( ( new ThresholdTracker() )->status( (int) wp_date( 'Y' ) )->exceeded );
		$decision = $this->h->finaliser->recheck( (int) $order->id, '', 1 );

		$this->assertSame( Rule::B2C_DESTINATION, $decision->rule() );
	}

	public function test_refunds_are_not_decided(): void {
		$this->buy( $this->download(), 'FR' );
		$order  = $this->place_order( 'FR' );
		$refund = edd_add_order(
			array(
				'parent' => $order->id,
				'type'   => 'refund',
				'status' => 'complete',
				'total'  => -118,
			)
		);

		$this->h->finaliser->on_built( $refund );

		$this->assertNull( DecisionStore::get( (int) $refund ) );
	}

	public function test_editing_the_address_decides_the_order_again_without_asking_the_registry(): void {
		$this->settings( array( 'oss_registered' => true ) );
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		$order = $this->place_order( 'DE' );
		$calls = $this->vies->calls;

		$address = $order->get_address();
		edd_update_order_address( $address->id, array( 'country' => 'FR' ) );
		do_action( 'edd_updated_edited_purchase', $order->id );

		$this->assertSame( $calls, $this->vies->calls );
		$decision = DecisionStore::get( (int) $order->id );
		$this->assertSame( 'FR', $decision->to_array()['customer']['billing']['country'] );
		$this->assertSame( OrderFinaliser::EDITED, $decision->trigger() );
	}
}
