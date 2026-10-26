<?php

namespace VatrightEdd\Ledger;

use DateTimeImmutable;
use DateTimeZone;
use EDD\Orders\Order;
use Throwable;
use VatrightEdd\Admin\DownloadVatCategory;
use VatrightEdd\Domain\Decision;
use VatrightEdd\Domain\Line;
use VatrightEdd\Domain\LineDecision;
use VatrightEdd\Domain\Scheme;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Rates\RateResolver;
use VatrightEdd\Scheduler;

/**
 * Turns decided orders into ledger rows: one row per rate and line decision
 * for a sale, and negative rows for each refund (a refund order in EDD 3) and
 * for a sale that is revoked, and offsetting rows when a revoked sale is
 * reinstated. Rows already reported are never edited by a later event; the
 * event adds its own rows in its own period.
 */
class LedgerWriter {

	const FX_META = '_vatright_edd_fx';

	const FX_RETRY_HOOK = 'vatright_edd_ledger_fx_retry';

	const LIVE = 'live';

	const SALE_STATUSES = array( 'complete', 'edd_subscription' );

	const RESTORE = 'restore';

	const VOID_STATUSES = array( 'revoked', 'failed', 'abandoned', 'cancelled' );

	/** @var Fx */
	private $fx;

	/** @var RateResolver */
	private $rates;

	public function __construct( Fx $fx, RateResolver $rates ) {
		$this->fx    = $fx;
		$this->rates = $rates;
	}

	public function register(): void {
		add_action( 'edd_transition_order_status', array( $this, 'on_status_changed' ), 10, 3 );
		add_action( 'edd_built_order', array( $this, 'on_created' ), 20, 1 );
		add_action( 'edd_post_add_manual_order', array( $this, 'on_created' ), 20, 1 );
		add_action( 'edd_refund_order', array( $this, 'on_refund' ), 10, 2 );
		add_action( self::FX_RETRY_HOOK, array( $this, 'on_fx_retry' ) );
	}

	/**
	 * @param mixed $from
	 * @param mixed $to
	 * @param mixed $order_id
	 */
	public function on_status_changed( $from, $to, $order_id ): void {
		$this->guarded(
			(int) $order_id,
			function ( Order $order ) use ( $to ): void {
				if ( in_array( $to, self::SALE_STATUSES, true ) ) {
					$this->record_order( $order );
				} elseif ( in_array( $to, self::VOID_STATUSES, true ) ) {
					$this->void_order( $order );
				}
			}
		);
	}

	/**
	 * Orders built or added already complete never see a status change.
	 *
	 * @param mixed $order_id
	 */
	public function on_created( $order_id ): void {
		$this->guarded(
			(int) $order_id,
			function ( Order $order ): void {
				if ( in_array( $order->status, self::SALE_STATUSES, true ) ) {
					$this->record_order( $order );
				}
			}
		);
	}

	/**
	 * @param mixed $order_id
	 * @param mixed $refund_id
	 */
	public function on_refund( $order_id, $refund_id ): void {
		try {
			$this->record_refund( (int) $order_id, (int) $refund_id );
		} catch ( Throwable $e ) {
			edd_debug_log( 'EU VAT Handling: could not write the refund ledger rows for order ' . (int) $order_id . ': ' . $e->getMessage() );
		}
	}

	/**
	 * @param mixed $order_id
	 */
	public function on_fx_retry( $order_id ): void {
		$this->guarded(
			(int) $order_id,
			function ( Order $order ): void {
				$this->record_order( $order );
				foreach ( edd_get_order_refunds( (int) $order->id ) as $refund ) {
					$this->record_refund( (int) $order->id, (int) $refund->id );
				}
			}
		);
	}

	public static function period_for( DateTimeImmutable $utc ): string {
		$local = $utc->setTimezone( wp_timezone() );
		return $local->format( 'Y' ) . '-Q' . (int) ceil( (int) $local->format( 'n' ) / 3 );
	}

