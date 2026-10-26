<?php

namespace VatrightEdd\Tests\Unit;

use PHPUnit\Framework\TestCase;

class DomainPurityTest extends TestCase {

	public function test_domain_directory_exists(): void {
		$this->assertDirectoryExists( dirname( __DIR__, 2 ) . '/src/Domain' );
	}

	public function test_domain_uses_no_wordpress_or_woocommerce_symbols(): void {
		$pattern = '/\b(wp_\w+|WC_\w+|WC\(|get_option|update_option|apply_filters|do_action|add_action|add_filter|__\(|_e\(|esc_\w+)/';
		$found   = array();
		$files   = glob( dirname( __DIR__, 2 ) . '/src/Domain/*.php' );
		foreach ( false === $files ? array() : $files as $file ) {
			if ( preg_match( $pattern, (string) file_get_contents( $file ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$found[] = basename( $file );
			}
		}
		$this->assertSame( array(), $found );
	}

	public function test_purity_check_catches_a_known_bad_file(): void {
		$pattern = '/\b(wp_\w+|WC_\w+|WC\(|get_option|update_option|apply_filters|do_action|add_action|add_filter|__\(|_e\(|esc_\w+)/';
		$this->assertSame( 1, preg_match( $pattern, '<?php $x = get_option( "a" );' ) );
	}
}
