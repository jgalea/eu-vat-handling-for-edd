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
	 * @return array{number: string, wording: string[], consultation: string, company: string, registered: string[]}|null Null when nothing is to be printed.
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
		$valid      = ValidationResult::VALID === ( $record['validation']['status'] ?? '' );
		$registered = array();
		if ( $valid ) {
			foreach ( array( (string) ( $record['validation']['name'] ?? '' ), (string) ( $record['validation']['address'] ?? '' ) ) as $part ) {
				foreach ( explode( "\n", str_replace( "\r", '', $part ) ) as $line ) {
					$line = trim( wp_strip_all_tags( $line ) );
					if ( '' !== $line && '---' !== $line ) {
						$registered[] = $line;
					}
				}
			}
		}
		return array(
			'registered'   => $registered,
			'number'       => (string) ( $record['vat_number']['canonical'] ?? '' ),
			'wording'      => $wording,
			'consultation' => $valid ? (string) ( $record['validation']['consultation'] ?? '' ) : '',
			'company'      => $valid ? (string) ( $record['validation']['name'] ?? '' ) : '',
		);
	}

	public static function legal_text( string $key, int $order_id = 0 ): string {
		switch ( $key ) {
			case 'art196':
				$text = __( 'Reverse charge: VAT to be accounted for by the recipient, Article 196 Council Directive 2006/112/EC', 'vat-right-for-edd' );
				break;
			case 'art138':
				$text = __( 'Exempt intra-Community supply of goods, Article 138 Council Directive 2006/112/EC', 'vat-right-for-edd' );
				break;
			default:
				$text = '';
		}
		return (string) apply_filters( 'vatright_edd_legal_text', $text, $key, $order_id );
	}

	/**
	 * The store's own VAT number for this order, when the receipt settings
	 * ask for it: the UK number for a UK customer, the EU one otherwise.
	 */
	public static function store_vat_number( int $order_id = 0 ): string {
		$settings = Settings::get();
		return $settings->receipt_vat_details() ? $settings->store_vat_number_for( self::customer_country( $order_id ) ) : '';
	}

	/**
	 * The country the sale was decided for, as the ledger records it, or the
	 * order's address when no decision was kept.
	 */
	public static function customer_country( int $order_id ): string {
		if ( $order_id < 1 ) {
			return '';
		}
		$decision = DecisionStore::get( $order_id );
		if ( null !== $decision ) {
			$record   = $decision->to_array();
			$resolved = $record['evidence']['resolved'] ?? null;
			$billing  = (string) ( $record['customer']['billing']['country'] ?? '' );
			return is_string( $resolved ) && '' !== $resolved ? $resolved : $billing;
		}
		$order   = edd_get_order( $order_id );
		$address = $order ? $order->get_address() : null;
		return $address ? (string) $address->country : '';
	}

	/**
	 * @param mixed $order
	 */
	public function render_receipt( $order ): void {
		$id     = is_object( $order ) && ! empty( $order->id ) ? (int) $order->id : 0;
		$facts  = 0 < $id ? self::facts( $id ) : null;
		$seller = Settings::get()->receipt_vat_details() ? Settings::get()->seller_details( self::customer_country( $id ) ) : array();
		if ( null === $facts && array() === $seller ) {
			return;
		}

		echo '<div class="vatright-edd-receipt">';
		if ( null !== $facts ) {
			if ( '' !== $facts['number'] ) {
				echo '<p class="vatright-edd-receipt__number"><strong>' . esc_html__( 'Customer VAT number', 'vat-right-for-edd' ) . ':</strong> ' . esc_html( $facts['number'] ) . '</p>';
			}
			if ( array() !== $facts['registered'] ) {
				echo '<p class="vatright-edd-receipt__registered"><strong>' . esc_html__( 'Customer', 'vat-right-for-edd' ) . ':</strong> ' . implode( '<br />', array_map( 'esc_html', $facts['registered'] ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each line is escaped.
			}
			foreach ( $facts['wording'] as $text ) {
				echo '<p class="vatright-edd-receipt__notice">' . esc_html( $text ) . '</p>';
			}
			if ( '' !== $facts['consultation'] ) {
				echo '<p class="vatright-edd-receipt__consultation"><strong>' . esc_html__( 'VAT number consultation reference', 'vat-right-for-edd' ) . ':</strong> ' . esc_html( $facts['consultation'] ) . '</p>';
			}
		}
		if ( array() !== $seller ) {
			echo '<p class="vatright-edd-receipt__seller">' . esc_html( implode( ', ', array_filter( array( $seller['company'] ?? '', $seller['address'] ?? '', $seller['email'] ?? '' ) ) ) );
			if ( ! empty( $seller['vat'] ) ) {
				echo '<br /><strong>' . esc_html__( 'VAT number', 'vat-right-for-edd' ) . ':</strong> ' . esc_html( $seller['vat'] );
			}
			echo '</p>';
		}
		echo '</div>';
	}

	public function add_email_tags(): void {
		// @phpstan-ignore argument.type (The stub types the callback as a string; EDD accepts any callable.)
		edd_add_email_tag( 'vat_number', __( 'The customer\'s VAT number, when the order was reverse charged', 'vat-right-for-edd' ), array( $this, 'tag_number' ) );
		// @phpstan-ignore argument.type (The stub types the callback as a string; EDD accepts any callable.)
		edd_add_email_tag( 'vat_notice', __( 'The reverse-charge wording, when the order was reverse charged', 'vat-right-for-edd' ), array( $this, 'tag_notice' ) );
		// @phpstan-ignore argument.type (The stub types the callback as a string; EDD accepts any callable.)
		edd_add_email_tag( 'vat_company', __( 'The company name the tax registry has for the customer\'s VAT number', 'vat-right-for-edd' ), array( $this, 'tag_company' ) );
	}

	/**
	 * @param mixed $order_id
	 */
	public function tag_number( $order_id ): string {
		$facts = self::facts( (int) $order_id );
		return null === $facts ? '' : self::tag_text( $facts['number'] );
	}

	/**
	 * @param mixed $order_id
	 */
	public function tag_notice( $order_id ): string {
		$facts = self::facts( (int) $order_id );
		return null === $facts ? '' : self::tag_text( implode( ' ', $facts['wording'] ) );
	}

	/**
	 * @param mixed $order_id
	 */
	public function tag_company( $order_id ): string {
		$facts = self::facts( (int) $order_id );
		return null === $facts ? '' : self::tag_text( $facts['company'] );
	}

	/**
	 * EDD puts a tag's output into the email as it is, and strips tags itself for plain-text mails, so HTML escaping would show as "&amp;" there. Markup characters are removed instead.
	 */
	private static function tag_text( string $text ): string {
		return trim( str_replace( array( '<', '>' ), '', wp_strip_all_tags( $text ) ) );
	}
}