	/**
	 * Writes the sale rows again from the order as it is now. Without a
	 * source, rows keep the one they already had. An order that was revoked
	 * is not rewritten: its sale rows may be in a filed period, so a
	 * reinstatement adds offsetting rows instead.
	 *
	 * @return bool False when the order has no decision to write rows from.
	 */
	public function record_order( Order $order, ?string $source = null ): bool {
		$decision = DecisionStore::get( (int) $order->id );
		if ( null === $decision ) {
			return false;
		}
		if ( 0 !== $this->count_rows( (int) $order->id, 'void' ) ) {
			$this->restore_order( $order );
			return true;
		}

		$supply_at = self::supply_time( $order );
		$period    = self::period_for( $supply_at );
		$source    = $source ?? $this->sale_source( (int) $order->id );
		$buckets   = $this->buckets( $order, $order, $decision );

		$years = $this->purge( "order_id = %d AND entry_type = 'sale'", array( (int) $order->id ) );
		$this->insert_rows( $order, $decision, $buckets, 'sale', null, $supply_at, $period, $period, $source );
		ThresholdTracker::flush( ...array_merge( $years, array( (int) substr( $period, 0, 4 ) ) ) );
		return true;
	}

	public function record_refund( int $order_id, int $refund_id ): void {
		$order  = edd_get_order( $order_id );
		$refund = edd_get_order( $refund_id );
		if ( ! $order instanceof Order || ! $refund instanceof Order || 'sale' !== $order->type || 'refund' !== $refund->type ) {
			return;
		}
		$decision = DecisionStore::get( $order_id );
		if ( null === $decision ) {
			return;
		}
		if ( 0 === $this->count_rows( $order_id, 'sale' ) ) {
			$this->record_order( $order );
		}

		$buckets = $this->buckets( $refund, $order, $decision );
		if ( ! $this->ties_out( $refund, $buckets ) ) {
			$buckets = $this->proportional_buckets( $order, $refund, $decision );
		}

		$sale_period = self::period_for( self::supply_time( $order ) );
		$refunded_at = self::supply_time( $refund );
		$period      = self::period_for( $refunded_at );

		$years = $this->purge( 'refund_id = %d', array( $refund_id ) );
		$this->insert_rows( $order, $decision, $buckets, 'refund', $refund_id, $refunded_at, $period, $sale_period, $this->sale_source( $order_id ) );
		ThresholdTracker::flush( ...array_merge( $years, array( (int) substr( $sale_period, 0, 4 ), (int) substr( $period, 0, 4 ) ) ) );
	}

	/**
	 * Adds rows that cancel the sale in the period the order was revoked.
	 * Does nothing for an order with refunds, which already settled it, or
	 * one that is not in the ledger or is voided already.
	 */
	public function void_order( Order $order ): void {
		$order_id = (int) $order->id;
		if ( array() !== edd_get_order_refunds( $order_id ) || 0 === $this->count_rows( $order_id, 'sale' ) || $this->is_voided( $order_id ) ) {
			return;
		}
		$this->offset_sale_rows( $order, 'void', -1 );
	}

	/**
	 * Adds rows that put a revoked sale back, in the period it was reinstated.
	 * Does nothing unless the order is currently voided.
	 */
	public function restore_order( Order $order ): void {
		if ( $this->is_voided( (int) $order->id ) ) {
			$this->offset_sale_rows( $order, self::RESTORE, 1 );
		}
	}

	private function is_voided( int $order_id ): bool {
		return $this->count_rows( $order_id, 'void' ) > $this->count_rows( $order_id, self::RESTORE );
	}

