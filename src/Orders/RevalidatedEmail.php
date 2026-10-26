<?php

namespace VatrightEdd\Orders;

use VatrightEdd\Reports\ReportsScreen;

class RevalidatedEmail {

	const REFUND  = 'refund';
	const INVALID = 'invalid';

	const REFUNDED = 'refunded';

	public function send( int $order_id, string $kind ): bool {
		$to = apply_filters( 'vatright_edd_revalidated_email_recipient', get_bloginfo( 'admin_email' ), $order_id, $kind );
		if ( ! is_string( $to ) || '' === $to ) {
			return false;
		}
		return wp_mail( $to, $this->subject( $order_id, $kind ), $this->body( $order_id, $kind ) );
	}

	public function subject( int $order_id, string $kind ): string {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		if ( self::INVALID === $kind ) {
			/* translators: 1: site name, 2: order number. */
			return sprintf( __( '[%1$s]: VAT not charged on order %2$s, number invalid', 'eu-vat-handling-for-edd' ), $site, $order_id );
		}
		if ( self::REFUNDED === $kind ) {
			/* translators: 1: site name, 2: order number. */
			return sprintf( __( '[%1$s]: VAT refunded on order %2$s', 'eu-vat-handling-for-edd' ), $site, $order_id );
		}
		/* translators: 1: site name, 2: order number. */
		return sprintf( __( '[%1$s]: VAT refund may be due on order %2$s', 'eu-vat-handling-for-edd' ), $site, $order_id );
	}

	public function body( int $order_id, string $kind ): string {
		if ( self::INVALID === $kind ) {
			$lead = __( 'The registry now reports the VAT number on this order as invalid, and no VAT was charged on it. VAT may be due.', 'eu-vat-handling-for-edd' );
		} elseif ( self::REFUNDED === $kind ) {
			$lead = __( 'The VAT number on this order could not be checked at checkout, so VAT was charged. The registry now confirms the number is valid, and the VAT was refunded automatically through EDD.', 'eu-vat-handling-for-edd' );
		} else {
			$lead = __( 'The VAT number on this order could not be checked at checkout, so VAT was charged. The registry now confirms the number is valid, so the customer may be due a refund of the VAT.', 'eu-vat-handling-for-edd' );
		}
		$note = self::REFUNDED === $kind
			? __( 'Check that the money reached the customer, and issue a corrected invoice if you send one.', 'eu-vat-handling-for-edd' )
			: __( 'The order totals have not been changed. Decide whether to refund or issue a corrected invoice.', 'eu-vat-handling-for-edd' );

		/* translators: %d: order number. */
		$label = sprintf( __( 'Order #%d', 'eu-vat-handling-for-edd' ), $order_id );
		return $lead . "\n\n" . $note . "\n\n" . $label . ': ' . ReportsScreen::order_url( $order_id ) . "\n";
	}
}
