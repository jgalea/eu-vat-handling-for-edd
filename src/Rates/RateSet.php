<?php

namespace VatrightEdd\Rates;

class RateSet {

	/** @var array<string,array{standard:float,reduced:array<int,array{rate:float,type:string,categories:array<int,string>}>}> */
	private $countries;

	/** @var string */
	private $source;

	/** @var string */
	private $situation_on;

	/**
	 * @param array<string,array{standard:float,reduced:array<int,array{rate:float,type:string,categories:array<int,string>}>}> $countries Keyed by country code.
	 */
	public function __construct( array $countries, string $source, string $situation_on ) {
		$this->countries    = $countries;
		$this->source       = $source;
		$this->situation_on = $situation_on;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): RateSet {
		return new self( $data['countries'], (string) $data['source'], (string) $data['situation_on'] );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'source'       => $this->source,
			'situation_on' => $this->situation_on,
			'countries'    => $this->countries,
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function country_codes(): array {
		return array_keys( $this->countries );
	}

	public function has( string $country ): bool {
		return isset( $this->countries[ $country ] );
	}

	public function standard( string $country ): ?float {
		return $this->countries[ $country ]['standard'] ?? null;
	}

	/**
	 * @return array<int,array{rate:float,type:string,categories:array<int,string>}>
	 */
	public function reduced( string $country ): array {
		return $this->countries[ $country ]['reduced'] ?? array();
	}

	public function source(): string {
		return $this->source;
	}

	public function situation_on(): string {
		return $this->situation_on;
	}
}
