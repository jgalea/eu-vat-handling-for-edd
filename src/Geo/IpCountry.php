<?php

namespace VatrightEdd\Geo;

use VatrightEdd\Settings;

/**
 * The visitor's country as a CDN in front of the site reports it. EDD has no
 * geolocation of its own and no lookup service is called, so without the
 * merchant naming their CDN (or a filter) there is no IP evidence. A header
 * is only trusted when the merchant says the site sits behind that CDN, since
 * on any other site a visitor can send it themselves.
 */
class IpCountry {

	/**
	 * The "IP country source" settings and their headers, as PHP sees them in $_SERVER.
	 */
	const SOURCES = array(
		'cloudflare' => 'HTTP_CF_IPCOUNTRY',
		'cloudfront' => 'HTTP_CLOUDFRONT_VIEWER_COUNTRY',
	);

	/**
	 * Codes the CDNs send when they don't know: unknown, Tor, anonymous proxy,
	 * satellite, and the continent-level EU and AP.
	 */
	const NOT_A_COUNTRY = array( 'XX', 'T1', 'A1', 'A2', 'O1', 'EU', 'AP' );

	public static function current(): ?string {
		$country = null;
		$source  = Settings::get()->ip_country_source();
		$headers = (array) apply_filters( 'vatright_edd_ip_country_headers', isset( self::SOURCES[ $source ] ) ? array( self::SOURCES[ $source ] ) : array() );
		foreach ( $headers as $header ) {
			if ( ! is_string( $header ) || ! isset( $_SERVER[ $header ] ) ) {
				continue;
			}
			$country = self::clean( sanitize_text_field( wp_unslash( (string) $_SERVER[ $header ] ) ) );
			if ( null !== $country ) {
				break;
			}
		}

		$filtered = apply_filters( 'vatright_edd_ip_country', $country );
		return is_string( $filtered ) ? self::clean( $filtered ) : null;
	}

	/**
	 * Whether this request carries a CDN country header, so the settings
	 * screen can suggest turning the source on.
	 */
	public static function cdn_header_present(): bool {
		foreach ( self::SOURCES as $header ) {
			if ( isset( $_SERVER[ $header ] ) && '' !== $_SERVER[ $header ] ) {
				return true;
			}
		}
		return false;
	}

	private static function clean( string $value ): ?string {
		$code = strtoupper( trim( $value ) );
		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $code ) || in_array( $code, self::NOT_A_COUNTRY, true ) ) {
			return null;
		}
		return $code;
	}
}
