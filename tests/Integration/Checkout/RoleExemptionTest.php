<?php

namespace VatrightEdd\Tests\Integration\Checkout;

use VatrightEdd\Checkout\VatField;
use VatrightEdd\Domain\Rule;
use VatrightEdd\Domain\Scheme;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Orders\OrderDetails;
use VatrightEdd\Settings;
use VatrightEdd\Tests\Support\LedgerTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/LedgerTestCase.php';

class RoleExemptionTest extends LedgerTestCase {

	private function customer( string $role ): int {
		$user = self::factory()->user->create( array( 'role' => $role ) );
		edd_add_customer(
			array(
				'email'   => 'buyer@example.com',
				'name'    => 'Buyer',
				'user_id' => $user,
			)
		);
		wp_set_current_user( $user );
		return $user;
	}

	public function test_a_cart_for_a_customer_with_an_exempt_role_is_not_taxed(): void {
		$this->settings( array( 'exempt_roles' => array( 'subscriber' ) ) );
		$this->customer( 'subscriber' );
		$this->buy( $this->download(), 'FR' );

		$this->assertSame( 0.0, (float) edd_get_cart_tax() );
		$decision = $this->h->decider->current();
		$this->assertSame( Rule::ROLE_EXEMPT, $decision->rule() );
		$this->assertTrue( $decision->is_vat_exempt() );
	}

	public function test_prices_including_tax_come_down_to_net_for_the_role(): void {
		edd_update_option( 'prices_include_tax', 'yes' );
		$this->settings( array( 'exempt_roles' => array( 'subscriber' ) ) );
		$this->customer( 'subscriber' );
		$this->buy( $this->download( 118.0 ), 'FR' );

		$this->assertSame( 100.0, (float) edd_get_cart_total() );
	}

	public function test_the_same_cart_is_taxed_when_the_role_is_not_listed(): void {
		$this->settings( array( 'exempt_roles' => array( 'shop_manager' ) ) );
		$this->customer( 'subscriber' );
		$this->buy( $this->download(), 'FR' );

		$this->assertSame( 18.0, (float) edd_get_cart_tax() );
		$this->assertSame( Rule::B2C_ORIGIN_BELOW_THRESHOLD, $this->h->decider->current()->rule() );
	}

	public function test_a_guest_is_taxed_even_when_every_role_is_listed(): void {
		$this->settings( array( 'exempt_roles' => array( 'subscriber', 'customer', 'administrator' ) ) );
		wp_set_current_user( 0 );
		$this->buy( $this->download(), 'FR' );

		$this->assertSame( 18.0, (float) edd_get_cart_tax() );
	}

	public function test_the_order_records_the_rule_and_the_roles_and_no_tax(): void {
		$this->settings( array( 'exempt_roles' => array( 'subscriber' ) ) );
		$this->customer( 'subscriber' );
		$this->buy( $this->download(), 'FR' );

		$order = $this->place_order( 'FR', array( 'id' => get_current_user_id() ) );

		$this->assertSame( 0.0, (float) $order->tax );
		$decision = DecisionStore::get( (int) $order->id );
		$this->assertSame( Rule::ROLE_EXEMPT, $decision->rule() );
		$this->assertSame( array( 'subscriber' ), $decision->to_array()['customer']['exempt_roles'] );
		$this->assertEmpty( $this->fresh( $order )->tax_rate_id );
	}

	public function test_an_order_built_without_the_checkout_checks_still_gets_the_role_rule(): void {
		$this->customer( 'subscriber' );
		$this->buy( $this->download(), 'FR' );
		$this->settings( array( 'exempt_roles' => array( 'subscriber' ) ) );

		$order = $this->place_order( 'FR', array( 'id' => get_current_user_id() ) );

		$this->assertSame( Rule::ROLE_EXEMPT, DecisionStore::get( (int) $order->id )->rule() );
	}

	public function test_the_ledger_books_the_order_under_its_own_scheme_without_vat(): void {
		$this->settings( array( 'exempt_roles' => array( 'subscriber' ) ) );
		$this->customer( 'subscriber' );
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR', array( 'id' => get_current_user_id() ) );
		$this->complete( $order );

		$rows = $this->rows( (int) $order->id );

		$this->assertCount( 1, $rows );
		$this->assertSame( Scheme::ROLE_EXEMPT, $rows[0]['scheme'] );
		$this->assertSame( Rule::ROLE_EXEMPT, $rows[0]['rule'] );
		$this->assertSame( 0.0, (float) $rows[0]['vat'] );
		$this->assertNull( $rows[0]['customer_vat'] );
	}

	public function test_a_confirmed_number_takes_precedence_over_the_role(): void {
		$this->settings( array( 'exempt_roles' => array( 'subscriber' ) ) );
		$this->customer( 'subscriber' );
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );

		$order = $this->place_order( 'DE', array( 'id' => get_current_user_id() ) );

