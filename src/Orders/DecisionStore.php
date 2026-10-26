<?php

namespace VatrightEdd\Orders;

use VatrightEdd\Domain\Decision;
use VatrightEdd\Domain\Scheme;

/**
 * The per-order record in edd_ordermeta: the latest decision as JSON and an
 * append-only log of the events that changed it.
 */
final class DecisionStore {

	const META = '_vatright_edd_decision';

	const LOG_META = '_vatright_edd_log';

	const NUMBER_META = '_vatright_edd_number';

	/**
	 * The key EDD Recurring reads to renew a reverse-charged order without tax.
	 */
	const REVERSE_CHARGED_META = '_edd_payment_vat_reverse_charged';

	public static function get( int $order_id ): ?Decision {
		$data = json_decode( (string) edd_get_order_meta( $order_id, self::META, true ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		try {
			return Decision::from_array( $data );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Stores the decision and keeps the number and Recurring's flag in step
	 * with it. A log entry is only added when the outcome differs from the one
	 * already stored, or when the caller passes detail to record (a reason, a
	 * refund) that must be logged either way.
	 *
	 * @param array<string,mixed> $detail
	 */
	public static function apply( int $order_id, Decision $decision, string $trigger, int $actor = 0, array $detail = array() ): void {
		$previous = self::get( $order_id );
		$record   = $decision->to_array();

		// Meta values are unslashed on the way in; slashing keeps the JSON's own backslashes.
		edd_update_order_meta( $order_id, self::META, wp_slash( (string) wp_json_encode( $record ) ) );

		$number = (string) ( $record['vat_number']['canonical'] ?? '' );
		if ( '' !== $number ) {
			edd_update_order_meta( $order_id, self::NUMBER_META, $number );
		} else {
			edd_delete_order_meta( $order_id, self::NUMBER_META );
		}

		if ( self::is_reverse_charged( $decision ) ) {
			edd_update_order_meta( $order_id, self::REVERSE_CHARGED_META, 1 );
		} else {
			edd_delete_order_meta( $order_id, self::REVERSE_CHARGED_META );
		}

		if ( array() === $detail && null !== $previous && self::signature( $previous ) === self::signature( $decision ) ) {
			return;
		}
		$entry = array(
			'at'            => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'trigger'       => $trigger,
			'decision_id'   => $decision->id(),
			'previous_id'   => null === $previous ? null : $previous->id(),
			'scheme'        => $decision->scheme(),
			'rule'          => $decision->rule(),
			'flags'         => $decision->flags(),
			'actor_user_id' => $actor,
		);
		if ( array() !== $detail ) {
			$entry['detail'] = $detail;
		}
		edd_add_order_meta( $order_id, self::LOG_META, wp_slash( (string) wp_json_encode( $entry ) ) );
	}

	/**
	 * Whether a log entry still holds a VAT number an admin typed or replaced.
	 */
	public static function log_holds_numbers( int $order_id ): bool {
		foreach ( self::log( $order_id ) as $entry ) {
			if ( array() !== array_filter( (array) ( $entry['detail']['changes']['vat_number'] ?? array() ), 'is_string' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Blanks the VAT numbers in the log's edit entries. The entries, their
	 * reasons and their order stay.
	 */
	public static function redact_log_numbers( int $order_id ): void {
		if ( ! self::log_holds_numbers( $order_id ) ) {
			return;
		}
		$entries = self::log( $order_id );
		edd_delete_order_meta( $order_id, self::LOG_META );
		foreach ( $entries as $entry ) {
			if ( isset( $entry['detail']['changes']['vat_number'] ) ) {
				$entry['detail']['changes']['vat_number'] = array( null, null );
			}
			edd_add_order_meta( $order_id, self::LOG_META, wp_slash( (string) wp_json_encode( $entry ) ) );
		}
	}

	/**
	 * @return array<int,array<string,mixed>> Oldest first.
	 */
	public static function log( int $order_id ): array {
		$entries = array();
		foreach ( (array) edd_get_order_meta( $order_id, self::LOG_META, false ) as $raw ) {
			$entry = json_decode( (string) $raw, true );
			if ( is_array( $entry ) ) {
				$entries[] = $entry;
			}
		}
		return $entries;
	}

	public static function is_reverse_charged( Decision $decision ): bool {
		if ( ! $decision->is_vat_exempt() ) {
			return false;
		}
		foreach ( $decision->lines() as $line ) {
			if ( in_array( $line->scheme(), array( Scheme::REVERSE_CHARGE_SERVICES, Scheme::INTRA_EU_GOODS ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What makes two decisions the same for the log: the treatment, the
	 * country VAT is due in, the flags and the number with its verdict.
	 */
	private static function signature( Decision $decision ): string {
		$record = $decision->to_array();
		return (string) wp_json_encode(
			array(
				$decision->scheme(),
				$decision->rule(),
				$decision->is_vat_exempt(),
				$decision->tax_country(),
				$decision->flags(),
				$record['vat_number']['canonical'] ?? '',
				$record['validation']['status'] ?? '',
			)
		);
	}
}
