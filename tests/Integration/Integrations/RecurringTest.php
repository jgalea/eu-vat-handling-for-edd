<?php

namespace VatrightEdd\Tests\Integration\Integrations;

use EDD\Orders\Order;
use EDD_Subscription;
use VatrightEdd\Checkout\InputGatherer;
use VatrightEdd\Checkout\TaxApplier;
use VatrightEdd\Domain\Rules;
use VatrightEdd\Domain\Scheme;
use VatrightEdd\Integrations\Recurring;
use VatrightEdd\Ledger\Fx;
use VatrightEdd\Ledger\ThresholdTracker;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Orders\OrderFinaliser;
use VatrightEdd\Rates\RateResolver;
use VatrightEdd\Rates\RateSync;
use VatrightEdd\Rates\Tedb;
use VatrightEdd\Registry\Router;
use VatrightEdd\Registry\ValidationCache;
use VatrightEdd\Registry\Validator;
use VatrightEdd\Registry\Vies;
use VatrightEdd\Settings;
use VatrightEdd\Tests\Support\FixtureTransport;
use VatrightEdd\Tests\Support\LedgerTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/LedgerTestCase.php';
require_once dirname( __DIR__, 2 ) . '/Support/RecurringStandIn.php';

class RecurringTest extends LedgerTestCase {

	/** @var RateResolver */
	private $resolver;

	/** @var Recurring */
	private $recurring;

	/** @var int */
	private $txn = 0;

	public function set_up() {
		parent::set_up();
		$this->resolver = new RateResolver( new RateSync( new Tedb( new FixtureTransport( sys_get_temp_dir() ) ) ) );
		$this->wire( true );
	}

	private function wire( bool $active ): void {
		$settings  = Settings::get();
		$validator = new Validator( new Router( new Vies( $this->vies ) ), new ValidationCache( $settings ), $settings );
		$gatherer  = new InputGatherer( $validator, new ThresholdTracker(), $this->checkout_fx(), $this->h->state, $this->resolver );

		$this->recurring = new Recurring( $gatherer, new Rules(), $this->resolver, $this->ledger, $this->backfill, $this->h->decider, $active );
		$this->recurring->register();
	}

	private function parent_order( string $country, int $download = 0, int $user_id = 0 ): Order {
		$this->buy( $download > 0 ? $download : $this->download(), $country );
		$order = $this->place_order( $country, array( 'id' => $user_id ) );
		$this->dated( $order, '2026-03-10 10:00:00' );
		$this->complete( $order );
		return $this->fresh( $order );
	}

	private function subscribe( Order $first, float $percent ): EDD_Subscription {
		$items = $first->get_items();
		return EDD_Subscription::signed_up( (int) $first->id, (int) $items[0]->product_id, (float) $first->total, $percent );
	}

	private function renew( EDD_Subscription $sub, float $gross ): Order {
		$id = $sub->add_payment(
			array(
				'amount'         => $gross,
				'transaction_id' => 'txn-' . ( ++$this->txn ),
				'date'           => '2026-04-10 10:00:00',
			)
		);
		$this->assertNotFalse( $id );
		return $this->fresh( edd_get_order( (int) $id ) );
	}

	/**
	 * @return string[]
	 */
	private function flags( Order $order ): array {
		$decision = DecisionStore::get( (int) $order->id );
		$this->assertNotNull( $decision, 'The renewal carries no decision.' );
		return $decision->flags();
	}

	private function age_registry_answer( int $order_id ): void {
		$record                             = json_decode( (string) edd_get_order_meta( $order_id, DecisionStore::META, true ), true );
		$record['validation']['checked_at'] = '2000-01-01T00:00:00Z';
		edd_update_order_meta( $order_id, DecisionStore::META, wp_slash( (string) wp_json_encode( $record ) ) );
	}

	public function test_a_reverse_charge_renewal_records_no_vat_and_reuses_the_fresh_registry_answer(): void {
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		$parent = $this->place_order( 'DE' );
		$this->complete( $parent );
		$parent = $this->fresh( $parent );
		$calls  = $this->vies->calls;

		$renewal = $this->renew( $this->subscribe( $parent, 0 ), 100.0 );

		$this->assertSame( 'edd_subscription', $renewal->status );
		$this->assertSame( 0.0, (float) $renewal->tax );
		$this->assertSame( 100.0, (float) $renewal->total );
		$this->assertSame( 0, (int) $renewal->tax_rate_id );
		$this->assertSame( $calls, $this->vies->calls );
		$decision = DecisionStore::get( (int) $renewal->id );
		$this->assertSame( Scheme::REVERSE_CHARGE_SERVICES, $decision->scheme() );
		$this->assertSame( Recurring::TRIGGER, $decision->trigger() );
		$rows = $this->rows( (int) $renewal->id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 0.0, (float) $rows[0]['vat'] );
		$this->assertSame( 100.0, (float) $rows[0]['net'] );
		$this->assertSame( self::VALID_DE, $rows[0]['customer_vat'] );
		$this->assertSame( 'live', $rows[0]['source'] );
	}

