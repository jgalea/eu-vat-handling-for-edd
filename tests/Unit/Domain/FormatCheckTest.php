<?php

namespace VatrightEdd\Tests\Unit\Domain;

use VatrightEdd\Domain\FormatCheck;
use VatrightEdd\Domain\VatNumber;
use PHPUnit\Framework\TestCase;

class FormatCheckTest extends TestCase {

	private function plausible( string $canonical ): bool {
		$number = VatNumber::parse( $canonical, substr( $canonical, 0, 2 ) );
		$this->assertNotNull( $number, $canonical );
		return FormatCheck::is_plausible( $number );
	}

	/**
	 * Numbers published by the companies themselves (imprints, filings). Each
	 * checksum algorithm has at least two entries here.
	 *
	 * @return array<string,array{string}>
	 */
	public function real_numbers(): array {
		$numbers = array(
			'DE Deutsche Telekom'  => 'DE123475223',
			'DE Deutsche Bank'     => 'DE114103379',
			'DE BMW'               => 'DE129273398',
			'DE Volkswagen'        => 'DE115235681',
			'IT Ferrari'           => 'IT00159560366',
			'IT Eni'               => 'IT00905811006',
			'IT Motorola'          => 'IT00743110157',
			'ES Inditex'           => 'ESA15075062',
			'ES Telefonica'        => 'ESA82018474',
			'ES Santander'         => 'ESA39000013',
			'NL ABN AMRO'          => 'NL820646660B01',
			'NL youth association' => 'NL004495445B01',
			'NL Booking'           => 'NL805734958B01',
			'BE AB InBev'          => 'BE0417497106',
			'BE Proximus'          => 'BE0202239951',
			'PL PKN Orlen'         => 'PL7740001454',
			'PL CD Projekt'        => 'PL7342867148',
			'PT EDP'               => 'PT500697256',
			'PT Galp'              => 'PT504499777',
			'GB HMRC'              => 'GB980780684',
			'GB Amazon UK'         => 'GB727255821',
			'GB third'             => 'GB220430231',
			'NO Equinor'           => 'NO923609016',
			'NO DNB'               => 'NO984851006',
			'NO Telenor'           => 'NO982463718',
			'CH UBS'               => 'CHE101329561',
			'CH Nestle'            => 'CHE105909036',
			'CH Novartis'          => 'CHE103867266',
		);
		$rows    = array();
		foreach ( $numbers as $label => $number ) {
			$rows[ $label ] = array( $number );
		}
		return $rows;
	}

	/**
	 * @dataProvider real_numbers
	 */
	public function test_real_numbers_are_plausible( string $canonical ): void {
		$this->assertTrue( $this->plausible( $canonical ), $canonical );
	}

	/**
	 * Each is a real number above with one digit changed.
	 *
	 * @return array<string,array{string}>
	 */
	public function one_digit_off(): array {
		$numbers = array(
			'DE'  => 'DE123475224',
			'IT'  => 'IT00159560367',
			'ES'  => 'ESA15075063',
			'NL'  => 'NL820646661B01',
			'BE'  => 'BE0417497107',
			'PL'  => 'PL7740001455',
			'PT'  => 'PT500697257',
			'GB'  => 'GB980780685',
			'NO'  => 'NO923609017',
			'CHE' => 'CHE101329562',
		);
		$rows    = array();
		foreach ( $numbers as $label => $number ) {
			$rows[ $label ] = array( $number );
		}
		return $rows;
	}

	/**
	 * @dataProvider one_digit_off
	 */
	public function test_one_digit_off_is_rejected( string $canonical ): void {
		$this->assertFalse( $this->plausible( $canonical ), $canonical );
	}

	public function test_dutch_sole_proprietor_series_uses_mod_97(): void {
		$this->assertTrue( $this->plausible( 'NL123456789B13' ) );
		$this->assertFalse( $this->plausible( 'NL123456789B14' ) );
	}

	public function test_gb_newer_series_variant_is_accepted(): void {
		$this->assertTrue( $this->plausible( 'GB100000034' ) );
	}

