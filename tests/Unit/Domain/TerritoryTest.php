<?php

namespace VatrightEdd\Tests\Unit\Domain;

use VatrightEdd\Domain\Territory;
use PHPUnit\Framework\TestCase;

class TerritoryTest extends TestCase {

	/**
	 * @return array<string,array{string,string}>
	 */
	public function outside_vat_area(): array {
		return array(
			'Art. 6 Canary Islands, Las Palmas'     => array( 'ES', '35001' ),
			'Art. 6 Canary Islands, Tenerife'       => array( 'ES', '38001' ),
			'Art. 6 Ceuta'                          => array( 'ES', '51001' ),
			'Art. 6 Melilla'                        => array( 'ES', '52001' ),
			'Art. 6 Aland Islands'                  => array( 'FI', '22100' ),
			'Art. 6 Aland Islands, spaced postcode' => array( 'FI', '22 100' ),
			'Art. 6 Helgoland'                      => array( 'DE', '27498' ),
			'Art. 6 Busingen'                       => array( 'DE', '78266' ),
			'Art. 6 Livigno'                        => array( 'IT', '23030' ),
			'Art. 6 Campione d Italia'              => array( 'IT', '22061' ),
			'Art. 6 Mount Athos'                    => array( 'GR', '63086' ),
			'Art. 6 Mount Athos, spaced postcode'   => array( 'GR', '630 86' ),
			'Art. 6 Guadeloupe entered as FR'       => array( 'FR', '97100' ),
			'Art. 6 Martinique entered as FR'       => array( 'FR', '97200' ),
			'Art. 6 French Guiana entered as FR'    => array( 'FR', '97300' ),
			'Art. 6 Reunion entered as FR'          => array( 'FR', '97400' ),
			'Art. 6 Mayotte entered as FR'          => array( 'FR', '97600' ),
			'Art. 7 Isle of Man is GB'              => array( 'IM', 'IM1 1AA' ),
			'non-EU country: United Kingdom'        => array( 'GB', 'SW1A 1AA' ),
			'non-EU country: Switzerland'           => array( 'CH', '8001' ),
			'non-EU country: United States'         => array( 'US', '10001' ),
			'non-EU country: Aland ISO code'        => array( 'AX', '22100' ),
		);
	}

	/**
	 * @dataProvider outside_vat_area
	 */
	public function test_outside_vat_area( string $iso, string $postcode ): void {
		$this->assertFalse( Territory::in_vat_area( $iso, $postcode ) );
	}

	/**
	 * Near-misses: the same country one postcode away, and the Art. 7 cases
	 * treated as part of a Member State.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function inside_vat_area(): array {
		return array(
			'ES Madrid'                       => array( 'ES', '28001' ),
			'ES Barcelona'                    => array( 'ES', '08001' ),
			'ES Salamanca next to 38'         => array( 'ES', '37001' ),
			'ES Cantabria next to 38'         => array( 'ES', '39001' ),
			'ES Pontevedra next to 35'        => array( 'ES', '36001' ),
			'ES Palma'                        => array( 'ES', '07001' ),
			'FI Helsinki'                     => array( 'FI', '00100' ),
			'FI Turku next to Aland'          => array( 'FI', '20100' ),
			'FI Pori next to Aland'           => array( 'FI', '28100' ),
			'DE Cuxhaven'                     => array( 'DE', '27472' ),
			'DE Helgoland neighbour postcode' => array( 'DE', '27499' ),
			'DE Radolfzell'                   => array( 'DE', '78315' ),
			'DE Busingen neighbour postcode'  => array( 'DE', '78267' ),
			'DE Berlin'                       => array( 'DE', '10115' ),
			'IT Sondrio'                      => array( 'IT', '23100' ),
			'IT Livigno neighbour postcode'   => array( 'IT', '23031' ),
			'IT Como'                         => array( 'IT', '22100' ),
			'IT Campione neighbour postcode'  => array( 'IT', '22060' ),
			'IT Rome'                         => array( 'IT', '00184' ),
			'GR Thessaloniki'                 => array( 'GR', '54248' ),
			'GR Athens'                       => array( 'GR', '10431' ),
			'GR Athos neighbour postcode'     => array( 'GR', '63087' ),
			'FR Paris'                        => array( 'FR', '75001' ),
			'FR Corsica'                      => array( 'FR', '20000' ),
			'FR 96 next to 97'                => array( 'FR', '96000' ),
			'MC taxed as FR'                  => array( 'MC', '98000' ),
			'MC taxed as FR, FR 98 postcode'  => array( 'FR', '98000' ),
			'MT Valletta'                     => array( 'MT', 'VLT 1117' ),
			'PT Lisbon'                       => array( 'PT', '1000-001' ),
			'partial ES postcode charges VAT' => array( 'ES', '350' ),
			'empty postcode charges VAT'      => array( 'ES', '' ),
			'lowercase country'               => array( 'de', '10115' ),
		);
	}

	/**
	 * @dataProvider inside_vat_area
	 */
	public function test_inside_vat_area( string $iso, string $postcode ): void {
		$this->assertTrue( Territory::in_vat_area( $iso, $postcode ) );
	}

	public function test_eu_has_27_members_without_duplicates(): void {
		$this->assertCount( 27, Territory::EU );
		$this->assertCount( 27, array_unique( Territory::EU ) );
	}

	public function test_is_eu_member(): void {
		$this->assertTrue( Territory::is_eu_member( 'DE' ) );
		$this->assertTrue( Territory::is_eu_member( 'gr' ) );
		$this->assertFalse( Territory::is_eu_member( 'EL' ) );
		$this->assertFalse( Territory::is_eu_member( 'GB' ) );
		$this->assertFalse( Territory::is_eu_member( 'MC' ) );
		$this->assertFalse( Territory::is_eu_member( 'CH' ) );
	}

	public function test_vat_country(): void {
		$this->assertSame( 'FR', Territory::vat_country( 'MC' ) );
		$this->assertSame( 'GB', Territory::vat_country( 'IM' ) );
		$this->assertSame( 'DE', Territory::vat_country( 'DE' ) );
		$this->assertSame( 'ES', Territory::vat_country( 'es' ) );
	}

	public function test_vat_prefix(): void {
		$this->assertSame( 'EL', Territory::vat_prefix( 'GR' ) );
		$this->assertSame( 'DE', Territory::vat_prefix( 'DE' ) );
		$this->assertSame( 'FR', Territory::vat_prefix( 'fr' ) );
	}
}
