<?php

namespace VatrightEdd\Tests\Unit\Rates;

use VatrightEdd\Rates\RateDiff;
use PHPUnit\Framework\TestCase;

class RateDiffTest extends TestCase {

	/**
	 * @param array<string,array{rate_id:int,current:float,imported:float|null}> $existing
	 */
	private function status_for( float $target_rate, array $existing ): string {
		$target = array(
			'country' => 'DE',
			'class'   => '',
			'type'    => 'standard',
			'rate'    => $target_rate,
		);
		$diff   = RateDiff::build( array( $target ), $existing, 'tedb', '2026-10-17' );
		return $diff->entries()['DE|']['status'];
	}

	public function test_missing_rate_is_an_add(): void {
		$this->assertSame( RateDiff::ADD, $this->status_for( 19.0, array() ) );
	}

	public function test_untouched_imported_rate_with_new_value_is_a_change(): void {
		$existing = array(
			'DE|' => array(
				'rate_id'  => 5,
				'current'  => 16.0,
				'imported' => 16.0,
			),
		);
		$this->assertSame( RateDiff::CHANGE, $this->status_for( 19.0, $existing ) );
	}

	public function test_diff_detects_merchant_edit(): void {
		$existing = array(
			'DE|' => array(
				'rate_id'  => 5,
				'current'  => 20.0,
				'imported' => 16.0,
			),
		);
		$this->assertSame( RateDiff::EDITED, $this->status_for( 19.0, $existing ) );
	}

	public function test_rate_matching_the_new_value_is_unchanged_even_if_edited(): void {
		$existing = array(
			'DE|' => array(
				'rate_id'  => 5,
				'current'  => 19.0,
				'imported' => 16.0,
			),
		);
		$this->assertSame( RateDiff::UNCHANGED, $this->status_for( 19.0, $existing ) );
	}

	public function test_unmanaged_rate_that_differs_counts_as_edited(): void {
		$existing = array(
			'DE|' => array(
				'rate_id'  => 5,
				'current'  => 18.0,
				'imported' => null,
			),
		);
		$this->assertSame( RateDiff::EDITED, $this->status_for( 19.0, $existing ) );
	}

	public function test_pending_count_excludes_unchanged(): void {
		$targets  = array(
			array(
				'country' => 'DE',
				'class'   => '',
				'type'    => 'standard',
				'rate'    => 19.0,
			),
			array(
				'country' => 'FR',
				'class'   => '',
				'type'    => 'standard',
				'rate'    => 20.0,
			),
		);
		$existing = array(
			'FR|' => array(
				'rate_id'  => 6,
				'current'  => 20.0,
				'imported' => 20.0,
			),
		);
		$diff     = RateDiff::build( $targets, $existing, 'tedb', '2026-10-17' );

		$this->assertSame( 1, $diff->pending_count() );
		$this->assertSame( array( 'FR|' ), array_keys( $diff->with_status( RateDiff::UNCHANGED ) ) );
	}
}
