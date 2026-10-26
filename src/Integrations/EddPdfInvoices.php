<?php

namespace VatrightEdd\Integrations;

use VatrightEdd\Orders\InvoiceText;

/**
 * Prints the customer's VAT number and the legal wording in the totals area of
 * EDD PDF Invoices, which draws with TCPDF. The hook only fires while that
 * add-on is active.
 */
class EddPdfInvoices {

	public function register(): void {
		add_action( 'eddpdfi_additional_fields', array( $this, 'print_facts' ), 10, 2 );
	}

	/**
	 * Every template positions the totals by itself, so each line starts where
	 * the template left the cursor and leaves it there (TCPDF line break mode
	 * 2: below, same x).
	 *
	 * @param mixed $pdf     The TCPDF document.
	 * @param mixed $payment The EDD_Payment being drawn.
	 */
	public function print_facts( $pdf, $payment ): void {
		$id    = EddInvoices::order_id( $payment );
		$facts = 0 < $id ? InvoiceText::facts( $id ) : null;
		if ( null === $facts || ! is_object( $pdf ) || ! method_exists( $pdf, 'MultiCell' ) ) {
			return;
		}

		$lines = array();
		if ( '' !== $facts['number'] ) {
			$lines[] = __( 'Customer VAT number:', 'eu-vat-handling-for-edd' ) . ' ' . $facts['number'];
		}
		foreach ( $facts['wording'] as $text ) {
			$lines[] = $text;
		}
		// @phpstan-ignore method.notFound (TCPDF ships with the add-on and is not available to analyse.)
		$x = $pdf->GetX();
		foreach ( $lines as $line ) {
			$pdf->MultiCell( 0, 6, wp_strip_all_tags( $line ), 0, 'L', false, 2, $x );
		}
	}
}
