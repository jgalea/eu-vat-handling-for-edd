<?php

namespace VatrightEdd\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use VatrightEdd\Checkout\InputGatherer;

require_once dirname( __DIR__, 2 ) . '/Support/SettingsStubs.php';

class KnownCountryTest extends TestCase {

	public function test_a_listed_country_is_kept_in_upper_case(): void {
		$this->assertSame( 'DE', InputGatherer::known_country( 'de' ) );
		$this->assertSame( 'US', InputGatherer::known_country( 'US' ) );
	}

	public function test_a_made_up_code_counts_as_no_country(): void {
		$this->assertSame( '', InputGatherer::known_country( 'ZZ' ) );
		$this->assertSame( '', InputGatherer::known_country( '' ) );
	}
}
