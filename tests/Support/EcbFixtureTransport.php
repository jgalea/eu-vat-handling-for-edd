<?php

namespace VatrightEdd\Tests\Support;

use VatrightEdd\Registry\HttpTransport;

/**
 * Serves the recorded USD series, cut to the requested period the way the ECB
 * does: an empty 200 when no day in range was published, a 404 for any other
 * currency.
 */
class EcbFixtureTransport implements HttpTransport {

	/** @var string[] */
	public $calls = array();

	/** @var bool */
	public $fail = false;

	public function request( string $method, string $url, string $body = '', array $headers = array(), int $timeout = 5 ): array {
		$this->calls[] = $url;
		if ( $this->fail ) {
			return array(
				'status' => 0,
				'body'   => '',
				'error'  => 'timeout',
			);
		}

		$dir = dirname( __DIR__ ) . '/fixtures/ecb/';
		if ( false === strpos( $url, '/D.USD.EUR.' ) ) {
			return array(
				'status' => 404,
				'body'   => (string) file_get_contents( $dir . 'unknown_currency.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				'error'  => '',
			);
		}

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$lines  = explode( "\n", trim( (string) file_get_contents( $dir . 'usd_2026-09-25_2026-10-18.csv' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$header = array_shift( $lines );
		$kept   = array();
		foreach ( $lines as $line ) {
			$date = explode( ',', $line )[6];
			if ( $date >= $query['startPeriod'] && $date <= $query['endPeriod'] ) {
				$kept[] = $line;
			}
		}

		return array(
			'status' => 200,
			'body'   => array() === $kept ? '' : $header . "\n" . implode( "\n", $kept ) . "\n",
			'error'  => '',
		);
	}
}
