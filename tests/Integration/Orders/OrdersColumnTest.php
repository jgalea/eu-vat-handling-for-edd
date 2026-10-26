<?php

namespace VatrightEdd\Tests\Integration\Orders;

use EDD\Orders\Order;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Orders\OrdersColumn;
use VatrightEdd\Tests\Support\OrdersTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/OrdersTestCase.php';

class OrdersColumnTest extends OrdersTestCase {

	public function set_up() {
		parent::set_up();
		( new OrdersColumn() )->register();
	}

	public function tear_down() {
		unset( $_GET[ OrdersColumn::FILTER ] );
		parent::tear_down();
	}

	private function cell( Order $order ): string {
		return (string) apply_filters( 'edd_payments_table_column', '', (int) $order->id, OrdersColumn::KEY );
	}

	private function reverse_charged(): Order {
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		return $this->place_order( 'DE' );
	}

	private function pending(): Order {
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::OUTAGE_DE, 'DE' );
		return $this->place_order( 'DE' );
	}

	private function consumer(): Order {
		$this->buy( $this->download(), 'FR' );
		return $this->place_order( 'FR' );
	}

	public function test_the_column_sits_before_the_amount(): void {
		$columns = apply_filters(
			'edd_payments_table_columns',
			array(
				'cb'     => '',
				'number' => 'Order',
				'amount' => 'Amount',
				'date'   => 'Date',
			)
		);

		$this->assertSame( array( 'cb', 'number', OrdersColumn::KEY, 'amount', 'date' ), array_keys( $columns ) );
		$this->assertSame( 'VAT', $columns[ OrdersColumn::KEY ] );
	}

	public function test_a_column_that_is_not_ours_is_left_alone(): void {
		$order = $this->reverse_charged();

		$this->assertSame( 'kept', apply_filters( 'edd_payments_table_column', 'kept', (int) $order->id, 'amount' ) );
	}

	public function test_a_validated_number_shows_with_its_status_and_the_treatment(): void {
		$cell = $this->cell( $this->reverse_charged() );

		$this->assertStringContainsString( '<strong>DE114103379</strong>', $cell );
		$this->assertStringContainsString( 'Valid', $cell );
		$this->assertStringContainsString( 'Reverse charge', $cell );
		$this->assertStringNotContainsString( 'Flagged', $cell );
	}

	public function test_an_order_waiting_for_validation_says_so(): void {
		$cell = $this->cell( $this->pending() );

		$this->assertStringContainsString( 'Pending validation', $cell );
		$this->assertStringContainsString( 'VAT charged', $cell );
		$this->assertStringNotContainsString( 'Flagged', $cell );
	}

	public function test_an_order_without_a_number_shows_only_the_treatment(): void {
		$cell = $this->cell( $this->consumer() );

		$this->assertStringNotContainsString( '<strong>', $cell );
		$this->assertStringContainsString( 'Origin', $cell );
	}

	public function test_a_role_exempt_order_is_labelled_as_such(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		edd_add_customer(
			array(
				'email'   => 'buyer@example.com',
				'name'    => 'Buyer',
				'user_id' => $user,
			)
		);
		wp_set_current_user( $user );
		$this->settings( array( 'exempt_roles' => array( 'subscriber' ) ) );

		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR', array( 'id' => $user ) );

		$this->assertStringContainsString( 'Exempt by role', $this->cell( $order ) );
	}

	public function test_a_flagged_order_is_marked_with_the_reason_on_hover(): void {
		$this->settings( array( 'b2b_only' => 'everywhere' ) );
		$cell = $this->cell( $this->consumer() );

		$this->assertStringContainsString( 'vatright-edd-vat-flagged', $cell );
		$this->assertStringContainsString( 'sells to businesses only', $cell );
	}

	public function test_an_order_with_no_decision_shows_nothing(): void {
		$order = $this->consumer();
		edd_delete_order_meta( (int) $order->id, '_vatright_edd_decision' );

		$this->assertSame( '', $this->cell( $order ) );
	}

	public function test_the_filter_offers_all_pending_and_flagged(): void {
		ob_start();
		do_action( 'edd_payment_advanced_filters_after_fields' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="' . OrdersColumn::FILTER . '"', $html );
		$this->assertStringContainsString( 'value="pending"', $html );
		$this->assertStringContainsString( 'value="flagged"', $html );
	}

	public function test_the_filter_keeps_the_chosen_value_selected(): void {
		$_GET[ OrdersColumn::FILTER ] = 'flagged';

		ob_start();
		do_action( 'edd_payment_advanced_filters_after_fields' );

		$this->assertMatchesRegularExpression( '/value="flagged"\s+selected/', (string) ob_get_clean() );
	}

	/**
	 * @return int[] Ids the list table would show for the filter, newest first.
	 */
	private function listed( string $filter ): array {
		$_GET[ OrdersColumn::FILTER ] = $filter;
		$args                         = apply_filters( 'edd_payments_table_parse_args', array( 'type' => 'sale' ), false );
		$ids                          = array_map( 'intval', wp_list_pluck( edd_get_orders( $args ), 'id' ) );
		sort( $ids );
		return $ids;
	}

	public function test_the_filter_narrows_the_list_to_pending_and_flagged_orders(): void {
		$charged = (int) $this->consumer()->id;
		$this->h->decider->reset();
		$pending = (int) $this->pending()->id;
		$this->h->decider->reset();
		$valid = (int) $this->reverse_charged()->id;

		$this->assertSame( array( $charged, $pending, $valid ), $this->listed( '' ) );
		$this->assertSame( array( $pending ), $this->listed( OrdersColumn::PENDING ) );
		$this->assertSame( array( $pending ), $this->listed( OrdersColumn::FLAGGED ) );
	}

	public function test_the_everyday_single_evidence_flag_is_not_flagged_in_the_list_or_the_column(): void {
		$order = $this->consumer();
		$this->assertContains( 'single_evidence', DecisionStore::get( (int) $order->id )->flags() );

		$this->assertStringNotContainsString( 'Flagged', $this->cell( $order ) );
		$this->assertSame( array(), $this->listed( OrdersColumn::FLAGGED ) );
	}

	public function test_a_flag_other_than_pending_counts_as_flagged(): void {
		$this->settings( array( 'b2b_only' => 'everywhere' ) );
		$flagged = (int) $this->consumer()->id;
		$this->settings( array() );
		$this->h->decider->reset();
		$clean = (int) $this->reverse_charged()->id;

		$this->assertSame( array( $flagged ), $this->listed( OrdersColumn::FLAGGED ) );
		$this->assertSame( array(), $this->listed( OrdersColumn::PENDING ) );
		$this->assertContains( $clean, $this->listed( '' ) );
	}

	public function test_an_unknown_filter_value_changes_nothing(): void {
		$this->consumer();
		$args                         = apply_filters( 'edd_payments_table_parse_args', array( 'type' => 'sale' ), false );
		$_GET[ OrdersColumn::FILTER ] = 'nonsense';

		$this->assertSame( $args, apply_filters( 'edd_payments_table_parse_args', array( 'type' => 'sale' ), false ) );
	}

	/**
	 * @return array<string,array{string,string,string}>
	 */
	public function treatments(): array {
		return array(
			'reverse charge' => array( 'RC_SERVICES_ART196', 'reverse_charge_services', 'Reverse charge' ),
			'oss'            => array( 'B2C_DESTINATION', 'oss_destination', 'OSS destination' ),
			'origin'         => array( 'B2C_ORIGIN_BELOW_THRESHOLD', 'origin', 'Origin' ),
			'domestic'       => array( 'DOMESTIC', 'domestic', 'Domestic' ),
			'export'         => array( 'EXPORT_NON_EU', 'export', 'No EU VAT' ),
			'pending exempt' => array( 'PENDING_EXEMPT', 'reverse_charge_services', 'Exempt' ),
			'invalid'        => array( 'INVALID_NUMBER', 'origin', 'VAT charged' ),
			'role'           => array( 'ROLE_EXEMPT', 'role_exempt', 'Exempt by role' ),
			'scheme only'    => array( 'SOMETHING_NEW', 'oss_destination', 'OSS destination' ),
			'unknown'        => array( 'SOMETHING_NEW', 'unheard_of', 'SOMETHING_NEW' ),
		);
	}

	/**
	 * @dataProvider treatments
	 */
	public function test_treatment_wording( string $rule, string $scheme, string $expected ): void {
		$this->assertSame( $expected, OrdersColumn::treatment( $rule, $scheme ) );
	}

	/**
	 * @return array<string,array{string,bool}>
	 */
	public function flags(): array {
		return array(
			'single evidence'           => array( 'single_evidence', false ),
			'self-certified conflict'   => array( 'evidence_conflict_self_certified', false ),
			'added by hand'             => array( 'manual', false ),
			'corrected by an admin'     => array( 'admin_corrected', false ),
			'refunded automatically'    => array( 'vat_auto_refunded', false ),
			'prefix mismatch'           => array( 'prefix_mismatch', false ),
			'validated after outage'    => array( 'validated_after_outage', true ),
			'invalid after exemption'   => array( 'invalid_after_exemption', true ),
			'tax differs'               => array( 'order_tax_differs', true ),
			'rate missing'              => array( 'rate_missing', true ),
			'no billing address'        => array( 'no_billing_address', true ),
			'business only unmet'       => array( 'b2b_only_unmet', true ),
			'refund skipped'            => array( 'auto_refund_skipped', true ),
			'refund failed'             => array( 'auto_refund_failed', true ),
			'renewal rate changed'      => array( 'renewal_rate_changed', true ),
			'renewal treatment changed' => array( 'renewal_treatment_changed', true ),
			'renewal vat not charged'   => array( 'renewal_vat_not_charged', true ),
			'unknown imported number'   => array( 'vat_number_unknown', true ),
		);
	}

	/**
	 * @dataProvider flags
	 */
	public function test_which_flags_need_an_admin( string $flag, bool $needs_action ): void {
		$order           = $this->consumer();
		$decision        = DecisionStore::get( (int) $order->id );
		$record          = $decision->to_array();
		$record['flags'] = array( $flag );
		edd_update_order_meta( (int) $order->id, DecisionStore::META, wp_slash( (string) wp_json_encode( $record ) ) );

		$shown = 1 === preg_match( '/Flagged/', $this->cell( $order ) );
		$this->assertSame( $needs_action, $shown );
		$expected = $needs_action ? array( (int) $order->id ) : array();
		$this->assertSame( $expected, $this->listed( OrdersColumn::FLAGGED ) );
	}
}
