<?php

namespace VatrightEdd\Rates;

class RateResolver {

	const STANDARD      = 'standard';
	const REDUCED       = 'reduced';
	const SUPER_REDUCED = 'super_reduced';

	/** @var RateSync */
	private $sync;

	public function __construct( RateSync $sync ) {
		$this->sync = $sync;
	}

	/**
	 * @return array<int,string>
	 */
	public static function categories(): array {
		return array( self::STANDARD, self::REDUCED, self::SUPER_REDUCED );
	}

	public function rate( string $country, string $category ): ?float {
		$rate = $this->reduced_rate( $country, $category );
		if ( null === $rate ) {
			$rate = $this->standard_rate( $country );
		}

		$filtered = apply_filters( 'vatright_edd_resolved_rate', $rate, $country, $category );
		return is_numeric( $filtered ) ? (float) $filtered : null;
	}

	private function standard_rate( string $country ): ?float {
		$row = edd_get_tax_rate_by_location( array( 'country' => $country ) );
		return $row ? (float) $row->amount : null;
	}

	private function reduced_rate( string $country, string $category ): ?float {
		if ( self::SUPER_REDUCED !== $category && self::REDUCED !== $category ) {
			return null;
		}

		$entries = $this->sync->latest()->reduced( $country );
		$wanted  = array( $category );
		if ( self::SUPER_REDUCED === $category ) {
			$wanted[] = self::REDUCED;
		}
		foreach ( $wanted as $type ) {
			$rates = array();
			foreach ( $entries as $entry ) {
				if ( $type === $entry['type'] ) {
					$rates[] = $entry['rate'];
				}
			}
			if ( $rates ) {
				return min( $rates );
			}
		}
		return null;
	}
}