	private function offset_sale_rows( Order $order, string $entry_type, int $sign ): void {
		global $wpdb;

		$table = Schema::ledger_table();
		$sales = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d AND entry_type = 'sale'", (int) $order->id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$at     = self::event_time( $order );
		$period = self::period_for( $at );
		foreach ( $sales as $row ) {
			unset( $row['id'] );
			foreach ( array( 'net', 'vat', 'net_eur', 'vat_eur' ) as $column ) {
				$row[ $column ] = null === $row[ $column ] ? null : $sign * (float) $row[ $column ];
			}
			$row['entry_type'] = $entry_type;
			$row['period']     = $period;
			$row['supply_at']  = $at->format( 'Y-m-d H:i:s' );
			$row['created_at'] = gmdate( 'Y-m-d H:i:s' );
			$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$years = array( (int) substr( $period, 0, 4 ) );
		foreach ( $sales as $row ) {
			$years[] = (int) substr( (string) $row['relates_to_period'], 0, 4 );
		}
		ThresholdTracker::flush( ...$years );
	}

	/**
	 * The rows recording the order with this decision would write, without
	 * writing any: its sale rows, its refunds' rows and the void rows of a
	 * revoked order. A dry run counts these.
	 *
	 * @return array<int,array{entry_type:string,period:string,scheme:string}>
	 */
	public function plan( Order $order, Decision $decision ): array {
		$plan    = array();
		$period  = self::period_for( self::supply_time( $order ) );
		$sales   = $this->buckets( $order, $order, $decision );
		$refunds = edd_get_order_refunds( (int) $order->id );

		foreach ( $sales as $bucket ) {
			$plan[] = $this->planned( 'sale', $period, $bucket, $decision );
		}
		foreach ( $refunds as $refund ) {
			$buckets = $this->buckets( $refund, $order, $decision );
			if ( ! $this->ties_out( $refund, $buckets ) ) {
				$buckets = $this->proportional_buckets( $order, $refund, $decision );
			}
			foreach ( $buckets as $bucket ) {
				$plan[] = $this->planned( 'refund', self::period_for( self::supply_time( $refund ) ), $bucket, $decision );
			}
		}
		if ( array() === $refunds && in_array( $order->status, self::VOID_STATUSES, true ) ) {
			$void_period = self::period_for( self::event_time( $order ) );
			foreach ( $sales as $bucket ) {
				$plan[] = $this->planned( 'void', $void_period, $bucket, $decision );
			}
		}
		return $plan;
	}

	/**
	 * @param array{rate_percent:float,net:float,vat:float,line:LineDecision|null,missing:bool} $bucket
	 * @return array{entry_type:string,period:string,scheme:string}
	 */
	private function planned( string $entry_type, string $period, array $bucket, Decision $decision ): array {
		return array(
			'entry_type' => $entry_type,
			'period'     => $period,
			'scheme'     => null === $bucket['line'] ? $decision->scheme() : $bucket['line']->scheme(),
		);
	}

	public function sale_source( int $order_id ): string {
		global $wpdb;

		$table  = Schema::ledger_table();
		$source = $wpdb->get_var( $wpdb->prepare( "SELECT source FROM {$table} WHERE order_id = %d AND entry_type = 'sale' LIMIT 1", $order_id ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return is_string( $source ) && '' !== $source ? $source : self::LIVE;
	}

	public function count_rows( int $order_id, ?string $entry_type = null ): int {
		global $wpdb;

		$table = Schema::ledger_table();
		if ( null === $entry_type ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_id = %d", $order_id ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND entry_type = %s", $order_id, $entry_type ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * When the supply happened: the completion time, else the creation time.
	 * EDD stores both in UTC.
	 */
	public static function supply_time( Order $order ): DateTimeImmutable {
		foreach ( array( $order->date_completed, $order->date_created ) as $value ) {
			if ( is_string( $value ) && '' !== $value && 0 !== strpos( $value, '0000' ) ) {
				return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
			}
		}
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	private static function event_time( Order $order ): DateTimeImmutable {
		$value = $order->date_modified;
		if ( '' !== $value && 0 !== strpos( $value, '0000' ) ) {
			return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
		}
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * A failure here must never undo or block a checkout.
	 */
	private function guarded( int $order_id, callable $work ): void {
		try {
			$order = $order_id > 0 ? edd_get_order( $order_id ) : false;
			if ( $order instanceof Order && 'sale' === $order->type ) {
				$work( $order );
			}
		} catch ( Throwable $e ) {
			edd_debug_log( 'EU VAT Handling: could not write the ledger rows for order ' . $order_id . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Groups the amounts on $source (an order or one of its refunds) by rate
	 * and line decision. The net of an order item is its total less its tax,
	 * so discounts and tax-inclusive prices are already in it.
	 *
	 * @return array<string,array{rate_percent:float,net:float,vat:float,line:LineDecision|null,missing:bool}>
	 */
	private function buckets( Order $source, Order $order, Decision $decision ): array {
		$is_sale = (int) $source->id === (int) $order->id;
		$flagged = in_array( 'rate_missing', $decision->flags(), true );

		$buckets = array();
		foreach ( $source->get_items() as $item ) {
			$original = $is_sale ? (int) $item->id : (int) $item->parent;
			$this->add(
				$buckets,
				$this->line_for( $decision, $original ),
				(float) $item->total - (float) $item->tax,
				(float) $item->tax,
				(int) $item->product_id,
				$source,
				$flagged
			);
		}
		foreach ( $source->get_fees() as $fee ) {
			$original = 'order_item' === $fee->object_type ? (int) $fee->object_id : 0;
			$this->add(
				$buckets,
				$this->line_for( $decision, $original ),
				(float) $fee->total - (float) $fee->tax,
				(float) $fee->tax,
				0,
				$source,
				$flagged
			);
		}

		return array_filter(
			$buckets,
			static function ( array $bucket ): bool {
				return 0.0 !== round( $bucket['net'], 4 ) || 0.0 !== round( $bucket['vat'], 4 );
			}
		);
	}

	private function line_for( Decision $decision, int $original_item_id ): ?LineDecision {
		$lines = $decision->lines();
		return ( $original_item_id > 0 ? $decision->line( (string) $original_item_id ) : null ) ?? ( $lines[0] ?? null );
	}

	/**
	 * @param array<string,array{rate_percent:float,net:float,vat:float,line:LineDecision|null,missing:bool}> $buckets
	 */
	private function add( array &$buckets, ?LineDecision $line, float $net, float $vat, int $product_id, Order $source, bool $flagged ): void {
		$percent = $this->rate_percent( $net, $vat, $line, $product_id, $source );
		$key     = $percent . '|' . ( null === $line ? '' : $line->supply_type() . $line->place_of_supply() . $line->scheme() );
		if ( ! isset( $buckets[ $key ] ) ) {
			$buckets[ $key ] = array(
				'rate_percent' => $percent,
				'net'          => 0.0,
				'vat'          => 0.0,
				'line'         => $line,
				'missing'      => false,
			);
		}
		$buckets[ $key ]['net'] += $net;
		$buckets[ $key ]['vat'] += $vat;
		if ( 0.0 !== round( $vat, 4 ) && null !== $line && ( $flagged || null === $this->rates->rate( $line->tax_country(), RateResolver::STANDARD ) ) ) {
			$buckets[ $key ]['missing'] = true;
		}
	}

	/**
	 * The rate the VAT was charged at. EDD rounds tax to the cent, so the
	 * quotient of a small line drifts from the rate; it is snapped to the
	 * country's current rate when it is close.
	 */
	private function rate_percent( float $net, float $vat, ?LineDecision $line, int $product_id, Order $source ): float {
		if ( 0.0 === round( $vat, 4 ) ) {
			return 0.0;
		}
		if ( 0.0 === round( $net, 4 ) ) {
			return round( $source->get_tax_rate(), 4 );
		}
		$effective = abs( $vat / $net ) * 100;
		if ( null !== $line ) {
			$category = $product_id > 0 ? DownloadVatCategory::for_download( $product_id ) : RateResolver::STANDARD;
			$known    = $this->rates->rate( $line->tax_country(), $category );
			if ( null !== $known && abs( $known - $effective ) <= 0.5 ) {
				return round( $known, 4 );
			}
		}
		return round( $effective, 2 );
	}

	/**
	 * Whether the rows add up to the order, tax included.
	 *
	 * @param array<string,array{rate_percent:float,net:float,vat:float,line:LineDecision|null,missing:bool}> $buckets
	 */
	private function ties_out( Order $order, array $buckets ): bool {
		$sum = 0.0;
		foreach ( $buckets as $bucket ) {
			$sum += $bucket['net'] + $bucket['vat'];
		}
		return abs( (float) $order->total - $sum ) < 0.01;
	}

	/**
	 * A refund whose items do not account for its total (a fee refunded by
	 * amount) is spread over the order's sale rows by its share of the total.
	 *
	 * @return array<string,array{rate_percent:float,net:float,vat:float,line:LineDecision|null,missing:bool}>
	 */
	private function proportional_buckets( Order $order, Order $refund, Decision $decision ): array {
		$total = (float) $order->total;
		if ( $total <= 0.0 ) {
			return array();
		}
		$share   = abs( (float) $refund->total ) / $total;
		$buckets = $this->buckets( $order, $order, $decision );
		foreach ( $buckets as $key => $bucket ) {
			$buckets[ $key ]['net'] = -$bucket['net'] * $share;
			$buckets[ $key ]['vat'] = -$bucket['vat'] * $share;
		}
		return $buckets;
	}

	/**
	 * @param array<string,array{rate_percent:float,net:float,vat:float,line:LineDecision|null,missing:bool}> $buckets
	 */
	private function insert_rows( Order $order, Decision $decision, array $buckets, string $entry_type, ?int $refund_id, DateTimeImmutable $at, string $period, string $relates_to, string $source ): void {
		global $wpdb;

		if ( array() === $buckets ) {
			return;
		}

		$data     = $decision->to_array();
		$fx       = $this->order_fx( $order );
		$vat_no   = $data['vat_number']['canonical'] ?? null;
		$resolved = $data['evidence']['resolved'] ?? null;
		$billing  = (string) ( $data['customer']['billing']['country'] ?? '' );
		$reverse  = array( Scheme::REVERSE_CHARGE_SERVICES, Scheme::INTRA_EU_GOODS );

		foreach ( $buckets as $bucket ) {
			$line   = $bucket['line'];
			$supply = null === $line ? Line::DIGITAL : $line->supply_type();
			$scheme = null === $line ? $decision->scheme() : $line->scheme();
			$place  = null === $line ? $billing : $line->place_of_supply();

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Schema::ledger_table(),
				array(
					'order_id'          => (int) $order->id,
					'refund_id'         => $refund_id,
					'entry_type'        => $entry_type,
					'decision_id'       => $data['id'],
					'scheme'            => $scheme,
					'rule'              => $decision->rule(),
					'supply_type'       => $supply,
					'customer_country'  => is_string( $resolved ) && '' !== $resolved ? $resolved : $billing,
					'place_of_supply'   => $place,
					'customer_vat'      => in_array( $scheme, $reverse, true ) && is_string( $vat_no ) ? $vat_no : null,
					'rate_percent'      => $bucket['rate_percent'],
					'net'               => round( $bucket['net'], 4 ),
					'vat'               => round( $bucket['vat'], 4 ),
					'currency'          => strtoupper( (string) $order->currency ),
					'eur_rate'          => $fx,
					'net_eur'           => null === $fx ? null : round( $bucket['net'] / $fx, 4 ),
					'vat_eur'           => null === $fx ? null : round( $bucket['vat'] / $fx, 4 ),
					'rate_missing'      => $bucket['missing'] ? 1 : 0,
					'supply_at'         => $at->format( 'Y-m-d H:i:s' ),
					'period'            => $period,
					'relates_to_period' => $relates_to,
					'source'            => $source,
					'created_at'        => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}
	}

	/**
	 * The ECB quote (currency units per EUR) for the supply date, fixed on the
	 * first ledger write so later writes and refunds convert identically.
	 */
	private function order_fx( Order $order ): ?float {
		$currency = strtoupper( (string) $order->currency );
		$stored   = edd_get_order_meta( (int) $order->id, self::FX_META, true );
		if ( is_array( $stored ) && ( $stored['currency'] ?? '' ) === $currency && isset( $stored['eur_rate'] ) ) {
			return (float) $stored['eur_rate'];
		}

		$ymd  = self::supply_time( $order )->format( 'Y-m-d' );
		$rate = $this->fx->rate_on_or_before( $currency, $ymd );
		if ( null === $rate ) {
			$this->schedule_fx_retry( $order );
			return null;
		}

		edd_update_order_meta(
			(int) $order->id,
			self::FX_META,
			array(
				'currency'  => $currency,
				'eur_rate'  => $rate,
				'rate_date' => $ymd,
				'source'    => 'ecb',
			)
		);
		return $rate;
	}

	private function schedule_fx_retry( Order $order ): void {
		$created = strtotime( (string) $order->date_created . ' UTC' );
		if ( false !== $created && $created > time() - 14 * DAY_IN_SECONDS ) {
			Scheduler::once( self::FX_RETRY_HOOK, array( (int) $order->id ), HOUR_IN_SECONDS );
		}
	}

	/**
	 * @param array<int,int|string> $args
	 * @return int[] years of the deleted rows
	 */
	private function purge( string $where, array $args ): array {
		global $wpdb;

		$table = Schema::ledger_table();
		$years = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT SUBSTR(relates_to_period, 1, 4) FROM {$table} WHERE {$where}", $args ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$where}", $args ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return array_map( 'intval', $years );
	}
}
