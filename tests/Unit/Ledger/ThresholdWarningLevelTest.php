<?php

namespace VatrightEdd\Tests\Unit\Ledger;

use PHPUnit\Framework\TestCase;
use VatrightEdd\Ledger\ThresholdWarning;

class ThresholdWarningLevelTest extends TestCase {

	public function test_below_the_share_there_is_no_level(): void {
		$this->assertNull( ThresholdWarning::level( 0.0, 10000.0, 80 ) );
		$this->assertNull( ThresholdWarning::level( 7999.99, 10000.0, 80 ) );
	}

	public function test_the_share_itself_is_the_warning_level(): void {
		$this->assertSame( ThresholdWarning::WARNING, ThresholdWarning::level( 8000.0, 10000.0, 80 ) );
		$this->assertSame( ThresholdWarning::WARNING, ThresholdWarning::level( 9999.99, 10000.0, 80 ) );
	}

	public function test_only_more_than_the_threshold_is_the_limit_level(): void {
		$this->assertSame( ThresholdWarning::WARNING, ThresholdWarning::level( 10000.0, 10000.0, 80 ), 'Exactly the threshold has not passed it.' );
		$this->assertSame( ThresholdWarning::LIMIT, ThresholdWarning::level( 10000.01, 10000.0, 80 ) );
		$this->assertSame( ThresholdWarning::LIMIT, ThresholdWarning::level( 25000.0, 10000.0, 80 ) );
	}

	public function test_a_different_share_and_threshold_are_honoured(): void {
		$this->assertNull( ThresholdWarning::level( 4999.0, 10000.0, 50 ) );
		$this->assertSame( ThresholdWarning::WARNING, ThresholdWarning::level( 5000.0, 10000.0, 50 ) );
		$this->assertSame( ThresholdWarning::WARNING, ThresholdWarning::level( 1800.0, 2000.0, 90 ) );
	}

	public function test_amounts_are_compared_to_the_cent(): void {
		$this->assertSame( ThresholdWarning::WARNING, ThresholdWarning::level( 7999.996, 10000.0, 80 ) );
	}
}
