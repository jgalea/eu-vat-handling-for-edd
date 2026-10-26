<?php

namespace VatrightEdd\Tests\Integration\Reports;

use EDD\Orders\Order;
use VatrightEdd\Ledger\Fx;
use VatrightEdd\Ledger\LedgerWriter;
use VatrightEdd\Ledger\Schema;
use VatrightEdd\Reports\Csv;
use VatrightEdd\Reports\EcSalesList;
use VatrightEdd\Reports\OssReport;
use VatrightEdd\Reports\ReportPeriod;
use VatrightEdd\Reports\ReportsScreen;
use VatrightEdd\Reports\RestController;
use VatrightEdd\Tests\Support\LedgerTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/LedgerTestCase.php';

/**
 * One fixed quarter (2026 Q3) with hand-computed EUR figures. The USD series
 * recorded in the ECB fixture gives 1.1355 on the last day of the quarter (OSS)
 * and 1.1378 on 28 September (the order date, used by the EC Sales List).
 */
class ReportsTest extends LedgerTestCase {

	/** @var OssReport */
	private $oss;

	/** @var EcSalesList */
	private $esl;

	public function set_up() {
		parent::set_up();
		$this->oss = new OssReport( new Fx( $this->ecb ) );
		$this->esl = new EcSalesList();
	}

	public function test_the_oss_quarter_totals_match_the_hand_computed_figures(): void {
		$this->oss_quarter();

		$report = $this->oss->build( 2026, 3 );

		// DE at 19%: EUR 100 + 50, plus USD 100 at 1.1355 = 88.0669; VAT 19 + 9.50 + 19 / 1.1355 = 16.7327.
		$this->assertSame(
			array(
				array(
					'member_state' => 'DE',
					'rate_percent' => 19.0,
					'supply_type'  => 'services',
					'taxable'      => 238.07,
					'vat'          => 45.23,
				),
				array(
					'member_state' => 'FR',
					'rate_percent' => 20.0,
					'supply_type'  => 'services',
					'taxable'      => 200.0,
					'vat'          => 40.0,
				),
			),
			$report['rows']
		);
		$this->assertSame( '2026-Q3', $report['period'] );
		$this->assertSame(
			array(
				array(
					'currency'  => 'USD',
					'period'    => '2026-Q3',
					'rate_date' => '2026-09-30',
					'rate'      => 1.1355,
				),
			),
			$report['rates']
		);
		$this->assertSame( array(), $report['corrections'] );
		$this->assertSame( array(), $this->oss->build( 2026, 2 )['rows'], 'Another quarter is empty, so the figures above are not a default.' );
	}

	public function test_the_oss_csv_matches_the_golden_file(): void {
		$this->oss_quarter();

		$csv = OssReport::csv( $this->oss->build( 2026, 3 ) );

		$this->assertSame( $this->golden( 'oss-2026-q3.csv' ), Csv::render( $csv[0], $csv[1] ) );
	}

	public function test_a_later_revoke_is_reported_as_a_correction_and_still_nets_in_the_sale_quarter(): void {
		$this->oss_quarter();
		$revoked = $this->sale( 'DE', 50.0, '2026-08-20 09:00:00' );
		$this->status( $revoked, 'revoked' );
		$void_period = (string) $this->rows( (int) $revoked->id )[1]['period'];
		$this->assertNotSame( '2026-Q3', $void_period, 'The revoke is booked today, not in the fixture quarter.' );

		$report = $this->oss->build( 2026, 3 );
		$this->assertSame( 238.07, $report['rows'][0]['taxable'], 'The revoked order cancels itself out of the quarter it belongs to.' );

		list( $year, $quarter ) = array_map( 'intval', explode( '-Q', $void_period ) );
		$later                  = $this->oss->build( $year, $quarter );
		$this->assertSame( array( '2026-Q3' ), array_unique( array_column( $later['corrections'], 'relates_to_period' ) ) );
		$this->assertSame( -50.0, $later['corrections'][0]['taxable'] );
		$this->assertSame( -9.5, $later['corrections'][0]['vat'] );
	}

	public function test_the_ec_sales_list_totals_match_and_leave_out_orders_without_a_number(): void {
		$this->esl_quarter();

		// DE114103379: EUR 100 + 50, plus USD 100 at the 28 September rate of 1.1378 = 87.8889.
		$this->assertSame(
			array(
				array(
					'customer_vat' => self::VALID_DE,
					'country'      => 'DE',
					'type'         => 'services',
					'net_eur'      => 237.89,
				),
			),
			$this->esl->build( 2026, 3 )
		);

		$unknown = $this->esl->unknown_number_orders( 2026, 3 );
		$this->assertCount( 1, $unknown );
		$this->assertSame( 80.0, $unknown[0]['net_eur'] );
		$this->assertSame( array(), $this->esl->unknown_number_orders( 2026, 2 ) );
	}

