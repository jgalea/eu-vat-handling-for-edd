<?php

namespace VatrightEdd\Reports;

use DateTimeImmutable;
use VatrightEdd\Ledger\Fx;
use VatrightEdd\Ledger\Schema;
use RuntimeException;

class OssReport {

	const RATE_RULE = 'ECB reference rate of the last day of the period';

	/** @var Fx */
	private $fx;

	/** @var array<string,array{currency:string,period:string,rate_date:string,rate:float}> */
	private $rates = array();

	public function __construct( Fx $fx ) {
		$this->fx = $fx;
	}

	/**
	 * Refunds, revokes and reinstatements count in the period of the sale they
	 * change. One booked in a later period is repeated in `corrections` of that
	 * later period so the earlier return can be amended.
	 *
	 * Sales whose VAT was charged at the store's own rate because the tax
	 * tables had no rate for the destination are left out of `rows`: their
	 * amounts are not the destination VAT the return declares. They are listed
	 * per order in `needs_correction` so the merchant can settle them.
	 *
	 * @return array{year:int,quarter:int,period:string,currency:string,rate_rule:string,rows:array<int,array<string,mixed>>,corrections:array<int,array<string,mixed>>,needs_correction:array<int,array<string,mixed>>,rates:array<int,array<string,mixed>>}
	 * @throws RuntimeException When a currency has no ECB rate on or after the period end.
	 */
	public function build( int $year, int $quarter, string $currency = 'EUR' ): array {
		$this->forget_rates();
		$currency = strtoupper( $currency );
		$period   = self::period( $year, $quarter );

		$rows        = $this->convert( $this->groups( 'relates_to_period = %s', array( $period ) ), $period, $currency );
		$corrections = array();
		foreach ( $this->corrections_periods( $period ) as $original ) {
			$groups = $this->groups( "entry_type IN ('refund','void','restore') AND period = %s AND relates_to_period = %s", array( $period, $original ) );
			foreach ( $this->convert( $groups, $original, $currency ) as $row ) {
				$corrections[] = array_merge( array( 'relates_to_period' => $original ), $row );
			}
		}

		$needs_correction = $this->needs_correction( $period, $currency );

		return array(
			'year'             => $year,
			'quarter'          => $quarter,
			'period'           => $period,
			'currency'         => $currency,
			'rate_rule'        => self::RATE_RULE,
			'rows'             => $rows,
			'corrections'      => $corrections,
			'needs_correction' => $needs_correction,
			'rates'            => array_values( $this->rates ),
		);
	}

