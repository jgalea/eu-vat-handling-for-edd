<?php

namespace VatrightEdd\Tests\Integration\Geo;

use VatrightEdd\Geo\IpCountry;
use VatrightEdd\Settings;
use VatrightEdd\Tests\Support\IntegrationTestCase;

class IpCountryTest extends IntegrationTestCase {

	/** @var array<string,mixed> */
	private $server;

	public function set_up() {
		parent::set_up();
		$this->server = $_SERVER;
		unset( $_SERVER['HTTP_CF_IPCOUNTRY'], $_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] );
		update_option( Settings::OPTION, array() );
	}

	private function source( string $source ): void {
		update_option( Settings::OPTION, array( 'ip_country_source' => $source ) );
	}

	public function tear_down() {
		$_SERVER = $this->server;
		parent::tear_down();
	}

	public function test_no_header_means_no_evidence(): void {
		$this->assertNull( IpCountry::current() );
	}

	public function test_a_spoofed_header_is_ignored_when_no_source_is_chosen(): void {
		$_SERVER['HTTP_CF_IPCOUNTRY']              = 'FR';
		$_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] = 'DE';
		$this->assertNull( IpCountry::current() );
	}

	public function test_cloudflare_header(): void {
		$this->source( 'cloudflare' );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'fr';
		$this->assertSame( 'FR', IpCountry::current() );
	}

	public function test_cloudflare_source_ignores_the_cloudfront_header(): void {
		$this->source( 'cloudflare' );
		$_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] = 'DE';
		$this->assertNull( IpCountry::current() );
	}

	public function test_cloudfront_header(): void {
		$this->source( 'cloudfront' );
		$_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] = 'DE';
		$this->assertSame( 'DE', IpCountry::current() );
	}

	public function test_unknown_and_tor_codes_are_not_countries(): void {
		$this->source( 'cloudflare' );
		foreach ( array( 'XX', 'T1', 'EU', 'A1', '', 'FRA', '<b>' ) as $code ) {
			$_SERVER['HTTP_CF_IPCOUNTRY'] = $code;
			$this->assertNull( IpCountry::current(), $code );
		}
	}

	public function test_the_filter_can_name_the_headers_to_trust(): void {
		$_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] = 'IT';
		add_filter(
			'vatright_edd_ip_country_headers',
			static function () {
				return array( 'HTTP_CLOUDFRONT_VIEWER_COUNTRY' );
			}
		);
		$this->assertSame( 'IT', IpCountry::current() );
	}

	public function test_a_cdn_header_on_the_request_is_detected(): void {
		$this->assertFalse( IpCountry::cdn_header_present() );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'FR';
		$this->assertTrue( IpCountry::cdn_header_present() );
	}

	public function test_the_headers_filter_can_still_switch_a_chosen_source_off(): void {
		$this->source( 'cloudflare' );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'FR';
		add_filter( 'vatright_edd_ip_country_headers', '__return_empty_array' );
		$this->assertNull( IpCountry::current() );
	}

	public function test_the_country_can_come_from_a_filter(): void {
		$this->source( 'cloudflare' );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'FR';
		add_filter(
			'vatright_edd_ip_country',
			static function ( $country ) {
				return 'FR' === $country ? 'es' : $country;
			}
		);
		$this->assertSame( 'ES', IpCountry::current() );
	}

	public function test_a_filter_returning_junk_gives_no_evidence(): void {
		add_filter(
			'vatright_edd_ip_country',
			static function () {
				return array( 'FR' );
			}
		);
		$this->assertNull( IpCountry::current() );
	}
}
