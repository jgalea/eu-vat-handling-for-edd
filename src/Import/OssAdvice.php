<?php

namespace VatrightEdd\Import;

use VatrightEdd\Domain\Territory;
use VatrightEdd\Settings;

/**
 * Barn2 EDD EU VAT charges EU consumers the destination rate and has no
 * threshold, so a store that imports from it may be OSS-registered. Without
 * "I am registered for the One Stop Shop" this plugin charges the store's own
 * rate below the EUR 10,000 threshold, which changes new orders and renewals.
 * The import recommends the setting and never switches it on by itself.
 */
class OssAdvice {

	const SETTING = 'setting';

	const ORDERS = 'orders';

	/**
	 * Whether an order was an EU consumer sale taxed at a rate other than the
	 * store country's.
	 *
	 * @param string $billing    Billing country.
	 * @param string $store      Store country.
	 * @param string $charged    Country of the rate charged, empty when untaxed.
	 * @param bool   $has_number Whether the customer gave a VAT number.
	 */
	public static function destination_charge( string $billing, string $store, string $charged, bool $has_number ): bool {
		if ( '' === $billing || '' === $charged || $has_number ) {
			return false;
		}
		$destination = Territory::vat_country( $billing );
		$home        = Territory::vat_country( $store );
		return Territory::is_eu_member( $destination ) && $destination !== $home && Territory::vat_country( $charged ) !== $home;
	}

	/**
	 * @return string|null Why to recommend the setting, or null when there is no reason or it is already on.
	 */
	public static function reason( bool $barn2_oss_country, bool $destination_orders, bool $already_registered ): ?string {
		if ( $already_registered ) {
			return null;
		}
		if ( $barn2_oss_country ) {
			return self::SETTING;
		}
		return $destination_orders ? self::ORDERS : null;
	}

	public static function message( string $reason ): string {
		if ( self::SETTING === $reason ) {
			$because = __( 'Barn2 EDD EU VAT has the EU MOSS number selected as your country of VAT registration, so you look to be registered for the One Stop Shop.', 'eu-vat-handling-for-edd' );
		} else {
			$because = __( 'Barn2 EDD EU VAT charged your EU consumers the VAT rate of their own country.', 'eu-vat-handling-for-edd' );
		}
		return sprintf(
			/* translators: 1: why the setting is recommended, 2: name of the setting. */
			__( '%1$s If you are registered for the One Stop Shop, or your sales to EU consumers are above 10,000 EUR a year, turn on "%2$s". Left off, new orders and renewals are charged your own country\'s VAT while you are below that threshold. The import does not switch it on by itself.', 'eu-vat-handling-for-edd' ),
			$because,
			self::setting_label()
		);
	}

	public static function setting_label(): string {
		return __( 'I am registered for the One Stop Shop', 'eu-vat-handling-for-edd' );
	}

	/**
	 * The reason for this store, from Barn2's settings and whether the history
	 * held destination charges.
	 */
	public static function for_store( bool $destination_orders ): ?string {
		return self::reason( Barn2Settings::oss_country(), $destination_orders, Settings::get()->oss_registered() );
	}

	public static function enable(): void {
		$stored = get_option( Settings::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$stored['oss_registered'] = true;
		update_option( Settings::OPTION, $stored );
	}
}
