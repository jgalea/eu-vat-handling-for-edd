<?php

namespace VatrightEdd\Registry;

use VatrightEdd\Domain\VatNumber;

class Router {

	/** @var Registry[] */
	private $registries;

	public function __construct( Registry ...$registries ) {
		$this->registries = $registries;
	}

	public function for( VatNumber $n ): ?Registry {
		foreach ( $this->registries as $registry ) {
			if ( $registry->supports( $n ) ) {
				return $registry;
			}
		}
		return null;
	}
}
