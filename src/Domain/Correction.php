<?php

namespace VatrightEdd\Domain;

/**
 * Re-expresses a stored decision after an admin has corrected the number, the
 * country of evidence or the treatment. Only the record changes: nothing here
 * knows what the order was charged.
 */
final class Correction {

	const REVERSE_CHARGE  = 'reverse_charge';
	const DOMESTIC        = 'domestic';
	const OSS_DESTINATION = 'oss_destination';
	const ORIGIN          = 'origin';
	const EXPORT          = 'export';

	const TREATMENTS = array(
		self::REVERSE_CHARGE,
		self::DOMESTIC,
		self::OSS_DESTINATION,
		self::ORIGIN,
		self::EXPORT,
	);

	const FLAG = 'admin_corrected';

	const TRIGGER = 'admin_vat_edit';

	/**
	 * Flags that describe how the original decision was reached and no longer
	 * hold once a person has decided.
	 */
	const SUPERSEDED_FLAGS = array( 'validation_pending', 'prefix_mismatch', 'evidence_conflict_self_certified', 'single_evidence' );

	/**
	 * The treatment a scheme stands for, or an empty string for the ones an
	 * admin cannot pick (mixed carts, territories outside the VAT area).
	 */
	public static function treatment_of( string $scheme ): string {
		switch ( $scheme ) {
			case Scheme::REVERSE_CHARGE_SERVICES:
			case Scheme::INTRA_EU_GOODS:
				return self::REVERSE_CHARGE;
			case Scheme::DOMESTIC:
				return self::DOMESTIC;
			case Scheme::OSS_DESTINATION:
				return self::OSS_DESTINATION;
			case Scheme::ORIGIN:
				return self::ORIGIN;
			case Scheme::EXPORT:
				return self::EXPORT;
			default:
				return '';
		}
	}

	/**
	 * @param string $country ISO code of the country the evidence points at.
	 */
	public static function apply( Decision $decision, string $treatment, string $country, ?VatNumber $number, string $id, string $at ): Decision {
		$record = $decision->to_array();
		$store  = (string) $record['store']['country'];

		$lines   = array();
		$schemes = array();
		foreach ( $decision->lines() as $line ) {
			list( $scheme, $place, $tax ) = self::placement( $treatment, $line->supply_type(), $country, $store );
			$lines[]                      = new LineDecision( $line->key(), $line->supply_type(), $place, $tax, $scheme );
			$schemes[]                    = $scheme;
		}
		$schemes = array_values( array_unique( $schemes ) );

		$old_canonical = (string) ( $record['vat_number']['canonical'] ?? '' );
		$validation    = $record['validation'];
		if ( null === $number || $number->canonical() !== $old_canonical ) {
			$validation = null;
		}

		$evidence             = $record['evidence'];
		$evidence['resolved'] = $country;
		$evidence['conflict'] = false;

		$flags = array_values( array_diff( $decision->flags(), self::SUPERSEDED_FLAGS ) );
		if ( ! in_array( self::FLAG, $flags, true ) ) {
			$flags[] = self::FLAG;
		}

		$exempt = self::REVERSE_CHARGE === $treatment;
		$first  = $lines[0] ?? null;
		$scheme = Scheme::MIXED;
		if ( 1 === count( $schemes ) ) {
			$scheme = $schemes[0];
		}

		return new Decision(
			$id,
			$at,
			self::TRIGGER,
			$record['store'],
			$record['customer'],
			null === $number ? null : array(
				'raw'       => $number->canonical(),
				'canonical' => $number->canonical(),
				'prefix'    => $number->prefix(),
				'country'   => $number->country(),
			),
			$validation,
			$evidence,
			$record['policy'],
			$lines,
			$scheme,
			self::rule_for( $treatment, null === $first ? Scheme::REVERSE_CHARGE_SERVICES : $first->scheme() ),
			$exempt,
			$flags,
			self::legal_text( $schemes ),
			null
		);
	}

	/**
	 * @return array{0: string, 1: string, 2: string} Scheme, place of supply, country whose VAT applies.
	 */
	private static function placement( string $treatment, string $supply_type, string $country, string $store ): array {
		switch ( $treatment ) {
			case self::REVERSE_CHARGE:
				return array( Line::GOODS === $supply_type ? Scheme::INTRA_EU_GOODS : Scheme::REVERSE_CHARGE_SERVICES, $country, $country );
			case self::OSS_DESTINATION:
				return array( Scheme::OSS_DESTINATION, $country, $country );
			case self::ORIGIN:
				return array( Scheme::ORIGIN, $store, $store );
			case self::EXPORT:
				return array( Scheme::EXPORT, $country, $country );
			default:
				return array( Scheme::DOMESTIC, $store, $store );
		}
	}

	private static function rule_for( string $treatment, string $first_scheme ): string {
		switch ( $treatment ) {
			case self::REVERSE_CHARGE:
				return Scheme::INTRA_EU_GOODS === $first_scheme ? Rule::RC_GOODS_ART138 : Rule::RC_SERVICES_ART196;
			case self::OSS_DESTINATION:
				return Rule::B2C_DESTINATION;
			case self::ORIGIN:
				return Rule::B2C_ORIGIN_BELOW_THRESHOLD;
			case self::EXPORT:
				return Rule::EXPORT_NON_EU;
			default:
				return Rule::DOMESTIC;
		}
	}

	/**
	 * @param string[] $schemes
	 * @return string[]
	 */
	private static function legal_text( array $schemes ): array {
		$text = array();
		if ( in_array( Scheme::REVERSE_CHARGE_SERVICES, $schemes, true ) ) {
			$text[] = 'art196';
		}
		if ( in_array( Scheme::INTRA_EU_GOODS, $schemes, true ) ) {
			$text[] = 'art138';
		}
		return $text;
	}
}
