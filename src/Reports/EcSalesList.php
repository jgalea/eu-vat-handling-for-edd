<?php

namespace VatrightEdd\Reports;

use VatrightEdd\Ledger\Schema;

class EcSalesList {

	/**
	 * Amounts are EUR at the order-date ECB rate. Refunds count in the period of
	 * the sale they reduce, and customers whose total nets to zero drop out.
	 *
	 * @return array<int,array{customer_vat:string,country:string,type:string,net_eur:float}>
	 * @throws IncompleteReport When an order in the period has no EUR amount.
	 */
	public function build_for( ReportPeriod $period ): array {
		global $wpdb;

		$table                    = Schema::ledger_table();
		list( $sale, $sale_args ) = $period->sale_condition( $table, wp_timezone() );
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$missing = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT order_id FROM {$table}
				WHERE scheme IN ('intra_eu_goods', 'reverse_charge_services') AND customer_vat IS NOT NULL AND {$sale} AND net_eur IS NULL
				ORDER BY order_id",
				$sale_args
			)
		);
		if ( array() !== $missing ) {
			throw new IncompleteReport( array_map( 'intval', $missing ) );
		}
		$found = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT customer_vat, IF(supply_type = 'goods', 'goods', 'services') AS type, SUM(net_eur) AS net_eur
				FROM {$table}
				WHERE scheme IN ('intra_eu_goods', 'reverse_charge_services') AND customer_vat IS NOT NULL AND {$sale}
				GROUP BY customer_vat, IF(supply_type = 'goods', 'goods', 'services')
				ORDER BY customer_vat, type",
				$sale_args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$rows = array();
		foreach ( $found as $row ) {
			$net = round( (float) $row['net_eur'], 2 );
			if ( 0.0 !== $net ) {
				$rows[] = array(
					'customer_vat' => $row['customer_vat'],
					'country'      => substr( $row['customer_vat'], 0, 2 ),
					'type'         => $row['type'],
					'net_eur'      => $net,
				);
			}
		}
		return $rows;
	}

	/**
	 * @return array<int,array{customer_vat:string,country:string,type:string,net_eur:float}>
	 * @throws IncompleteReport When an order in the quarter has no EUR amount.
	 */
	public function build( int $year, int $quarter ): array {
		return $this->build_for( ReportPeriod::quarter( $year, $quarter ) );
	}

	public static function unknown_title(): string {
		return __( 'VAT number not recorded, not included above', 'eu-vat-handling-for-edd' );
	}

	/**
	 * Reverse-charged sales with no VAT number on record, which a backfill
	 * leaves behind when the old plugin did not keep it. They cannot be on the
	 * list as filed, so build() leaves them out and they are reported here.
	 * Orders whose amounts net to zero (refunded in full) drop out.
	 *
	 * @return array<int,array{order_id:int,net_eur:float|null}>
	 */
	public function unknown_number_orders_for( ReportPeriod $period ): array {
		global $wpdb;

		$table                    = Schema::ledger_table();
		list( $sale, $sale_args ) = $period->sale_condition( $table, wp_timezone() );
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$found = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_id, SUM(net_eur) AS net_eur, COUNT(net_eur) AS converted, COUNT(*) AS total
				FROM {$table}
				WHERE scheme IN ('intra_eu_goods', 'reverse_charge_services') AND customer_vat IS NULL AND {$sale}
				GROUP BY order_id
				ORDER BY order_id",
				$sale_args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$orders = array();
		foreach ( $found as $row ) {
			$known = (int) $row['converted'] === (int) $row['total'];
			$net   = $known ? round( (float) $row['net_eur'], 2 ) : null;
			if ( null === $net || 0.0 !== $net ) {
				$orders[] = array(
					'order_id' => (int) $row['order_id'],
					'net_eur'  => $net,
				);
			}
		}
		return $orders;
	}

	/**
	 * @return array<int,array{order_id:int,net_eur:float|null}>
	 */
	public function unknown_number_orders( int $year, int $quarter ): array {
		return $this->unknown_number_orders_for( ReportPeriod::quarter( $year, $quarter ) );
	}

	/**
	 * @param array<int,array{customer_vat:string,country:string,type:string,net_eur:float}> $rows
	 * @param array<int,array{order_id:int,net_eur:float|null}>                              $unknown
	 * @return array{0:string[],1:array<int,array<mixed>>}
	 */
	public static function csv( array $rows, array $unknown = array() ): array {
		$header = array(
			__( 'VAT number', 'eu-vat-handling-for-edd' ),
			__( 'Country', 'eu-vat-handling-for-edd' ),
			__( 'Goods or services', 'eu-vat-handling-for-edd' ),
			__( 'Net amount (EUR)', 'eu-vat-handling-for-edd' ),
		);
		$lines  = array();
		foreach ( $rows as $row ) {
			$lines[] = array( $row['customer_vat'], $row['country'], $row['type'], number_format( $row['net_eur'], 2, '.', '' ) );
		}
		if ( array() !== $unknown ) {
			$lines[] = array();
			$lines[] = array( self::unknown_title(), __( 'Order', 'eu-vat-handling-for-edd' ), '', __( 'Net amount (EUR)', 'eu-vat-handling-for-edd' ) );
			foreach ( $unknown as $row ) {
				$lines[] = array( '', $row['order_id'], '', null === $row['net_eur'] ? '' : number_format( $row['net_eur'], 2, '.', '' ) );
			}
		}
		return array( $header, $lines );
	}
}
