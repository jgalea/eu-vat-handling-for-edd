<?php

namespace VatrightEdd\Domain;

final class FormatCheck {

	const PATTERNS = array(
		'AT'  => '/^U\d{8}$/',
		'BE'  => '/^[01]\d{9}$/',
		'BG'  => '/^\d{9,10}$/',
		'CY'  => '/^\d{8}[A-Z]$/',
		'CZ'  => '/^\d{8,10}$/',
		'DE'  => '/^\d{9}$/',
		'DK'  => '/^\d{8}$/',
		'EE'  => '/^\d{9}$/',
		'EL'  => '/^\d{9}$/',
		'ES'  => '/^[A-Z0-9]\d{7}[A-Z0-9]$/',
		'FI'  => '/^\d{8}$/',
		'FR'  => '/^[A-HJ-NP-Z0-9]{2}\d{9}$/',
		'HR'  => '/^\d{11}$/',
		'HU'  => '/^\d{8}$/',
		'IE'  => '/^(\d{7}[A-W][A-I]?|\d[A-Z+*]\d{5}[A-W])$/',
		'IT'  => '/^\d{11}$/',
		'LT'  => '/^(\d{9}|\d{12})$/',
		'LU'  => '/^\d{8}$/',
		'LV'  => '/^\d{11}$/',
		'MT'  => '/^\d{8}$/',
		'NL'  => '/^\d{9}B\d{2}$/',
		'PL'  => '/^\d{10}$/',
		'PT'  => '/^\d{9}$/',
		'RO'  => '/^\d{2,10}$/',
		'SE'  => '/^\d{12}$/',
		'SI'  => '/^\d{8}$/',
		'SK'  => '/^\d{10}$/',
		'XI'  => '/^(\d{9}|\d{12}|GD\d{3}|HA\d{3})$/',
		'GB'  => '/^(\d{9}|\d{12}|GD\d{3}|HA\d{3})$/',
		'CHE' => '/^\d{9}$/',
		'NO'  => '/^\d{9}$/',
	);

	/**
	 * An XI number is the trader's GB number with another prefix, so the GB
	 * checksum would apply, but HMRC does not publish it. A wrongly rejected
	 * XI number charges VAT to a business buying goods tax-free, so XI is
	 * checked by pattern only and VIES decides.
	 */
	public static function is_plausible( VatNumber $n ): bool {
		$prefix = $n->prefix();
		$number = $n->number();

		if ( ! isset( self::PATTERNS[ $prefix ] ) || 1 !== preg_match( self::PATTERNS[ $prefix ], $number ) ) {
			return false;
		}

		switch ( $prefix ) {
			case 'DE':
				return self::checksum_de( $number );
			case 'IT':
				return self::checksum_it( $number );
			case 'ES':
				return self::checksum_es( $number );
			case 'NL':
				return self::checksum_nl( $prefix . $number );
			case 'BE':
				return self::checksum_be( $number );
			case 'PL':
				return self::checksum_pl( $number );
			case 'PT':
				return self::checksum_pt( $number );
			case 'GB':
				return self::checksum_gb( $number );
			case 'NO':
				return self::checksum_no( $number );
			case 'CHE':
				return self::checksum_che( $number );
			default:
				return true;
		}
	}

	private static function checksum_de( string $n ): bool {
		$product = 10;
		for ( $i = 0; $i < 8; $i++ ) {
			$sum = ( (int) $n[ $i ] + $product ) % 10;
			if ( 0 === $sum ) {
				$sum = 10;
			}
			$product = ( 2 * $sum ) % 11;
		}
		$check = 11 - $product;
		if ( 10 === $check ) {
			$check = 0;
		}
		return $check === (int) $n[8];
	}

