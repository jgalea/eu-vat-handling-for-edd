<?php

namespace VatrightEdd\Import;

use DateTimeImmutable;
use DateTimeZone;
use EDD\Orders\Order;
use Throwable;
use VatrightEdd\Coexistence;
use VatrightEdd\Ledger\Backfill;
use VatrightEdd\Ledger\Fx;
use VatrightEdd\Ledger\LedgerWriter;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Scheduler;

/**
 * Brings order history into the ledger: orders Barn2 EDD EU VAT handled get
 * the decision its meta implies, the rest are inferred like any backfill.
 * The dry run walks the same selection and counts the rows the run writes.
 */
class Importer {

	const HOOK = 'vatright_edd_import_barn2_batch';

	const OPTION = 'vatright_edd_import_barn2';

	const BATCH = 50;

	const PAGE = 200;

	const STALE_AFTER = 900;

	const RUNNING = 'running';

	const DONE = 'done';

	/** @var Backfill */
	private $backfill;

	/** @var Barn2History */
	private $history;

	/** @var LedgerWriter */
	private $ledger;

	/** @var Fx */
	private $fx;

	public function __construct( Backfill $backfill, Barn2History $history, LedgerWriter $ledger, Fx $fx ) {
		$this->backfill = $backfill;
		$this->history  = $history;
		$this->ledger   = $ledger;
		$this->fx       = $fx;
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run_batch' ) );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function state(): ?array {
		$state = get_option( self::OPTION, null );
		return is_array( $state ) ? $state : null;
	}

	public static function is_running(): bool {
		$state = self::state();
		return null !== $state && self::RUNNING === ( $state['status'] ?? '' ) && time() - (int) ( $state['updated'] ?? 0 ) < self::STALE_AFTER;
	}

	/**
	 * Counts, per quarter and scheme, what a run from $since would write.
	 *
	 * @return array{orders:int,rows:int,barn2_orders:int,backfill_orders:int,quarters:array<string,array{orders:int,rows:int,schemes:array<string,int>}>}
	 */
	public function dry_run( DateTimeImmutable $since ): array {
		$report = array(
			'orders'          => 0,
			'rows'            => 0,
			'barn2_orders'    => 0,
			'backfill_orders' => 0,
			'quarters'        => array(),
		);

		foreach ( $this->walk( $since ) as $order ) {
			if ( $this->done( $order ) ) {
				continue;
			}
			$decision = $this->history->decision( $order );
			$from     = null !== $decision ? 'barn2_orders' : 'backfill_orders';
			$decision = $decision ?? $this->backfill->infer( $order );

			++$report['orders'];
			++$report[ $from ];
			$sale_quarter = LedgerWriter::period_for( LedgerWriter::supply_time( $order ) );
			$this->tally_order( $report['quarters'], $sale_quarter );
			foreach ( $this->ledger->plan( $order, $decision ) as $row ) {
				++$report['rows'];
				$this->tally_row( $report['quarters'], $row['period'], $row['scheme'] );
			}
		}

		ksort( $report['quarters'] );
		return $report;
	}

	/**
	 * Queues the first batch, which fetches the ECB rates the run needs.
	 *
	 * @return bool False when a run is already going.
	 */
	public function start( DateTimeImmutable $since ): bool {
		if ( self::is_running() ) {
			return false;
		}
		$this->begin( $since );
		Scheduler::once( self::HOOK, array( 0 ) );
		return true;
	}

	/**
	 * Runs every batch in this process, for the CLI.
	 *
	 * @return array<string,mixed>|null The final state, or null when a run is already going.
	 */
	public function run_all( DateTimeImmutable $since ): ?array {
		if ( self::is_running() ) {
			return null;
		}
		$this->begin( $since );
		while ( $this->process_next() ) {
			continue;
		}
		return (array) self::state();
	}

	/**
	 * @param mixed $offset
	 */
	public function run_batch( $offset = 0 ): void {
		$state = self::state();
		if ( null === $state || self::RUNNING !== ( $state['status'] ?? '' ) || (int) $offset !== (int) $state['offset'] ) {
			return;
		}
		if ( $this->process_next() ) {
			Scheduler::once( self::HOOK, array( (int) ( self::state()['offset'] ?? 0 ) ) );
		}
	}