	public function test_a_stale_registry_answer_is_checked_again(): void {
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		$parent = $this->place_order( 'DE' );
		$this->complete( $parent );
		$this->age_registry_answer( (int) $parent->id );
		$calls = $this->vies->calls;

		$this->renew( $this->subscribe( $this->fresh( $parent ), 0 ), 100.0 );

		$this->assertGreaterThan( $calls, $this->vies->calls );
	}

	public function test_an_origin_renewal_keeps_the_store_country_split_after_a_rate_import(): void {
		$parent = $this->parent_order( 'FR' );
		$this->assertSame( 18.0, (float) $parent->tax );
		edd_update_order( (int) $parent->id, array( 'tax_rate_id' => $this->active_rate_id( 'FR' ) ) );
		edd_add_tax_rate(
			array(
				'name'   => 'FR',
				'amount' => 25,
				'scope'  => 'country',
				'status' => 'active',
			)
		);

		$renewal = $this->renew( $this->subscribe( $this->fresh( $parent ), 18 ), 118.0 );

		$this->assertSame( 18.0, (float) $renewal->tax );
		$this->assertSame( $this->active_rate_id( 'MT' ), (int) $renewal->tax_rate_id );
		$this->assertSame( Scheme::ORIGIN, DecisionStore::get( (int) $renewal->id )->scheme() );
		$this->assertNotContains( Recurring::RATE_CHANGED_FLAG, $this->flags( $renewal ) );
		$this->assertSame( 18.0, (float) $this->rows( (int) $renewal->id )[0]['vat'] );
	}

	public function test_control_without_the_integration_the_import_moves_the_split(): void {
		remove_all_filters( 'edd_recurring_add_payment_pre_args' );
		remove_all_actions( 'edd_recurring_add_subscription_order' );
		$parent = $this->parent_order( 'FR' );
		edd_update_order( (int) $parent->id, array( 'tax_rate_id' => $this->active_rate_id( 'FR' ) ) );
		edd_add_tax_rate(
			array(
				'name'   => 'FR',
				'amount' => 25,
				'scope'  => 'country',
				'status' => 'active',
			)
		);

		$renewal = $this->renew( $this->subscribe( $this->fresh( $parent ), 18 ), 118.0 );

		$this->assertSame( 23.6, (float) $renewal->tax );
	}

	public function test_a_renewal_after_the_threshold_was_crossed_is_split_at_the_destination_rate(): void {
		$parent = $this->parent_order( 'FR' );
		$this->ledger_sale( (int) wp_date( 'Y' ), 10001.0 );

		$renewal = $this->renew( $this->subscribe( $parent, 18 ), 118.0 );

		$this->assertSame( 118.0, (float) $renewal->total );
		$this->assertSame( 19.67, (float) $renewal->tax );
		$this->assertSame( $this->active_rate_id( 'FR' ), (int) $renewal->tax_rate_id );
		$decision = DecisionStore::get( (int) $renewal->id );
		$this->assertSame( Scheme::OSS_DESTINATION, $decision->scheme() );
		$this->assertContains( Recurring::TREATMENT_CHANGED_FLAG, $decision->flags() );
		$this->assertContains( Recurring::RATE_CHANGED_FLAG, $decision->flags() );
		$row = $this->rows( (int) $renewal->id )[0];
		$this->assertSame( 'FR', $row['place_of_supply'] );
		$this->assertSame( 19.67, (float) $row['vat'] );
	}

	public function test_a_renewal_outside_the_eu_records_no_vat_without_warnings(): void {
		$parent  = $this->parent_order( 'US' );
		$renewal = $this->renew( $this->subscribe( $parent, 0 ), 100.0 );

		$this->assertSame( 0.0, (float) $renewal->tax );
		$decision = DecisionStore::get( (int) $renewal->id );
		$this->assertSame( Scheme::EXPORT, $decision->scheme() );
		$this->assertNotContains( Recurring::VAT_NOT_CHARGED_FLAG, $decision->flags() );
		$this->assertNotContains( TaxApplier::MISSING_FLAG, $decision->flags() );
		$this->assertNotContains( OrderFinaliser::TAX_DIFFERS_FLAG, DecisionStore::get( (int) $parent->id )->flags() );
	}

