<?php

namespace VatrightEdd\Domain;

final class Territory {

	const EU = array(
		'AT',
		'BE',
		'BG',
		'HR',
		'CY',
		'CZ',
		'DK',
		'EE',
		'FI',
		'FR',
		'DE',
		'GR',
		'HU',
		'IE',
		'IT',
		'LV',
		'LT',
		'LU',
		'MT',
		'NL',
		'PL',
		'PT',
		'RO',
		'SK',
		'SI',
		'ES',
		'SE',
	);

	/**
	 * Postcode prefixes of places inside an EU country that sit outside the EU VAT
	 * area. Only complete five-character postcodes are matched, so a partial
	 * postcode is charged VAT rather than treated as outside the area.
	 */
	const EXCLUDED_POSTCODES = array(
		'ES' => array( '35', '38', '51', '52' ),
		'FI' => array( '22' ),
		'DE' => array( '27498', '78266' ),
		'IT' => array( '23030', '22061' ),
		'GR' => array( '63086' ),
		'FR' => array( '97' ),
	);

	public static function is_eu_member( string $iso ): bool {
		return in_array( strtoupper( $iso ), self::EU, true );
	}

	public static function in_vat_area( string $iso, string $postcode ): bool {
		$country = self::vat_country( $iso );
		if ( ! self::is_eu_member( $country ) ) {
			return false;
		}
		$postcode = strtoupper( (string) preg_replace( '/[\s-]+/', '', $postcode ) );
		if ( 5 !== strlen( $postcode ) ) {
			return true;
		}
		foreach ( self::EXCLUDED_POSTCODES[ $country ] ?? array() as $prefix ) {
			if ( 0 === strpos( $postcode, $prefix ) ) {
				return false;
			}
		}
		return true;
	}

	public static function vat_country( string $iso ): string {
		$iso = strtoupper( $iso );
		if ( 'MC' === $iso ) {
			return 'FR';
		}
		if ( 'IM' === $iso ) {
			return 'GB';
		}
		return $iso;
	}

	public static function vat_prefix( string $iso ): string {
		$iso = strtoupper( $iso );
		return 'GR' === $iso ? 'EL' : $iso;
	}
}
