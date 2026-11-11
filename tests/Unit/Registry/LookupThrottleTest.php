<?php

namespace VatrightEdd\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use VatrightEdd\Registry\LookupThrottle;

require_once dirname( __DIR__, 2 ) . '/Support/TransientStub.php';

class LookupThrottleTest extends TestCase {

	protected function setUp(): void {
		vatright_edd_test_reset_transients();
		$GLOBALS['vatright_edd_filter_overrides'] = array(
			'vatright_edd_global_lookup_limit'      => array( 30, HOUR_IN_SECONDS ),
			'vatright_edd_global_free_lookup_limit' => array( 20, HOUR_IN_SECONDS ),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['vatright_edd_filter_overrides'] );
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	private function allow_from( string $ip, string $bucket = 'checkout' ): bool {
		$_SERVER['REMOTE_ADDR'] = $ip;
		return ( new LookupThrottle() )->allow( $bucket );
	}

	public function test_a_few_clients_cannot_lock_out_a_new_visitors_first_lookups(): void {
		foreach ( array( '203.0.113.1', '203.0.113.2', '203.0.113.3', '203.0.113.4', '203.0.113.5' ) as $attacker ) {
			for ( $i = 0; $i < 10; $i++ ) {
				$this->allow_from( $attacker );
			}
		}
		$this->assertGreaterThanOrEqual( 30, $GLOBALS['vatright_edd_transients']['vatright_edd_t_global_checkout']['count'] );

		$this->assertTrue( $this->allow_from( '198.51.100.7' ) );
		$this->assertTrue( $this->allow_from( '198.51.100.7' ) );
	}

	public function test_the_site_wide_cap_still_stops_heavy_clients(): void {
		foreach ( array( '203.0.113.1', '203.0.113.2', '203.0.113.3', '203.0.113.4', '203.0.113.5', '203.0.113.6' ) as $attacker ) {
			for ( $i = 0; $i < 10; $i++ ) {
				$this->allow_from( $attacker );
			}
		}
		$this->assertTrue( $this->allow_from( '198.51.100.7' ) );
		$this->assertTrue( $this->allow_from( '198.51.100.7' ) );
		$this->assertFalse( $this->allow_from( '198.51.100.7' ) );
	}

	public function test_a_single_client_is_capped_per_window(): void {
		$allowed = 0;
		for ( $i = 0; $i < 15; $i++ ) {
			$allowed += $this->allow_from( '203.0.113.9' ) ? 1 : 0;
		}
		$this->assertSame( 10, $allowed );
	}

	public function test_many_clients_using_their_free_lookups_stay_under_the_absolute_cap(): void {
		$allowed = 0;
		for ( $i = 1; $i <= 100; $i++ ) {
			for ( $j = 0; $j < 2; $j++ ) {
				$allowed += $this->allow_from( '192.0.' . $i . '.1' ) ? 1 : 0;
			}
		}
		$this->assertSame( 20, $allowed );
		$this->assertLessThanOrEqual( 20 + 30, count( $GLOBALS['vatright_edd_transients'] ) );
	}

	public function test_one_client_hammering_leaves_the_reserve_for_others(): void {
		for ( $i = 0; $i < 50; $i++ ) {
			$this->allow_from( '203.0.113.1' );
		}
		$this->assertSame( 2, $GLOBALS['vatright_edd_transients']['vatright_edd_t_global_free_checkout']['count'] );
		$this->assertTrue( $this->allow_from( '198.51.100.7' ) );
	}

	public function test_one_ipv6_48_rotating_its_56s_cannot_lock_out_other_networks(): void {
		for ( $i = 0; $i < 60; $i++ ) {
			$subnet = sprintf( '2001:db8:1:%x00::1', $i );
			for ( $j = 0; $j < 10; $j++ ) {
				$this->allow_from( $subnet );
			}
		}

		$this->assertTrue( $this->allow_from( '198.51.100.7' ) );
		$this->assertTrue( $this->allow_from( '2001:db8:2::1' ) );
	}

	public function test_one_ipv4_24_rotating_its_addresses_cannot_lock_out_other_networks(): void {
		for ( $i = 1; $i <= 200; $i++ ) {
			$this->allow_from( '203.0.113.' . $i );
			$this->allow_from( '203.0.113.' . $i );
		}

		$this->assertSame( 6, $GLOBALS['vatright_edd_transients']['vatright_edd_t_global_free_checkout']['count'] );
		$this->assertTrue( $this->allow_from( '198.51.100.7' ) );
	}
}
