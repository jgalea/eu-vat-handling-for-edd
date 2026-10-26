<?php

namespace VatrightEdd\Tests\Integration\Import;

use EDD\Orders\Order;
use VatrightEdd\Import\Barn2History;
use VatrightEdd\Import\Barn2Settings;
use VatrightEdd\Import\ImportRunner;
use VatrightEdd\Import\Importer;
use VatrightEdd\Import\ImportScreen;
use VatrightEdd\Import\OssAdvice;
use VatrightEdd\Ledger\Fx;
use VatrightEdd\Ledger\Schema;
use VatrightEdd\Ledger\ThresholdTracker;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Settings;
use VatrightEdd\Tests\Support\LedgerTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/LedgerTestCase.php';

class OssAdviceTest extends LedgerTestCase {

	const SINCE = '2025-01-01';

	const BARN2 = array( '_edd_payment_vat_is_eu' => '1' );

	/** @var Importer */
	private $importer;

	/** @var ImportRunner */
	private $runner;

	public function set_up() {
		parent::set_up();
		$barn2          = new Barn2Settings();
		$this->importer = new Importer( $this->backfill, new Barn2History( $this->backfill ), $this->ledger, new Fx( $this->ecb ) );
		$this->runner   = new ImportRunner( $barn2, $this->importer );
		delete_option( Importer::OPTION );
		delete_option( Barn2Settings::REPORT_OPTION );
		edd_delete_option( Barn2Settings::COUNTRY_OPTION );
		$_POST    = array();
		$_REQUEST = array();
	}

	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();
		remove_all_filters( 'wp_redirect' );
		edd_delete_option( Barn2Settings::COUNTRY_OPTION );
		parent::tear_down();
	}

	public function test_a_barn2_order_charged_the_destination_rate_is_flagged_by_the_dry_run_and_the_run(): void {
		$this->place( 'DE', '2025-09-02 10:00:00', true );

		$plan = $this->importer->dry_run( $this->since() );
		$this->assertTrue( $plan['destination'] );

		$state = $this->importer->run_all( $this->since() );
		$this->assertTrue( $state['destination'] );
		$this->assertTrue( (bool) Importer::state()['destination'] );
	}

	public function test_orders_that_were_not_charged_destination_vat_to_a_consumer_do_not_flag(): void {
		$this->place( 'DE', '2025-09-02 10:00:00', false );
		$this->place( 'MT', '2025-09-03 10:00:00', true );
		$this->place( 'US', '2025-09-04 10:00:00', true );
		$this->place( 'DE', '2025-09-05 10:00:00', true, self::VALID_DE, array( '_edd_payment_vat_number' => self::VALID_DE ) );

		$this->assertFalse( $this->importer->dry_run( $this->since() )['destination'] );
		$this->assertFalse( $this->importer->run_all( $this->since() )['destination'] );
	}

	public function test_the_flag_is_not_a_constant_it_follows_the_orders(): void {
		$this->place( 'DE', '2025-09-02 10:00:00', false );
		$this->assertFalse( $this->importer->dry_run( $this->since() )['destination'] );

		$this->place( 'FR', '2025-09-12 10:00:00', true );
		$this->assertTrue( $this->importer->dry_run( $this->since() )['destination'] );
	}

	public function test_an_order_without_barn2_meta_is_not_evidence(): void {
		$this->place( 'DE', '2025-09-02 10:00:00', true, null, array(), false );

		$this->assertFalse( $this->importer->dry_run( $this->since() )['destination'] );
	}

	public function test_orders_before_the_window_are_not_evidence(): void {
		$this->place( 'DE', '2024-05-02 10:00:00', true );

		$this->assertFalse( $this->importer->dry_run( $this->since() )['destination'] );
	}

	public function test_a_second_run_still_recommends(): void {
		$this->place( 'DE', '2025-09-02 10:00:00', true );
		$this->importer->run_all( $this->since() );

		$this->assertTrue( $this->importer->dry_run( $this->since() )['destination'] );
		$this->assertTrue( $this->importer->run_all( $this->since() )['destination'] );
		$this->assertNotSame( array(), $this->recommendation( $this->runner->execute( array( 'since' => self::SINCE ) ) ) );
	}

	public function test_the_command_recommends_in_a_dry_run_and_writes_nothing(): void {
		$this->place( 'DE', '2025-09-02 10:00:00', true );

		$lines = $this->runner->execute(
			array(
				'dry-run' => true,
				'since'   => self::SINCE,
			)
		);

		$this->assertCount( 1, $this->recommendation( $lines ) );
		$this->assertStringContainsString( '--oss-registered', implode( "\n", $lines ) );
		$this->assertFalse( Settings::get()->oss_registered() );
		$this->assertNull( Importer::state() );
	}

	public function test_the_command_recommends_after_a_real_run_without_switching_it_on(): void {
		$this->place( 'DE', '2025-09-02 10:00:00', true );

		$lines = $this->runner->execute( array( 'since' => self::SINCE ) );

		$this->assertCount( 1, $this->recommendation( $lines ) );
		$this->assertFalse( Settings::get()->oss_registered(), 'The import switched OSS on by itself.' );
		$this->assertNotNull( DecisionStore::get( (int) $this->first_order_id() ) );
	}

	public function test_the_flag_turns_the_setting_on_in_a_real_run_and_not_in_a_dry_run(): void {
		$this->place( 'DE', '2025-09-02 10:00:00', true );

		$dry = $this->runner->execute(
			array(
				'dry-run'        => true,
				'oss-registered' => true,
				'since'          => self::SINCE,
			)
		);
		$this->assertFalse( Settings::get()->oss_registered(), 'A dry run switched OSS on.' );
		$this->assertStringContainsString( 'would turn on', implode( "\n", $dry ) );

		$real = $this->runner->execute(
			array(
				'oss-registered' => true,
				'since'          => self::SINCE,
			)
		);
		$this->assertTrue( Settings::get()->oss_registered() );
		$this->assertSame( array(), $this->recommendation( $real ), 'A store that just switched OSS on is not asked to.' );
		$this->assertStringContainsString( 'OSS', implode( "\n", $real ) );
	}

	public function test_the_flag_works_without_any_signal_and_keeps_other_settings(): void {
		update_option( Settings::OPTION, array( 'seller_company' => 'Kept Ltd' ) );

		$this->runner->execute(
			array(
				'oss-registered' => true,
				'settings-only'  => true,
			)
		);

		$this->assertTrue( Settings::get()->oss_registered() );
		$this->assertSame( 'Kept Ltd', Settings::get()->text( 'seller_company' ) );
	}

	public function test_a_store_already_registered_gets_no_recommendation(): void {
		$this->place( 'DE', '2025-09-02 10:00:00', true );
		update_option( Settings::OPTION, array( 'oss_registered' => true ) );

		$lines = $this->runner->execute(
			array(
				'dry-run' => true,
				'since'   => self::SINCE,
			)
		);

		$this->assertSame( array(), $this->recommendation( $lines ) );
	}

	public function test_a_history_with_no_destination_charges_gets_no_recommendation(): void {
		$this->place( 'DE', '2025-09-02 10:00:00', false );

		$lines = $this->runner->execute(
			array(
				'dry-run' => true,
				'since'   => self::SINCE,
			)
		);

		$this->assertSame( array(), $this->recommendation( $lines ) );
	}

	public function test_barn2s_eu_moss_number_country_is_a_reason_even_without_orders(): void {
		edd_update_option( Barn2Settings::COUNTRY_OPTION, 'EU' );

		$lines = $this->runner->execute(
			array(
				'dry-run'       => true,
				'settings-only' => true,
			)
		);
		$this->assertCount( 1, $this->recommendation( $lines ) );

		edd_update_option( Barn2Settings::COUNTRY_OPTION, 'MT' );
		$lines = $this->runner->execute(
			array(
				'dry-run'       => true,
				'settings-only' => true,
			)
		);
		$this->assertSame( array(), $this->recommendation( $lines ), 'A country that is not the EU MOSS entry is no signal.' );
	}

	public function test_every_message_names_the_setting_and_the_threshold(): void {
		foreach ( array( OssAdvice::SETTING, OssAdvice::ORDERS ) as $reason ) {
			$message = OssAdvice::message( $reason );
			$this->assertStringContainsString( 'One Stop Shop', $message );
			$this->assertStringContainsString( '10,000', $message );
		}
		$this->assertNotSame( OssAdvice::message( OssAdvice::SETTING ), OssAdvice::message( OssAdvice::ORDERS ) );
	}

	public function test_the_dry_run_screen_shows_the_notice_with_a_one_click_control_and_a_link(): void {
		$this->place( 'DE', '2025-09-02 10:00:00', true );
		set_transient(
			ImportScreen::DRY_TRANSIENT,
			array(
				'since'    => self::SINCE,
				'settings' => null,
				'history'  => $this->importer->dry_run( $this->since() ),
				'oss'      => OssAdvice::ORDERS,
			),
			HOUR_IN_SECONDS
		);

		$html = $this->render();

		$this->assertStringContainsString( 'One Stop Shop', $html );
		$this->assertStringContainsString( 'value="vatright_edd_import_oss"', $html );
		$this->assertStringContainsString( 'section=', $html, 'The notice links to the setting.' );
		$this->assertFalse( Settings::get()->oss_registered() );
	}

	public function test_the_screen_shows_the_notice_after_a_real_run(): void {
		$this->place( 'DE', '2025-09-02 10:00:00', true );
		$this->importer->run_all( $this->since() );

		$html = $this->render();

		$this->assertStringContainsString( 'value="vatright_edd_import_oss"', $html );
		$this->assertFalse( Settings::get()->oss_registered() );
	}

	public function test_the_screen_shows_no_notice_without_a_signal_or_once_registered(): void {
		$this->place( 'DE', '2025-09-02 10:00:00', false );
		$this->importer->run_all( $this->since() );
		$this->assertStringNotContainsString( 'vatright_edd_import_oss', $this->render(), 'No signal, no notice.' );

		delete_option( Importer::OPTION );
		$this->place( 'FR', '2025-09-12 10:00:00', true );
		$this->importer->run_all( $this->since() );
		$this->assertStringContainsString( 'vatright_edd_import_oss', $this->render(), 'Control: the notice appears with a signal.' );

		update_option( Settings::OPTION, array( 'oss_registered' => true ) );
		$this->assertStringNotContainsString( 'vatright_edd_import_oss', $this->render() );
	}

	public function test_the_one_click_control_sets_the_setting_for_an_authorised_request_only(): void {
		$screen = $this->screen();
		add_filter(
			'wp_redirect',
			static function (): void {
				throw new \RuntimeException( 'redirected' );
			}
		);

		try {
			$screen->handle_enable_oss();
			$this->fail( 'A request without a nonce was accepted.' );
		} catch ( \WPDieException $e ) {
			$this->assertFalse( Settings::get()->oss_registered() );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( ImportScreen::NONCE );
		try {
			$screen->handle_enable_oss();
			$this->fail( 'A subscriber switched OSS on.' );
		} catch ( \WPDieException $e ) {
			$this->assertFalse( Settings::get()->oss_registered() );
		}

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $admin )->add_cap( 'manage_shop_settings' );
		wp_set_current_user( $admin );
		$_REQUEST['_wpnonce'] = wp_create_nonce( ImportScreen::NONCE );
		try {
			$screen->handle_enable_oss();
			$this->fail( 'Expected a redirect.' );
		} catch ( \RuntimeException $e ) {
			$this->assertTrue( Settings::get()->oss_registered() );
		}
	}

	private function screen(): ImportScreen {
		return new ImportScreen( new Barn2Settings(), $this->importer );
	}

	private function render(): string {
		ob_start();
		$this->screen()->render();
		return (string) ob_get_clean();
	}

	/**
	 * @param string[] $lines
	 * @return string[]
	 */
	private function recommendation( array $lines ): array {
		return array_values(
			array_filter(
				$lines,
				static function ( string $line ): bool {
					return 0 === strpos( $line, 'Recommendation:' );
				}
			)
		);
	}

	private function since(): \DateTimeImmutable {
		return new \DateTimeImmutable( self::SINCE . ' 00:00:00', wp_timezone() );
	}

	/** @var int[] */
	private $placed = array();

	private function first_order_id(): int {
		return (int) $this->placed[0];
	}

	/**
	 * Places an order through the checkout and leaves it as Barn2 would have:
	 * no plugin data, Barn2's meta. $destination charges the customer's rate
	 * (OSS on at checkout); otherwise the store's.
	 *
	 * @param array<string,string> $extra_meta
	 */
	private function place( string $country, string $when, bool $destination, ?string $number = null, array $extra_meta = array(), bool $barn2 = true ): Order {
		global $wpdb;
		$this->settings( array( 'oss_registered' => $destination ) );
		edd_empty_cart();
		$this->buy( $this->download(), $country );
		if ( null !== $number ) {
			$this->enter_number( $number, $country );
		}
		$order = $this->place_order( $country );
		$this->dated( $order, $when );
		$this->complete( $order );
		$wpdb->delete( Schema::ledger_table(), array( 'order_id' => (int) $order->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( array( DecisionStore::META, DecisionStore::LOG_META, DecisionStore::NUMBER_META, DecisionStore::REVERSE_CHARGED_META ) as $key ) {
			edd_delete_order_meta( (int) $order->id, $key );
		}
		foreach ( array_merge( $barn2 ? self::BARN2 : array(), $extra_meta ) as $key => $value ) {
			edd_update_order_meta( (int) $order->id, $key, $value );
		}
		ThresholdTracker::flush( 2024, 2025, 2026 );
		$this->settings( array() );
		$this->placed[] = (int) $order->id;
		return $this->fresh( $order );
	}
}
