<?php

namespace VatrightEdd\Domain;

final class Line {

	const GOODS   = 'goods';
	const DIGITAL = 'digital';
	const SERVICE = 'service';

	/** @var string */
	private $key;

	/** @var string */
	private $supply_type;

	/** @var bool */
	private $needs_shipping;

	public function __construct( string $key, string $supply_type, bool $needs_shipping ) {
		$this->key            = $key;
		$this->supply_type    = $supply_type;
		$this->needs_shipping = $needs_shipping;
	}

	public function key(): string {
		return $this->key;
	}

	public function supply_type(): string {
		return $this->supply_type;
	}

	public function needs_shipping(): bool {
		return $this->needs_shipping;
	}
}
