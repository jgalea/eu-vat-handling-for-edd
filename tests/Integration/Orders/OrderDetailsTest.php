<?php

namespace VatrightEdd\Tests\Integration\Orders;

use RuntimeException;
use VatrightEdd\Domain\Decision;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Orders\OrderDetails;
use VatrightEdd\Domain\Correction;
use VatrightEdd\Orders\VatEdit;
use VatrightEdd\Tests\Support\LedgerTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/LedgerTestCase.php';

class OrderDetailsTest extends LedgerTestCase {

	/** @var OrderDetails */
	private $details;

	public function set_up() {
		parent::set_up();
		$this->details = new OrderDetails( $this->h->finaliser, new VatEdit( $this->ledger ) );
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

	/**
	 * @param array<string,mixed> $post
	 * @return array{json: mixed, died: string|null}
	 */
	private function ajax_edit( array $post ): array {
		$_POST    = $post;
		$_REQUEST = $post;
		$died     = null;
		ob_start();
		try {
			$this->details->ajax_edit();
		} catch ( RuntimeException $e ) {
			$died = $e->getMessage();
		}
		return array(
			'json' => json_decode( (string) ob_get_clean(), true ),
			'died' => $died,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function edit_post( \EDD\Orders\Order $order ): array {
		return array(
			'nonce'      => wp_create_nonce( OrderDetails::EDIT_ACTION ),
			'order_id'   => $order->id,
			'vat_number' => 'DE114103379',
			'country'    => 'DE',
			'treatment'  => Correction::REVERSE_CHARGE,
			'reason'     => 'Certificate on file',
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

	public function test_an_admin_sees_the_edit_form_with_the_warning_and_a_user_without_the_capability_does_not(): void {
		$order = $this->reverse_charged_order();

		$html = $this->render( $order );
		$this->assertStringContainsString( 'id="vatright-edd-edit-save"', $html );
		$this->assertStringContainsString( 'id="vatright-edd-edit-reason"', $html );
		$this->assertStringContainsString( 'does not change the amounts charged', $html );
		$this->assertStringContainsString( 'it does not refund anything', $html );
		$this->assertMatchesRegularExpression( '/<option value="DE" selected=.selected.>/', $html, 'The country of evidence is preselected.' );
		$this->assertMatchesRegularExpression( '/<option value="reverse_charge" selected=.selected.>/', $html, 'So is the treatment.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertStringNotContainsString( 'vatright-edd-edit', $this->render( $order ) );
	}

	public function test_the_edit_form_is_absent_when_the_screen_has_no_editor(): void {
		$order   = $this->reverse_charged_order();
		$details = new OrderDetails( $this->h->finaliser );
		ob_start();
		$details->render( $order );
		$this->assertStringNotContainsString( 'vatright-edd-edit', (string) ob_get_clean() );
	}

	public function test_an_edit_with_a_nonce_and_the_capability_is_applied_and_logged(): void {
		$order = $this->reverse_charged_order();
		$this->complete( $order );
		$post               = $this->edit_post( $order );
		$post['vat_number'] = 'DE129273398';

		$result = $this->ajax_edit( $post );

		$this->assertTrue( $result['json']['success'], (string) wp_json_encode( $result ) );
		$this->assertTrue( $result['json']['data']['rebooked'] );
		$log   = DecisionStore::log( (int) $order->id );
		$entry = $log[ count( $log ) - 1 ];
		$this->assertSame( 'admin_vat_edit', $entry['trigger'] );
		$this->assertSame( get_current_user_id(), $entry['actor_user_id'] );
		$this->assertSame( 'Certificate on file', $entry['detail']['reason'] );
		$this->assertSame( array( self::VALID_DE, self::OUTAGE_DE ), $entry['detail']['changes']['vat_number'] );
	}

	public function test_an_edit_without_or_with_a_wrong_nonce_is_refused_and_changes_nothing(): void {
		$order = $this->reverse_charged_order();
		$log   = DecisionStore::log( (int) $order->id );

		foreach ( array( '', 'nope' ) as $nonce ) {
			$post          = $this->edit_post( $order );
			$post['nonce'] = $nonce;
			if ( '' === $nonce ) {
				unset( $post['nonce'] );
			}
			$post['vat_number'] = 'DE129273398';
			$this->assertSame( '-1', $this->ajax_edit( $post )['died'] );
		}
		$this->assertSame( $log, DecisionStore::log( (int) $order->id ) );
	}

	public function test_an_edit_by_a_user_without_the_capability_is_refused_with_a_valid_nonce(): void {
		$order = $this->reverse_charged_order();
		$log   = DecisionStore::log( (int) $order->id );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$post               = $this->edit_post( $order );
		$post['vat_number'] = 'DE129273398';

		$result = $this->ajax_edit( $post );

		$this->assertFalse( $result['json']['success'] );
		$this->assertSame( $log, DecisionStore::log( (int) $order->id ) );
		$this->assertSame( self::VALID_DE, DecisionStore::get( (int) $order->id )->to_array()['vat_number']['canonical'] );
	}

	public function test_an_edit_without_a_reason_comes_back_as_an_error_message(): void {
		$order              = $this->reverse_charged_order();
		$post               = $this->edit_post( $order );
		$post['reason']     = '  ';
		$post['vat_number'] = 'DE129273398';

		$result = $this->ajax_edit( $post );

		$this->assertFalse( $result['json']['success'] );
		$this->assertSame( 'Give a reason for the change.', $result['json']['data']['message'] );
		$this->assertCount( 1, DecisionStore::log( (int) $order->id ) );
	}

	public function test_the_log_shows_who_why_and_the_old_and_new_values_escaped(): void {
		$order              = $this->reverse_charged_order();
		$post               = $this->edit_post( $order );
		$post['vat_number'] = 'DE129273398';
		$post['reason']     = 'asked by email';
		$this->assertTrue( $this->ajax_edit( $post )['json']['success'] );
		$record = DecisionStore::get( (int) $order->id );
		DecisionStore::apply( (int) $order->id, $record, 'admin_vat_edit', get_current_user_id(), array( 'reason' => '<script>alert(1)</script> hostile' ) );

		$html = $this->render( $this->fresh( $order ) );

		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)', $html );
		$this->assertStringContainsString( 'VAT number: ' . self::VALID_DE . ' to ' . self::OUTAGE_DE, $html );
		$this->assertStringContainsString( 'admin_vat_edit', $html );
		$this->assertStringContainsString( wp_get_current_user()->display_name, $html );
	}

	public function test_an_automatic_refund_shows_in_the_log_and_flags(): void {
		$order = $this->reverse_charged_order();
		DecisionStore::apply(
			(int) $order->id,
			DecisionStore::get( (int) $order->id )->with_flag( \VatrightEdd\Orders\VatRefund::FLAG_REFUNDED ),
			\VatrightEdd\Orders\VatRefund::TRIGGER,
			0,
			array(
				'refund_id' => 55,
				'amount'    => 19.0,
				'currency'  => 'EUR',
			)
		);

		$html = $this->render( $this->fresh( $order ) );

		$this->assertStringContainsString( 'The VAT was refunded automatically after the number validated.', $html );
		$this->assertStringContainsString( 'Refund 55 of', $html );
		$this->assertStringContainsString( 'auto_vat_refund', $html );
	}
}
