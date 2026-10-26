<?php

namespace VatrightEdd\Import;

use EDD\Orders\Order;
use VatrightEdd\Domain\Decision;
use VatrightEdd\Domain\Rule;
use VatrightEdd\Domain\Scheme;
use VatrightEdd\Domain\Territory;
use VatrightEdd\Domain\ValidationResult;
use VatrightEdd\Domain\VatNumber;
use VatrightEdd\Ledger\Backfill;

/**
 * Reads the per-order meta Barn2 EDD EU VAT left and turns it into the
 * decision the order would have carried. Barn2 kept no country evidence and
 * no rates, so the country comes from the order's address and the treatment
 * from what was charged: Barn2 always charged the destination rate, and
 * never applied the store's rate below the threshold.
 */
class Barn2History {

	const TRIGGER = 'import_barn2';

	const SOURCE = 'barn2';

	const IMPORTED_META = '_vatright_edd_imported';

	const NUMBER_META = '_edd_payment_vat_number';

	const VALID_META = '_edd_payment_vat_number_valid';

	const NAME_META = '_edd_payment_vat_company_name';

	const ADDRESS_META = '_edd_payment_vat_company_address';

	const CONSULTATION_META = '_edd_payment_vat_consultation_number';

	/**
	 * The key the original EDD VAT plugin used. Barn2 reads it first.
	 */
	const LEGACY_REVERSE_META = '_edd_payment_vat_reversed_charged';

	const REVERSE_META = '_edd_payment_vat_reverse_charged';

	/** @var Backfill */
	private $backfill;

	public function __construct( Backfill $backfill ) {
		$this->backfill = $backfill;
	}

	public static function has_barn2_meta( int $order_id ): bool {
		return Backfill::has_foreign_meta( $order_id );
	}

	/**
	 * @return Decision|null Null when the order carries no Barn2 meta.
	 */
	public function decision( Order $order ): ?Decision {
		$order_id = (int) $order->id;
		if ( ! self::has_barn2_meta( $order_id ) ) {
			return null;
		}

		$data    = $this->backfill->infer( $order )->to_array();
		$billing = (string) $data['customer']['billing']['country'];
		$store   = (string) $data['store']['country'];
		$taxed   = abs( (float) $order->tax ) > 0.0;
		$raw     = trim( (string) edd_get_order_meta( $order_id, self::NUMBER_META, true ) );
		$valid   = self::flag( edd_get_order_meta( $order_id, self::VALID_META, true ) );
		$reverse = self::flag( edd_get_order_meta( $order_id, self::LEGACY_REVERSE_META, true ) ) || self::flag( edd_get_order_meta( $order_id, self::REVERSE_META, true ) );
		$number  = '' === $raw ? null : VatNumber::parse( $raw, $billing );
		$flags   = array_values( array_diff( $data['flags'], array( 'backfilled', Backfill::UNKNOWN_NUMBER_FLAG ) ) );

		$scheme     = (string) $data['scheme'];
		$rule       = (string) $data['rule'];
		$exempt     = (bool) $data['vat_exempt'];
		$legal_text = array();
		$place      = null;
		$customer   = Territory::vat_country( '' === $billing ? $store : $billing );

		if ( Scheme::ORIGIN === $scheme ) {
			$scheme = Scheme::OSS_DESTINATION;
			$rule   = Rule::B2C_DESTINATION;
			$place  = $customer;
		}

		$in_area = ! in_array( $scheme, array( Scheme::EXPORT, Scheme::OUTSIDE_TERRITORY ), true );
		if ( $in_area && null !== $number && $reverse && ! $taxed && $valid ) {
			if ( Scheme::DOMESTIC === $scheme ) {
				$rule   = Rule::DOMESTIC_B2B_ALLOWED;
				$exempt = true;
			} else {
				$scheme     = Scheme::REVERSE_CHARGE_SERVICES;
				$rule       = Rule::RC_SERVICES_ART196;
				$exempt     = true;
				$place      = Territory::vat_country( $number->country() );
				$legal_text = array( 'art196' );
			}
		} elseif ( $in_area && null !== $number && $taxed && ! $reverse ) {
			if ( Scheme::DOMESTIC === $scheme ) {
				$rule = Rule::DOMESTIC_B2B_GUARD;
			} elseif ( ! $valid ) {
				$rule = Rule::INVALID_NUMBER;
			}
		}

		if ( Scheme::REVERSE_CHARGE_SERVICES === $scheme && null === $number ) {
			$flags[] = Backfill::UNKNOWN_NUMBER_FLAG;
		}

		$data['scheme']     = $scheme;
		$data['rule']       = $rule;
		$data['vat_exempt'] = $exempt;
		$data['legal_text'] = $legal_text;
		if ( null !== $place ) {
			$data['lines'] = array_map(
				static function ( array $line ) use ( $scheme, $place ): array {
					$line['scheme']          = $scheme;
					$line['place_of_supply'] = $place;
					$line['tax_country']     = $place;
					return $line;
				},
				$data['lines']
			);
		}

		if ( '' !== $raw ) {
			$data['vat_number'] = array(
				'raw'       => $raw,
				'canonical' => null === $number ? '' : $number->canonical(),
				'prefix'    => null === $number ? '' : $number->prefix(),
				'country'   => null === $number ? '' : $number->country(),
			);
			$data['validation'] = ( new ValidationResult(
				$valid ? ValidationResult::VALID : ValidationResult::INVALID,
				self::SOURCE,
				self::iso( (string) $order->date_created ),
				self::text( $order_id, self::NAME_META ),
				self::text( $order_id, self::ADDRESS_META ),
				self::text( $order_id, self::CONSULTATION_META ),
				false,
				'as_recorded'
			) )->to_array();
			$flags[]            = 'registry_as_recorded';
		}

		$flags[]         = 'imported';
		$flags[]         = 'evidence_billing_only';
		$data['flags']   = array_values( array_unique( $flags ) );
		$data['trigger'] = self::TRIGGER;

		return Decision::from_array( $data );
	}

	/**
	 * Marks the order as imported, with the plugin and version it came from.
	 */
	public static function mark_imported( int $order_id, string $version ): void {
		edd_update_order_meta(
			$order_id,
			self::IMPORTED_META,
			array(
				'source'  => self::SOURCE,
				'version' => $version,
				'at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
			)
		);
	}

	/**
	 * @param mixed $value
	 */
	private static function flag( $value ): bool {
		return is_scalar( $value ) && filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	private static function text( int $order_id, string $key ): ?string {
		$value = edd_get_order_meta( $order_id, $key, true );
		return is_scalar( $value ) && '' !== trim( (string) $value ) ? trim( (string) $value ) : null;
	}

	private static function iso( string $mysql_utc ): string {
		$time = strtotime( $mysql_utc . ' UTC' );
		return gmdate( 'Y-m-d\TH:i:s\Z', false === $time ? 0 : $time );
	}
}
