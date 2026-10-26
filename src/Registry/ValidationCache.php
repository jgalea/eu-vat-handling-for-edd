<?php

namespace VatrightEdd\Registry;

use VatrightEdd\Domain\ValidationResult;
use VatrightEdd\Domain\VatNumber;
use VatrightEdd\Settings;

class ValidationCache {

	const PREFIX = 'vatright_edd_v_';

	/** @var Settings */
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function get( VatNumber $n, ?VatNumber $requester ): ?ValidationResult {
		$stored = get_transient( $this->key( $n, $requester ) );
		if ( ! is_array( $stored ) ) {
			return null;
		}
		return ValidationResult::from_array( $stored )->with_from_cache( true );
	}

	public function put( VatNumber $n, ?VatNumber $requester, ValidationResult $result ): void {
		$ttl = $this->ttl( $result->status() );
		if ( 0 === $ttl ) {
			return;
		}
		set_transient( $this->key( $n, $requester ), $result->to_array(), $ttl );
	}

	public function forget( VatNumber $n, ?VatNumber $requester ): void {
		delete_transient( $this->key( $n, $requester ) );
	}

	private function ttl( string $status ): int {
		switch ( $status ) {
			case ValidationResult::VALID:
				return $this->settings->cache_ttl_valid_days() * DAY_IN_SECONDS;
			case ValidationResult::INVALID:
				return HOUR_IN_SECONDS;
			case ValidationResult::UNAVAILABLE:
				return 5 * MINUTE_IN_SECONDS;
			default:
				return 0;
		}
	}

	private function key( VatNumber $n, ?VatNumber $requester ): string {
		return self::PREFIX . md5( $n->canonical() . '|' . ( null === $requester ? '' : $requester->canonical() ) );
	}
}
