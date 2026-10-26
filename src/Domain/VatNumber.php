<?php

namespace VatrightEdd\Domain;

final class VatNumber {

	const EU_PREFIXES = array( 'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK' );

	const OTHER_PREFIXES = array( 'XI', 'GB', 'CHE', 'NO' );

	/** @var string */
	private $prefix;

	/** @var string */
	private $number;

	private function __construct( string $prefix, string $number ) {
		$this->prefix = $prefix;
		$this->number = $number;
	}

	public static function parse( string $raw, string $billing_iso ): ?VatNumber {
		$clean = strtoupper( (string) preg_replace( '/[\s.\-\/]+/', '', $raw ) );
		if ( '' === $clean ) {
			return null;
		}

		$billing_prefix = self::prefix_for_billing( $billing_iso );
		$prefix         = self::leading_prefix( $clean, $billing_prefix );

		if ( null === $prefix ) {
			if ( null === $billing_prefix ) {
				return null;
			}
			$prefix = $billing_prefix;
			$body   = $clean;
		} else {
			$body = self::strip_prefix( $clean, $prefix );
		}

		if ( 'CHE' === $prefix ) {
			$body = (string) preg_replace( '/(MWST|TVA|IVA)$/', '', $body );
		} elseif ( 'NO' === $prefix ) {
			$body = (string) preg_replace( '/MVA$/', '', $body );
		}

		if ( '' === $body ) {
			return null;
		}
		return new self( $prefix, $body );
	}

	public function prefix(): string {
		return $this->prefix;
	}

	public function country(): string {
		if ( 'EL' === $this->prefix ) {
			return 'GR';
		}
		if ( 'XI' === $this->prefix ) {
			return 'GB';
		}
		if ( 'CHE' === $this->prefix ) {
			return 'CH';
		}
		return $this->prefix;
	}

	public function number(): string {
		return $this->number;
	}

	public function canonical(): string {
		return $this->prefix . $this->number;
	}

	public function is_eu(): bool {
		return 'XI' === $this->prefix || in_array( $this->prefix, self::EU_PREFIXES, true );
	}

	public function display(): string {
		if ( 'CHE' === $this->prefix && 1 === preg_match( '/^(\d{3})(\d{3})(\d{3})$/', $this->number, $m ) ) {
			return 'CHE-' . $m[1] . '.' . $m[2] . '.' . $m[3];
		}
		return $this->prefix . ' ' . $this->number;
	}

	private static function prefix_for_billing( string $iso ): ?string {
		$iso = Territory::vat_country( $iso );
		if ( 'CH' === $iso ) {
			return 'CHE';
		}
		$prefix = Territory::vat_prefix( $iso );
		if ( in_array( $prefix, self::EU_PREFIXES, true ) || in_array( $prefix, self::OTHER_PREFIXES, true ) ) {
			return $prefix;
		}
		return null;
	}

	private static function leading_prefix( string $clean, ?string $billing_prefix ): ?string {
		if ( null !== $billing_prefix && 0 === strpos( $clean, $billing_prefix ) ) {
			return $billing_prefix;
		}
		if ( 0 === strpos( $clean, 'GR' ) ) {
			return 'EL';
		}
		foreach ( array_merge( self::EU_PREFIXES, self::OTHER_PREFIXES ) as $prefix ) {
			if ( 0 === strpos( $clean, $prefix ) ) {
				return $prefix;
			}
		}
		if ( 0 === strpos( $clean, 'CH' ) ) {
			return 'CHE';
		}
		return null;
	}

	private static function strip_prefix( string $clean, string $prefix ): string {
		if ( 'EL' === $prefix && 0 === strpos( $clean, 'GR' ) ) {
			return substr( $clean, 2 );
		}
		if ( 'CHE' === $prefix && 0 !== strpos( $clean, 'CHE' ) ) {
			return substr( $clean, 2 );
		}
		return substr( $clean, strlen( $prefix ) );
	}
}
