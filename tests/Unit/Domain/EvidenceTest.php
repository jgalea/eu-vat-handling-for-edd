<?php

namespace VatrightEdd\Tests\Unit\Domain;

use VatrightEdd\Domain\Evidence;
use PHPUnit\Framework\TestCase;

class EvidenceTest extends TestCase {

	public function test_all_pieces_agree(): void {
		$result = ( new Evidence( 'DE', 'DE', 'DE', null ) )->resolve();
		$this->assertSame(
			array(
				'country'  => 'DE',
				'conflict' => false,
				'pieces'   => 3,
			),
			$result
		);
	}

	public function test_two_agreeing_pieces_without_a_third(): void {
		$result = ( new Evidence( 'DE', 'DE', null, null ) )->resolve();
		$this->assertSame( 'DE', $result['country'] );
		$this->assertFalse( $result['conflict'] );
		$this->assertSame( 2, $result['pieces'] );
	}

	public function test_billing_and_ip_disagree_is_a_conflict_resolved_to_billing(): void {
		$result = ( new Evidence( 'DE', null, 'FR', null ) )->resolve();
		$this->assertSame( 'DE', $result['country'] );
		$this->assertTrue( $result['conflict'] );
		$this->assertSame( 1, $result['pieces'] );
	}

	public function test_majority_country_still_reports_the_conflict(): void {
		$result = ( new Evidence( 'DE', 'DE', 'FR', null ) )->resolve();
		$this->assertSame( 'DE', $result['country'] );
		$this->assertTrue( $result['conflict'] );
		$this->assertSame( 2, $result['pieces'] );
	}

	public function test_majority_wins_over_billing(): void {
		$result = ( new Evidence( 'FR', 'DE', 'DE', null ) )->resolve();
		$this->assertSame( 'DE', $result['country'] );
		$this->assertTrue( $result['conflict'] );
		$this->assertSame( 2, $result['pieces'] );
	}

	public function test_self_certification_wins_and_clears_the_conflict(): void {
		$result = ( new Evidence( 'DE', 'DE', 'FR', 'FR' ) )->resolve();
		$this->assertSame( 'FR', $result['country'] );
		$this->assertFalse( $result['conflict'] );
		$this->assertSame( 2, $result['pieces'] );
	}

	public function test_self_certification_against_all_pieces(): void {
		$result = ( new Evidence( 'DE', null, 'DE', 'AT' ) )->resolve();
		$this->assertSame( 'AT', $result['country'] );
		$this->assertFalse( $result['conflict'] );
		$this->assertSame( 1, $result['pieces'] );
	}

	public function test_single_piece(): void {
		$result = ( new Evidence( 'DE', null, null, null ) )->resolve();
		$this->assertSame( 'DE', $result['country'] );
		$this->assertFalse( $result['conflict'] );
		$this->assertSame( 1, $result['pieces'] );
	}

	public function test_no_pieces(): void {
		$result = ( new Evidence( null, null, null, null ) )->resolve();
		$this->assertNull( $result['country'] );
		$this->assertFalse( $result['conflict'] );
		$this->assertSame( 0, $result['pieces'] );
	}

	public function test_missing_billing_with_disagreeing_others_has_no_country(): void {
		$result = ( new Evidence( null, 'DE', 'FR', null ) )->resolve();
		$this->assertNull( $result['country'] );
		$this->assertTrue( $result['conflict'] );
		$this->assertSame( 0, $result['pieces'] );
	}

	public function test_to_array_matches_record_keys(): void {
		$this->assertSame(
			array(
				'billing'        => 'DE',
				'shipping'       => null,
				'ip'             => 'FR',
				'self_certified' => null,
			),
			( new Evidence( 'DE', null, 'FR', null ) )->to_array()
		);
	}
}