	private static function checksum_it( string $n ): bool {
		$sum = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			$digit = (int) $n[ $i ];
			if ( 1 === $i % 2 ) {
				$digit *= 2;
				if ( $digit > 9 ) {
					$digit -= 9;
				}
			}
			$sum += $digit;
		}
		return ( 10 - $sum % 10 ) % 10 === (int) $n[10];
	}

	/**
	 * Only company numbers (CIF) are checked. Personal NIF and NIE numbers are
	 * personal data, so no real public examples exist to verify the letter
	 * algorithm against; they stay regex-only.
	 */
	private static function checksum_es( string $n ): bool {
		if ( 1 !== preg_match( '/^[ABCDEFGHJNPQRSUVW]\d{7}[0-9A-J]$/', $n ) ) {
			return true;
		}
		$sum = 0;
		for ( $i = 1; $i <= 7; $i++ ) {
			$digit = (int) $n[ $i ];
			if ( 1 === $i % 2 ) {
				$digit *= 2;
				$digit  = intdiv( $digit, 10 ) + $digit % 10;
			}
			$sum += $digit;
		}
		$control = ( 10 - $sum % 10 ) % 10;
		return (string) $control === $n[8] || substr( 'JABCDEFGHI', $control, 1 ) === $n[8];
	}

	/**
	 * Numbers issued before 2020 pass the 11-proof; sole-proprietor numbers
	 * issued since then pass ISO 7064 mod 97-10 over the full number.
	 */
	private static function checksum_nl( string $full ): bool {
		$n   = substr( $full, 2 );
		$sum = 0;
		for ( $i = 0; $i < 8; $i++ ) {
			$sum += (int) $n[ $i ] * ( 9 - $i );
		}
		if ( $sum % 11 === (int) $n[8] ) {
			return true;
		}

		$digits = '';
		foreach ( str_split( $full ) as $char ) {
			$digits .= ctype_alpha( $char ) ? (string) ( ord( $char ) - 55 ) : $char;
		}
		$remainder = 0;
		foreach ( str_split( $digits ) as $digit ) {
			$remainder = ( $remainder * 10 + (int) $digit ) % 97;
		}
		return 1 === $remainder;
	}

	private static function checksum_be( string $n ): bool {
		return 97 - ( (int) substr( $n, 0, 8 ) ) % 97 === (int) substr( $n, 8, 2 );
	}

	private static function checksum_pl( string $n ): bool {
		$weights = array( 6, 5, 7, 2, 3, 4, 5, 6, 7 );
		$sum     = 0;
		foreach ( $weights as $i => $weight ) {
			$sum += $weight * (int) $n[ $i ];
		}
		return $sum % 11 === (int) $n[9];
	}

	private static function checksum_pt( string $n ): bool {
		$sum = 0;
		for ( $i = 0; $i < 8; $i++ ) {
			$sum += (int) $n[ $i ] * ( 9 - $i );
		}
		$check = 11 - $sum % 11;
		if ( $check >= 10 ) {
			$check = 0;
		}
		return $check === (int) $n[8];
	}

	/**
	 * The "+ 55" variant covers numbers issued under the newer series; it only
	 * widens what is accepted, so it cannot wrongly reject a real business.
	 * GD and HA government and health authority numbers are range-checked only.
	 */
	private static function checksum_gb( string $n ): bool {
		if ( 1 === preg_match( '/^(GD|HA)(\d{3})$/', $n, $m ) ) {
			$value = (int) $m[2];
			return 'GD' === $m[1] ? $value < 500 : $value >= 500;
		}
		$weights = array( 8, 7, 6, 5, 4, 3, 2 );
		$sum     = 0;
		foreach ( $weights as $i => $weight ) {
			$sum += $weight * (int) $n[ $i ];
		}
		$check = (int) substr( $n, 7, 2 );
		return 0 === ( $sum + $check ) % 97 || 0 === ( $sum + $check + 55 ) % 97;
	}

	private static function checksum_no( string $n ): bool {
		return self::mod11_weighted( $n, array( 3, 2, 7, 6, 5, 4, 3, 2 ) );
	}

	private static function checksum_che( string $n ): bool {
		return self::mod11_weighted( $n, array( 5, 4, 3, 2, 7, 6, 5, 4 ) );
	}

	/**
	 * @param int[] $weights Weights for the first eight digits.
	 */
	private static function mod11_weighted( string $n, array $weights ): bool {
		$sum = 0;
		foreach ( $weights as $i => $weight ) {
			$sum += $weight * (int) $n[ $i ];
		}
		$check = 11 - $sum % 11;
		if ( 11 === $check ) {
			$check = 0;
		}
		return 10 !== $check && $check === (int) $n[8];
	}
}
