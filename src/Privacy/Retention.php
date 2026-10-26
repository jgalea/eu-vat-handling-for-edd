<?php

namespace VatrightEdd\Privacy;

use DateTimeImmutable;
use DateTimeZone;
use EDD\Orders\Order;
use VatrightEdd\Ledger\LedgerWriter;
use VatrightEdd\Ledger\Schema;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Scheduler;
use VatrightEdd\Settings;

class Retention {

	const HOOK          = 'vatright_edd_retention';
	const BATCH         = 100;
	const SCRUBBED_META = '_vatright_edd_scrubbed';

	/**
	 * What other EU VAT plugins keep on an order, which an import copies into
	 * the decision and which has to go with it.
	 */
	const FOREIGN_PERSONAL_META = array(
		'_edd_payment_vat_number',
		'_edd_payment_vat_company_name',
		'_edd_payment_vat_company_address',
		'_edd_payment_vat_consultation_number',
	);

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run_batch' ) );
		Scheduler::recurring( self::HOOK, 'daily' );
	}

	/**
	 * Skipped orders stay in the result set, so the offset moves past them;
	 * scrubbed orders drop out of it.
	 */
	public function run_batch( int $offset = 0 ): void {
		$cutoff = new DateTimeImmutable( ( (int) wp_date( 'Y' ) - Settings::get()->retention_years() ) . '-01-01', wp_timezone() );
		$orders = edd_get_orders(
			array(
				'type'               => 'sale',
				'date_created_query' => array(
					'before' => $cutoff->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
				),
				'meta_query'         => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => DecisionStore::META,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => self::SCRUBBED_META,
						'compare' => 'NOT EXISTS',
					),
				),
				'orderby'            => 'id',
				'order'              => 'ASC',
				'number'             => self::BATCH,
				'offset'             => $offset,
			)
		);
		$orders = array_values( array_filter( $orders, 'is_object' ) );

		$skipped = 0;
		foreach ( $orders as $order ) {
			if ( self::is_retained( $order ) ) {
				++$skipped;
				continue;
			}
			self::scrub( $order );
		}

		if ( count( $orders ) === self::BATCH ) {
			Scheduler::once( self::HOOK, array( $offset + $skipped ) );
		}
	}

	public static function retained_until( Order $order ): DateTimeImmutable {
		$year = (int) wp_date( 'Y', LedgerWriter::supply_time( $order )->getTimestamp() );
		return new DateTimeImmutable( ( $year + Settings::get()->retention_years() + 1 ) . '-01-01', wp_timezone() );
	}

	public static function is_retained( Order $order ): bool {
		return self::retained_until( $order )->getTimestamp() > time();
	}

	public static function holds_personal_data( Order $order ): bool {
		if ( '' !== (string) edd_get_order_meta( (int) $order->id, DecisionStore::NUMBER_META, true ) ) {
			return true;
		}
		foreach ( self::FOREIGN_PERSONAL_META as $key ) {
			if ( '' !== (string) edd_get_order_meta( (int) $order->id, $key, true ) ) {
				return true;
			}
		}
		$record = json_decode( (string) edd_get_order_meta( (int) $order->id, DecisionStore::META, true ), true );
		if ( ! is_array( $record ) ) {
			return false;
		}
		return ! empty( $record['vat_number'] )
			|| ! empty( $record['validation']['name'] )
			|| ! empty( $record['validation']['address'] )
			|| ! empty( $record['validation']['consultation'] )
			|| ! empty( $record['evidence']['ip'] );
	}

	/**
	 * Removes the personal data from the order's decision record and the
	 * ledger rows. The scheme, rule, amounts and country evidence the tax
	 * return rests on stay.
	 */
	public static function scrub( Order $order ): bool {
		global $wpdb;

		$order_id = (int) $order->id;
		$changed  = self::holds_personal_data( $order );

		edd_delete_order_meta( $order_id, DecisionStore::NUMBER_META );
		foreach ( self::FOREIGN_PERSONAL_META as $key ) {
			edd_delete_order_meta( $order_id, $key );
		}
		$record = json_decode( (string) edd_get_order_meta( $order_id, DecisionStore::META, true ), true );
		if ( is_array( $record ) ) {
			$record['vat_number'] = null;
			foreach ( array( 'name', 'address', 'consultation' ) as $field ) {
				if ( isset( $record['validation'][ $field ] ) ) {
					$record['validation'][ $field ] = null;
				}
			}
			if ( isset( $record['evidence']['ip'] ) ) {
				$record['evidence']['ip'] = null;
			}
			edd_update_order_meta( $order_id, DecisionStore::META, wp_slash( (string) wp_json_encode( $record ) ) );
		}
		edd_update_order_meta( $order_id, self::SCRUBBED_META, gmdate( 'Y-m-d\TH:i:s\Z' ) );

		$wpdb->update( Schema::ledger_table(), array( 'customer_vat' => null ), array( 'order_id' => $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $changed;
	}
}
