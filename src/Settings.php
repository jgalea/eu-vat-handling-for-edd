<?php

namespace VatrightEdd;

use VatrightEdd\Domain\VatNumber;

class Settings {

	const OPTION = 'vatright_edd_settings';

	/** @var Settings|null */
	private static $instance = null;

	public static function get(): Settings {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'store_vat_number'             => '',
			'store_vat_number_gb'          => '',
			'outage_policy'                => 'charge',
			'cache_ttl_valid_days'         => 7,
			'allow_domestic_exemption'     => false,
			'oss_registered'               => false,
			'threshold_warning'            => true,
			'threshold_warning_percent'    => 80,
			'self_certification'           => true,
			'notify_admin_on_revalidation' => true,
			'auto_refund_on_validation'    => false,
			'retention_years'              => 10,
			'hmrc_client_id'               => '',
			'hmrc_client_secret'           => '',
			'report_currency'              => 'EUR',
			'ip_country_source'            => 'none',
			'receipt_vat_details'          => true,
			'seller_company'               => '',
			'seller_email'                 => '',
			'seller_address_1'             => '',
			'seller_address_2'             => '',
			'seller_city'                  => '',
			'seller_postcode'              => '',
			'seller_country'               => '',
			'delete_data_on_uninstall'     => false,
		);
	}

	public function store_vat_number(): string {
		return (string) $this->value( 'store_vat_number' );
	}

	public function store_vat_number_gb(): string {
		return (string) $this->value( 'store_vat_number_gb' );
	}

	/**
	 * The number the store quotes to a customer of this country: the UK
	 * number for a UK customer, the EU number for everyone else. A store with
	 * only one of the two quotes it to all.
	 */
	public function store_vat_number_for( string $country ): string {
		$eu = $this->store_vat_number();
		$gb = $this->store_vat_number_gb();
		if ( 'GB' === strtoupper( $country ) ) {
			return '' !== $gb ? $gb : $eu;
		}
		return '' !== $eu ? $eu : $gb;
	}

	/**
	 * The store's numbers as parsed VAT numbers, EU first, for registries to
	 * take as the requester.
	 *
	 * @return VatNumber[]
	 */
	public function store_requesters(): array {
		$numbers = array();
		foreach ( array( $this->store_vat_number(), $this->store_vat_number_gb() ) as $raw ) {
			$parsed = VatNumber::parse( $raw, '' );
			if ( null !== $parsed ) {
				$numbers[] = $parsed;
			}
		}
		return $numbers;
	}

	public function outage_policy(): string {
		$policy = (string) $this->value( 'outage_policy' );
		return in_array( $policy, array( 'charge', 'exempt_pending', 'reject' ), true ) ? $policy : 'charge';
	}

	public function cache_ttl_valid_days(): int {
		return (int) $this->value( 'cache_ttl_valid_days' );
	}

	public function allow_domestic_exemption(): bool {
		return (bool) $this->value( 'allow_domestic_exemption' );
	}

	public function oss_registered(): bool {
		return (bool) $this->value( 'oss_registered' );
	}

	public function threshold_warning(): bool {
		return (bool) $this->value( 'threshold_warning' );
	}

	public function threshold_warning_percent(): int {
		return max( 1, min( 99, (int) $this->value( 'threshold_warning_percent' ) ) );
	}

	public function self_certification(): bool {
		return (bool) $this->value( 'self_certification' );
	}

	public function notify_admin_on_revalidation(): bool {
		return (bool) $this->value( 'notify_admin_on_revalidation' );
	}

	public function auto_refund_on_validation(): bool {
		return (bool) $this->value( 'auto_refund_on_validation' );
	}

	public function retention_years(): int {
		return (int) $this->value( 'retention_years' );
	}

	public function hmrc_client_id(): string {
		return (string) $this->value( 'hmrc_client_id' );
	}

	public function hmrc_client_secret(): string {
		return (string) $this->value( 'hmrc_client_secret' );
	}

	public function report_currency(): string {
		return (string) $this->value( 'report_currency' );
	}

	public function ip_country_source(): string {
		$source = (string) $this->value( 'ip_country_source' );
		return in_array( $source, array( 'none', 'cloudflare', 'cloudfront' ), true ) ? $source : 'none';
	}

	public function text( string $key ): string {
		return (string) $this->value( $key );
	}

	public function receipt_vat_details(): bool {
		return (bool) $this->value( 'receipt_vat_details' );
	}

	/**
	 * The seller details a receipt or invoice prints: company, address lines
	 * as one line each, email, in that order, empty parts left out. The VAT
	 * number is the one for the customer's country, when it is given.
	 *
	 * @return array<string,string> Label key => value.
	 */
	public function seller_details( string $country = '' ): array {
		$address = array_filter(
			array(
				(string) $this->value( 'seller_address_1' ),
				(string) $this->value( 'seller_address_2' ),
				trim( (string) $this->value( 'seller_postcode' ) . ' ' . (string) $this->value( 'seller_city' ) ),
				(string) $this->value( 'seller_country' ),
			),
			static function ( string $part ): bool {
				return '' !== $part;
			}
		);
		return array_filter(
			array(
				'company' => (string) $this->value( 'seller_company' ),
				'address' => implode( ', ', $address ),
				'email'   => (string) $this->value( 'seller_email' ),
				'vat'     => $this->store_vat_number_for( $country ),
			),
			static function ( string $part ): bool {
				return '' !== $part;
			}
		);
	}

	public function delete_data_on_uninstall(): bool {
		return (bool) $this->value( 'delete_data_on_uninstall' );
	}

	/**
	 * @return mixed
	 */
	private function value( string $key ) {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION, array() );
		if ( is_array( $stored ) && array_key_exists( $key, $stored ) ) {
			return $stored[ $key ];
		}
		return $defaults[ $key ];
	}
}