	/**
	 * Handles one batch of the running import.
	 *
	 * @return bool Whether more orders remain.
	 */
	private function process_next(): bool {
		$state   = (array) self::state();
		$since   = new DateTimeImmutable( (string) $state['since'] . ' 00:00:00', wp_timezone() );
		$offset  = (int) $state['offset'];
		$orders  = $this->backfill->select( $since, $offset, self::BATCH );
		$version = self::barn2_version();

		if ( empty( $state['prepared'] ) ) {
			$this->prepare( $since );
			$state['prepared'] = true;
		}

		foreach ( $orders as $order ) {
			if ( $this->done( $order ) ) {
				continue;
			}
			try {
				$decision = $this->history->decision( $order );
				$source   = null !== $decision ? Barn2History::SOURCE : Backfill::SOURCE;
				if ( $this->backfill->backfill_order( $order, $source, $decision ) ) {
					++$state['orders'];
					$state['rows'] += $this->ledger->count_rows( (int) $order->id );
					if ( null !== $decision ) {
						Barn2History::mark_imported( (int) $order->id, $version );
					}
				}
			} catch ( Throwable $e ) {
				++$state['failed'];
				edd_debug_log( 'EU VAT Handling: could not import order ' . (int) $order->id . ' (' . get_class( $e ) . ').' );
			}
		}

		$more              = count( $orders ) === self::BATCH;
		$state['offset']   = $offset + count( $orders );
		$state['status']   = $more ? self::RUNNING : self::DONE;
		$state['finished'] = $more ? '' : gmdate( 'Y-m-d H:i:s' );
		$this->save( $state );
		return $more;
	}

	/**
	 * An order is done once it has a decision or ledger rows, which is what
	 * makes a second run write nothing.
	 */
	public function done( Order $order ): bool {
		$order_id = (int) $order->id;
		return '' !== (string) edd_get_order_meta( $order_id, DecisionStore::META, true ) || 0 !== $this->ledger->count_rows( $order_id );
	}

	/**
	 * @return \Generator<int,Order>
	 */
	private function walk( DateTimeImmutable $since ): \Generator {
		$offset = 0;
		do {
			$orders = $this->backfill->select( $since, $offset, self::PAGE );
			$full   = count( $orders ) === self::PAGE;
			foreach ( $orders as $order ) {
				yield $order;
			}
			$offset += self::PAGE;
		} while ( $full );
	}

	private function begin( DateTimeImmutable $since ): void {
		$this->save(
			array(
				'status'   => self::RUNNING,
				'since'    => $since->format( 'Y-m-d' ),
				'offset'   => 0,
				'orders'   => 0,
				'rows'     => 0,
				'failed'   => 0,
				'prepared' => false,
				'started'  => gmdate( 'Y-m-d H:i:s' ),
				'finished' => '',
			)
		);
	}

	/**
	 * One ECB request per currency for the whole range, so the batches only
	 * read stored rates.
	 */
	private function prepare( DateTimeImmutable $since ): void {
		$ranges = array();
		foreach ( $this->walk( $since ) as $order ) {
			$currency = strtoupper( (string) $order->currency );
			if ( 'EUR' === $currency || $this->done( $order ) ) {
				continue;
			}
			$day = LedgerWriter::supply_time( $order )->format( 'Y-m-d' );
			if ( ! isset( $ranges[ $currency ] ) ) {
				$ranges[ $currency ] = array( $day, $day );
			}
			$ranges[ $currency ] = array( min( $ranges[ $currency ][0], $day ), max( $ranges[ $currency ][1], $day ) );
		}
		foreach ( $ranges as $currency => $range ) {
			$from = ( new DateTimeImmutable( $range[0], new DateTimeZone( 'UTC' ) ) )->modify( '-' . Fx::LOOKUP_DAYS . ' days' );
			$this->fx->prefetch( $currency, $from->format( 'Y-m-d' ), $range[1] );
		}
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function save( array $state ): void {
		$state['updated'] = time();
		update_option( self::OPTION, $state, false );
	}

	/**
	 * @param array<string,array{orders:int,rows:int,schemes:array<string,int>}> $quarters
	 */
	private function tally_order( array &$quarters, string $quarter ): void {
		$quarters[ $quarter ] = $quarters[ $quarter ] ?? array(
			'orders'  => 0,
			'rows'    => 0,
			'schemes' => array(),
		);
		++$quarters[ $quarter ]['orders'];
	}

	/**
	 * @param array<string,array{orders:int,rows:int,schemes:array<string,int>}> $quarters
	 */
	private function tally_row( array &$quarters, string $quarter, string $scheme ): void {
		$quarters[ $quarter ] = $quarters[ $quarter ] ?? array(
			'orders'  => 0,
			'rows'    => 0,
			'schemes' => array(),
		);
		++$quarters[ $quarter ]['rows'];
		$quarters[ $quarter ]['schemes'][ $scheme ] = ( $quarters[ $quarter ]['schemes'][ $scheme ] ?? 0 ) + 1;
	}

	private static function barn2_version(): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		return (string) ( $plugins[ Coexistence::BARN2_PLUGIN ]['Version'] ?? '' );
	}
}
