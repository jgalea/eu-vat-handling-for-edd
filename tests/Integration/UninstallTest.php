<?php

namespace VatrightEdd\Tests\Integration;

use VatrightEdd\Ledger\Schema;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Orders\OrderFinaliser;
use VatrightEdd\Settings;
use VatrightEdd\Tests\Support\LedgerTestCase;

require_once __DIR__ . '/../Support/LedgerTestCase.php';

class UninstallTest extends LedgerTestCase {

	/** @var int */
	private $order_id;

	/** @var int */
	private $customer_id;

	public function set_up() {
		parent::set_up();
		$this->buy( $this->download(), 'FR' );
		$order = $this->place_order( 'FR' );
		$this->complete( $order );
		$this->order_id    = (int) $order->id;
		$this->customer_id = (int) $this->fresh( $order )->customer_id;
		edd_update_customer_meta( $this->customer_id, OrderFinaliser::CUSTOMER_NUMBER_META, self::VALID_DE );
		set_transient( 'vatright_edd_probe', '1', HOUR_IN_SECONDS );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'vatright_edd_probe_hook' );
		update_option( 'unrelated_option_for_uninstall_test', 'keep' );
	}

	private function uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'eu-vat-handling-for-edd/eu-vat-handling-for-edd.php' );
		}
		include dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
	}

	public function test_nothing_is_removed_unless_the_store_opted_in(): void {
		$this->assertGreaterThan( 0, $this->ledger_total_rows(), 'The control: there is data to lose.' );

		$this->uninstall();

		$this->assertTrue( $this->table_exists( Schema::ledger_table() ) );
		$this->assertGreaterThan( 0, $this->ledger_total_rows() );
		$this->assertNotFalse( get_option( Schema::DB_VERSION_OPTION ) );
		$this->assertNotFalse( get_transient( 'vatright_edd_probe' ) );
		$this->assertNotFalse( wp_next_scheduled( 'vatright_edd_probe_hook' ) );
		$this->assertSame( self::VALID_DE, edd_get_customer_meta( $this->customer_id, OrderFinaliser::CUSTOMER_NUMBER_META, true ) );
	}

	public function test_opting_in_removes_the_plugin_data_and_nothing_else(): void {
		update_option( Settings::OPTION, array( 'delete_data_on_uninstall' => true ) );
		$drops = array();
		add_filter(
			'query',
			static function ( $query ) use ( &$drops ) {
				if ( 0 === strpos( (string) $query, 'DROP ' ) ) {
					$drops[] = $query;
				}
				return $query;
			}
		);

		$this->uninstall();

		// The suite runs on temporary tables; the real drop is checked in wp-env.
		foreach ( array( Schema::ledger_table(), Schema::fx_table(), Schema::rate_rows_table() ) as $table ) {
			$this->assertMatchesRegularExpression( '/^DROP (TEMPORARY )?TABLE IF EXISTS ' . preg_quote( $table, '/' ) . '$/m', implode( "\n", $drops ) . "\n", $table . ' was not dropped.' );
		}
		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( Schema::DB_VERSION_OPTION ) );
		$this->assertFalse( get_transient( 'vatright_edd_probe' ) );
		$this->assertFalse( wp_next_scheduled( 'vatright_edd_probe_hook' ) );
		$this->assertSame( '', (string) edd_get_customer_meta( $this->customer_id, OrderFinaliser::CUSTOMER_NUMBER_META, true ) );
		$this->assertSame( 'keep', get_option( 'unrelated_option_for_uninstall_test' ) );
		$this->assertNotNull( DecisionStore::get( $this->order_id ), 'Order meta stays with the orders.' );
	}
}
