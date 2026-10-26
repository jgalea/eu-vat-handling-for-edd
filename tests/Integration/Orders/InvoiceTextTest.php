<?php

namespace VatrightEdd\Tests\Integration\Orders;

use VatrightEdd\Domain\Decision;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Orders\InvoiceText;
use VatrightEdd\Tests\Support\OrdersTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/OrdersTestCase.php';

class InvoiceTextTest extends OrdersTestCase {

	const WORDING = 'Article 196 Council Directive 2006/112/EC';

	/** @var InvoiceText */
	private $text;

	public function set_up() {
		parent::set_up();
		$this->text = new InvoiceText();
		$this->text->register();
	}

	private function reverse_charged(): \EDD\Orders\Order {
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		return $this->place_order( 'DE' );
	}

	private function receipt( \EDD\Orders\Order $order ): string {
		ob_start();
		do_action( 'edd_order_receipt_after_table', $order, array() );
		return (string) ob_get_clean();
	}

	public function test_a_reverse_charged_receipt_carries_the_number_and_the_wording(): void {
		$html = $this->receipt( $this->reverse_charged() );

		$this->assertSame( 1, substr_count( $html, self::VALID_DE ) );
		$this->assertSame( 1, substr_count( $html, self::WORDING ) );
	}

	public function test_a_charged_receipt_carries_neither(): void {
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );

		$this->assertSame( '', $this->receipt( $order ) );
	}

	public function test_the_setting_turns_the_receipt_text_off(): void {
		$order = $this->reverse_charged();
		$this->settings( array( 'receipt_vat_details' => false ) );

		$this->assertSame( '', $this->receipt( $order ) );
	}

	public function test_the_seller_details_print_with_the_store_number_and_are_escaped(): void {
		$this->settings(
			array(
				'store_vat_number' => 'MT20000010',
				'seller_company'   => 'Shop <b>Ltd</b>',
				'seller_address_1' => '1 High Street',
				'seller_postcode'  => 'VLT 1000',
				'seller_city'      => 'Valletta',
				'seller_country'   => 'Malta',
				'seller_email'     => 'hello@example.com',
			)
		);
		$this->buy( $this->download(), 'FR' );
		$html = $this->receipt( $this->place_order( 'FR' ) );

		$this->assertStringContainsString( 'Shop &lt;b&gt;Ltd&lt;/b&gt;, 1 High Street, VLT 1000 Valletta, Malta, hello@example.com', $html );
		$this->assertStringContainsString( 'MT20000010', $html );
		$this->assertStringNotContainsString( '<b>Ltd', $html );
	}

	public function test_the_registry_name_is_escaped_in_the_company_tag(): void {
		$order  = $this->reverse_charged();
		$record = DecisionStore::get( (int) $order->id )->to_array();

		$record['validation']['name'] = 'A & B <script>x</script>';
		DecisionStore::apply( (int) $order->id, Decision::from_array( $record ), 'test' );

		$this->text->add_email_tags();
		$out = edd_do_email_tags( '{vat_company}', $order->id );
		$this->assertSame( 'A &amp; B &lt;script&gt;x&lt;/script&gt;', $out );
	}

	public function test_email_tags_fill_for_a_reverse_charged_order_and_stay_empty_otherwise(): void {
		$this->text->add_email_tags();
		$order = $this->reverse_charged();

		$this->assertSame( self::VALID_DE, edd_do_email_tags( '{vat_number}', $order->id ) );
		$this->assertStringContainsString( self::WORDING, edd_do_email_tags( '{vat_notice}', $order->id ) );
		$this->assertSame( 'Example GmbH', edd_do_email_tags( '{vat_company}', $order->id ) );

		$this->buy( $this->download(), 'FR' );
		$charged = $this->place_order( 'FR' );
		$this->assertSame( '', edd_do_email_tags( '{vat_number}{vat_notice}{vat_company}', $charged->id ) );
	}

	public function test_the_wording_can_be_changed_with_a_filter(): void {
		add_filter(
			'vatright_edd_legal_text',
			static function ( $text, $key ) {
				return 'art196' === $key ? 'Autoliquidation' : $text;
			},
			10,
			2
		);
		$html = $this->receipt( $this->reverse_charged() );

		$this->assertStringContainsString( 'Autoliquidation', $html );
		$this->assertStringNotContainsString( self::WORDING, $html );
	}
}