	public function test_a_number_that_turned_invalid_is_flagged_and_no_vat_is_taken_from_a_net_priced_gross(): void {
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		$parent = $this->place_order( 'DE' );
		$this->complete( $parent );
		$this->age_registry_answer( (int) $parent->id );
		$this->vies->set( '114103379', \VatrightEdd\Tests\Support\ViesStub::INVALID );
		$sub = $this->subscribe( $this->fresh( $parent ), 0 );

		$renewal = $this->renew( $sub, 100.0 );

		$this->assertSame( 0.0, (float) $renewal->tax );
		$decision = DecisionStore::get( (int) $renewal->id );
		$this->assertFalse( $decision->is_vat_exempt() );
		$this->assertContains( Recurring::VAT_NOT_CHARGED_FLAG, $decision->flags() );
		$this->assertContains( Recurring::TREATMENT_CHANGED_FLAG, $decision->flags() );
		$this->assertSame( '', $sub->recurring_tax_rate, 'The subscription is left as it was.' );
		$this->assertSame( 0.0, (float) $this->rows( (int) $renewal->id )[0]['vat'] );
	}

	public function test_a_reduced_rate_line_gets_the_reduced_recurring_tax_at_signup(): void {
		$this->settings( array( 'oss_registered' => true ) );
		$this->buy( $this->download( 100.0, 'reduced' ), 'FR' );
		$reduced = $this->resolver->rate( 'FR', RateResolver::REDUCED );
		$this->assertSame( 5.5, $reduced );

		$args = EDD_Subscription::signup_arguments( edd_get_cart_content_details()[0] );

		$this->assertSame( 5.5, (float) $args['recurring_tax'] );
		$this->assertSame( 105.5, (float) $args['recurring_amount'] );
		$this->assertSame( 0.055, (float) $args['recurring_tax_rate'] );
		$this->assertSame( 0.055, (float) $args['initial_tax_rate'] );
	}

	public function test_control_a_standard_line_keeps_the_carts_rate(): void {
		$this->settings( array( 'oss_registered' => true ) );
		$this->buy( $this->download(), 'FR' );

		$args = EDD_Subscription::signup_arguments( edd_get_cart_content_details()[0] );

		$this->assertSame( 20.0, (float) $args['recurring_tax'] );
		$this->assertSame( 0.2, (float) $args['recurring_tax_rate'] );
	}

	public function test_a_reduced_rate_renewal_is_split_at_the_reduced_rate(): void {
		$this->settings( array( 'oss_registered' => true ) );
		$parent = $this->parent_order( 'FR', $this->download( 100.0, 'reduced' ) );
		$this->assertSame( 5.5, (float) $parent->tax );

		$renewal = $this->renew( $this->subscribe( $parent, 5.5 ), 105.5 );

		$this->assertSame( 5.5, (float) $renewal->tax );
		$this->assertNotContains( Recurring::RATE_CHANGED_FLAG, $this->flags( $renewal ) );
	}

	public function test_an_admins_manual_renewal_tax_is_kept(): void {
		$parent = $this->parent_order( 'FR' );
		$sub    = $this->subscribe( $parent, 18 );

		$id = $sub->add_payment(
			array(
				'amount'         => 118.0,
				'tax'            => 5.0,
				'transaction_id' => 'manual-1',
			)
		);

		$this->assertSame( 5.0, (float) edd_get_order( (int) $id )->tax );
		$this->assertNotNull( DecisionStore::get( (int) $id ) );
	}

	public function test_a_renewal_of_a_subscription_from_before_the_plugin_is_backfilled(): void {
		$parent = $this->parent_order( 'FR' );
		$this->wipe( $parent );

		$renewal = $this->renew( $this->subscribe( $parent, 18 ), 118.0 );

		$this->assertNull( DecisionStore::get( (int) $parent->id ) );
		$this->assertContains( 'backfilled', $this->flags( $renewal ) );
		$this->assertSame( 'backfill', $this->rows( (int) $renewal->id )[0]['source'] );
	}

	public function test_a_parent_owned_by_an_importer_is_left_for_it(): void {
		$parent = $this->parent_order( 'FR' );
		$this->wipe( $parent );
		edd_update_order_meta( (int) $parent->id, '_edd_payment_vat_number', 'FR123' );

		$renewal = $this->renew( $this->subscribe( $parent, 18 ), 118.0 );

		$this->assertNull( DecisionStore::get( (int) $renewal->id ) );
		$this->assertSame( array(), $this->rows( (int) $renewal->id ) );
	}

