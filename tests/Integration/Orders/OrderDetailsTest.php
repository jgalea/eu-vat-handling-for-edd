<?php

namespace VatrightEdd\Tests\Integration\Orders;

use RuntimeException;
use VatrightEdd\Domain\Decision;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Orders\OrderDetails;
use VatrightEdd\Tests\Support\OrdersTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/OrdersTestCase.php';

class OrderDetailsTest extends OrdersTestCase {

	/** @var OrderDetails */
	private $details;

	public function set_up() {
		parent::set_up();
		$this->details = new OrderDetails( $this->h->finaliser );
		$this->details->register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function ( $message ) {
					throw new RuntimeException( esc_html( is_scalar( $message ) ? (string) $message : '' ) );
				};
			}
		);
	}

	private function reverse_charged_order(): \EDD\Orders\Order {
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		return $this->place_order( 'DE' );
	}

	/**
	 * @param array<string,mixed> $post
	 * @return array{json: mixed, died: string|null}
	 */
	private function ajax( array $post ): array {
		$_POST    = $post;
		$_REQUEST = $post;
		$died     = null;
		ob_start();
		try {
			$this->details->ajax_recheck();
		} catch ( RuntimeException $e ) {
			$died = $e->getMessage();
		}
		return array(
			'json' => json_decode( (string) ob_get_clean(), true ),
			'died' => $died,
		);
	}

	private function render( \EDD\Orders\Order $order ): string {
		ob_start();
		$this->details->render( $order );
		return (string) ob_get_clean();
	}

	public function test_the_section_is_added_for_a_saved_order_only(): void {
		$order = $this->reverse_charged_order();

		$sections = apply_filters( 'edd_get_order_details_sections', array(), $order );
		$this->assertSame( 'vatright_edd', $sections[0]['id'] );

		$this->assertSame( array(), apply_filters( 'edd_get_order_details_sections', array(), new \EDD\Orders\Order() ), 'The add-order screen passes an order with no id.' );
	}

	public function test_the_section_shows_the_decision_and_registry_answer(): void {
		$html = $this->render( $this->reverse_charged_order() );

		$this->assertStringContainsString( 'reverse charge (Article 196)', $html );
		$this->assertStringContainsString( self::VALID_DE, $html );
		$this->assertStringContainsString( 'Example GmbH', $html );
		$this->assertStringContainsString( 'id="vatright-edd-recheck"', $html );
	}

	public function test_third_party_text_is_escaped(): void {
		$order  = $this->reverse_charged_order();
		$record = DecisionStore::get( (int) $order->id )->to_array();

		$record['validation']['name']    = '<script>alert(1)</script>';
		$record['validation']['address'] = '"><img src=x onerror=alert(2)>';
		$record['vat_number']['raw']     = '<i>DE</i>';
		DecisionStore::apply( (int) $order->id, Decision::from_array( $record ), 'test' );

		$html = $this->render( $order );
		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( '<i>DE</i>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)', $html );
	}

	public function test_an_order_without_a_decision_says_so(): void {
		$order = $this->reverse_charged_order();
		edd_delete_order_meta( $order->id, DecisionStore::META );

		$this->assertStringContainsString( 'No VAT decision is recorded', $this->render( $order ) );
	}

	public function test_a_user_who_cannot_edit_orders_gets_no_button(): void {
		$order = $this->reverse_charged_order();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertStringNotContainsString( 'vatright-edd-recheck', $this->render( $order ) );
	}

	public function test_the_recheck_runs_with_a_nonce_and_the_capability(): void {
		$order = $this->reverse_charged_order();
		$calls = $this->vies->calls;
		$this->vies->set( '114103379', 'invalid' );

		$result = $this->ajax(
			array(
				'nonce'      => wp_create_nonce( OrderDetails::ACTION ),
				'order_id'   => $order->id,
				'vat_number' => self::VALID_DE,
			)
		);

		$this->assertTrue( $result['json']['success'], (string) wp_json_encode( $result ) );
		$this->assertGreaterThan( $calls, $this->vies->calls );
		$log = DecisionStore::log( (int) $order->id );
		$this->assertCount( 2, $log );
		$this->assertSame( 'recheck', $log[1]['trigger'] );
		$this->assertSame( get_current_user_id(), $log[1]['actor_user_id'] );
	}

	public function test_a_missing_or_wrong_nonce_is_refused_and_nothing_is_asked(): void {
		$order = $this->reverse_charged_order();
		$calls = $this->vies->calls;

		foreach ( array( array(), array( 'nonce' => 'nope' ) ) as $post ) {
			$result = $this->ajax( array_merge( $post, array( 'order_id' => $order->id ) ) );
			$this->assertSame( '-1', $result['died'] );
		}
		$this->assertSame( $calls, $this->vies->calls );
	}

	public function test_a_user_without_the_capability_is_refused_with_a_valid_nonce(): void {
		$order = $this->reverse_charged_order();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$calls = $this->vies->calls;

		$result = $this->ajax(
			array(
				'nonce'    => wp_create_nonce( OrderDetails::ACTION ),
				'order_id' => $order->id,
			)
		);

		$this->assertFalse( $result['json']['success'] );
		$this->assertSame( $calls, $this->vies->calls );
		$this->assertCount( 1, DecisionStore::log( (int) $order->id ) );
	}

	public function test_an_unknown_order_is_a_404_not_a_fatal(): void {
		$result = $this->ajax(
			array(
				'nonce'    => wp_create_nonce( OrderDetails::ACTION ),
				'order_id' => 987654,
			)
		);

		$this->assertFalse( $result['json']['success'] );
	}
}
