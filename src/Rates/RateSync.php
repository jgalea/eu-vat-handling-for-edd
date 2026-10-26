<?php

namespace VatrightEdd\Rates;

use VatrightEdd\Ledger\Schema;
use VatrightEdd\Scheduler;

class RateSync {

	const HOOK = 'vatright_edd_rates_check';

	const OPTION_LATEST = 'vatright_edd_rates_latest';

	const OPTION_PENDING = 'vatright_edd_rates_pending';

	/** @var Tedb */
	private $tedb;

	public function __construct( Tedb $tedb ) {
		$this->tedb = $tedb;
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'check' ) );
		Scheduler::recurring( self::HOOK, 'weekly' );
	}

	public function latest(): RateSet {
		$stored = get_option( self::OPTION_LATEST );
		return is_array( $stored ) ? RateSet::from_array( $stored ) : Snapshot::load();
	}

	public function refresh(): bool {
		$set = $this->tedb->fetch( gmdate( 'Y-m-d' ) );
		if ( null === $set ) {
			return false;
		}
		update_option( self::OPTION_LATEST, $set->to_array(), false );
		return true;
	}

	public function check(): void {
		$this->refresh();
		update_option( self::OPTION_PENDING, $this->plan( $this->latest() )->pending_count(), false );
	}

	public function plan( RateSet $set ): RateDiff {
		global $wpdb;

		$targets = array();
		foreach ( $set->country_codes() as $country ) {
			$targets[] = array(
				'country' => $country,
				'class'   => '',
				'type'    => 'standard',
				'rate'    => (float) $set->standard( $country ),
			);
		}

		$imported = array();
		$table    = Schema::rate_rows_table();
		$rows     = $wpdb->get_results( "SELECT edd_rate_id, imported_rate FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
		foreach ( (array) $rows as $row ) {
			$imported[ (int) $row->edd_rate_id ] = (float) $row->imported_rate;
		}

		$existing = array();
		/** @var array<int,array{id:int,country:string,rate:float,state:string,status:string,scope:string}> $edd_rates */
		$edd_rates = edd_get_tax_rates();
		foreach ( $edd_rates as $rate ) {
			if ( 'active' !== $rate['status'] || 'country' !== $rate['scope'] || '' !== $rate['state'] || '' === $rate['country'] ) {
				continue;
			}
			$key = RateDiff::key( $rate['country'], '' );
			if ( ! isset( $existing[ $key ] ) ) {
				$existing[ $key ] = array(
					'rate_id'  => (int) $rate['id'],
					'current'  => (float) $rate['rate'],
					'imported' => $imported[ (int) $rate['id'] ] ?? null,
				);
			}
		}
		return RateDiff::build( $targets, $existing, $set->source(), $set->situation_on() );
	}

	/**
	 * @param array<int,string> $accepted_keys
	 */
	public function apply( RateDiff $diff, array $accepted_keys, bool $overwrite_edited ): int {
		global $wpdb;

		$table   = Schema::rate_rows_table();
		$applied = 0;
		foreach ( $diff->entries() as $key => $entry ) {
			if ( ! in_array( $key, $accepted_keys, true ) || RateDiff::UNCHANGED === $entry['status'] ) {
				continue;
			}
			if ( RateDiff::EDITED === $entry['status'] && ! $overwrite_edited ) {
				continue;
			}
			// EDD retires the active rate for the country when a new one is added, which keeps the history.
			$rate_id = edd_add_tax_rate(
				array(
					'name'        => $entry['country'],
					'description' => '',
					'amount'      => $entry['rate'],
					'scope'       => 'country',
					'status'      => 'active',
				)
			);
			if ( ! $rate_id ) {
				continue;
			}
			$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'edd_rate_id'   => $rate_id,
					'country'       => $entry['country'],
					'imported_rate' => $entry['rate'],
					'source'        => $diff->source(),
					'source_date'   => $diff->source_date(),
				)
			);
			++$applied;
		}
		update_option( self::OPTION_PENDING, max( 0, $diff->pending_count() - $applied ), false );
		return $applied;
	}
}
