<?php

namespace VatrightEdd\Tests\Integration\Orders;

use VatrightEdd\Domain\Decision;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Tests\Support\OrdersTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/OrdersTestCase.php';

class DecisionStoreTest extends OrdersTestCase {

	public function test_third_party_text_survives_the_round_trip_through_order_meta(): void {
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		$order = $this->place_order( 'DE' );

		$record                       = DecisionStore::get( (int) $order->id )->to_array();
		$record['validation']['name'] = 'Müller "Söhne" \\ & Co <b>/</b> é';
		DecisionStore::apply( (int) $order->id, Decision::from_array( $record ), 'test' );

		$stored = DecisionStore::get( (int) $order->id );
		$this->assertNotNull( $stored );
		$this->assertSame( $record, $stored->to_array() );
	}

	public function test_the_log_grows_only_when_the_outcome_changes(): void {
		$this->buy( $this->download(), 'DE' );
		$order = $this->place_order( 'DE' );
		$id    = (int) $order->id;
		$this->assertCount( 1, DecisionStore::log( $id ) );

		$same = DecisionStore::get( $id );
		DecisionStore::apply( $id, $same, 'again' );
		$this->assertCount( 1, DecisionStore::log( $id ), 'The same outcome adds no entry.' );

		DecisionStore::apply( $id, $same->with_flag( 'validation_pending' ), 'changed' );
		$log = DecisionStore::log( $id );
		$this->assertCount( 2, $log );
		$this->assertSame( 'changed', $log[1]['trigger'] );
		$this->assertSame( $same->id(), $log[1]['previous_id'] );
	}

	public function test_an_order_with_no_record_has_no_decision(): void {
		$this->assertNull( DecisionStore::get( 999999 ) );
		$this->assertSame( array(), DecisionStore::log( 999999 ) );
	}

	public function test_a_damaged_record_reads_as_no_decision(): void {
		$this->buy( $this->download(), 'DE' );
		$order = $this->place_order( 'DE' );
		edd_update_order_meta( $order->id, DecisionStore::META, wp_slash( '{"v":1}' ) );

		$this->assertNull( DecisionStore::get( (int) $order->id ) );
	}

	public function test_detail_is_logged_even_when_the_outcome_is_the_same(): void {
		$this->buy( $this->download(), 'DE' );
		$order = $this->place_order( 'DE' );
		$id    = (int) $order->id;

		DecisionStore::apply( $id, DecisionStore::get( $id ), 'admin_vat_edit', 7, array( 'reason' => 'Checked by hand' ) );

		$log = DecisionStore::log( $id );
		$this->assertCount( 2, $log );
		$this->assertSame( array( 'reason' => 'Checked by hand' ), $log[1]['detail'] );
		$this->assertSame( 7, $log[1]['actor_user_id'] );
		$this->assertArrayNotHasKey( 'detail', $log[0], 'Entries without detail keep their old shape.' );
	}

	public function test_the_numbers_in_edit_entries_can_be_blanked_and_the_rest_stays(): void {
		$this->buy( $this->download(), 'DE' );
		$order = $this->place_order( 'DE' );
		$id    = (int) $order->id;
		DecisionStore::apply(
			$id,
			DecisionStore::get( $id ),
			'admin_vat_edit',
			7,
			array(
				'reason'  => 'Typo',
				'changes' => array(
					'vat_number' => array( 'DE111111111', 'DE114103379' ),
					'country'    => array( 'FR', 'DE' ),
				),
			)
		);
		$this->assertTrue( DecisionStore::log_holds_numbers( $id ) );

		DecisionStore::redact_log_numbers( $id );

		$log = DecisionStore::log( $id );
		$this->assertCount( 2, $log );
		$this->assertSame( array( null, null ), $log[1]['detail']['changes']['vat_number'] );
		$this->assertSame( array( 'FR', 'DE' ), $log[1]['detail']['changes']['country'] );
		$this->assertSame( 'Typo', $log[1]['detail']['reason'] );
		$this->assertSame( 7, $log[1]['actor_user_id'] );
		$this->assertFalse( DecisionStore::log_holds_numbers( $id ) );
		$this->assertStringNotContainsString( '114103379', (string) wp_json_encode( $log ) );
	}
}
