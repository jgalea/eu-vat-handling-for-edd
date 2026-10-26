<?php

namespace VatrightEdd\Tests\Integration\Orders;

use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Orders\Revalidation;
use VatrightEdd\Registry\Router;
use VatrightEdd\Registry\ValidationCache;
use VatrightEdd\Registry\Validator;
use VatrightEdd\Registry\Vies;
use VatrightEdd\Scheduler;
use VatrightEdd\Settings;
use VatrightEdd\Tests\Support\OrdersTestCase;
use VatrightEdd\Tests\Support\ViesStub;

require_once dirname( __DIR__, 2 ) . '/Support/OrdersTestCase.php';

class RevalidationTest extends OrdersTestCase {

	/** @var Revalidation */
	private $reval;

	/** @var array<int,array{to:mixed,subject:string,message:string}> */
	private $mails = array();

	public function set_up() {
		parent::set_up();
		$settings    = Settings::get();
		$validator   = new Validator( new Router( new Vies( $this->vies ) ), new ValidationCache( $settings ), $settings );
		$this->reval = new Revalidation( $validator );
		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $short, $atts ) {
				$this->mails[] = array(
					'to'      => $atts['to'],
					'subject' => $atts['subject'],
					'message' => $atts['message'],
				);
				return true;
			},
			10,
			2
		);
	}

	public function test_a_pending_number_that_validates_flags_the_order_and_sends_the_email(): void {
		$order = $this->pending_order( 'charge' );
		$this->assertSame( array(), $this->mails, 'Nothing is sent before the re-check.' );
		$tax = (float) $order->tax;
		$this->assertGreaterThan( 0.0, $tax, 'The unavailable registry left VAT charged.' );

		$this->vies->set( '129273398', ViesStub::VALID );
		$this->reval->run( (int) $order->id, 1 );

		$decision = DecisionStore::get( (int) $order->id );
		$this->assertNotContains( 'validation_pending', $decision->flags() );
		$this->assertContains( 'validated_after_outage', $decision->flags() );
		$this->assertSame( 'valid', $decision->to_array()['validation']['status'] );
		$this->assertSame( $tax, (float) $this->fresh( $order )->tax, 'The order totals are not changed.' );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( get_bloginfo( 'admin_email' ), $this->mails[0]['to'] );
		$this->assertStringContainsString( 'refund may be due', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'id=' . $order->id, $this->mails[0]['message'] );
		$log = DecisionStore::log( (int) $order->id );
		$this->assertSame( 'revalidation', $log[ count( $log ) - 1 ]['trigger'] );
	}

	public function test_the_email_is_not_sent_when_the_setting_is_off(): void {
		$order = $this->pending_order( 'charge', array( 'notify_admin_on_revalidation' => false ) );

		$this->vies->set( '129273398', ViesStub::VALID );
		$this->reval->run( (int) $order->id, 1 );

		$this->assertContains( 'validated_after_outage', DecisionStore::get( (int) $order->id )->flags() );
		$this->assertSame( array(), $this->mails );
	}

	public function test_a_number_found_invalid_after_an_exemption_flags_the_order_and_sends_the_email(): void {
		$order = $this->pending_order( 'exempt_pending' );
		$this->assertSame( 0.0, (float) $order->tax );

		$this->vies->set( '129273398', ViesStub::INVALID );
		$this->reval->run( (int) $order->id, 1 );

		$decision = DecisionStore::get( (int) $order->id );
		$this->assertContains( 'invalid_after_exemption', $decision->flags() );
		$this->assertNotContains( 'validation_pending', $decision->flags() );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'number invalid', $this->mails[0]['subject'] );
	}

	public function test_an_invalid_number_on_a_charged_order_changes_nothing_to_act_on(): void {
		$order = $this->pending_order( 'charge' );

		$this->vies->set( '129273398', ViesStub::INVALID );
		$this->reval->run( (int) $order->id, 1 );

		$decision = DecisionStore::get( (int) $order->id );
		$this->assertNotContains( 'validation_pending', $decision->flags() );
		$this->assertNotContains( 'invalid_after_exemption', $decision->flags() );
		$this->assertSame( array(), $this->mails );
	}

	public function test_a_registry_still_down_keeps_the_order_pending_and_schedules_the_next_attempt(): void {
		$order = $this->pending_order( 'charge' );
		$this->clear_schedule( (int) $order->id );

		$this->reval->run( (int) $order->id, 1 );

		$this->assertContains( 'validation_pending', DecisionStore::get( (int) $order->id )->flags() );
		$this->assertTrue( $this->is_scheduled( (int) $order->id, 2 ), 'The second attempt is queued.' );
		$this->assertSame( array(), $this->mails );
	}

	public function test_after_the_last_attempt_a_note_is_left_and_nothing_more_is_queued(): void {
		$order = $this->pending_order( 'charge' );
		$this->clear_schedule( (int) $order->id );

		$this->reval->run( (int) $order->id, count( Revalidation::DELAYS ) );

		$this->assertFalse( $this->is_scheduled( (int) $order->id, count( Revalidation::DELAYS ) + 1 ) );
		$notes = array_map(
			static function ( $note ) {
				return (string) $note->content;
			},
			(array) edd_get_payment_notes( (int) $order->id )
		);
		$this->assertStringContainsString( 'repeated attempts', implode( ' ', $notes ) );
	}

	public function test_a_built_order_with_a_pending_number_is_queued_and_a_settled_one_is_not(): void {
		$this->reval->register();

		$pending = $this->pending_order( 'charge' );
		$this->assertTrue( $this->is_scheduled( (int) $pending->id, 1 ) );

		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		$settled = $this->place_order( 'DE' );
		$this->assertFalse( $this->is_scheduled( (int) $settled->id, 1 ), 'The control: a number that checked out is not re-checked.' );
	}

	public function test_an_already_settled_order_is_left_alone(): void {
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		$order = $this->place_order( 'DE' );
		$calls = $this->vies->calls;

		$this->reval->run( (int) $order->id, 1 );

		$this->assertSame( $calls, $this->vies->calls );
		$this->assertSame( array(), $this->mails );
	}

	/**
	 * @param array<string,mixed> $extra
	 */
	private function pending_order( string $policy, array $extra = array() ) {
		$this->settings( array_merge( array( 'outage_policy' => $policy ), $extra ) );
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::OUTAGE_DE, 'DE' );
		$order = $this->place_order( 'DE' );
		$this->assertContains( 'validation_pending', DecisionStore::get( (int) $order->id )->flags(), 'The fixture order is waiting on the registry.' );
		return $order;
	}

	private function is_scheduled( int $order_id, int $attempt ): bool {
		$args = array( $order_id, $attempt );
		if ( Scheduler::uses_action_scheduler() ) {
			return as_has_scheduled_action( Revalidation::HOOK, $args, 'vatright-edd' );
		}
		return false !== wp_next_scheduled( Revalidation::HOOK, $args );
	}

	private function clear_schedule( int $order_id ): void {
		$last = count( Revalidation::DELAYS ) + 1;
		for ( $attempt = 1; $attempt <= $last; $attempt++ ) {
			$args = array( $order_id, $attempt );
			if ( Scheduler::uses_action_scheduler() ) {
				as_unschedule_all_actions( Revalidation::HOOK, $args, 'vatright-edd' );
			}
			wp_clear_scheduled_hook( Revalidation::HOOK, $args );
		}
	}
}
