<?php

namespace VatrightEdd\Registry;

use VatrightEdd\Domain\ValidationResult;
use VatrightEdd\Domain\VatNumber;

interface Registry {

	public function id(): string;

	public function supports( VatNumber $n ): bool;

	/**
	 * Never throws: every failure comes back as an UNAVAILABLE result.
	 */
	public function check( VatNumber $n, ?VatNumber $requester ): ValidationResult;
}
