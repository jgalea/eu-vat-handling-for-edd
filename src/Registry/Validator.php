<?php

namespace VatrightEdd\Registry;

use VatrightEdd\Domain\FormatCheck;
use VatrightEdd\Domain\ValidationResult;
use VatrightEdd\Domain\VatNumber;
use VatrightEdd\Settings;

class Validator {

	/** @var Router */
	private $router;

	/** @var ValidationCache */
	private $cache;

	/** @var Settings */
	private $settings;

	/** @var LookupThrottle */
	private $throttle;

	public function __construct( Router $router, ValidationCache $cache, Settings $settings, ?LookupThrottle $throttle = null ) {
		$this->router   = $router;
		$this->cache    = $cache;
		$this->settings = $settings;
		$this->throttle = $throttle ?? new LookupThrottle();
	}

	public function validate( VatNumber $n, bool $allow_network, bool $fresh = false ): ValidationResult {
		return $this->lookup( $n, $allow_network, $fresh, null );
	}

	/**
	 * For lookups an anonymous visitor sets off (cart updates, checkout). A
	 * cached verdict is returned as usual; a cache miss reaches the registry
	 * only while the throttle allows it, and is UNCHECKED with detail
	 * 'throttled' otherwise, which checkout treats like an outage with VAT
	 * charged.
	 *
	 * @param string $bucket 'update' or 'checkout', each with its own per-client allowance.
	 */
	public function validate_for_visitor( VatNumber $n, string $bucket ): ValidationResult {
		return $this->lookup( $n, true, false, $bucket );
	}

	private function lookup( VatNumber $n, bool $allow_network, bool $fresh, ?string $bucket ): ValidationResult {
		if ( ! FormatCheck::is_plausible( $n ) ) {
			return new ValidationResult( ValidationResult::FORMAT_INVALID, 'format', gmdate( 'Y-m-d\TH:i:s\Z' ) );
		}

		$registry = $this->router->for( $n );
		if ( null === $registry ) {
			return new ValidationResult( ValidationResult::UNCHECKED, 'none', gmdate( 'Y-m-d\TH:i:s\Z' ), null, null, null, false, 'no_registry' );
		}

		$requester = $this->requester_for( $registry );
		$cached    = $fresh ? null : $this->cache->get( $n, $requester );
		if ( null !== $cached ) {
			return $cached;
		}
		if ( ! $allow_network ) {
			return new ValidationResult( ValidationResult::UNCHECKED, $registry->id(), gmdate( 'Y-m-d\TH:i:s\Z' ), null, null, null, false, 'cache_miss' );
		}
		if ( null !== $bucket && ! $this->throttle->allow( $bucket ) ) {
			return new ValidationResult( ValidationResult::UNCHECKED, $registry->id(), gmdate( 'Y-m-d\TH:i:s\Z' ), null, null, null, false, 'throttled' );
		}

		$result = $registry->check( $n, $requester );
		if ( null !== $requester && ValidationResult::UNAVAILABLE === $result->status() && 'INVALID_REQUESTER_INFO' === $result->detail() ) {
			$result = $this->with_detail( $registry->check( $n, null ), 'requester_rejected' );
		}

		$this->cache->put( $n, $requester, $result );
		return $result;
	}

	private function requester_for( Registry $registry ): ?VatNumber {
		$store = VatNumber::parse( $this->settings->store_vat_number(), '' );
		if ( null === $store || ! $registry->supports( $store ) ) {
			return null;
		}
		return $store;
	}

	private function with_detail( ValidationResult $result, string $detail ): ValidationResult {
		if ( '' !== $result->detail() ) {
			return $result;
		}
		return new ValidationResult(
			$result->status(),
			$result->registry(),
			$result->checked_at(),
			$result->name(),
			$result->address(),
			$result->consultation(),
			$result->from_cache(),
			$detail
		);
	}
}
