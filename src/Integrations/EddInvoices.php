<?php

namespace VatrightEdd\Integrations;

use VatrightEdd\Orders\InvoiceText;
use VatrightEdd\Settings;

/**
 * Prints the customer's VAT number and the legal wording on EDD Invoices, and
 * the seller's details and VAT number when the invoice settings have none.
 * The hooks only ever fire while that add-on is active.
 */
class EddInvoices {

	public function register(): void {
		add_action( 'edd_invoices_after_customer_details', array( $this, 'customer_number' ), 20, 1 );
		add_action( 'edd_invoices_after_company_details', array( $this, 'seller' ), 10, 0 );
		add_action( 'edd_invoices_after_company_details', array( $this, 'store_number' ), 20, 1 );
		add_action( 'edd_invoices_invoice_additional_info', array( $this, 'wording' ), 20, 1 );
	}

	/**
	 * @param mixed $order
	 */
	public function customer_number( $order ): void {
		$id    = self::order_id( $order );
		$facts = 0 < $id ? InvoiceText::facts( $id ) : null;
		if ( null === $facts || '' === $facts['number'] ) {
			return;
		}
		// The add-on prints its own Tax/VAT line when the customer gave one at its invoice form.
		$theirs = (string) edd_get_order_meta( $id, 'invoices_vat', true );
		if ( '' !== $theirs && strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $theirs ) ) === $facts['number'] ) {
			return;
		}
		echo '<div class="customer-vat"><span class="invoice-label">' . esc_html__( 'Customer VAT number:', 'eu-vat-handling-for-edd' ) . '</span> ' . esc_html( $facts['number'] ) . '</div>';
	}

	/**
	 * Barn2 EDD EU VAT printed its own seller details on the invoice, so a
	 * store moving from it can have none in the add-on's settings.
	 */
	public function seller(): void {
		$address = function_exists( 'edd_invoices_get_company_address' ) ? edd_invoices_get_company_address() : edd_get_option( 'edd-invoices-company-address', '' );
		if ( '' !== (string) edd_get_option( 'edd-invoices-company-name', '' ) || '' !== (string) $address ) {
			return;
		}
		$settings = Settings::get();
		$seller   = $settings->receipt_vat_details() ? $settings->seller_details() : array();
		unset( $seller['vat'] );
		if ( ! $seller ) {
			return;
		}
		echo '<div class="address">';
		if ( isset( $seller['company'] ) ) {
			echo '<div class="invoice-label">' . esc_html( $seller['company'] ) . '</div>';
			unset( $seller['company'] );
		}
		echo implode( '<br />', array_map( 'esc_html', $seller ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each part is escaped.
	}

	/**
	 * @param mixed $order
	 */
	public function store_number( $order = null ): void {
		$number = InvoiceText::store_vat_number( self::order_id( $order ) );
		if ( '' === $number || '' !== (string) edd_get_option( 'edd-invoices-tax', '' ) ) {
			return;
		}
		echo '<div class="storefront__vat"><span class="invoice-label">' . esc_html__( 'VAT number:', 'eu-vat-handling-for-edd' ) . '</span> ' . esc_html( $number ) . '</div>';
	}

	/**
	 * @param mixed $order
	 */
	public function wording( $order ): void {
		$id    = self::order_id( $order );
		$facts = 0 < $id ? InvoiceText::facts( $id ) : null;
		if ( null === $facts || array() === $facts['wording'] ) {
			return;
		}
		echo '<div class="information"><header>' . esc_html__( 'VAT:', 'eu-vat-handling-for-edd' ) . '</header><article>';
		foreach ( $facts['wording'] as $text ) {
			echo '<div class="vat-notice">' . esc_html( $text ) . '</div>';
		}
		echo '</article></div>';
	}

	/**
	 * The add-on passes an EDD order, or an EDD_Payment on EDD 2.
	 *
	 * @param mixed $order
	 */
	public static function order_id( $order ): int {
		if ( is_object( $order ) ) {
			if ( ! empty( $order->id ) ) {
				return (int) $order->id;
			}
			if ( ! empty( $order->ID ) ) {
				return (int) $order->ID;
			}
		}
		return 0;
	}
}
