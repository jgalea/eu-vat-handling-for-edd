<?php

namespace VatrightEdd\Tests\Integration\Privacy;

use EDD\Orders\Order;
use VatrightEdd\Domain\ValidationResult;
use VatrightEdd\Domain\VatNumber;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Orders\OrderFinaliser;
use VatrightEdd\Privacy\Privacy;
use VatrightEdd\Privacy\Retention;
use VatrightEdd\Registry\ValidationCache;
use VatrightEdd\Settings;
use VatrightEdd\Tests\Support\LedgerTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/LedgerTestCase.php';

class PrivacyTest extends LedgerTestCase {

	const EMAIL = 'buyer@example.com';

	/** @var Privacy */
	private $privacy;

	public function set_up() {
		parent::set_up();
		$this->privacy = new Privacy( new ValidationCache( Settings::get() ) );
	}

	private function reverse_charged_order( string $created ): Order {
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		$order = $this->place_order( 'DE' );
		$this->dated( $order, $created );
		$this->complete( $order );
		return $this->fresh( $order );
	}

	/**
	 * @param array<string,mixed> $result
	 * @return string
	 */
	private function exported_text( array $result ): string {
		return (string) wp_json_encode( $result['data'] );
	}

	public function test_the_exporter_and_eraser_are_registered(): void {
		$this->privacy->register();

		$this->assertArrayHasKey( Privacy::GROUP, apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( Privacy::GROUP, apply_filters( 'wp_privacy_personal_data_erasers', array() ) );
	}

	public function test_the_export_lists_the_number_the_registry_answer_and_the_ledger_rows(): void {
		$order = $this->reverse_charged_order( '2030-03-10 10:00:00' );

		$result = $this->privacy->export( self::EMAIL );

		$text = $this->exported_text( $result );
		$this->assertStringContainsString( self::VALID_DE, $text );
		$this->assertStringContainsString( 'Example GmbH', $text );
		$this->assertStringContainsString( (string) $order->get_number(), $text );
		$groups = array_unique( array_column( $result['data'], 'group_id' ) );
		$this->assertContains( Privacy::GROUP . '-ledger', $groups );
		$this->assertTrue( $result['done'] );
	}

	public function test_the_export_for_an_unknown_email_is_empty(): void {
		$this->reverse_charged_order( '2030-03-10 10:00:00' );

		$result = $this->privacy->export( 'nobody@example.com' );

		$this->assertSame( array(), $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_the_export_includes_the_number_saved_on_the_customer(): void {
		$order = $this->reverse_charged_order( '2030-03-10 10:00:00' );
		edd_update_customer_meta( (int) $order->customer_id, OrderFinaliser::CUSTOMER_NUMBER_META, self::VALID_DE );

		$result = $this->privacy->export( self::EMAIL );

		$this->assertContains( 'Saved VAT number', array_column( $result['data'][0]['data'], 'name' ) );
	}

	public function test_an_order_inside_the_retention_period_is_kept_and_the_customer_is_told(): void {
		$order = $this->reverse_charged_order( '2030-03-10 10:00:00' );

		$result = $this->privacy->erase( self::EMAIL );

		$this->assertTrue( $result['items_retained'] );
		$this->assertFalse( $result['items_removed'] );
		$this->assertCount( 1, $result['messages'] );
		$this->assertSame( self::VALID_DE, edd_get_order_meta( (int) $order->id, DecisionStore::NUMBER_META, true ) );
		$this->assertSame( self::VALID_DE, $this->rows( (int) $order->id )[0]['customer_vat'] );
	}

	public function test_an_order_past_the_retention_period_is_scrubbed_but_keeps_what_the_return_rests_on(): void {
		$order = $this->reverse_charged_order( '2010-03-10 10:00:00' );
		$this->assertNotSame( '', (string) edd_get_order_meta( (int) $order->id, DecisionStore::NUMBER_META, true ), 'The control: the number is there before the erasure.' );

		$result = $this->privacy->erase( self::EMAIL );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( '', (string) edd_get_order_meta( (int) $order->id, DecisionStore::NUMBER_META, true ) );
		$this->assertNotSame( '', (string) edd_get_order_meta( (int) $order->id, Retention::SCRUBBED_META, true ) );
		$decision = DecisionStore::get( (int) $order->id );
		$this->assertNotNull( $decision );
		$record = $decision->to_array();
		$this->assertNull( $record['vat_number'] );
		$this->assertNull( $record['validation']['name'] );
		$this->assertSame( 'reverse_charge_services', $decision->scheme() );
		$row = $this->rows( (int) $order->id )[0];
		$this->assertNull( $row['customer_vat'] );
		$this->assertSame( 100.0, (float) $row['net'] );
		$this->assertStringNotContainsString( self::VALID_DE, $this->exported_text( $this->privacy->export( self::EMAIL ) ) );
	}

	public function test_the_number_saved_on_the_customer_goes_whatever_the_retention(): void {
		$order = $this->reverse_charged_order( '2030-03-10 10:00:00' );
		edd_update_customer_meta( (int) $order->customer_id, OrderFinaliser::CUSTOMER_NUMBER_META, self::VALID_DE );
		edd_update_customer_meta( (int) $order->customer_id, OrderFinaliser::CUSTOMER_VALIDATED_META, '2030-03-10T10:00:00Z' );

		$result = $this->privacy->erase( self::EMAIL );

		$this->assertTrue( $result['items_removed'] );
		$this->assertSame( '', (string) edd_get_customer_meta( (int) $order->customer_id, OrderFinaliser::CUSTOMER_NUMBER_META, true ) );
		$this->assertSame( '', (string) edd_get_customer_meta( (int) $order->customer_id, OrderFinaliser::CUSTOMER_VALIDATED_META, true ) );
	}

	public function test_cached_answers_for_the_saved_number_go_whichever_store_number_asked(): void {
		update_option(
			Settings::OPTION,
			array(
				'store_vat_number'    => 'MT20000010',
				'store_vat_number_gb' => 'GB333289454',
			)
		);
		Settings::reset();
		$order = $this->reverse_charged_order( '2030-03-10 10:00:00' );
		edd_update_customer_meta( (int) $order->customer_id, OrderFinaliser::CUSTOMER_NUMBER_META, 'GB980780684' );

		$cache  = new ValidationCache( Settings::get() );
		$number = VatNumber::parse( 'GB980780684', 'GB' );
		$eu     = VatNumber::parse( 'MT20000010', 'MT' );
		$uk     = VatNumber::parse( 'GB333289454', 'GB' );
		$result = new ValidationResult( ValidationResult::VALID, 'hmrc', '2030-03-10T10:00:00Z', 'Example Ltd' );
		$this->assertNotNull( $number );
		foreach ( array( null, $eu, $uk ) as $requester ) {
			$cache->put( $number, $requester, $result );
			$this->assertNotNull( $cache->get( $number, $requester ), 'The control: the answer is cached before the erasure.' );
		}

		( new Privacy( $cache ) )->erase( self::EMAIL );

		foreach ( array( null, $eu, $uk ) as $requester ) {
			$this->assertNull( $cache->get( $number, $requester ) );
		}
	}

	public function test_other_plugins_copies_of_the_number_go_with_the_scrub(): void {
		$order = $this->reverse_charged_order( '2010-03-10 10:00:00' );
		edd_update_order_meta( (int) $order->id, '_edd_payment_vat_number', self::VALID_DE );
		edd_update_order_meta( (int) $order->id, '_edd_payment_vat_company_name', 'Example GmbH' );

		$this->privacy->erase( self::EMAIL );

		$this->assertSame( '', (string) edd_get_order_meta( (int) $order->id, '_edd_payment_vat_number', true ) );
		$this->assertSame( '', (string) edd_get_order_meta( (int) $order->id, '_edd_payment_vat_company_name', true ) );
	}

	public function test_the_retention_job_scrubs_only_orders_past_the_period(): void {
		$old    = $this->reverse_charged_order( '2010-03-10 10:00:00' );
		$recent = $this->reverse_charged_order( '2030-03-10 10:00:00' );

		( new Retention() )->run_batch();

		$this->assertSame( '', (string) edd_get_order_meta( (int) $old->id, DecisionStore::NUMBER_META, true ) );
		$this->assertSame( self::VALID_DE, edd_get_order_meta( (int) $recent->id, DecisionStore::NUMBER_META, true ) );
		$this->assertNotSame( '', (string) edd_get_order_meta( (int) $old->id, Retention::SCRUBBED_META, true ) );
	}

	public function test_a_longer_retention_setting_keeps_the_old_order(): void {
		$old = $this->reverse_charged_order( '2010-03-10 10:00:00' );
		update_option( Settings::OPTION, array( 'retention_years' => 30 ) );
		Settings::reset();

		( new Retention() )->run_batch();

		$this->assertSame( self::VALID_DE, edd_get_order_meta( (int) $old->id, DecisionStore::NUMBER_META, true ) );
	}

	public function test_the_policy_text_names_the_retention_period(): void {
		$this->assertStringContainsString( '10 years', $this->privacy->policy_text() );
	}
}
