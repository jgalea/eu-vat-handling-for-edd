<?php

namespace VatrightEdd\Admin;

use VatrightEdd\Checkout\InputGatherer;
use VatrightEdd\Checkout\TaxApplier;
use VatrightEdd\Domain\Territory;
use VatrightEdd\Rates\RatesScreen;
use VatrightEdd\Settings;

class SetupCheck {

	const ERROR = 'error';

	const WARNING = 'warning';

	const INFO = 'info';

	/**
	 * @return array<int,array{id:string,severity:string,message:string,fix_url:string}>
	 */
	public function issues(): array {
		$issues   = array();
		$taxes    = admin_url( 'edit.php?post_type=download&page=edd-settings&tab=taxes' );
		$settings = Settings::get();
		$store    = InputGatherer::store_country();

		if ( ! edd_use_taxes() ) {
			$issues[] = $this->issue( 'taxes_disabled', self::ERROR, __( 'Taxes are turned off in Easy Digital Downloads, so no VAT is calculated.', 'eu-vat-handling-for-edd' ), $taxes );
		}

		if ( '' === $store ) {
			$issues[] = $this->issue( 'shop_country_missing', self::ERROR, __( 'The shop country is not set, so EU VAT Handling cannot tell domestic sales from cross-border ones.', 'eu-vat-handling-for-edd' ), $taxes );
		} elseif ( ! Territory::is_eu_member( $store ) ) {
			$issues[] = $this->issue( 'shop_country_outside_eu', self::WARNING, __( 'The shop country is outside the EU. This version is built for EU-established stores.', 'eu-vat-handling-for-edd' ), $taxes );
		}

		if ( '' === $settings->store_vat_number() ) {
			$issues[] = $this->issue( 'store_vat_number_missing', self::WARNING, __( 'Your store VAT number is not set, so VIES cannot give you consultation numbers as proof of validation.', 'eu-vat-handling-for-edd' ), SettingsSection::url() );
		}

		$missing = $this->countries_without_standard_rate();
		if ( array() !== $missing ) {
			$issues[] = $this->issue(
				'standard_rates_missing',
				self::WARNING,
				sprintf(
					/* translators: %s: comma-separated country codes. */
					__( 'No standard VAT rate for: %s.', 'eu-vat-handling-for-edd' ),
					implode( ', ', $missing )
				),
				RatesScreen::url()
			);
		}

		$fallen_back = $this->rates_charged_at_origin();
		if ( array() !== $fallen_back ) {
			$issues[] = $this->issue(
				'destination_rates_missing',
				self::ERROR,
				sprintf(
					/* translators: %s: comma-separated country codes. */
					__( 'Orders needed a VAT rate your tax rates do not have, so your own country\'s rate was charged instead: %s. Add the missing rates.', 'eu-vat-handling-for-edd' ),
					implode( ', ', $fallen_back )
				),
				RatesScreen::url()
			);
		}

		if ( 'none' === $settings->ip_country_source() ) {
			$issues[] = $this->issue( 'ip_country_source_none', self::INFO, __( 'No IP country source is chosen, so digital sales have one piece of location evidence fewer. If the site is behind Cloudflare or CloudFront, choose it.', 'eu-vat-handling-for-edd' ), SettingsSection::url() );
		}

		if ( '' === $settings->hmrc_client_id() || '' === $settings->hmrc_client_secret() ) {
			$issues[] = $this->issue( 'hmrc_credentials_missing', self::INFO, __( 'No HMRC credentials, so GB VAT numbers are format-checked only. This never changes the VAT charged.', 'eu-vat-handling-for-edd' ), SettingsSection::url() );
		}

		return $issues;
	}

	/**
	 * @return array{id:string,severity:string,message:string,fix_url:string}
	 */
	private function issue( string $id, string $severity, string $message, string $fix_url ): array {
		return array(
			'id'       => $id,
			'severity' => $severity,
			'message'  => $message,
			'fix_url'  => $fix_url,
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function countries_without_standard_rate(): array {
		$covered = array();
		/** @var array<int,array{country:string,state:string,status:string,scope:string}> $rates */
		$rates = edd_get_tax_rates();
		foreach ( $rates as $rate ) {
			if ( 'active' === $rate['status'] && 'country' === $rate['scope'] && '' === $rate['state'] ) {
				$covered[ $rate['country'] ] = true;
			}
		}
		return array_values(
			array_filter(
				Territory::EU,
				static function ( string $iso ) use ( $covered ): bool {
					return ! isset( $covered[ $iso ] );
				}
			)
		);
	}

	/**
	 * Countries checkout had to tax at the store's own rate, still without a
	 * rate of their own.
	 *
	 * @return array<int,string>
	 */
	private function rates_charged_at_origin(): array {
		$seen = get_option( TaxApplier::MISSING_OPTION, array() );
		if ( ! is_array( $seen ) ) {
			return array();
		}
		$countries = array();
		foreach ( array_keys( $seen ) as $country ) {
			$row = edd_get_tax_rate_by_location( array( 'country' => (string) $country ) );
			if ( empty( $row->id ) ) {
				$countries[] = (string) $country;
			}
		}
		return $countries;
	}
}
