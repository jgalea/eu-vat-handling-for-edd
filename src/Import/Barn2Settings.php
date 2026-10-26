<?php

namespace VatrightEdd\Import;

use VatrightEdd\Domain\FormatCheck;
use VatrightEdd\Domain\VatNumber;
use VatrightEdd\Settings;

/**
 * Copies Barn2 EDD EU VAT's settings into ours. Values move server-side only:
 * what comes back, and what any caller may print, is a field name and what
 * happened to it, never a value.
 */
class Barn2Settings {

	const IMPORTED    = 'imported';
	const ALREADY_SET = 'already set';
	const SKIPPED     = 'skipped';
	const MISMATCH    = 'country differs from the shop country';

	const TEXT   = 'text';
	const EMAIL  = 'email';
	const NUMBER = 'number';
	const FLAG   = 'flag';
	const SECRET = 'secret';

	/**
	 * Barn2 option => array( our key, type ).
	 */
	const MAP = array(
		'edd_vat_number'                      => array( 'store_vat_number', self::NUMBER ),
		'edd_vat_reverse_charge_base_country' => array( 'allow_domestic_exemption', self::FLAG ),
		'edd_vat_hmrc_client_id'              => array( 'hmrc_client_id', self::TEXT ),
		'edd_vat_hmrc_client_secret'          => array( 'hmrc_client_secret', self::SECRET ),
		'edd_vat_company_name'                => array( 'seller_company', self::TEXT ),
		'edd_vat_company_email'               => array( 'seller_email', self::EMAIL ),
		'edd_vat_address_line_1'              => array( 'seller_address_1', self::TEXT ),
		'edd_vat_address_line_2'              => array( 'seller_address_2', self::TEXT ),
		'edd_vat_address_city'                => array( 'seller_city', self::TEXT ),
		'edd_vat_address_code'                => array( 'seller_postcode', self::TEXT ),
		'edd_vat_purchase_receipt'            => array( 'receipt_vat_details', self::FLAG ),
	);

	/**
	 * Barn2 settings with no counterpart here.
	 */
	const UNMAPPED = array(
		'edd_uk_vat_number',
		'edd_vat_vatsense_api_key',
		'edd_vat_show_website_address',
		'edd_vat_address_invoice',
	);

	const COUNTRY_OPTION = 'edd_vat_address_country';

	const REPORT_OPTION = 'vatright_edd_import_barn2_settings';

	/**
	 * Whether Barn2 left any settings behind.
	 */
	public static function exists(): bool {
		$keys = array_merge( array_keys( self::MAP ), self::UNMAPPED, array( self::COUNTRY_OPTION ) );
		foreach ( $keys as $key ) {
			if ( self::has( $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param bool $apply False reports what would happen and writes nothing.
	 * @return array<string,string> Barn2 option name => outcome, for the options Barn2 has set.
	 */
	public function import( bool $overwrite, bool $apply = true ): array {
		$stored  = get_option( Settings::OPTION, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$report  = array();
		$changes = array();

		foreach ( self::MAP as $option => $target ) {
			if ( ! self::has( $option ) ) {
				continue;
			}
			list( $key, $type ) = $target;
			if ( ! $overwrite && self::is_set( $stored, $key ) ) {
				$report[ $option ] = self::ALREADY_SET;
				continue;
			}
			$value = $this->clean( edd_get_option( $option, '' ), $type );
			if ( null === $value ) {
				$report[ $option ] = self::SKIPPED;
				continue;
			}
			$changes[ $key ]   = $value;
			$report[ $option ] = self::IMPORTED;
		}

		foreach ( self::UNMAPPED as $option ) {
			if ( self::has( $option ) ) {
				$report[ $option ] = self::SKIPPED;
			}
		}

		if ( self::has( self::COUNTRY_OPTION ) ) {
			$report[ self::COUNTRY_OPTION ] = $this->country_differs() ? self::MISMATCH : self::SKIPPED;
		}

		if ( $apply ) {
			if ( array() !== $changes ) {
				update_option( Settings::OPTION, array_merge( $stored, $changes ) );
			}
			update_option( self::REPORT_OPTION, $report, false );
		}
		return $report;
	}

	/**
	 * The outcome of the last settings import: field names and outcomes only.
	 *
	 * @return array<string,string>|null
	 */
	public static function last_report(): ?array {
		$report = get_option( self::REPORT_OPTION, null );
		return is_array( $report ) ? $report : null;
	}

	private static function has( string $option ): bool {
		$value = edd_get_option( $option, '' );
		return is_scalar( $value ) && '' !== trim( (string) $value );
	}

	/**
	 * @param array<mixed> $stored
	 */
	private static function is_set( array $stored, string $key ): bool {
		return array_key_exists( $key, $stored ) && ! in_array( $stored[ $key ], array( '', null, false, 0 ), true );
	}

	/**
	 * Barn2 stores "EU" when the address country is the shop's.
	 */
	private function country_differs(): bool {
		$country = strtoupper( trim( (string) edd_get_option( self::COUNTRY_OPTION, '' ) ) );
		return 'EU' !== $country && strtoupper( (string) edd_get_shop_country() ) !== $country;
	}

	/**
	 * @param mixed $raw
	 * @return mixed|null Null when the value cannot be used.
	 */
	private function clean( $raw, string $type ) {
		if ( ! is_scalar( $raw ) ) {
			return null;
		}
		$text = trim( (string) $raw );
		switch ( $type ) {
			case self::FLAG:
				return true;
			case self::EMAIL:
				$email = sanitize_email( $text );
				return '' === $email ? null : $email;
			case self::NUMBER:
				$parsed = VatNumber::parse( $text, (string) edd_get_shop_country() );
				return null !== $parsed && FormatCheck::is_plausible( $parsed ) ? $parsed->canonical() : null;
			case self::SECRET:
				return $text;
			default:
				$clean = sanitize_text_field( $text );
				return '' === $clean ? null : $clean;
		}
	}
}
