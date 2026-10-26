<?php

namespace VatrightEdd\Tests\Integration\Rates;

use VatrightEdd\Ledger\Schema;
use VatrightEdd\Rates\RatesScreen;
use VatrightEdd\Rates\RateSync;
use VatrightEdd\Rates\Tedb;
use VatrightEdd\Tests\Support\FixtureTransport;
use VatrightEdd\Tests\Support\IntegrationTestCase;
use WPDieException;

require_once dirname( __DIR__, 2 ) . '/Support/FixtureTransport.php';

class RatesScreenTest extends IntegrationTestCase {

	/** @var RatesScreen */
	private $screen;

	public function set_up() {
		parent::set_up();
		Schema::install();
		foreach ( edd_get_tax_rates() as $rate ) {
			edd_delete_tax_rate( $rate['id'] );
		}
		delete_option( RateSync::OPTION_LATEST );
		delete_option( RateSync::OPTION_PENDING );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->screen = new RatesScreen( new RateSync( new Tedb( new FixtureTransport( sys_get_temp_dir() ) ) ) );
	}

	public function tear_down() {
		unset( $_GET['vatright_edd_applied'], $_GET['vatright_edd_refreshed'], $_POST['vatright_edd_rate_keys'], $_REQUEST['_wpnonce'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	private function notices( string $hook ): string {
		ob_start();
		$this->screen->$hook();
		return (string) ob_get_clean();
	}

	public function test_the_screen_is_added_under_downloads_for_shop_managers(): void {
		global $submenu;
		$this->screen->add_menu();

		$slugs = wp_list_pluck( (array) ( $submenu['edit.php?post_type=download'] ?? array() ), 2 );
		$this->assertContains( RatesScreen::PAGE, $slugs );
	}

	public function test_edd_header_gets_a_readable_title_for_the_screen_only(): void {
		$this->assertSame( 'EU VAT rates', $this->screen->page_title( 'Vatright-edd', RatesScreen::PAGE ) );
		$this->assertSame( 'Settings', $this->screen->page_title( 'Settings', 'edd-settings' ) );
	}

	public function test_render_lists_every_country_with_a_nonce_and_escaped_output(): void {
		ob_start();
		$this->screen->render();
		$html = (string) ob_get_clean();

		$this->assertSame( 27, substr_count( $html, 'name="vatright_edd_rate_keys[]"' ) );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'value="vatright_edd_rates_apply"', $html );
	}

	public function test_render_is_empty_without_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		$this->screen->render();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_apply_without_a_nonce_is_refused(): void {
		$_POST['vatright_edd_rate_keys'] = array( 'DE|' );

		$this->expectException( WPDieException::class );
		$this->screen->handle_apply();
	}

	public function test_refresh_without_a_nonce_is_refused(): void {
		$this->expectException( WPDieException::class );
		$this->screen->handle_refresh();
	}

	public function test_apply_without_the_capability_is_refused_even_with_a_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_REQUEST['_wpnonce']            = wp_create_nonce( RatesScreen::NONCE );
		$_POST['vatright_edd_rate_keys'] = array( 'DE|' );

		$this->expectException( WPDieException::class );
		$this->screen->handle_apply();
	}

	public function test_results_show_only_on_the_rates_screen(): void {
		$_GET['vatright_edd_applied'] = '3';

		set_current_screen( 'dashboard' );
		$this->assertSame( '', $this->notices( 'result_message' ) );

		set_current_screen( 'download_page_' . RatesScreen::PAGE );
		$this->assertStringContainsString( '3 VAT rates applied.', $this->notices( 'result_message' ) );
	}

	public function test_applying_nothing_says_so(): void {
		$_GET['vatright_edd_applied'] = '0';
		set_current_screen( 'download_page_' . RatesScreen::PAGE );

		$this->assertStringContainsString( 'No VAT rates were changed.', $this->notices( 'result_message' ) );
	}

	public function test_a_failed_refresh_shows_an_error(): void {
		$_GET['vatright_edd_refreshed'] = '0';
		set_current_screen( 'download_page_' . RatesScreen::PAGE );

		$this->assertStringContainsString( 'notice-error', $this->notices( 'result_message' ) );
	}

	public function test_pending_notice_shows_on_edd_settings_when_changes_wait(): void {
		update_option( RateSync::OPTION_PENDING, 2, false );
		set_current_screen( 'download_page_edd-settings' );

		$html = $this->notices( 'pending_notice' );
		$this->assertStringContainsString( '2 VAT rate changes available.', $html );
		$this->assertStringContainsString( 'page=' . RatesScreen::PAGE, $html );
	}

	public function test_no_pending_notice_when_nothing_waits_or_off_screen(): void {
		set_current_screen( 'download_page_edd-settings' );
		$this->assertSame( '', $this->notices( 'pending_notice' ) );

		update_option( RateSync::OPTION_PENDING, 2, false );
		set_current_screen( 'dashboard' );
		$this->assertSame( '', $this->notices( 'pending_notice' ) );
	}
}
