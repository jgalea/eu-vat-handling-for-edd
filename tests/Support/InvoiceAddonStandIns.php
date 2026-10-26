<?php

namespace VatrightEdd\Tests\Support;

require_once __DIR__ . '/RecordingPdf.php';

/**
 * Fires the hooks the two commercial invoice add-ons fire, with the
 * arguments they pass, in the order their templates do. The names and
 * arguments come from reading EDD Invoices 1.4.0 and EDD PDF Invoices 2.2.24;
 * neither add-on's code is part of this repository.
 */
class InvoiceAddonStandIns {

	/**
	 * The HTML invoice: company details, customer details, then the additional
	 * information section.
	 *
	 * @param object $order EDD\Orders\Order.
	 */
	public static function html_invoice( $order ): string {
		ob_start();
		do_action( 'edd_invoices_invoice_contacts', $order );
		do_action( 'edd_invoices_after_company_details', $order );
		do_action( 'edd_invoices_after_customer_details', $order );
		do_action( 'edd_invoices_invoice_additional_info', $order );
		return (string) ob_get_clean();
	}

	/**
	 * The totals area of a PDF invoice, drawn on a recording document.
	 *
	 * @param object $payment EDD_Payment.
	 */
	public static function pdf_invoice( $payment ): RecordingPdf {
		$pdf = new RecordingPdf();
		do_action( 'eddpdfi_additional_fields', $pdf, $payment, array(), array(), 'manual', 'Manual', 0, 'Seller', '1 Jan 2026', 'Complete' );
		return $pdf;
	}
}
