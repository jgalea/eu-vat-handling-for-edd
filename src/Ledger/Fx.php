<?php

namespace VatrightEdd\Ledger;

use DateTimeImmutable;
use DateTimeZone;
use VatrightEdd\Registry\HttpTransport;

/**
 * Rates are the ECB reference quote: units of the currency per 1 EUR.
 * A date the ECB did not publish on is stored with a NULL rate, so it is
 * fetched once and not asked for again.
 */
class Fx {

	const LOOKUP_DAYS = 10;
	const ENDPOINT    = 'https://data-api.ecb.europa.eu/service/data/EXR/D.%s.EUR.SP00.A';

	/** @var HttpTransport */
	private $transport;

	public function __construct( HttpTransport $transport ) {
		$this->transport = $transport;
	}

	public function rate_on_or_before( string $currency, string $ymd ): ?float {
		return $this->lookup( strtoupper( $currency ), $ymd, -1 );
	}

	public function rate_on_or_after( string $currency, string $ymd ): ?float {
		return $this->lookup( strtoupper( $currency ), $ymd, 1 );
	}

	/**
	 * Fetches a whole date range in one request, so a bulk import does not ask
	 * the ECB once per order. Lookups inside the range then hit the stored rows.
	 */
	public function prefetch( string $currency, string $from_ymd, string $to_ymd ): void {
		$currency = strtoupper( $currency );
		$day      = new DateTimeImmutable( $from_ymd, new DateTimeZone( 'UTC' ) );
		$end      = new DateTimeImmutable( $to_ymd, new DateTimeZone( 'UTC' ) );
		if ( 'EUR' === $currency || $day > $end ) {
			return;
		}
		$days = array();
		while ( $day <= $end ) {
			$days[] = $day->format( 'Y-m-d' );
			$day    = $day->modify( '+1 day' );
		}
		$this->fetch( $currency, $days );
	}

	/**
	 * @return array<string,float> date => rate
	 */
	public static function parse_csv( string $csv ): array {
		$lines = preg_split( '/\r\n|\n|\r/', trim( $csv ) );
		if ( false === $lines || count( $lines ) < 2 ) {
			return array();
		}
		$header = str_getcsv( (string) array_shift( $lines ), ',', '"', '' );
		$date   = array_search( 'TIME_PERIOD', $header, true );
		$value  = array_search( 'OBS_VALUE', $header, true );
		if ( false === $date || false === $value ) {
			return array();
		}
		$rates = array();
		foreach ( $lines as $line ) {
			$row = str_getcsv( $line, ',', '"', '' );
			if ( isset( $row[ $date ], $row[ $value ] ) && is_numeric( $row[ $value ] ) ) {
				$rates[ $row[ $date ] ] = (float) $row[ $value ];
			}
		}
		return $rates;
	}

	private function lookup( string $currency, string $ymd, int $step ): ?float {
		if ( 'EUR' === $currency ) {
			return 1.0;
		}
		$days    = $this->window( $ymd, $step );
		$rows    = $this->cached( $currency, $days );
		$fetched = false;
		foreach ( $days as $day ) {
			if ( ! $fetched && ! array_key_exists( $day, $rows ) ) {
				$this->fetch( $currency, $days );
				$rows    = $this->cached( $currency, $days );
				$fetched = true;
			}
			if ( isset( $rows[ $day ] ) ) {
				return (float) $rows[ $day ];
			}
		}
		return null;
	}

	/**
	 * @return string[] dates ordered by preference: the requested day first, then outward.
	 */
	private function window( string $ymd, int $step ): array {
		$base = new DateTimeImmutable( $ymd, new DateTimeZone( 'UTC' ) );
		$days = array();
		for ( $i = 0; $i <= self::LOOKUP_DAYS; $i++ ) {
			$days[] = $base->modify( ( $step * $i ) . ' days' )->format( 'Y-m-d' );
		}
		return $days;
	}

	/**
	 * @param string[] $days
	 * @return array<string,float|null> only dates that have a stored row
	 */
	private function cached( string $currency, array $days ): array {
		global $wpdb;

		$table = Schema::fx_table();
		$marks = implode( ',', array_fill( 0, count( $days ), '%s' ) );
		$found = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare( "SELECT rate_date, rate FROM {$table} WHERE currency = %s AND rate_date IN ({$marks})", array_merge( array( $currency ), $days ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			ARRAY_A
		);
		$rows  = array();
		foreach ( $found as $row ) {
			$rows[ $row['rate_date'] ] = null === $row['rate'] ? null : (float) $row['rate'];
		}
		return $rows;
	}

	/**
	 * Fetches the whole window so the stored rows are contiguous, and marks
	 * unpublished past days with a NULL rate. Days within the last two do not
	 * get a marker because the ECB may still publish them, so a repeat fetch of
	 * the same window is held off for an hour.
	 *
	 * @param string[] $days
	 */
	private function fetch( string $currency, array $days ): void {
		global $wpdb;

		sort( $days );
		$start = $days[0];
		$end   = $days[ count( $days ) - 1 ];
		$url   = sprintf( self::ENDPOINT, rawurlencode( $currency ) ) . '?' . http_build_query(
			array(
				'startPeriod' => $start,
				'endPeriod'   => $end,
				'format'      => 'csvdata',
			)
		);

		$throttle = 'vatright_edd_fx_' . md5( $url );
		if ( false !== get_transient( $throttle ) ) {
			return;
		}

		$response = $this->transport->request( 'GET', $url, '', array(), 5 );
		set_transient( $throttle, 1, 200 === $response['status'] ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		if ( 200 !== $response['status'] ) {
			return;
		}

		$rates  = self::parse_csv( $response['body'] );
		$marker = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '-2 days' )->format( 'Y-m-d' );
		$table  = Schema::fx_table();
		foreach ( $days as $day ) {
			if ( isset( $rates[ $day ] ) ) {
				$wpdb->query( $wpdb->prepare( "REPLACE INTO {$table} (currency, rate_date, rate) VALUES (%s, %s, %f)", $currency, $day, $rates[ $day ] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			} elseif ( $day <= $marker ) {
				$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (currency, rate_date, rate) VALUES (%s, %s, NULL)", $currency, $day ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			}
		}
	}
}