	public function test_the_ec_sales_list_csv_matches_the_golden_file_and_names_the_excluded_order(): void {
		$this->esl_quarter();
		$unknown = $this->esl->unknown_number_orders( 2026, 3 );

		$csv = EcSalesList::csv( $this->esl->build( 2026, 3 ), $unknown );

		$this->assertSame(
			str_replace( '{ORDER}', (string) $unknown[0]['order_id'], $this->golden( 'ec-sales-list-2026-q3.csv' ) ),
			Csv::render( $csv[0], $csv[1] )
		);
		$plain = EcSalesList::csv( $this->esl->build( 2026, 3 ) );
		$this->assertCount( 1, $plain[1], 'Without excluded orders the CSV is the list alone.' );
	}

	public function test_an_excluded_order_that_is_revoked_drops_out(): void {
		$this->esl_quarter();
		$order = edd_get_order( $this->esl->unknown_number_orders( 2026, 3 )[0]['order_id'] );
		$this->assertInstanceOf( Order::class, $order );
		$this->status( $order, 'revoked' );

		$this->assertSame( array(), $this->esl->unknown_number_orders( 2026, 3 ) );
	}

	public function test_a_rate_missing_for_the_ec_sales_list_is_reported_not_zeroed(): void {
		$this->esl_quarter();
		global $wpdb;
		$wpdb->query( 'UPDATE ' . Schema::ledger_table() . " SET net_eur = NULL WHERE currency = 'USD'" ); // phpcs:ignore WordPress.DB

		$this->expectException( \VatrightEdd\Reports\IncompleteReport::class );
		$this->esl->build( 2026, 3 );
	}

	public function test_rest_denies_users_without_the_reports_capability(): void {
		$this->oss_quarter();
		$this->rest_server();

		wp_set_current_user( 0 );
		$this->assertSame(
			401,
			$this->get(
				'/vatright-edd/v1/reports/oss',
				array(
					'year'    => 2026,
					'quarter' => 3,
				)
			)->get_status()
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( array( '/vatright-edd/v1/reports/oss', '/vatright-edd/v1/reports/ec-sales-list' ) as $route ) {
			$this->assertSame(
				403,
				$this->get(
					$route,
					array(
						'year'    => 2026,
						'quarter' => 3,
					)
				)->get_status(),
				$route
			);
		}
		$this->assertSame( 403, $this->get( '/vatright-edd/v1/orders/1/vat' )->get_status() );

		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user )->add_cap( 'view_shop_reports' );
		wp_set_current_user( $user );
		$response = $this->get(
			'/vatright-edd/v1/reports/oss',
			array(
				'year'    => 2026,
				'quarter' => 3,
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 238.07, $response->get_data()['rows'][0]['taxable'] );
	}

	public function test_rest_ec_sales_list_reports_the_excluded_count(): void {
		$this->esl_quarter();
		$this->rest_server();
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user )->add_cap( 'view_shop_reports' );
		wp_set_current_user( $user );

		$data = $this->get(
			'/vatright-edd/v1/reports/ec-sales-list',
			array(
				'year'    => 2026,
				'quarter' => 3,
			)
		)->get_data();

		$this->assertSame( 237.89, $data['rows'][0]['net_eur'] );
		$this->assertSame( 1, $data['excluded']['count'] );
		$this->assertSame( 80.0, $data['excluded']['orders'][0]['net_eur'] );
	}

	public function test_rest_rejects_a_bad_quarter(): void {
		$this->rest_server();
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user )->add_cap( 'view_shop_reports' );
		wp_set_current_user( $user );

