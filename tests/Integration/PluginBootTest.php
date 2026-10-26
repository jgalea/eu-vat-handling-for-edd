<?php

namespace VatrightEdd\Tests\Integration;

use VatrightEdd\Coexistence;
use VatrightEdd\Import\Importer;
use VatrightEdd\Ledger\Schema;
use VatrightEdd\Plugin;
use VatrightEdd\Scheduler;
use VatrightEdd\Tests\Support\IntegrationTestCase;

class PluginBootTest extends IntegrationTestCase {

	public function set_up() {
		parent::set_up();
		remove_action( 'init', array( Scheduler::class, 'ensure' ) );
		remove_action( 'admin_notices', array( Coexistence::class, 'notice' ) );
		remove_action( 'admin_notices', array( Plugin::class, 'missing_edd_notice' ) );
		remove_action( 'admin_notices', array( Coexistence::class, 'edd_vat_notice' ) );
	}

	public function tear_down() {
		edd_update_option( 'vat_enable', false );
		parent::tear_down();
	}

	public function test_edd_is_loaded_in_the_test_site(): void {
		$this->assertTrue( class_exists( 'Easy_Digital_Downloads' ) );
	}

	public function test_boot_registers_hooks_next_to_edd(): void {
		Plugin::boot();
		$this->assertNotFalse( has_action( 'init', array( Scheduler::class, 'ensure' ) ) );
		$this->assertFalse( has_action( 'admin_notices', array( Coexistence::class, 'notice' ) ) );
	}

	public function test_edds_own_vat_on_free_edd_is_an_upsell_and_does_not_stop_the_plugin(): void {
		edd_update_option( 'vat_enable', true );

		$this->assertFalse( edd_is_pro() );
		$this->assertFalse( Coexistence::edd_vat_active() );
	}

	/**
	 * Runs before the Barn2 test, whose stub class cannot be undefined again.
	 */
	public function test_boot_with_edd_pro_vat_on_registers_no_hooks_and_shows_notice(): void {
		add_filter( 'edd_is_pro', '__return_true' );
		edd_update_option( 'vat_enable', true );

		$this->assertTrue( Coexistence::edd_vat_active() );
		$this->assertFalse( Coexistence::barn2_active() );
		Plugin::boot();
		$this->assertFalse( has_action( 'init', array( Scheduler::class, 'ensure' ) ) );
		$this->assertNotFalse( has_action( 'admin_notices', array( Coexistence::class, 'edd_vat_notice' ) ) );

		ob_start();
		Coexistence::edd_vat_notice();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Easy Digital Downloads (Pro)', $html );
	}

	public function test_edd_pro_with_its_vat_off_lets_the_plugin_run(): void {
		add_filter( 'edd_is_pro', '__return_true' );
		edd_update_option( 'vat_enable', false );

		$this->assertFalse( Coexistence::edd_vat_active() );
	}

	public function test_boot_with_barn2_class_registers_no_hooks_and_shows_notice(): void {
		if ( ! class_exists( Coexistence::BARN2_CLASS, false ) ) {
			eval( 'namespace Barn2\Plugin\EDD_VAT; class Plugin {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}

		$this->assertTrue( Coexistence::barn2_active() );
		remove_all_actions( Importer::HOOK );
		remove_all_actions( 'cli_init' );
		remove_action( 'init', array( Schema::class, 'upgrade' ), 1 );
		Plugin::boot();
		$this->assertFalse( has_action( 'init', array( Scheduler::class, 'ensure' ) ) );
		$this->assertNotFalse( has_action( 'admin_notices', array( Coexistence::class, 'notice' ) ) );
		$this->assertTrue( has_action( 'cli_init' ), 'The WP-CLI command is registered while Barn2 is active.' );
		$this->assertTrue( has_action( Importer::HOOK ), 'The import still runs while Barn2 is active.' );
		$this->assertNotFalse( has_action( 'init', array( Schema::class, 'upgrade' ) ), 'The import needs the ledger tables.' );

		ob_start();
		Coexistence::notice();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Barn2 EDD EU VAT', $html );
	}
}
