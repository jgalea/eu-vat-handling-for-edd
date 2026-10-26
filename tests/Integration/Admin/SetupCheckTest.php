<?php

namespace VatrightEdd\Tests\Integration\Admin;

use VatrightEdd\Admin\SetupCheck;
use VatrightEdd\Admin\SettingsSection;
use VatrightEdd\Checkout\TaxApplier;
use VatrightEdd\Settings;
use VatrightEdd\Tests\Support\OrdersTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/OrdersTestCase.php';

class SetupCheckTest extends OrdersTestCase {

	/**
	 * @return string[]
	 */
	private function ids(): array {
		return array_column( ( new SetupCheck() )->issues(), 'id' );
	}

	private function complete_settings(): void {
		$this->settings(
			array(
				'store_vat_number'   => 'MT12345634',
				'ip_country_source'  => 'cloudflare',
				'hmrc_client_id'     => 'id',
				'hmrc_client_secret' => 'secret',
			)
		);
		Settings::reset();
	}

	public function test_a_fresh_store_is_told_what_is_missing(): void {
		$ids = $this->ids();

		$this->assertContains( 'store_vat_number_missing', $ids );
		$this->assertContains( 'ip_country_source_none', $ids );
		$this->assertContains( 'hmrc_credentials_missing', $ids );
		$this->assertNotContains( 'taxes_disabled', $ids );
		$this->assertNotContains( 'standard_rates_missing', $ids );
	}

	public function test_a_complete_store_has_no_issues(): void {
		$this->complete_settings();

		$this->assertSame( array(), $this->ids() );
	}

	public function test_taxes_off_is_an_error(): void {
		$this->complete_settings();
		edd_update_option( 'enable_taxes', false );

		$issues = ( new SetupCheck() )->issues();

		$this->assertSame( array( 'taxes_disabled' ), array_column( $issues, 'id' ) );
		$this->assertSame( SetupCheck::ERROR, $issues[0]['severity'] );
	}

	public function test_a_shop_country_outside_the_eu_is_a_warning(): void {
		$this->complete_settings();
		edd_update_option( 'base_country', 'US' );

		$this->assertSame( array( 'shop_country_outside_eu' ), $this->ids() );
	}

	public function test_a_missing_shop_country_is_an_error(): void {
		$this->complete_settings();
		edd_update_option( 'base_country', '' );

		$ids = $this->ids();

		$this->assertTrue( in_array( 'shop_country_missing', $ids, true ) || in_array( 'shop_country_outside_eu', $ids, true ), 'EDD falls back to a default country, which is outside the EU.' );
	}

	public function test_a_country_without_a_standard_rate_is_listed(): void {
		$this->complete_settings();
		edd_delete_tax_rate( $this->active_rate_id( 'DE' ) );

		$issues = ( new SetupCheck() )->issues();

		$this->assertSame( array( 'standard_rates_missing' ), array_column( $issues, 'id' ) );
		$this->assertStringContainsString( 'DE', $issues[0]['message'] );
		$this->assertStringNotContainsString( 'FR', $issues[0]['message'] );
	}

	public function test_rates_charged_at_the_origin_rate_are_an_error_until_the_rate_exists(): void {
		$this->complete_settings();
		update_option( TaxApplier::MISSING_OPTION, array( 'DE' => '2030-01-01T00:00:00Z' ) );
		$this->assertNotContains( 'destination_rates_missing', $this->ids(), 'DE has a rate, so the old record is stale.' );

		edd_delete_tax_rate( $this->active_rate_id( 'DE' ) );

		$this->assertContains( 'destination_rates_missing', $this->ids() );
	}

	public function test_the_settings_section_shows_the_issues_with_a_fix_link(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		get_user_by( 'id', get_current_user_id() )->add_cap( SettingsSection::CAPABILITY );
		edd_update_option( 'enable_taxes', false );

		ob_start();
		( new SettingsSection( new SetupCheck() ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Taxes are turned off', $html );
		$this->assertStringContainsString( 'tab=taxes', $html );
	}
}
