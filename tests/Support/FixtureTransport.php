<?php

namespace VatrightEdd\Tests\Support;

use VatrightEdd\Registry\HttpTransport;
use RuntimeException;

class FixtureTransport implements HttpTransport {

	/** @var string */
	private $fixture_dir;

	public function __construct( string $fixture_dir ) {
		$this->fixture_dir = rtrim( $fixture_dir, '/' );
	}

	public function request( string $method, string $url, string $body = '', array $headers = array(), int $timeout = 5 ): array {
		$files = glob( $this->fixture_dir . '/*.json' );
		foreach ( false === $files ? array() : $files as $file ) {
			$fixture = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! is_array( $fixture ) || ! $this->matches( $fixture['request'] ?? array(), $method, $url, $body ) ) {
				continue;
			}
			$response = $fixture['response'] ?? array();
			return array(
				'status' => (int) ( $response['status'] ?? 0 ),
				'body'   => (string) ( $response['body'] ?? '' ),
				'error'  => (string) ( $response['error'] ?? '' ),
			);
		}
		throw new RuntimeException( "No fixture in {$this->fixture_dir} for $method $url" ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private function matches( array $request, string $method, string $url, string $body ): bool {
		if ( strtoupper( (string) ( $request['method'] ?? '' ) ) !== strtoupper( $method ) ) {
			return false;
		}
		if ( ( $request['url'] ?? '' ) !== $url ) {
			return false;
		}
		$needle = (string) ( $request['body_contains'] ?? '' );
		return '' === $needle || false !== strpos( $body, $needle );
	}
}