		$decision = DecisionStore::get( (int) $order->id );
		$this->assertSame( Rule::RC_SERVICES_ART196, $decision->rule() );
		$this->assertSame( Scheme::REVERSE_CHARGE_SERVICES, $decision->scheme() );
	}

	public function test_an_invalid_number_does_not_stop_a_role_exempt_customer(): void {
		$this->settings( array( 'exempt_roles' => array( 'subscriber' ) ) );
		$this->customer( 'subscriber' );
		$this->buy( $this->download(), 'DE' );
		$_POST['billing_country']        = 'DE';
		$_POST[ VatField::NUMBER_FIELD ] = self::INVALID_DE;
		edd_clear_errors();
		$this->h->errors->check( array(), $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- stands in for EDD's verified checkout post.

		$this->assertEmpty( edd_get_errors() );
	}

	public function test_business_only_mode_still_refuses_a_role_exempt_customer_without_a_number(): void {
		$this->settings(
			array(
				'exempt_roles' => array( 'subscriber' ),
				'b2b_only'     => 'everywhere',
			)
		);
		$this->customer( 'subscriber' );
		$this->buy( $this->download(), 'DE' );
		$_POST['billing_country'] = 'DE';
		edd_clear_errors();
		$this->h->errors->check( array(), $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- stands in for EDD's verified checkout post.

		$this->assertArrayHasKey( 'vatright_edd_b2b_only_required', (array) edd_get_errors() );
	}

	public function test_a_guest_using_a_role_exempt_customers_email_gets_no_exemption(): void {
		$this->settings( array( 'exempt_roles' => array( 'subscriber' ) ) );
		$this->customer( 'subscriber' );
		wp_set_current_user( 0 );
		$this->buy( $this->download(), 'FR' );

		$order = $this->place_order( 'FR' );

		$this->assertSame( 18.0, (float) $order->tax );
		$decision = DecisionStore::get( (int) $order->id );
		$this->assertSame( Rule::B2C_ORIGIN_BELOW_THRESHOLD, $decision->rule() );
		$this->assertArrayNotHasKey( 'exempt_roles', $decision->to_array()['customer'] );
	}

	public function test_another_logged_in_user_using_the_email_gets_no_exemption(): void {
		$this->settings( array( 'exempt_roles' => array( 'subscriber' ) ) );
		$this->customer( 'subscriber' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->buy( $this->download(), 'FR' );

		$order = $this->place_order( 'FR', array( 'id' => get_current_user_id() ) );

		$this->assertSame( Rule::B2C_ORIGIN_BELOW_THRESHOLD, DecisionStore::get( (int) $order->id )->rule() );
	}

	public function test_an_admin_recheck_never_derives_the_exemption_from_the_linked_customer(): void {
		$this->settings( array( 'exempt_roles' => array( 'subscriber' ) ) );
		$this->customer( 'subscriber' );
		wp_set_current_user( 0 );
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );

		$decision = $this->h->finaliser->recheck( (int) $order->id, '', 0 );

		$this->assertSame( Rule::B2C_ORIGIN_BELOW_THRESHOLD, $decision->rule() );
	}

	public function test_an_admin_recheck_keeps_the_exemption_the_order_was_decided_with(): void {
		$this->settings( array( 'exempt_roles' => array( 'subscriber' ) ) );
		$this->customer( 'subscriber' );
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR', array( 'id' => get_current_user_id() ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$decision = $this->h->finaliser->recheck( (int) $order->id, '', get_current_user_id() );

		$this->assertSame( Rule::ROLE_EXEMPT, $decision->rule() );
	}

	public function test_removing_the_role_from_the_setting_ends_the_exemption_on_a_recheck(): void {
		$this->settings( array( 'exempt_roles' => array( 'subscriber' ) ) );
		$this->customer( 'subscriber' );
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR', array( 'id' => get_current_user_id() ) );
		$this->settings( array() );

		$decision = $this->h->finaliser->recheck( (int) $order->id, '', 0 );

		$this->assertNotSame( Rule::ROLE_EXEMPT, $decision->rule() );
	}

	public function test_the_order_screen_names_the_rule_and_the_roles(): void {
		$this->settings( array( 'exempt_roles' => array( 'subscriber' ) ) );
		$this->customer( 'subscriber' );
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR', array( 'id' => get_current_user_id() ) );

		ob_start();
		( new OrderDetails( $this->h->finaliser ) )->render( $this->fresh( $order ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'VAT-exempt because of the customer role', $html );
		$this->assertStringContainsString( 'Exempt customer roles', $html );
		$this->assertStringContainsString( 'subscriber', $html );
	}

	public function test_only_existing_roles_are_kept_in_the_setting(): void {
		update_option( Settings::OPTION, array( 'exempt_roles' => array( 'subscriber', 'no_such_role', 7, 'subscriber' ) ) );

		$this->assertSame( array( 'subscriber' ), Settings::get()->exempt_roles() );
	}

	public function test_it_is_off_by_default(): void {
		$this->assertSame( array(), Settings::get()->exempt_roles() );
	}
}