		$this->assertSame(
			400,
			$this->get(
				'/vatright-edd/v1/reports/oss',
				array(
					'year'    => 2026,
					'quarter' => 5,
				)
			)->get_status()
		);
	}

	public function test_a_month_report_holds_only_that_months_sales(): void {
		$this->oss_quarter();

		$august = $this->oss->build_for( ReportPeriod::month( 2026, 8 ) );

		$this->assertSame( '2026-08', $august['period'] );
		$this->assertSame( 'month', $august['kind'] );
		$this->assertNull( $august['quarter'] );
		$this->assertSame(
			array(
				array(
					'member_state' => 'DE',
					'rate_percent' => 19.0,
					'supply_type'  => 'services',
					'taxable'      => 50.0,
					'vat'          => 9.5,
				),
				array(
					'member_state' => 'FR',
					'rate_percent' => 20.0,
					'supply_type'  => 'services',
					'taxable'      => 200.0,
					'vat'          => 40.0,
				),
			),
			$august['rows']
		);
		$this->assertSame( array(), $august['rates'], 'August has only euro sales.' );
		$this->assertSame( array(), $this->oss->build_for( ReportPeriod::month( 2026, 6 ) )['rows'] );
	}

	public function test_a_non_euro_month_converts_at_the_rate_of_the_months_last_day(): void {
		$this->oss_quarter();

		$september = $this->oss->build_for( ReportPeriod::month( 2026, 9 ) );

		// USD 100 at 1.1355 on 30 September: 88.0669; VAT 19 / 1.1355 = 16.7327.
		$this->assertSame( 88.07, $september['rows'][0]['taxable'] );
		$this->assertSame( 16.73, $september['rows'][0]['vat'] );
		$this->assertSame(
			array(
				array(
					'currency'  => 'USD',
					'period'    => '2026-09',
					'rate_date' => '2026-09-30',
					'rate'      => 1.1355,
				),
			),
			$september['rates']
		);
	}

	public function test_a_custom_range_includes_whole_days_and_converts_at_the_range_end(): void {
		$this->oss_quarter();

		$range = ReportPeriod::range( '2026-08-01', '2026-09-30' );
		$this->assertNotNull( $range );
		$report = $this->oss->build_for( $range );

		// DE: 50 + USD 100 at 1.1355 = 138.0669; VAT 9.50 + 16.7327 = 26.2327.
		$this->assertSame( 138.07, $report['rows'][0]['taxable'] );
		$this->assertSame( 26.23, $report['rows'][0]['vat'] );
		$this->assertSame( 200.0, $report['rows'][1]['taxable'] );
		$this->assertSame( '2026-09-30', $report['rates'][0]['rate_date'] );
		$this->assertSame( '2026-08-01_2026-09-30', $report['period'] );

		$edge = ReportPeriod::range( '2026-08-20', '2026-08-20' );
		$this->assertNotNull( $edge );
		$this->assertSame( 50.0, $this->oss->build_for( $edge )['rows'][0]['taxable'], 'An order at 09:00 on the last day is in the range.' );
	}

	public function test_a_quarter_built_the_old_way_is_the_quarter_period(): void {
		$this->oss_quarter();

		$this->assertSame( $this->oss->build( 2026, 3 ), $this->oss->build_for( ReportPeriod::quarter( 2026, 3 ) ) );
	}

	public function test_a_month_report_nets_a_revoke_and_lists_it_as_a_correction_in_the_month_it_was_booked(): void {
		$this->oss_quarter();
		$revoked = $this->sale( 'DE', 50.0, '2026-08-20 09:00:00' );
		$this->status( $revoked, 'revoked' );
		$void_month = substr( (string) $this->rows( (int) $revoked->id )[1]['supply_at'], 0, 7 );
		$this->assertNotSame( '2026-08', $void_month, 'The revoke is booked today, not in the fixture month.' );

		$august = $this->oss->build_for( ReportPeriod::month( 2026, 8 ) );
		$this->assertSame( 50.0, $august['rows'][0]['taxable'], 'The revoked order cancels itself out of the month it belongs to.' );
		$this->assertSame( array(), $august['corrections'] );

		list( $year, $month ) = array_map( 'intval', explode( '-', $void_month ) );
		$later                = $this->oss->build_for( ReportPeriod::month( $year, $month ) );
		$this->assertSame( array( '2026-08' ), array_unique( array_column( $later['corrections'], 'relates_to_period' ) ) );
		$this->assertSame( -50.0, $later['corrections'][0]['taxable'] );
		$this->assertSame( -9.5, $later['corrections'][0]['vat'] );
		$this->assertSame( array(), $later['rows'], 'A correction is not a sale of the later month.' );
	}

	public function test_a_month_oss_csv_matches_the_golden_file(): void {
		$this->oss_quarter();

		$range = ReportPeriod::range( '2026-08-01', '2026-09-30' );
		$this->assertNotNull( $range );
		$csv = OssReport::csv( $this->oss->build_for( $range ) );

		$this->assertSame( $this->golden( 'oss-2026-08-01-to-2026-09-30.csv' ), Csv::render( $csv[0], $csv[1] ) );
	}

	public function test_the_ec_sales_list_for_a_month_and_a_range(): void {
		$this->esl_quarter();

		$august = $this->esl->build_for( ReportPeriod::month( 2026, 8 ) );
		$this->assertSame( 50.0, $august[0]['net_eur'] );
		$this->assertCount( 1, $august );

		$unknown = $this->esl->unknown_number_orders_for( ReportPeriod::month( 2026, 8 ) );
		$this->assertCount( 1, $unknown );
		$this->assertSame( 80.0, $unknown[0]['net_eur'] );
		$this->assertSame( array(), $this->esl->unknown_number_orders_for( ReportPeriod::month( 2026, 7 ) ) );

		// USD 100 at the 28 September rate of 1.1378 = 87.8889.
		$this->assertSame( 87.89, $this->esl->build_for( ReportPeriod::month( 2026, 9 ) )[0]['net_eur'] );
		$range = ReportPeriod::range( '2026-08-01', '2026-09-30' );
		$this->assertNotNull( $range );
		$this->assertSame( 137.89, $this->esl->build_for( $range )[0]['net_eur'] );
		$this->assertSame( array(), $this->esl->build_for( ReportPeriod::month( 2026, 6 ) ) );
		$this->assertSame( $this->esl->build( 2026, 3 ), $this->esl->build_for( ReportPeriod::quarter( 2026, 3 ) ) );
	}

	public function test_a_missing_ec_rate_only_blocks_the_periods_that_contain_the_order(): void {
		$this->esl_quarter();
		global $wpdb;
		$wpdb->query( 'UPDATE ' . Schema::ledger_table() . " SET net_eur = NULL WHERE currency = 'USD'" ); // phpcs:ignore WordPress.DB

		$this->assertSame( 50.0, $this->esl->build_for( ReportPeriod::month( 2026, 8 ) )[0]['net_eur'] );
		$this->expectException( \VatrightEdd\Reports\IncompleteReport::class );
		$this->esl->build_for( ReportPeriod::month( 2026, 9 ) );
	}

	public function test_the_ec_sales_list_csv_for_a_month_is_the_months_list(): void {
		$this->esl_quarter();

		$period = ReportPeriod::month( 2026, 8 );
		$csv    = EcSalesList::csv( $this->esl->build_for( $period ) );

		$this->assertSame(
			"\"VAT number\",Country,\"Goods or services\",\"Net amount (EUR)\"\n" . self::VALID_DE . ",DE,services,50.00\n",
			Csv::render( $csv[0], $csv[1] )
		);
	}

	public function test_rest_reports_take_a_month_or_a_date_range(): void {
		$this->oss_quarter();
		$this->esl_quarter();
		$this->rest_server();
		$this->report_user();

		$month = $this->get(
			'/vatright-edd/v1/reports/oss',
			array(
				'year'  => 2026,
				'month' => 8,
			)
		);
		$this->assertSame( 200, $month->get_status() );
		$this->assertSame( '2026-08', $month->get_data()['period'] );
		$this->assertSame( 50.0, $month->get_data()['rows'][0]['taxable'] );

		$range = $this->get(
			'/vatright-edd/v1/reports/oss',
			array(
				'from' => '2026-08-01',
				'to'   => '2026-08-10',
			)
		);
		$this->assertSame( 200, $range->get_status() );
		$this->assertSame( array( 'FR' ), array_column( $range->get_data()['rows'], 'member_state' ) );

		$esl = $this->get(
			'/vatright-edd/v1/reports/ec-sales-list',
			array(
				'from' => '2026-08-15',
				'to'   => '2026-09-30',
			)
		)->get_data();
		$this->assertSame( '2026-08-15_2026-09-30', $esl['period'] );
		$this->assertSame( 137.89, $esl['rows'][0]['net_eur'] );
	}

	public function test_rest_keeps_the_quarter_response_shape(): void {
		$this->oss_quarter();
		$this->rest_server();
		$this->report_user();

		$data = $this->get(
			'/vatright-edd/v1/reports/oss',
			array(
				'year'    => 2026,
				'quarter' => 3,
			)
		)->get_data();

		$this->assertSame( 2026, $data['year'] );
		$this->assertSame( 3, $data['quarter'] );
		$this->assertSame( '2026-Q3', $data['period'] );
	}

	public function test_rest_rejects_an_unusable_period(): void {
		$this->rest_server();
		$this->report_user();

		$cases = array(
			'nothing'         => array(),
			'year only'       => array( 'year' => 2026 ),
			'bad month'       => array(
				'year'  => 2026,
				'month' => 13,
			),
			'from without to' => array( 'from' => '2026-08-01' ),
			'reversed range'  => array(
				'from' => '2026-08-10',
				'to'   => '2026-08-01',
			),
			'impossible date' => array(
				'from' => '2026-02-30',
				'to'   => '2026-03-05',
			),
		);
		foreach ( $cases as $name => $params ) {
			foreach ( array( '/vatright-edd/v1/reports/oss', '/vatright-edd/v1/reports/ec-sales-list' ) as $route ) {
				$this->assertSame( 400, $this->get( $route, $params )->get_status(), $name . ' ' . $route );
			}
		}
	}

	public function test_the_screen_shows_the_chosen_month_and_posts_it_to_the_exports(): void {
		$this->oss_quarter();
		$this->report_user();
		$_GET = array(
			'period' => 'month',
			'year'   => '2026',
			'month'  => '7',
		);

		ob_start();
		( new ReportsScreen( $this->oss, $this->esl ) )->render();
		$html = (string) ob_get_clean();
		$_GET = array();

		$this->assertStringContainsString( 'name="period"', $html );
		$this->assertMatchesRegularExpression( '/<option value="month"\s+selected=\'selected\'/', $html );
		$this->assertMatchesRegularExpression( '/name="month"[^>]*>.*?<option value="7"\s+selected=\'selected\'/s', $html );
		$this->assertStringContainsString( 'name="from"', $html );
		$this->assertStringContainsString( 'name="to"', $html );
		$this->assertMatchesRegularExpression( '/<input type="hidden" name="period" value="month"/', $html );
		$this->assertStringContainsString( '100.00', $html );
		$this->assertStringNotContainsString( '200.00', $html, 'The FR sale of 200 was in August.' );
	}

	private function report_user(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user )->add_cap( 'view_shop_reports' );
		wp_set_current_user( $user );
	}

	private function oss_quarter(): void {
		$this->settings( array( 'oss_registered' => true ) );
		$this->sale( 'DE', 100.0, '2026-07-15 10:00:00' );
		$this->sale( 'FR', 200.0, '2026-08-02 10:00:00' );
		$this->sale( 'DE', 50.0, '2026-08-20 09:00:00' );
		$this->sale( 'DE', 100.0, '2026-09-28 12:00:00', 'USD' );
	}

	private function esl_quarter(): void {
		$this->sale( 'DE', 100.0, '2026-07-15 10:00:00', 'EUR', self::VALID_DE );
		$this->sale( 'DE', 50.0, '2026-08-20 09:00:00', 'EUR', self::VALID_DE );
		$this->sale( 'DE', 100.0, '2026-09-28 12:00:00', 'USD', self::VALID_DE );

		$unknown = $this->sale( 'DE', 80.0, '2026-08-05 10:00:00', 'EUR', self::VALID_DE );
		global $wpdb;
		$wpdb->delete( Schema::ledger_table(), array( 'order_id' => (int) $unknown->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( array( \VatrightEdd\Orders\DecisionStore::META, \VatrightEdd\Orders\DecisionStore::LOG_META, \VatrightEdd\Orders\DecisionStore::REVERSE_CHARGED_META ) as $key ) {
			edd_delete_order_meta( (int) $unknown->id, $key );
		}
		$this->backfill->backfill_order( $this->fresh( $unknown ) );
		$this->assertNull( $this->rows( (int) $unknown->id )[0]['customer_vat'], 'The backfilled order has lost its number.' );
	}

	private function sale( string $country, float $price, string $when, string $currency = 'EUR', string $number = '' ): Order {
		edd_empty_cart();
		$this->h->state->clear();
		$this->order_currency = $currency;
		$this->buy( $this->download( $price ), $country );
		if ( '' !== $number ) {
			$this->enter_number( $number, $country );
		}
		$order                = $this->place_order( $country );
		$this->order_currency = 'EUR';
		$this->dated( $order, $when );
		$this->complete( $order );
		return $this->fresh( $order );
	}

	private function golden( string $name ): string {
		$text = (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/reports/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertNotSame( '', $text, "Golden file {$name} is empty." );
		return $text;
	}

	private function rest_server(): void {
		global $wp_rest_server;
		foreach ( $GLOBALS['wp_filter']['rest_api_init']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof RestController ) {
					remove_action( 'rest_api_init', $callback['function'], $priority );
				}
			}
		}
		( new RestController( $this->oss, $this->esl ) )->register();
		$wp_rest_server = null;
		rest_get_server();
	}

	/**
	 * @param array<string,mixed> $params
	 */
	private function get( string $route, array $params = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', $route );
		$request->set_query_params( $params );
		return rest_do_request( $request );
	}
}
