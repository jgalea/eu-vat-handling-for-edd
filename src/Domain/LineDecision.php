<?php

namespace VatrightEdd\Domain;

final class LineDecision {

	/** @var string */
	private $key;

	/** @var string */
	private $supply_type;

	/** @var string */
	private $place_of_supply;

	/** @var string */
	private $tax_country;

	/** @var string */
	private $scheme;

	public function __construct( string $key, string $supply_type, string $place_of_supply, string $tax_country, string $scheme ) {
		$this->key             = $key;
		$this->supply_type     = $supply_type;
		$this->place_of_supply = $place_of_supply;
		$this->tax_country     = $tax_country;
		$this->scheme          = $scheme;
	}

	public function key(): string {
		return $this->key;
	}

	public function supply_type(): string {
		return $this->supply_type;
	}

	public function place_of_supply(): string {
		return $this->place_of_supply;
	}

	public function tax_country(): string {
		return $this->tax_country;
	}

	public function scheme(): string {
		return $this->scheme;
	}

	/**
	 * @return array<string,string>
	 */
	public function to_array(): array {
		return array(
			'key'             => $this->key,
			'supply_type'     => $this->supply_type,
			'place_of_supply' => $this->place_of_supply,
			'tax_country'     => $this->tax_country,
			'scheme'          => $this->scheme,
		);
	}

	/**
	 * @param array<string,string> $data
	 */
	public static function from_array( array $data ): LineDecision {
		return new self( $data['key'], $data['supply_type'], $data['place_of_supply'], $data['tax_country'], $data['scheme'] );
	}
}