	/**
	 * @param array{year:int,quarter:int,period:string,currency:string,rate_rule:string,rows:array<int,array<string,mixed>>,corrections:array<int,array<string,mixed>>,needs_correction:array<int,array<string,mixed>>,rates:array<int,array<string,mixed>>} $report
	 * @return array{0:string[],1:array<int,array<mixed>>}
	 */
	public static function csv( array $report ): array {
		$currency = $report['currency'];
		$header   = array(
			__( 'Member state of consumption', 'eu-vat-handling-for-edd' ),
			__( 'VAT rate %', 'eu-vat-handling-for-edd' ),
			__( 'Supply type', 'eu-vat-handling-for-edd' ),
			/* translators: %s: currency code. */
			sprintf( __( 'Taxable amount (%s)', 'eu-vat-handling-for-edd' ), $currency ),
			/* translators: %s: currency code. */
			sprintf( __( 'VAT amount (%s)', 'eu-vat-handling-for-edd' ), $currency ),
		);

		$lines = array();
		foreach ( $report['rows'] as $row ) {
			$lines[] = array( $row['member_state'], $row['rate_percent'], $row['supply_type'], self::money( $row['taxable'] ), self::money( $row['vat'] ) );
		}

		if ( array() !== $report['corrections'] ) {
			$lines[] = array();
			$lines[] = array( __( 'Corrections to earlier periods', 'eu-vat-handling-for-edd' ), __( 'VAT rate %', 'eu-vat-handling-for-edd' ), __( 'Supply type', 'eu-vat-handling-for-edd' ), __( 'Taxable amount', 'eu-vat-handling-for-edd' ), __( 'VAT amount', 'eu-vat-handling-for-edd' ), __( 'Period corrected', 'eu-vat-handling-for-edd' ) );
			foreach ( $report['corrections'] as $row ) {
				$lines[] = array( $row['member_state'], $row['rate_percent'], $row['supply_type'], self::money( $row['taxable'] ), self::money( $row['vat'] ), $row['relates_to_period'] );
			}
		}

		if ( array() !== $report['needs_correction'] ) {
			$lines[] = array();
			$lines[] = array( self::needs_correction_title(), __( 'Order', 'eu-vat-handling-for-edd' ), __( 'Rate charged %', 'eu-vat-handling-for-edd' ), __( 'Supply type', 'eu-vat-handling-for-edd' ), __( 'Taxable amount', 'eu-vat-handling-for-edd' ), __( 'VAT charged', 'eu-vat-handling-for-edd' ) );
			foreach ( $report['needs_correction'] as $row ) {
				$lines[] = array( $row['member_state'], $row['order_id'], $row['rate_percent'], $row['supply_type'], self::money( $row['taxable'] ), self::money( $row['vat'] ) );
			}
		}

		if ( array() !== $report['rates'] ) {
			$lines[] = array();
			$lines[] = array(
				/* translators: %s: conversion rule. */
				sprintf( __( 'Exchange rates used: %s', 'eu-vat-handling-for-edd' ), $report['rate_rule'] ),
				__( 'Period', 'eu-vat-handling-for-edd' ),
				__( 'Rate date', 'eu-vat-handling-for-edd' ),
				__( 'Units per EUR', 'eu-vat-handling-for-edd' ),
			);
			foreach ( $report['rates'] as $rate ) {
				$lines[] = array( $rate['currency'], $rate['period'], $rate['rate_date'], $rate['rate'] );
			}
		}

		return array( $header, $lines );
	}

	public static function needs_correction_title(): string {
		return __( 'Charged at your own rate, needs correction (not included above)', 'eu-vat-handling-for-edd' );
	}

	private static function money( float $amount ): string {
		return number_format( $amount, 2, '.', '' );
	}

	public static function period( int $year, int $quarter ): string {
		return $year . '-Q' . $quarter;
	}

	public static function period_end( string $period ): string {
		list( $year, $quarter ) = explode( '-Q', $period );
		$month                  = sprintf( '%04d-%02d-01', (int) $year, (int) $quarter * 3 );
		return ( new DateTimeImmutable( $month ) )->modify( 'last day of this month' )->format( 'Y-m-d' );
	}

	/**
	 * @return string[]
	 */
	private function corrections_periods( string $period ): array {
		global $wpdb;

		$table = Schema::ledger_table();
		return $wpdb->get_col( // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare( "SELECT DISTINCT relates_to_period FROM {$table} WHERE scheme = 'oss_destination' AND rate_missing = 0 AND entry_type IN ('refund','void','restore') AND period = %s AND relates_to_period <> period ORDER BY relates_to_period", $period ) // phpcs:ignore WordPress.DB
		);
	}

