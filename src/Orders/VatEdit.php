<?php

namespace VatrightEdd\Orders;

use VatrightEdd\Domain\Correction;
use VatrightEdd\Domain\Decision;
use VatrightEdd\Domain\FormatCheck;
use VatrightEdd\Domain\VatNumber;
use VatrightEdd\Ledger\LedgerWriter;
use WP_Error;

/**
 * An admin's correction of the VAT record on one order. The decision log keeps
 * every earlier decision and gains an entry with the person, the old and new
 * values and the reason. The amounts charged are never touched and nothing is
 * refunded; the ledger rows are written again so the reports follow.
 */
final class VatEdit {

	const REASON_MAX = 500;

	/** @var LedgerWriter */
	private $ledger;

	public function __construct( LedgerWriter $ledger ) {
		$this->ledger = $ledger;
	}

	/**
	 * @return array{decision: Decision, rebooked: bool}|WP_Error
	 */
	public function apply( int $order_id, string $raw_number, string $country, string $treatment, string $reason, int $actor ) {
		$reason = trim( mb_substr( $reason, 0, self::REASON_MAX ) );
		if ( '' === $reason ) {
			return new WP_Error( 'reason_required', __( 'Give a reason for the change.', 'eu-vat-handling-for-edd' ) );
		}
		$order = $order_id > 0 ? edd_get_order( $order_id ) : false;
		if ( ! $order instanceof \EDD\Orders\Order || 'sale' !== $order->type ) {
			return new WP_Error( 'no_order', __( 'This order cannot carry a VAT decision.', 'eu-vat-handling-for-edd' ) );
		}
		$before = DecisionStore::get( $order_id );
		if ( null === $before ) {
			return new WP_Error( 'no_decision', __( 'No VAT decision is recorded for this order.', 'eu-vat-handling-for-edd' ) );
		}

		$country = strtoupper( trim( $country ) );
		if ( ! array_key_exists( $country, edd_get_country_list() ) ) {
			return new WP_Error( 'bad_country', __( 'Choose a country.', 'eu-vat-handling-for-edd' ) );
		}
		if ( ! in_array( $treatment, Correction::TREATMENTS, true ) ) {
			return new WP_Error( 'bad_treatment', __( 'Choose a treatment.', 'eu-vat-handling-for-edd' ) );
		}
		$raw_number = trim( $raw_number );
		$number     = '' === $raw_number ? null : VatNumber::parse( $raw_number, $country );
		if ( '' !== $raw_number && ( null === $number || ! FormatCheck::is_plausible( $number ) ) ) {
			return new WP_Error( 'bad_number', __( 'That does not look like a VAT number.', 'eu-vat-handling-for-edd' ) );
		}
		if ( Correction::REVERSE_CHARGE === $treatment && null === $number ) {
			return new WP_Error( 'number_required', __( 'A reverse charge needs the customer VAT number.', 'eu-vat-handling-for-edd' ) );
		}

		$after = Correction::apply( $before, $treatment, $country, $number, wp_generate_uuid4(), gmdate( 'Y-m-d\TH:i:s\Z' ) );
		$after = $this->tax_flag( $order, $after );

		$changes = self::changes( $before, $after );
		if ( array() === $changes ) {
			return new WP_Error( 'unchanged', __( 'Nothing was changed.', 'eu-vat-handling-for-edd' ) );
		}

		DecisionStore::apply(
			$order_id,
			$after,
			Correction::TRIGGER,
			$actor,
			array(
				'reason'  => $reason,
				'changes' => $changes,
			)
		);
		$rebooked = $this->ledger->rebook( $order );

		$user = get_userdata( $actor );
		edd_insert_payment_note(
			$order_id,
			sprintf(
				/* translators: 1: user name, 2: reason given. */
				__( 'EU VAT record corrected by %1$s. Reason: %2$s', 'eu-vat-handling-for-edd' ),
				$user ? $user->display_name : __( 'an admin', 'eu-vat-handling-for-edd' ),
				$reason
			)
		);

		return array(
			'decision' => $after,
			'rebooked' => $rebooked,
		);
	}

	private function tax_flag( \EDD\Orders\Order $order, Decision $decision ): Decision {
		$record          = $decision->to_array();
		$record['flags'] = array_values( array_diff( $record['flags'], array( OrderFinaliser::TAX_DIFFERS_FLAG ) ) );
		$clean           = Decision::from_array( $record );
		return OrderFinaliser::tax_fits( $order, $clean ) ? $clean : $clean->with_flag( OrderFinaliser::TAX_DIFFERS_FLAG );
	}

	/**
	 * @return array<string,array{0: string|null, 1: string|null}>
	 */
	private static function changes( Decision $before, Decision $after ): array {
		$old = $before->to_array();
		$new = $after->to_array();

		$pairs   = array(
			'vat_number' => array( (string) ( $old['vat_number']['canonical'] ?? '' ), (string) ( $new['vat_number']['canonical'] ?? '' ) ),
			'country'    => array( (string) ( $old['evidence']['resolved'] ?? '' ), (string) ( $new['evidence']['resolved'] ?? '' ) ),
			'treatment'  => array( '' !== Correction::treatment_of( $before->scheme() ) ? Correction::treatment_of( $before->scheme() ) : $before->scheme(), Correction::treatment_of( $after->scheme() ) ),
		);
		$changes = array();
		foreach ( $pairs as $field => $pair ) {
			if ( $pair[0] !== $pair[1] ) {
				$changes[ $field ] = $pair;
			}
		}
		return $changes;
	}
}