	public function test_the_integration_is_inert_without_recurring(): void {
		remove_all_filters( 'edd_recurring_add_payment_pre_args' );
		remove_all_filters( 'edd_recurring_subscription_recurring_amounts' );
		remove_all_filters( 'edd_recurring_subscription_pre_gateway_args' );
		remove_all_actions( 'edd_recurring_add_subscription_order' );

		$this->wire( false );

		$this->assertFalse( has_filter( 'edd_recurring_add_payment_pre_args' ) );
		$this->assertFalse( has_filter( 'edd_recurring_subscription_recurring_amounts' ) );
		$this->assertFalse( has_filter( 'edd_recurring_subscription_pre_gateway_args' ) );
		$this->assertFalse( has_action( 'edd_recurring_add_subscription_order' ) );
		$this->assertFalse( Recurring::is_installed() );
	}

	public function test_a_renewal_with_taxes_off_is_left_alone(): void {
		$parent = $this->parent_order( 'FR' );
		edd_update_option( 'enable_taxes', false );

		$renewal = $this->renew( $this->subscribe( $parent, 18 ), 118.0 );

		$this->assertNull( DecisionStore::get( (int) $renewal->id ) );
		$this->assertNotContains( OrderFinaliser::TAX_DIFFERS_FLAG, array() );
	}

	private function wipe( Order $order ): void {
		global $wpdb;
		edd_delete_order_meta( (int) $order->id, DecisionStore::META );
		edd_delete_order_meta( (int) $order->id, DecisionStore::LOG_META );
		$wpdb->delete( \VatrightEdd\Ledger\Schema::ledger_table(), array( 'order_id' => (int) $order->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function test_a_renewal_for_a_role_exempt_customer_is_recorded_under_the_role_rule_without_vat(): void {
		update_option( Settings::OPTION, array( 'exempt_roles' => array( 'subscriber' ) ) );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		edd_add_customer(
			array(
				'email'   => 'buyer@example.com',
				'name'    => 'Buyer',
				'user_id' => $user,
			)
		);
		wp_set_current_user( $user );
		$parent = $this->parent_order( 'FR', 0, $user );
		$this->assertSame( 'ROLE_EXEMPT', DecisionStore::get( (int) $parent->id )->rule() );
		wp_set_current_user( 0 );

		$renewal = $this->renew( $this->subscribe( $parent, 0 ), 100.0 );

		$this->assertSame( 0.0, (float) $renewal->tax );
		$decision = DecisionStore::get( (int) $renewal->id );
		$this->assertSame( 'ROLE_EXEMPT', $decision->rule() );
		$this->assertSame( Recurring::TRIGGER, $decision->trigger() );
		$this->assertNotContains( Recurring::TREATMENT_CHANGED_FLAG, $decision->flags() );
		$this->assertSame( Scheme::ROLE_EXEMPT, $this->rows( (int) $renewal->id )[0]['scheme'] );
	}

	public function test_a_renewal_does_not_become_exempt_because_the_user_gained_the_role_later(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		edd_add_customer(
			array(
				'email'   => 'buyer@example.com',
				'name'    => 'Buyer',
				'user_id' => $user,
			)
		);
		wp_set_current_user( $user );
		$parent = $this->parent_order( 'FR', 0, $user );
		$sub    = $this->subscribe( $parent, 18.0 );
		update_option( Settings::OPTION, array( 'exempt_roles' => array( 'subscriber' ) ) );
		wp_set_current_user( 0 );

		$renewal = $this->renew( $sub, 118.0 );

		$this->assertNotSame( 'ROLE_EXEMPT', DecisionStore::get( (int) $renewal->id )->rule() );
		$this->assertSame( 18.0, (float) $renewal->tax );
	}

	public function test_a_renewal_stops_being_exempt_when_the_role_leaves_the_setting(): void {
		update_option( Settings::OPTION, array( 'exempt_roles' => array( 'subscriber' ) ) );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		edd_add_customer(
			array(
				'email'   => 'buyer@example.com',
				'name'    => 'Buyer',
				'user_id' => $user,
			)
		);
		wp_set_current_user( $user );
		$parent = $this->parent_order( 'FR', 0, $user );
		$sub    = $this->subscribe( $parent, 0 );
		update_option( Settings::OPTION, array() );
		wp_set_current_user( 0 );

		$renewal = $this->renew( $sub, 100.0 );

		$this->assertNotSame( 'ROLE_EXEMPT', DecisionStore::get( (int) $renewal->id )->rule() );
	}
}
