<?php

namespace VatrightEdd\Tests\Integration\Import;

use InvalidArgumentException;
use VatrightEdd\Import\Barn2Settings;
use VatrightEdd\Import\ImportRunner;
use VatrightEdd\Import\Importer;
use VatrightEdd\Import\ImportScreen;
use VatrightEdd\Settings;
use VatrightEdd\Tests\Support\LedgerTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/LedgerTestCase.php';

class Barn2SettingsTest extends LedgerTestCase {

	const SECRET = 'hmrc-secret-7f3a91c0d2b84e56';

	const CLIENT = 'hmrc-client-5d1e88a4c7f20b39';

	const VATSENSE = 'vatsense-key-0b6c4d9e1a7f3825';

	/** @var Barn2Settings */
	private $barn2;

	/** @var string */
	private $log_file;

	/** @var string|false */
	private $previous_log;

	public function set_up() {
		parent::set_up();
		$this->barn2        = new Barn2Settings();
		$this->log_file     = tempnam( sys_get_temp_dir(), 'vatright-edd-log' );
		$this->previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		edd_update_option( 'debug_mode', true );
		EDD()->debug_log->clear_log_file();
		// EDD 3.1 forgets the log path when it clears the file.
		EDD()->debug_log->setup_log_file();
		foreach ( $this->barn2_options() as $key => $value ) {
			edd_update_option( $key, $value );
		}
		delete_option( Barn2Settings::REPORT_OPTION );
		delete_option( Importer::OPTION );
	}