	/**
	 * @param array<int,string> $args
	 * @return array<int,array<string,mixed>>
	 */
	private function groups( string $where, array $args ): array {
		global $wpdb;

		$table = Schema::ledger_table();
		$sql   = "SELECT place_of_supply AS member_state, rate_percent, IF(supply_type = 'goods', 'goods', 'services') AS supply_type, currency,
				SUM(net) AS net, SUM(vat) AS vat
			FROM {$table} WHERE scheme = 'oss_destination' AND rate_missing = 0 AND {$where}
			GROUP BY place_of_supply, rate_percent, IF(supply_type = 'goods', 'goods', 'services'), currency";
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * OSS sales of the period, net of their refunds, whose VAT was charged at
	 * the store's rate. One row per order, member state, rate and supply type.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function needs_correction( string $period, string $currency ): array {
		global $wpdb;

		$table  = Schema::ledger_table();
		$sql    = "SELECT order_id, place_of_supply AS member_state, rate_percent, IF(supply_type = 'goods', 'goods', 'services') AS supply_type, currency,
				SUM(net) AS net, SUM(vat) AS vat
			FROM {$table} WHERE scheme = 'oss_destination' AND rate_missing = 1 AND relates_to_period = %s
			GROUP BY order_id, place_of_supply, rate_percent, IF(supply_type = 'goods', 'goods', 'services'), currency
			ORDER BY order_id, place_of_supply";
		$groups = $wpdb->get_results( $wpdb->prepare( $sql, $period ), ARRAY_A ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$end  = self::period_end( $period );
		$rows = array();
		foreach ( $groups as $group ) {
			$source = (string) $group['currency'];
			$rows[] = array(
				'order_id'     => (int) $group['order_id'],
				'member_state' => $group['member_state'],
				'rate_percent' => (float) $group['rate_percent'],
				'supply_type'  => $group['supply_type'],
				'taxable'      => round( $this->amount( (float) $group['net'], $source, $currency, $period, $end ), 2 ),
				'vat'          => round( $this->amount( (float) $group['vat'], $source, $currency, $period, $end ), 2 ),
			);
		}
		return $rows;
	}

	/**
	 * Converts each currency group at the rate of the period end and merges
	 * groups that land on the same row.
	 *
	 * @param array<int,array<string,mixed>> $groups
	 * @return array<int,array<string,mixed>>
	 */
	private function convert( array $groups, string $period, string $currency ): array {
		$end    = self::period_end( $period );
		$merged = array();
		foreach ( $groups as $group ) {
			$source = (string) $group['currency'];
			$key    = $group['member_state'] . '|' . (float) $group['rate_percent'] . '|' . $group['supply_type'];
			if ( ! isset( $merged[ $key ] ) ) {
				$merged[ $key ] = array(
					'member_state' => $group['member_state'],
					'rate_percent' => (float) $group['rate_percent'],
					'supply_type'  => $group['supply_type'],
					'taxable'      => 0.0,
					'vat'          => 0.0,
				);
			}
			$merged[ $key ]['taxable'] += $this->amount( (float) $group['net'], $source, $currency, $period, $end );
			$merged[ $key ]['vat']     += $this->amount( (float) $group['vat'], $source, $currency, $period, $end );
		}

		ksort( $merged );
		foreach ( $merged as &$row ) {
			$row['taxable'] = round( $row['taxable'], 2 );
			$row['vat']     = round( $row['vat'], 2 );
		}
		unset( $row );
		return array_values( $merged );
	}

	private function amount( float $amount, string $source, string $currency, string $period, string $end ): float {
		if ( $source === $currency ) {
			return $amount;
		}
		$eur = $amount / $this->rate( $source, $period, $end );
		return 'EUR' === $currency ? $eur : $eur * $this->rate( $currency, $period, $end );
	}

	private function forget_rates(): void {
		$this->rates = array();
	}

	private function rate( string $currency, string $period, string $end ): float {
		$key = $currency . '|' . $period;
		if ( ! isset( $this->rates[ $key ] ) ) {
			$rate = $this->fx->rate_on_or_after( $currency, $end );
			if ( null === $rate ) {
				throw new RuntimeException( sprintf( 'No ECB rate for %s on or after %s.', esc_html( $currency ), esc_html( $end ) ) );
			}
			$this->rates[ $key ] = array(
				'currency'  => $currency,
				'period'    => $period,
				'rate_date' => $end,
				'rate'      => $rate,
			);
		}
		return $this->rates[ $key ]['rate'];
	}
}
