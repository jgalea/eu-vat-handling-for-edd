<?php

namespace VatrightEdd\Tests\Integration\Integrations;

use VatrightEdd\Integrations\EddInvoices;
use VatrightEdd\Integrations\EddPdfInvoices;
use VatrightEdd\Tests\Support\InvoiceAddonStandIns;
use VatrightEdd\Tests\Support\OrdersTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/OrdersTestCase.php';
require_once dirname( __DIR__, 2 ) . '/Support/InvoiceAddonStandIns.php';

class InvoiceAddonsTest extends OrdersTestCase {

	const WORDING = 'Reverse charge: VAT to be accounted for by the recipient, Article 196 Council Directive 2006/112/EC';

	public function set_up() {
		parent::set_up();
		( new EddInvoices() )->register();
		( new EddPdfInvoices() )->register();
	}

	private function reverse_charged(): \EDD\Orders\Order {
		$this->buy( $this->download(), 'DE' );
		$this->enter_number( self::VALID_DE, 'DE' );
		return $this->place_order( 'DE' );
	}

	private function domestic_charged(): \EDD\Orders\Order {
		$this->buy( $this->download(), 'FR' );
		return $this->place_order( 'FR' );
	}

	public function test_edd_invoices_shows_the_wording_and_the_customers_number_on_a_reverse_charged_order(): void {
		$html = InvoiceAddonStandIns::html_invoice( $this->reverse_charged() );

		$this->assertSame( 1, substr_count( $html, self::WORDING ) );
		$this->assertSame( 1, substr_count( $html, self::VALID_DE ) );
	}

	public function test_edd_invoices_shows_neither_on_a_charged_order(): void {
		$order = $this->domestic_charged();

		$this->assertSame( '', InvoiceAddonStandIns::html_invoice( $order ) );
	}

	public function test_edd_invoices_does_not_repeat_a_number_it_already_prints(): void {
		$order = $this->reverse_charged();
		edd_update_order_meta( $order->id, 'invoices_vat', 'DE 114 103 379' );

		$html = InvoiceAddonStandIns::html_invoice( $order );

		$this->assertStringNotContainsString( 'Customer VAT number', $html );
		$this->assertStringContainsString( self::WORDING, $html );
	}

	public function test_the_store_number_is_added_unless_the_add_on_has_one(): void {
		$this->settings( array( 'store_vat_number' => 'MT20000010' ) );
		$order = $this->domestic_charged();

		$this->assertStringContainsString( 'MT20000010', InvoiceAddonStandIns::html_invoice( $order ) );

		edd_update_option( 'edd-invoices-tax', 'MT99999999' );
		$this->assertStringNotContainsString( 'MT20000010', InvoiceAddonStandIns::html_invoice( $order ) );
		edd_delete_option( 'edd-invoices-tax' );
	}

	public function test_the_invoice_prints_the_store_number_for_the_customers_country(): void {
		$this->settings(
			array(
				'store_vat_number'    => 'MT20000010',
				'store_vat_number_gb' => 'GB333289454',
			)
		);
		$this->buy( $this->download(), 'GB' );
		$uk = InvoiceAddonStandIns::html_invoice( $this->place_order( 'GB' ) );
		$this->assertStringContainsString( 'GB333289454', $uk );
		$this->assertStringNotContainsString( 'MT20000010', $uk );

		edd_empty_cart();
		$fr = InvoiceAddonStandIns::html_invoice( $this->domestic_charged() );
		$this->assertStringContainsString( 'MT20000010', $fr );
		$this->assertStringNotContainsString( 'GB333289454', $fr );
	}

	public function test_the_seller_is_named_when_the_add_on_has_no_company_details(): void {
		$this->settings(
			array(
				'seller_company'   => 'Example Store Ltd',
				'seller_address_1' => '1 Example Street',
				'seller_city'      => 'Valletta',
				'seller_postcode'  => 'VLT 1000',
			)
		);
		$order = $this->domestic_charged();

		$html = InvoiceAddonStandIns::html_invoice( $order );
		$this->assertSame( 1, substr_count( $html, 'Example Store Ltd' ) );
		$this->assertStringContainsString( '1 Example Street', $html );

		edd_update_option( 'edd-invoices-company-name', 'Their Name Ltd' );
		$this->assertStringNotContainsString( 'Example Store Ltd', InvoiceAddonStandIns::html_invoice( $order ), 'The add-on prints its own company details.' );
		edd_delete_option( 'edd-invoices-company-name' );
	}

	public function test_the_receipt_setting_switches_the_invoice_output_off(): void {
		$order = $this->reverse_charged();
		$this->settings( array( 'receipt_vat_details' => false ) );

		$this->assertSame( '', InvoiceAddonStandIns::html_invoice( $order ) );
	}

	public function test_pdf_invoices_draws_the_wording_and_the_number_without_moving_the_cursor(): void {
		$order = $this->reverse_charged();

		$pdf = InvoiceAddonStandIns::pdf_invoice( edd_get_payment( $order->id ) );

		$this->assertSame( array( 'Customer VAT number: ' . self::VALID_DE, self::WORDING ), $pdf->texts() );
		foreach ( $pdf->cells as $cell ) {
			$this->assertSame( 2, $cell['ln'], 'Each line goes below and leaves the cursor where the template put it.' );
			$this->assertSame( 61.0, $cell['x'] );
		}
	}

	public function test_pdf_invoices_draws_nothing_on_a_charged_order(): void {
		$order = $this->domestic_charged();

		$this->assertSame( array(), InvoiceAddonStandIns::pdf_invoice( edd_get_payment( $order->id ) )->cells );
	}

	public function test_a_payment_without_a_decision_is_ignored(): void {
		$this->assertSame( array(), InvoiceAddonStandIns::pdf_invoice( (object) array( 'ID' => 424242 ) )->cells );
		$this->assertSame( '', InvoiceAddonStandIns::html_invoice( (object) array( 'id' => 424242 ) ) );
	}
}