	public function tear_down() {
		ini_set( 'error_log', (string) $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		if ( is_string( $this->log_file ) && file_exists( $this->log_file ) ) {
			unlink( $this->log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		EDD()->debug_log->clear_log_file();
		edd_update_option( 'debug_mode', false );
		parent::tear_down();
	}

	/**
	 * @return array<string,string>
	 */
	private function barn2_options(): array {
		return array(
			'edd_vat_number'                      => 'MT 1234-5678',
			'edd_uk_vat_number'                   => 'GB333289454',
			'edd_vat_vatsense_api_key'            => self::VATSENSE,
			'edd_vat_reverse_charge_base_country' => '1',
			'edd_vat_address_country'             => 'EU',
			'edd_vat_company_name'                => 'Example Ltd',
			'edd_vat_company_email'               => 'accounts@example.com',
			'edd_vat_address_line_1'              => '1 High Street',
			'edd_vat_address_line_2'              => 'Floor 2',
			'edd_vat_address_city'                => 'Valletta',
			'edd_vat_address_code'                => 'VLT 1000',
			'edd_vat_purchase_receipt'            => '1',
			'edd_vat_hmrc_client_id'              => self::CLIENT,
			'edd_vat_hmrc_client_secret'          => self::SECRET,
		);
	}

	public function test_every_mapped_setting_lands_in_ours(): void {
		$report = $this->barn2->import( false );

		$ours = $this->ours();
		$this->assertSame( 'MT12345678', $ours['store_vat_number'] );
		$this->assertTrue( $ours['allow_domestic_exemption'] );
		$this->assertSame( self::CLIENT, $ours['hmrc_client_id'] );
		$this->assertSame( self::SECRET, $ours['hmrc_client_secret'] );
		$this->assertSame( 'Example Ltd', $ours['seller_company'] );
		$this->assertSame( 'accounts@example.com', $ours['seller_email'] );
		$this->assertSame( '1 High Street', $ours['seller_address_1'] );
		$this->assertSame( 'Floor 2', $ours['seller_address_2'] );
		$this->assertSame( 'Valletta', $ours['seller_city'] );
		$this->assertSame( 'VLT 1000', $ours['seller_postcode'] );
		$this->assertTrue( $ours['receipt_vat_details'] );
		$this->assertSame( Barn2Settings::IMPORTED, $report['edd_vat_hmrc_client_secret'] );
		$this->assertSame( Settings::get()->hmrc_client_secret(), self::SECRET );
	}

	public function test_the_vatsense_key_is_reported_but_not_copied(): void {
		$report = $this->barn2->import( false );

		$this->assertSame( Barn2Settings::SKIPPED, $report['edd_vat_vatsense_api_key'] );
		$this->assertStringNotContainsString( self::VATSENSE, (string) wp_json_encode( $this->ours() ) );
	}

	public function test_the_uk_vat_number_becomes_the_uk_store_number(): void {
		$report = $this->barn2->import( false );

		$this->assertSame( Barn2Settings::IMPORTED, $report['edd_uk_vat_number'] );
		$this->assertSame( 'GB333289454', $this->ours()['store_vat_number_gb'] );
		$this->assertSame( 'MT12345678', $this->ours()['store_vat_number'], 'The EU number is not touched.' );
	}

	public function test_a_uk_number_without_its_prefix_is_read_as_gb_and_a_foreign_one_is_skipped(): void {
		edd_update_option( 'edd_uk_vat_number', '333 2894 54' );
		$this->assertSame( Barn2Settings::IMPORTED, $this->barn2->import( true )['edd_uk_vat_number'] );
		$this->assertSame( 'GB333289454', $this->ours()['store_vat_number_gb'] );

		delete_option( Settings::OPTION );
		edd_update_option( 'edd_uk_vat_number', 'DE114103379' );
		$this->assertSame( Barn2Settings::SKIPPED, $this->barn2->import( true )['edd_uk_vat_number'] );
		$this->assertArrayNotHasKey( 'store_vat_number_gb', $this->ours() );
	}

	public function test_filled_in_settings_are_kept_unless_overwrite_is_asked_for(): void {
		update_option(
			Settings::OPTION,
			array(
				'seller_company' => 'Kept Ltd',
				'seller_city'    => '',
			)
		);

		$report = $this->barn2->import( false );

		$this->assertSame( Barn2Settings::ALREADY_SET, $report['edd_vat_company_name'] );
		$this->assertSame( Barn2Settings::IMPORTED, $report['edd_vat_address_city'], 'An empty value is not "already set".' );
		$this->assertSame( 'Kept Ltd', $this->ours()['seller_company'] );
		$this->assertSame( 'Valletta', $this->ours()['seller_city'] );

		$report = $this->barn2->import( true );

		$this->assertSame( Barn2Settings::IMPORTED, $report['edd_vat_company_name'] );
		$this->assertSame( 'Example Ltd', $this->ours()['seller_company'] );
	}

	public function test_a_dry_run_reports_without_writing(): void {
		$report = $this->barn2->import( false, false );

		$this->assertSame( Barn2Settings::IMPORTED, $report['edd_vat_company_name'] );
		$this->assertSame( array(), (array) get_option( Settings::OPTION, array() ) );
		$this->assertNull( Barn2Settings::last_report() );
	}

	public function test_an_address_country_that_is_not_the_shops_is_reported_not_imported(): void {
		edd_update_option( 'edd_vat_address_country', 'DE' );
		$this->assertSame( Barn2Settings::MISMATCH, $this->barn2->import( false )['edd_vat_address_country'] );

		edd_update_option( 'edd_vat_address_country', 'MT' );
		$this->assertSame( Barn2Settings::SKIPPED, $this->barn2->import( false )['edd_vat_address_country'] );
		$this->assertSame( '', $this->ours()['seller_country'] ?? '' );
	}

	public function test_a_store_number_that_does_not_look_valid_is_skipped(): void {
		edd_update_option( 'edd_vat_number', 'not a number' );

		$this->assertSame( Barn2Settings::SKIPPED, $this->barn2->import( false )['edd_vat_number'] );
		$this->assertSame( '', $this->ours()['store_vat_number'] ?? '' );
	}

	public function test_a_site_where_barn2_saved_nothing_reports_nothing(): void {
		foreach ( array_keys( $this->barn2_options() ) as $key ) {
			edd_delete_option( $key );
		}

		$this->assertSame( array(), $this->barn2->import( false ) );
		$this->assertFalse( Barn2Settings::exists() );
	}

	public function test_no_secret_value_reaches_the_screen_the_command_the_report_or_any_log(): void {
		$command = new ImportRunner( $this->barn2, $this->importer() );
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		get_user_by( 'id', $admin )->add_cap( 'manage_shop_settings' );

		$output  = implode( "\n", $command->execute( array( 'dry-run' => true ) ) );
		$output .= implode( "\n", $command->execute( array( 'settings-only' => true ) ) );
		$output .= implode(
			"\n",
			$command->execute(
				array(
					'settings-only' => true,
					'overwrite'     => true,
				)
			)
		); // phpcs:ignore Universal.Arrays.MultipleStatementAlignment

		$screen = new ImportScreen( $this->barn2, $this->importer() );
		ob_start();
		$screen->render();
		$output .= (string) ob_get_clean();

		$output .= (string) wp_json_encode( Barn2Settings::last_report() );
		$output .= (string) wp_json_encode( $this->barn2->import( true, false ) );
		$output .= (string) get_transient( ImportScreen::DRY_TRANSIENT );
		$logs    = (string) file_get_contents( $this->log_file ) . (string) EDD()->debug_log->get_file_contents(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertStringContainsString( 'edd_vat_hmrc_client_secret: imported', $output, 'The field name is reported.' );
		$this->assertStringContainsString( 'edd_vat_hmrc_client_secret', $output );
		foreach ( array( self::SECRET, self::CLIENT, self::VATSENSE, 'accounts@example.com' ) as $value ) {
			$this->assertStringNotContainsString( $value, $output, 'A setting value reached the output.' );
			$this->assertStringNotContainsString( $value, $logs, 'A setting value reached a log.' );
		}
	}

	/**
	 * The assertion above must be able to fail: the same checks catch a run
	 * that does print the value.
	 */
	public function test_the_secret_check_fails_when_the_value_is_echoed(): void {
		$command = new ImportRunner( $this->barn2, $this->importer() );
		$output  = implode( "\n", $command->execute( array( 'settings-only' => true ) ) );
		$leaky   = $output . "\nedd_vat_hmrc_client_secret: " . self::SECRET;
		edd_debug_log( 'leaked ' . self::SECRET );
		$logs = (string) EDD()->debug_log->get_file_contents();

		$this->assertStringNotContainsString( self::SECRET, $output );
		$this->assertStringContainsString( self::SECRET, $leaky, 'Control: echoing the value is detected in the output.' );
		$this->assertStringContainsString( self::SECRET, $logs, 'Control: logging the value is detected in the log.' );
	}

	public function test_the_command_rejects_conflicting_or_malformed_options(): void {
		$command = new ImportRunner( $this->barn2, $this->importer() );

		foreach ( array(
			array(
				'settings-only' => true,
				'history-only'  => true,
			),
			array( 'since' => '2025-13-40' ),
			array( 'since' => 'last year' ),
		) as $assoc ) { // phpcs:ignore Universal.Arrays.MultipleStatementAlignment
			try {
				$command->execute( array_merge( array( 'dry-run' => true ), $assoc ) );
				$this->fail( 'Expected the options to be refused: ' . wp_json_encode( $assoc ) );
			} catch ( InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_history_only_leaves_the_settings_alone(): void {
		$command = new ImportRunner( $this->barn2, $this->importer() );

		$lines = $command->execute(
			array(
				'history-only' => true,
				'since'        => '2025-01-01',
			)
		);

		$this->assertSame( array(), (array) get_option( Settings::OPTION, array() ) );
		$this->assertStringContainsString( 'History from 2025-01-01', implode( "\n", $lines ) );
		$this->assertStringNotContainsString( 'Settings', implode( "\n", $lines ) );
	}

	public function test_the_screen_checks_capability_and_nonce_before_importing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$screen = new ImportScreen( $this->barn2, $this->importer() );
		$_POST  = array( 'vatright_edd_settings' => '1' );

		try {
			$screen->handle_run();
			$this->fail( 'A subscriber ran the import.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( array(), (array) get_option( Settings::OPTION, array() ) );
		}
	}

	private function importer(): Importer {
		return new Importer( $this->backfill, new \VatrightEdd\Import\Barn2History( $this->backfill ), $this->ledger, new \VatrightEdd\Ledger\Fx( $this->ecb ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function ours(): array {
		$stored = get_option( Settings::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}
}
