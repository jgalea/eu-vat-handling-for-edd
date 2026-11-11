<?php

namespace VatrightEdd\Reports;

use VatrightEdd\Ledger\Schema;
use VatrightEdd\Orders\DecisionStore;

class TransactionExport {

	public function count_for( ReportPeriod $period ): int {
		global $wpdb;

		$table               = Schema::ledger_table();
		list( $sale, $args ) = $period->sale_condition( $table, wp_timezone() );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sale}", $args ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Every ledger entry of the sales made in the period, with the evidence
	 * and the registry answer the order was decided on.
	 *
	 * @return array{0:string[],1:array<int,array<mixed>>}
	 */
	public function build_for( ReportPeriod $period ): array {
		global $wpdb;

		$table               = Schema::ledger_table();
		list( $sale, $args ) = $period->sale_condition( $table, wp_timezone() );
		$entries             = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$sale} ORDER BY supply_at, id", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$records = array();
		$numbers = array();
		$rows    = array();
		foreach ( (array) $entries as $entry ) {
			$order_id = (int) $entry['order_id'];
			if ( ! isset( $records[ $order_id ] ) ) {
				$decision             = DecisionStore::get( $order_id );
				$records[ $order_id ] = null === $decision ? array() : $decision->to_array();
				$order                = edd_get_order( $order_id );
				$numbers[ $order_id ] = $order ? (string) $order->get_number() : (string) $order_id;
			}
			$rows[] = self::row( $entry, $records[ $order_id ], $numbers[ $order_id ] );
		}
		return array( self::header(), $rows );
	}

	/**
	 * @return string[]
	 */
	public static function header(): array {
		return array(
			__( 'Order', 'vat-right-for-edd' ),
			__( 'Order number', 'vat-right-for-edd' ),
			__( 'Entry', 'vat-right-for-edd' ),
			__( 'Date (UTC)', 'vat-right-for-edd' ),
			__( 'Customer country', 'vat-right-for-edd' ),
			__( 'Place of supply', 'vat-right-for-edd' ),
			__( 'Billing country', 'vat-right-for-edd' ),
			__( 'Shipping country', 'vat-right-for-edd' ),
			__( 'IP country', 'vat-right-for-edd' ),
			__( 'Self-certified country', 'vat-right-for-edd' ),
			__( 'Customer VAT number', 'vat-right-for-edd' ),
			__( 'Registry', 'vat-right-for-edd' ),
			__( 'Registry answer', 'vat-right-for-edd' ),
			__( 'Registered name', 'vat-right-for-edd' ),
			__( 'Consultation reference', 'vat-right-for-edd' ),
			__( 'Checked at', 'vat-right-for-edd' ),
			__( 'Scheme', 'vat-right-for-edd' ),
			__( 'Rule', 'vat-right-for-edd' ),
			__( 'Supply type', 'vat-right-for-edd' ),
			__( 'VAT rate %', 'vat-right-for-edd' ),
			__( 'Net', 'vat-right-for-edd' ),
			__( 'VAT', 'vat-right-for-edd' ),
			__( 'Currency', 'vat-right-for-edd' ),
			__( 'ECB rate', 'vat-right-for-edd' ),
			__( 'Net (EUR)', 'vat-right-for-edd' ),
			__( 'VAT (EUR)', 'vat-right-for-edd' ),
			__( 'Flags', 'vat-right-for-edd' ),
		);
	}

	/**
	 * @param array<string,mixed> $entry  A ledger row.
	 * @param array<string,mixed> $record The order's stored decision, empty when none.
	 * @return array<int,mixed>
	 */
	public static function row( array $entry, array $record, string $order_number ): array {
		$evidence   = (array) ( $record['evidence'] ?? array() );
		$validation = (array) ( $record['validation'] ?? array() );
		return array(
			(int) $entry['order_id'],
			$order_number,
			$entry['entry_type'],
			$entry['supply_at'],
			$entry['customer_country'],
			$entry['place_of_supply'],
			$evidence['billing'] ?? '',
			$evidence['shipping'] ?? '',
			$evidence['ip'] ?? '',
			$evidence['self_certified'] ?? '',
			$entry['customer_vat'] ?? '',
			$validation['registry'] ?? '',
			$validation['status'] ?? '',
			$validation['name'] ?? '',
			$validation['consultation'] ?? '',
			$validation['checked_at'] ?? '',
			$entry['scheme'],
			$entry['rule'],
			$entry['supply_type'],
			$entry['rate_percent'],
			$entry['net'],
			$entry['vat'],
			$entry['currency'],
			$entry['eur_rate'] ?? '',
			$entry['net_eur'] ?? '',
			$entry['vat_eur'] ?? '',
			implode( ' ', array_map( 'strval', (array) ( $record['flags'] ?? array() ) ) ),
		);
	}
}
