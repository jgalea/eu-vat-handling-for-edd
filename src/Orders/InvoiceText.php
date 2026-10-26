<?php

namespace VatrightEdd\Orders;

use VatrightEdd\Domain\ValidationResult;
use VatrightEdd\Settings;

/**
 * What a reverse-charged order has to say to the customer: their VAT number
 * and the legal wording. Printed under the receipt table and offered as
 * email tags; the invoice add-ons print the same facts through EddInvoices
 * and EddPdfInvoices. Orders that were charged VAT carry neither.
 */
class InvoiceText {

	public function register(): void {
		add_action( 'edd_order_receipt_after_table', array( $this, 'render_receipt' ), 10, 1 );
		add_action( 'edd_add_email_tags', array( $this, 'add_email_tags' ) );
	}

	/**
	 * @return array{number: string, wording: string[], consultation: string, company: string}|null Null when nothing is to be printed.
	 */
	public static function facts( int $order_id ): ?array {
		if ( ! Settings::get()->receipt_vat_details() ) {
			return null;
		}
		$decision = DecisionStore::get( $order_id );
		if ( null === $decision || ( ! $decision->is_vat_exempt() && array() === $decision->legal_text() ) ) {
			return null;
		}
		$record = $decision->to_array();

		$wording = array();
		foreach ( $decision->legal_text() as $key ) {
			$text = self::legal_text( $key, $order_id );
			if ( '' !== $text ) {
				$wording[] = $text;
			}
		}
		$valid = ValidationResult::VALID === ( $record['validation']['status'] ?? '' );
		return array(
			'number'       => (string) ( $record['vat_number']['canonical'] ?? '' ),
			'wording'      => $wording,
			'consultation' => $valid ? (string) ( $record['validation']['consultation'] ?? '' ) : '',
			'company'      => $valid ? (string) ( $record['validation']['name'] ?? '' ) : '',
		);
	}

	public static function legal_text( string $key, int $order_id = 0 ): string {
		switch ( $key ) {
			case 'art196':
				$text = __( 'Reverse charge: VAT to be accounted for by the recipient, Article 196 Council Directive 2006/112/EC', 'eu-vat-handling-for-edd' );
				break;
			case 'art138':
				$text = __( 'Exempt intra-Community supply of goods, Article 138 Council Directive 2006/112/EC', 'eu-vat-handling-for-edd' );
				break;
			default:
				$text = '';
		}
		return (string) apply_filters( 'vatright_edd_legal_text', $text, $key, $order_id );
	}

	/**
	 * The store's own VAT number, when the receipt settings ask for it.
	 */
	public static function store_vat_number(): string {
		$settings = Settings::get();
		return $settings->receipt_vat_details() ? $settings->store_vat_number() : '';
	}

	/**
	 * @param mixed $order
	 */
	public function render_receipt( $order ): void {
		$id     = is_object( $order ) && ! empty( $order->id ) ? (int) $order->id : 0;
		$facts  = 0 < $id ? self::facts( $id ) : null;
		$seller = Settings::get()->receipt_vat_details() ? Settings::get()->seller_details() : array();
		if ( null === $facts && array() === $seller ) {
			return;
		}

		echo '<div class="vatright-edd-receipt">';
		if ( null !== $facts ) {
			if ( '' !== $facts['number'] ) {
				echo '<p class="vatright-edd-receipt__number"><strong>' . esc_html__( 'Customer VAT number', 'eu-vat-handling-for-edd' ) . ':</strong> ' . esc_html( $facts['number'] ) . '</p>';
			}
			foreach ( $facts['wording'] as $text ) {
				echo '<p class="vatright-edd-receipt__notice">' . esc_html( $text ) . '</p>';
			}
			if ( '' !== $facts['consultation'] ) {
				echo '<p class="vatright-edd-receipt__consultation"><strong>' . esc_html__( 'VAT number consultation reference', 'eu-vat-handling-for-edd' ) . ':</strong> ' . esc_html( $facts['consultation'] ) . '</p>';
			}
		}
		if ( array() !== $seller ) {
			echo '<p class="vatright-edd-receipt__seller">' . esc_html( implode( ', ', array_filter( array( $seller['company'] ?? '', $seller['address'] ?? '', $seller['email'] ?? '' ) ) ) );
			if ( ! empty( $seller['vat'] ) ) {
				echo '<br /><strong>' . esc_html__( 'VAT number', 'eu-vat-handling-for-edd' ) . ':</strong> ' . esc_html( $seller['vat'] );
			}
			echo '</p>';
		}
		echo '</div>';
	}

	public function add_email_tags(): void {
		// @phpstan-ignore argument.type (The stub types the callback as a string; EDD accepts any callable.)
		edd_add_email_tag( 'vat_number', __( 'The customer\'s VAT number, when the order was reverse charged', 'eu-vat-handling-for-edd' ), array( $this, 'tag_number' ) );
		// @phpstan-ignore argument.type (The stub types the callback as a string; EDD accepts any callable.)
		edd_add_email_tag( 'vat_notice', __( 'The reverse-charge wording, when the order was reverse charged', 'eu-vat-handling-for-edd' ), array( $this, 'tag_notice' ) );
		// @phpstan-ignore argument.type (The stub types the callback as a string; EDD accepts any callable.)
		edd_add_email_tag( 'vat_company', __( 'The company name the tax registry has for the customer\'s VAT number', 'eu-vat-handling-for-edd' ), array( $this, 'tag_company' ) );
	}

	/**
	 * @param mixed $order_id
	 */
	public function tag_number( $order_id ): string {
		$facts = self::facts( (int) $order_id );
		return null === $facts ? '' : esc_html( $facts['number'] );
	}

	/**
	 * @param mixed $order_id
	 */
	public function tag_notice( $order_id ): string {
		$facts = self::facts( (int) $order_id );
		return null === $facts ? '' : esc_html( implode( ' ', $facts['wording'] ) );
	}

	/**
	 * @param mixed $order_id
	 */
	public function tag_company( $order_id ): string {
		$facts = self::facts( (int) $order_id );
		return null === $facts ? '' : esc_html( $facts['company'] );
	}
}
