<?php

namespace VatrightEdd\Tests\Integration\Checkout;

use VatrightEdd\Checkout\VatField;
use VatrightEdd\Domain\Rule;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Orders\OrderFinaliser;
use VatrightEdd\Tests\Support\OrdersTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/OrdersTestCase.php';

class B2bOnlyTest extends OrdersTestCase {

	/**
	 * @param string[]            $countries
	 * @param array<string,mixed> $more
	 */
	private function b2b( string $mode, array $countries = array(), array $more = array() ): void {
		$this->settings(
			array_merge(
				array(
					'b2b_only'           => $mode,
					'b2b_only_countries' => $countries,
				),
				$more
			)
		);
	}

	/**
	 * @param array<string,string> $post
	 * @return array<string,string>
	 */
	private function checkout( string $country, array $post = array() ): array {
		$_POST['billing_country'] = $country;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- stands in for EDD's verified checkout post.
		$_POST = array_merge( $_POST, $post );
		edd_clear_errors();
		do_action( 'edd_checkout_error_checks', array(), $_POST );
		// phpcs:enable
		$errors = edd_get_errors();
		return is_array( $errors ) ? array_intersect_key( $errors, array_flip( preg_grep( '/^vatright_edd_/', array_keys( $errors ) ) ) ) : array();
	}

	private function cart(): void {
		edd_add_to_cart( $this->download() );
		$this->h->decider->forget();
	}

	public function test_off_by_default_lets_an_order_without_a_number_through(): void {
		$this->cart();

		$this->assertSame( array(), $this->checkout( 'DE' ) );
	}

	public function test_checkout_refuses_an_order_without_a_number(): void {
		$this->b2b( 'everywhere' );
		$this->cart();

		$errors = $this->checkout( 'DE' );

		$this->assertSame( array( 'vatright_edd_b2b_only_required' ), array_keys( $errors ) );
		$this->assertStringContainsString( 'Enter a valid VAT number', $errors['vatright_edd_b2b_only_required'] );
	}

	public function test_a_verified_number_lets_the_order_through(): void {
		$this->b2b( 'everywhere' );
		$this->cart();

		$this->assertSame( array(), $this->checkout( 'DE', array( VatField::NUMBER_FIELD => self::VALID_DE ) ) );
		$this->assertTrue( $this->h->decider->current()->is_vat_exempt() );
	}

	public function test_an_invalid_number_gets_the_business_only_message_not_the_pay_with_vat_hint(): void {
		$this->b2b( 'everywhere' );
		$this->cart();

		$errors = $this->checkout( 'DE', array( VatField::NUMBER_FIELD => self::INVALID_DE ) );

		$this->assertSame( array( 'vatright_edd_invalid_vat_number' ), array_keys( $errors ) );
		$this->assertStringContainsString( 'businesses only', $errors['vatright_edd_invalid_vat_number'] );
		$this->assertStringNotContainsString( 'leave the field empty', $errors['vatright_edd_invalid_vat_number'] );
	}

	public function test_the_invalid_number_message_is_unchanged_when_the_mode_is_off(): void {
		$this->cart();

		$errors = $this->checkout( 'DE', array( VatField::NUMBER_FIELD => self::INVALID_DE ) );

		$this->assertStringContainsString( 'leave the field empty', $errors['vatright_edd_invalid_vat_number'] );
	}

	public function test_eu_mode_leaves_non_eu_customers_alone(): void {
		$this->b2b( 'eu' );
		$this->cart();

		$this->assertSame( array(), $this->checkout( 'CH' ) );
		$this->assertArrayHasKey( 'vatright_edd_b2b_only_required', $this->checkout( 'FR' ) );
	}

	public function test_list_mode_covers_only_the_chosen_countries(): void {
		$this->b2b( 'countries', array( 'FR', 'CH' ) );
		$this->cart();

		$this->assertArrayHasKey( 'vatright_edd_b2b_only_required', $this->checkout( 'CH' ) );
		$this->assertSame( array(), $this->checkout( 'DE' ) );
	}

	public function test_everywhere_mode_covers_countries_outside_the_eu_too(): void {
		$this->b2b( 'everywhere' );
		$this->cart();

		$this->assertArrayHasKey( 'vatright_edd_b2b_only_required', $this->checkout( 'CH' ) );
	}

	public function test_a_shop_with_taxes_off_has_no_field_to_ask_for_so_nothing_is_required(): void {
		edd_update_option( 'enable_taxes', false );
		$this->b2b( 'everywhere' );
		$this->cart();

		$this->assertSame( array(), $this->checkout( 'DE' ) );
	}