	public function test_xi_numbers_are_format_checked_only_by_pattern(): void {
		$this->assertTrue( $this->plausible( 'XI GD001' ) );
		$this->assertFalse( $this->plausible( 'XI12345678' ) );
	}

	public function test_xi_numbers_are_not_rejected_on_the_gb_checksum(): void {
		$this->assertFalse( $this->plausible( 'GB980780685' ), 'Control: the GB checksum rejects these digits' );
		$this->assertTrue( $this->plausible( 'XI980780685' ) );
	}

	public function test_gb_government_and_health_ranges(): void {
		$this->assertTrue( $this->plausible( 'GBGD001' ) );
		$this->assertFalse( $this->plausible( 'GBGD500' ) );
		$this->assertTrue( $this->plausible( 'GBHA500' ) );
		$this->assertFalse( $this->plausible( 'GBHA499' ) );
	}

	public function test_gb_branch_number_checks_first_nine_digits(): void {
		$this->assertTrue( $this->plausible( 'GB980780684001' ) );
		$this->assertFalse( $this->plausible( 'GB980780685001' ) );
	}

	public function test_spanish_individual_numbers_are_regex_only(): void {
		$this->assertTrue( $this->plausible( 'ES12345678Z' ) );
		$this->assertTrue( $this->plausible( 'ES12345678A' ) );
		$this->assertTrue( $this->plausible( 'ESX1234567L' ) );
	}

	/**
	 * Countries without a verified checksum accept any well-formed number.
	 *
	 * @return array<string,array{string}>
	 */
	public function regex_only_numbers(): array {
		return array(
			'AT'         => array( 'ATU13585627' ),
			'BG'         => array( 'BG123456789' ),
			'BG10'       => array( 'BG1234567890' ),
			'CY'         => array( 'CY10259033P' ),
			'CZ'         => array( 'CZ12345678' ),
			'DK'         => array( 'DK12345678' ),
			'EE'         => array( 'EE123456789' ),
			'EL'         => array( 'GR123456789' ),
			'FI'         => array( 'FI12345678' ),
			'FR'         => array( 'FR12345678901' ),
			'FR letters' => array( 'FRAB123456789' ),
			'HR'         => array( 'HR12345678901' ),
			'HU'         => array( 'HU12345678' ),
			'IE'         => array( 'IE6388047V' ),
			'IE old'     => array( 'IE1A23456B' ),
			'LT9'        => array( 'LT123456789' ),
			'LT12'       => array( 'LT123456789012' ),
			'LU'         => array( 'LU12345678' ),
			'LV'         => array( 'LV12345678901' ),
			'MT'         => array( 'MT12345678' ),
			'RO'         => array( 'RO12' ),
			'SE'         => array( 'SE123456789001' ),
			'SI'         => array( 'SI12345678' ),
			'SK'         => array( 'SK1234567890' ),
		);
	}

	/**
	 * @dataProvider regex_only_numbers
	 */
	public function test_regex_only_countries_accept_well_formed( string $canonical ): void {
		$this->assertTrue( $this->plausible( $canonical ), $canonical );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function malformed(): array {
		return array(
			'DE too short'     => array( 'DE12345678' ),
			'DE letters'       => array( 'DE12345678A' ),
			'AT missing U'     => array( 'AT12345678' ),
			'NL missing B'     => array( 'NL820646660001' ),
			'BE starts with 2' => array( 'BE2417497106' ),
			'PL nine digits'   => array( 'PL774000145' ),
			'FR too short'     => array( 'FR1234567890' ),
			'FR forbidden key' => array( 'FRIO123456789' ),
			'HR ten digits'    => array( 'HR1234567890' ),
			'SE ten digits'    => array( 'SE1234567890' ),
			'GB ten digits'    => array( 'GB1234567890' ),
			'CHE eight digits' => array( 'CHE12345678' ),
			'NO ten digits'    => array( 'NO1234567890' ),
			'ES letters only'  => array( 'ESABCDEFGHI' ),
		);
	}

	/**
	 * @dataProvider malformed
	 */
	public function test_malformed_numbers_are_rejected( string $canonical ): void {
		$this->assertFalse( $this->plausible( $canonical ), $canonical );
	}
}
