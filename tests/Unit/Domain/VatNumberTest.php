<?php

namespace VatrightEdd\Tests\Unit\Domain;

use VatrightEdd\Domain\VatNumber;
use PHPUnit\Framework\TestCase;

class VatNumberTest extends TestCase {

	/**
	 * @return array<string,array{string,string,string}>
	 */
	public function messy_input(): array {
		return array(
			'lowercase with spaces'             => array( 'de 123 456 789', 'DE', 'DE123456789' ),
			'dots'                              => array( 'DE.123.456.789', 'DE', 'DE123456789' ),
			'dashes'                            => array( 'DE-123-456-789', 'DE', 'DE123456789' ),
			'slashes'                           => array( 'FR 12/345678901', 'FR', 'FR12345678901' ),
			'missing prefix'                    => array( '123456789', 'DE', 'DE123456789' ),
			'typed prefix differs from billing' => array( 'DE.123.456.789', 'FR', 'DE123456789' ),
			'GR typed for Greece'               => array( 'GR123456789', 'GR', 'EL123456789' ),
			'GR typed with foreign billing'     => array( 'GR 123 456 789', 'CY', 'EL123456789' ),
			'EL typed lowercase'                => array( 'el 123 456 789', 'GR', 'EL123456789' ),
			'missing prefix for Greece'         => array( '123456789', 'GR', 'EL123456789' ),
			'XI for Northern Ireland'           => array( 'XI 123 4567 89', 'GB', 'XI123456789' ),
			'GB missing prefix'                 => array( '123 4567 89', 'GB', 'GB123456789' ),
			'Swiss with MWST'                   => array( 'CHE-123.456.789 MWST', 'CH', 'CHE123456789' ),
			'Swiss lowercase TVA'               => array( 'che-123.456.789 tva', 'CH', 'CHE123456789' ),
			'Swiss IVA no prefix'               => array( '123.456.789 IVA', 'CH', 'CHE123456789' ),
			'Swiss CH prefix'                   => array( 'CH123456789', 'CH', 'CHE123456789' ),
			'Norway with MVA'                   => array( 'NO 123456789 MVA', 'NO', 'NO123456789' ),
			'Norway lowercase no prefix'        => array( '923 609 016 mva', 'NO', 'NO923609016' ),
			'Dutch with B suffix spaced'        => array( 'nl 8206.46.660 b01', 'NL', 'NL820646660B01' ),
			'Spanish letter first, no prefix'   => array( 'a-150-750-62', 'ES', 'ESA15075062' ),
			'Irish trailing letter'             => array( 'IE 6388047 V', 'IE', 'IE6388047V' ),
			'Monaco billing uses FR'            => array( '12345678901', 'MC', 'FR12345678901' ),
			'Isle of Man billing uses GB'       => array( '123456789', 'IM', 'GB123456789' ),
			'surrounding whitespace'            => array( "  DE123456789\n", 'DE', 'DE123456789' ),
		);
	}

	/**
	 * @dataProvider messy_input
	 */
	public function test_normalises_messy_input( string $raw, string $billing, string $expected ): void {
		$number = VatNumber::parse( $raw, $billing );
		$this->assertNotNull( $number );
		$this->assertSame( $expected, $number->canonical() );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public function unparseable(): array {
		return array(
			'empty'                         => array( '', 'DE' ),
			'only separators'               => array( ' .-/ ', 'DE' ),
			'prefix only'                   => array( 'DE', 'DE' ),
			'Swiss suffix only'             => array( 'CHE MWST', 'CH' ),
			'no prefix and non-VAT country' => array( '123456789', 'US' ),
		);
	}

	/**
	 * @dataProvider unparseable
	 */
	public function test_returns_null_when_nothing_to_parse( string $raw, string $billing ): void {
		$this->assertNull( VatNumber::parse( $raw, $billing ) );
	}

	public function test_accessors_split_prefix_and_number(): void {
		$number = VatNumber::parse( 'NL820646660B01', 'NL' );
		$this->assertNotNull( $number );
		$this->assertSame( 'NL', $number->prefix() );
		$this->assertSame( 'NL', $number->country() );
		$this->assertSame( '820646660B01', $number->number() );
	}

	/**
	 * @return array<string,array{string,string,string,string,bool}>
	 */
	public function prefix_mapping(): array {
		return array(
			'Greece'           => array( 'EL123456789', 'GR', 'EL', 'GR', true ),
			'Northern Ireland' => array( 'XI123456789', 'GB', 'XI', 'GB', true ),
			'Great Britain'    => array( 'GB123456789', 'GB', 'GB', 'GB', false ),
			'Switzerland'      => array( 'CHE123456789', 'CH', 'CHE', 'CH', false ),
			'Norway'           => array( 'NO123456789', 'NO', 'NO', 'NO', false ),
			'Germany'          => array( 'DE123456789', 'DE', 'DE', 'DE', true ),
		);
	}

	/**
	 * @dataProvider prefix_mapping
	 */
	public function test_prefix_country_and_eu_flag( string $raw, string $billing, string $prefix, string $country, bool $is_eu ): void {
		$number = VatNumber::parse( $raw, $billing );
		$this->assertNotNull( $number );
		$this->assertSame( $prefix, $number->prefix() );
		$this->assertSame( $country, $number->country() );
		$this->assertSame( $is_eu, $number->is_eu() );
	}

	public function test_display_formats_swiss_and_others(): void {
		$swiss = VatNumber::parse( 'CHE123456789', 'CH' );
		$de    = VatNumber::parse( 'DE123456789', 'DE' );
		$this->assertNotNull( $swiss );
		$this->assertNotNull( $de );
		$this->assertSame( 'CHE-123.456.789', $swiss->display() );
		$this->assertSame( 'DE 123456789', $de->display() );
	}
}