	public function test_the_message_can_be_edited_with_a_filter(): void {
		$this->b2b( 'everywhere' );
		$seen = array();
		add_filter(
			'vatright_edd_b2b_only_message',
			static function ( $message, $code, $country ) use ( &$seen ) {
				$seen = array( $code, $country );
				return 'Trade accounts only.';
			},
			10,
			3
		);
		$this->cart();

		$errors = $this->checkout( 'DE' );

		$this->assertSame( 'Trade accounts only.', $errors['vatright_edd_b2b_only_required'] );
		$this->assertSame( array( 'b2b_only_required', 'DE' ), $seen );
	}

	/**
	 * @return array<string,array{string,bool}>
	 */
	public function outage_policies(): array {
		return array(
			'charge VAT and flag it'     => array( 'charge', true ),
			'exempt now, re-check later' => array( 'exempt_pending', true ),
			'do not accept the order'    => array( 'reject', false ),
		);
	}

	/**
	 * A registry that was tried and failed follows the outage policy.
	 *
	 * @dataProvider outage_policies
	 */
	public function test_a_registry_outage_follows_the_outage_policy( string $policy, bool $accepted ): void {
		$this->b2b( 'everywhere', array(), array( 'outage_policy' => $policy ) );
		$this->cart();

		$errors = $this->checkout( 'DE', array( VatField::NUMBER_FIELD => self::OUTAGE_DE ) );

		if ( $accepted ) {
			$this->assertSame( array(), $errors );
			$this->assertContains( 'validation_pending', $this->h->decider->current()->flags() );
			return;
		}
		$this->assertSame( array( 'vatright_edd_registry_unavailable' ), array_keys( $errors ) );
		$this->assertStringContainsString( 'businesses only', $errors['vatright_edd_registry_unavailable'] );
	}

	/**
	 * Turning a visitor away for load is not an outage the shop chose to
	 * absorb: the number stays unchecked and the order is refused.
	 */
	public function test_a_throttled_lookup_leaves_the_number_unchecked_and_is_refused(): void {
		$this->b2b( 'everywhere', array(), array( 'outage_policy' => 'exempt_pending' ) );
		add_filter(
			'vatright_edd_client_lookup_limit',
			static function () {
				return array( 0, 600 );
			}
		);
		$this->cart();

		$errors = $this->checkout( 'DE', array( VatField::NUMBER_FIELD => self::VALID_DE ) );

		$this->assertSame( array( 'vatright_edd_b2b_only_unverified' ), array_keys( $errors ) );
		$this->assertSame( 0, $this->vies->calls );
	}

	public function test_a_number_no_registry_covers_is_refused_rather_than_taken_on_trust(): void {
		$this->b2b( 'everywhere' );
		$this->cart();

		$errors = $this->checkout( 'US', array( VatField::NUMBER_FIELD => 'US123456789' ) );

		$this->assertSame( array( 'vatright_edd_b2b_only_unverified' ), array_keys( $errors ) );
	}

	public function test_an_order_built_without_the_checkout_checks_is_flagged_when_it_has_no_number(): void {
		$this->b2b( 'everywhere' );
		$this->buy( $this->download(), 'DE' );

		$order = $this->place_order( 'DE' );

		$decision = DecisionStore::get( (int) $order->id );
		$this->assertContains( OrderFinaliser::B2B_ONLY_FLAG, $decision->flags() );
		$this->assertSame( Rule::B2C_ORIGIN_BELOW_THRESHOLD, $decision->rule(), 'the tax the order was charged is not touched' );
	}

	public function test_an_order_built_with_a_verified_number_is_not_flagged(): void {
		$this->b2b( 'everywhere' );
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );

		$order = $this->place_order( 'DE' );

		$this->assertNotContains( OrderFinaliser::B2B_ONLY_FLAG, DecisionStore::get( (int) $order->id )->flags() );
	}

	public function test_an_order_built_outside_the_covered_countries_is_not_flagged(): void {
		$this->b2b( 'countries', array( 'FR' ) );
		$this->buy( $this->download(), 'DE' );

		$order = $this->place_order( 'DE' );

		$this->assertNotContains( OrderFinaliser::B2B_ONLY_FLAG, DecisionStore::get( (int) $order->id )->flags() );
	}

	public function test_an_order_built_while_a_registry_was_down_carries_the_pending_flag_only(): void {
		$this->b2b( 'everywhere' );
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::OUTAGE_DE, 'DE' );

		$order = $this->place_order( 'DE' );

		$flags = DecisionStore::get( (int) $order->id )->flags();
		$this->assertContains( 'validation_pending', $flags );
		$this->assertNotContains( OrderFinaliser::B2B_ONLY_FLAG, $flags );
	}

	public function test_an_admin_recheck_does_not_add_the_flag(): void {
		$this->buy( $this->download(), 'DE' );
		$order = $this->place_order( 'DE' );
		$this->b2b( 'everywhere' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$decision = $this->h->finaliser->recheck( (int) $order->id, '', get_current_user_id() );

		$this->assertNotContains( OrderFinaliser::B2B_ONLY_FLAG, $decision->flags() );
	}
}
