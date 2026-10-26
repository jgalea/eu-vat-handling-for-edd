<?php

namespace VatrightEdd\Orders;

use EDD\Orders\Order;
use Throwable;
use VatrightEdd\Settings;

/**
 * Refunds the VAT of an order whose number validated after the order was
 * placed, when the merchant has switched that on. The refund is EDD's own
 * partial refund of the tax only, so EDD's totals, the ledger and the other
 * extensions see an ordinary refund.
 *
 * Money moves once. A per-order lock stops two runs overlapping, and a marker
 * written before the refund is issued stops a later run repeating it. Anything
 * unusual (an earlier refund, tax that does not match the decision, a gateway
 * EDD cannot refund through, an order EDD will not refund) is skipped and
 * flagged for the merchant instead.
 */
final class VatRefund {

	const MARKER_META = '_vatright_edd_vat_refund';

	const TRIGGER = 'auto_vat_refund';

	const LOCK_PREFIX = 'vatright_edd_refund_lock_';

	const LOCK_TTL = 300;

	const REFUNDED = 'refunded';
	const SKIPPED  = 'skipped';
	const FAILED   = 'failed';
	const BUSY     = 'busy';
	const ALREADY  = 'already';
	const OFF      = 'off';

	const FLAG_REFUNDED = 'vat_auto_refunded';
	const FLAG_SKIPPED  = 'auto_refund_skipped';
	const FLAG_FAILED   = 'auto_refund_failed';

	/** @var OrderFinaliser */
	private $finaliser;

	public function __construct( OrderFinaliser $finaliser ) {
		$this->finaliser = $finaliser;
	}

	/**
	 * Gateways whose refunds EDD records without anything left to do at a
	 * payment provider. EDD's own refund hooks only reach Stripe or PayPal
	 * from the refund form, so an automatic refund there would record money as
	 * returned that never left the provider.
	 *
	 * @return string[]
	 */
	public static function gateways(): array {
		$gateways = apply_filters( 'vatright_edd_auto_refund_gateways', array( 'manual' ) );
		return is_array( $gateways ) ? array_values( array_filter( array_map( 'strval', $gateways ) ) ) : array();
	}

	/**
	 * @return array<string,mixed>|null The marker of an order that was handled.
	 */
	public static function marker( int $order_id ): ?array {
		$marker = json_decode( (string) edd_get_order_meta( $order_id, self::MARKER_META, true ), true );
		return is_array( $marker ) ? $marker : null;
	}

	/**
	 * @return string One of the outcome constants.
	 */
	public function refund_if_enabled( int $order_id ): string {
		if ( ! Settings::get()->auto_refund_on_validation() ) {
			return self::OFF;
		}
		if ( ! self::lock( $order_id ) ) {
			return self::BUSY;
		}
		try {
			return $this->run( $order_id );
		} finally {
			self::unlock( $order_id );
		}
	}

