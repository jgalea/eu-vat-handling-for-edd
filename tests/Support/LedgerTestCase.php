<?php

namespace VatrightEdd\Tests\Support;

use EDD\Orders\Order;
use VatrightEdd\Ledger\Backfill;
use VatrightEdd\Ledger\Fx;
use VatrightEdd\Ledger\LedgerWriter;
use VatrightEdd\Ledger\Schema;
use VatrightEdd\Rates\RateResolver;
use VatrightEdd\Rates\RateSync;
use VatrightEdd\Rates\Tedb;

require_once __DIR__ . '/OrdersTestCase.php';
require_once __DIR__ . '/FixtureTransport.php';
require_once __DIR__ . '/EcbFixtureTransport.php';

/**
 * OrdersTestCase with a ledger writer whose ECB answers come from the
 * recorded fixture. Order dates are set by the test, so no result depends on
 * the day the suite runs.
 */
abstract class LedgerTestCase extends OrdersTestCase {

	/** @var EcbFixtureTransport */
	protected $ecb;

	/** @var LedgerWriter */
	protected $ledger;

	/** @var Backfill */
	protected $backfill;

	public function set_up() {
		parent::set_up();
		$this->ecb      = new EcbFixtureTransport();
		$rates          = new RateResolver( new RateSync( new Tedb( new FixtureTransport( sys_get_temp_dir() ) ) ) );
		$this->ledger   = new LedgerWriter( new Fx( $this->ecb ), $rates );
		$this->backfill = new Backfill( $this->ledger, $rates );
		$this->ledger->register();
		$this->backfill->register();

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $admin )->add_cap( 'edit_shop_payments' );
		wp_set_current_user( $admin );
	}

	/**
	 * A flat rate for any date, so sizing a cart against the threshold never
	 * reads today's date or the fixture.
	 */
	protected function checkout_fx(): ?Fx {
		return new class( new FixtureTransport( sys_get_temp_dir() ) ) extends Fx {
			public function rate_on_or_before( string $currency, string $ymd ): ?float {
				return 1.1;
			}
		};
	}

	protected function dated( Order $order, string $created, ?string $completed = null ): void {
		edd_update_order(
			(int) $order->id,
			array(
				'date_created'   => $created,
				'date_completed' => $completed ?? $created,
			)
		);
	}

	protected function complete( Order $order ): void {
		edd_update_order( (int) $order->id, array( 'status' => 'complete' ) );
	}

	protected function status( Order $order, string $status ): void {
		edd_update_order( (int) $order->id, array( 'status' => $status ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	protected function rows( int $order_id ): array {
		global $wpdb;
		$table = Schema::ledger_table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id", $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	protected function sum( array $rows, string $column ): float {
		return round( array_sum( array_map( 'floatval', array_column( $rows, $column ) ) ), 4 );
	}

	protected function ledger_total_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::ledger_table() ); // phpcs:ignore WordPress.DB
	}

	protected function ecb_order_in_usd(): void {
		$this->order_currency = 'USD';
	}
}