	private function run( int $order_id ): string {
		// A run that finished a moment ago on another request wrote its marker after this request cached the meta.
		wp_cache_delete( $order_id, 'edd_order_meta' );
		if ( null !== self::marker( $order_id ) ) {
			return self::ALREADY;
		}

		$order = edd_get_order( $order_id );
		if ( ! $order instanceof Order || 'sale' !== $order->type ) {
			return self::ALREADY;
		}

		$plan = $this->plan( $order );
		if ( is_string( $plan ) ) {
			return $this->skip( $order, $plan );
		}

		self::write_marker(
			$order_id,
			array(
				'state' => 'started',
				'at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			)
		);

		try {
			$refund_id = edd_refund_order( $order_id, $plan['items'], $plan['adjustments'] );
		} catch ( Throwable $e ) {
			$refund_id = new \WP_Error( 'exception', $e->getMessage() );
		}
		if ( is_wp_error( $refund_id ) || ! $refund_id ) {
			return $this->fail( $order, is_wp_error( $refund_id ) ? $refund_id->get_error_message() : 'EDD returned no refund.' );
		}

		return $this->done( $order, (int) $refund_id, $plan['tax'] );
	}

	/**
	 * The tax-only refund lines, or the reason there is nothing safe to
	 * refund.
	 *
	 * @return array{items: array<int,array<string,mixed>>, adjustments: array<int,array<string,mixed>>, tax: float}|string
	 */
	private function plan( Order $order ) {
		$order_id = (int) $order->id;

		if ( ! in_array( (string) $order->gateway, self::gateways(), true ) ) {
			return 'gateway_unsupported';
		}
		$decision = DecisionStore::get( $order_id );
		if ( null === $decision ) {
			return 'no_decision';
		}
		$flags = $decision->flags();
		if ( in_array( OrderFinaliser::TAX_DIFFERS_FLAG, $flags, true ) || in_array( OrderFinaliser::ADMIN_CORRECTED_FLAG, $flags, true ) ) {
			return 'tax_differs_from_decision';
		}
		if ( array() !== edd_get_order_refunds( $order_id ) || in_array( $order->status, array( 'partially_refunded', 'refunded' ), true ) ) {
			return 'already_refunded';
		}
		if ( ! edd_is_order_refundable( $order_id ) ) {
			return 'not_refundable';
		}
		$charged = (float) $order->tax;
		if ( $charged <= 0.0 ) {
			return 'no_tax_charged';
		}

		$now = $this->finaliser->preview( $order_id );
		if ( null === $now || ! $now->is_vat_exempt() || ! DecisionStore::is_reverse_charged( $now ) ) {
			return 'not_exempt_now';
		}

		$items = array();
		$sum   = 0.0;
		foreach ( $order->get_items() as $item ) {
			$tax = (float) ( $item->get_refundable_amounts()['tax'] ?? 0 );
			if ( $tax > 0.0 ) {
				$items[] = array(
					'order_item_id' => (int) $item->id,
					'quantity'      => 0,
					'subtotal'      => 0.0,
					'tax'           => $tax,
				);
				$sum    += $tax;
			}
		}
		$adjustments = array();
		foreach ( $order->get_fees() as $fee ) {
			$tax = (float) ( $fee->get_refundable_amounts()['tax'] ?? 0 );
			if ( $tax > 0.0 ) {
				$adjustments[] = array(
					'adjustment_id' => (int) $fee->id,
					'subtotal'      => 0.0,
					'tax'           => $tax,
				);
				$sum          += $tax;
			}
		}
		if ( abs( $sum - $charged ) >= 0.01 ) {
			return 'tax_differs_from_decision';
		}

		return array(
			'items'       => $items,
			'adjustments' => $adjustments,
			'tax'         => round( $sum, 2 ),
		);
	}

	private function skip( Order $order, string $reason ): string {
		$order_id = (int) $order->id;
		self::write_marker(
			$order_id,
			array(
				'state'  => 'skipped',
				'at'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'reason' => $reason,
			)
		);
		$this->record( $order, self::FLAG_SKIPPED, array( 'skipped' => $reason ) );
		edd_insert_payment_note(
			$order_id,
			sprintf(
				/* translators: %s: reason in words. */
				__( 'The VAT was not refunded automatically: %s. Refund it by hand if it is due.', 'eu-vat-handling-for-edd' ),
				self::reason_text( $reason )
			)
		);
		return self::SKIPPED;
	}

	private function fail( Order $order, string $message ): string {
		$order_id = (int) $order->id;
		self::write_marker(
			$order_id,
			array(
				'state'   => 'failed',
				'at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'message' => $message,
			)
		);
		$this->record( $order, self::FLAG_FAILED, array( 'message' => $message ) );
		edd_insert_payment_note(
			$order_id,
			sprintf(
				/* translators: %s: error message from EDD. */
				__( 'The automatic VAT refund failed: %s. Nothing was refunded.', 'eu-vat-handling-for-edd' ),
				$message
			)
		);
		return self::FAILED;
	}

	private function done( Order $order, int $refund_id, float $tax ): string {
		$order_id = (int) $order->id;
		self::write_marker(
			$order_id,
			array(
				'state'     => 'refunded',
				'at'        => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'refund_id' => $refund_id,
				'amount'    => $tax,
				'currency'  => strtoupper( (string) $order->currency ),
			)
		);
		$this->record(
			$order,
			self::FLAG_REFUNDED,
			array(
				'refund_id' => $refund_id,
				'amount'    => $tax,
				'currency'  => strtoupper( (string) $order->currency ),
			)
		);
		edd_insert_payment_note(
			$order_id,
			sprintf(
				/* translators: 1: amount with currency, 2: refund order number. */
				__( 'The VAT number validated after the order, so the VAT (%1$s) was refunded automatically (refund %2$s).', 'eu-vat-handling-for-edd' ),
				edd_currency_filter( edd_format_amount( $tax ), strtoupper( (string) $order->currency ) ),
				$refund_id
			)
		);
		return self::REFUNDED;
	}

	/**
	 * @param array<string,mixed> $detail
	 */
	private function record( Order $order, string $flag, array $detail ): void {
		$decision = DecisionStore::get( (int) $order->id );
		if ( null === $decision ) {
			return;
		}
		DecisionStore::apply( (int) $order->id, $decision->with_flag( $flag ), self::TRIGGER, 0, $detail );
	}

	public static function reason_text( string $reason ): string {
		switch ( $reason ) {
			case 'gateway_unsupported':
				return __( 'its payment gateway is not one the plugin refunds through', 'eu-vat-handling-for-edd' );
			case 'no_decision':
				return __( 'the order has no VAT decision', 'eu-vat-handling-for-edd' );
			case 'tax_differs_from_decision':
				return __( 'the tax on the order does not match the VAT decision', 'eu-vat-handling-for-edd' );
			case 'already_refunded':
				return __( 'the order has already been refunded in part', 'eu-vat-handling-for-edd' );
			case 'not_refundable':
				return __( 'EDD does not allow this order to be refunded', 'eu-vat-handling-for-edd' );
			case 'no_tax_charged':
				return __( 'no VAT was charged on the order', 'eu-vat-handling-for-edd' );
			case 'not_exempt_now':
				return __( 'the order would not be exempt from VAT with this number now', 'eu-vat-handling-for-edd' );
			default:
				return $reason;
		}
	}

	/**
	 * A row in the options table can only be inserted once, which is what
	 * makes it a lock; a lock older than the ttl belongs to a run that died.
	 */
	private static function lock( int $order_id ): bool {
		$key = self::LOCK_PREFIX . $order_id;
		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$held = (int) get_option( $key );
		if ( $held > time() - self::LOCK_TTL ) {
			return false;
		}
		delete_option( $key );
		return (bool) add_option( $key, time(), '', false );
	}

	private static function unlock( int $order_id ): void {
		delete_option( self::LOCK_PREFIX . $order_id );
	}

	/**
	 * @param array<string,mixed> $marker
	 */
	private static function write_marker( int $order_id, array $marker ): void {
		edd_update_order_meta( $order_id, self::MARKER_META, wp_slash( (string) wp_json_encode( $marker ) ) );
	}
}
