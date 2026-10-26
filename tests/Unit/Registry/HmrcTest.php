<?php

namespace VatrightEdd\Tests\Unit\Registry;

use VatrightEdd\Domain\ValidationResult;
use VatrightEdd\Domain\VatNumber;
use VatrightEdd\Registry\Hmrc;
use VatrightEdd\Registry\HttpTransport;
use VatrightEdd\Tests\Support\FixtureTransport;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/FixtureTransport.php';
require_once dirname( __DIR__, 2 ) . '/Support/TransientStub.php';

class HmrcTest extends TestCase {

	protected function setUp(): void {
		vatright_edd_test_reset_transients();
	}

	private function hmrc( ?HttpTransport $transport = null, string $id = 'test-id' ): Hmrc {
		$transport = $transport ?? new FixtureTransport( dirname( __DIR__, 2 ) . '/fixtures/hmrc' );
		return new Hmrc( $transport, $id, 'test-secret' );
	}

	private function gb( string $number ): VatNumber {
		return VatNumber::parse( 'GB' . $number, 'GB' );
	}

	public function test_supports_gb_only(): void {
		$this->assertTrue( $this->hmrc()->supports( $this->gb( '553557881' ) ) );
		$this->assertFalse( $this->hmrc()->supports( VatNumber::parse( 'XI553557881', 'GB' ) ) );
		$this->assertFalse( $this->hmrc()->supports( VatNumber::parse( 'DE123456789', 'DE' ) ) );
	}

	public function test_valid_number(): void {
		$result = $this->hmrc()->check( $this->gb( '553557881' ), null );
		$this->assertSame( ValidationResult::VALID, $result->status() );
		$this->assertSame( 'hmrc', $result->registry() );
		$this->assertSame( 'Credite Sberger Donal Inc.', $result->name() );
		$this->assertSame( '131B Barton Hamlet, SW97 5CK, GB', $result->address() );
		$this->assertNull( $result->consultation() );
	}

	public function test_valid_with_requester_returns_consultation_number(): void {
		$result = $this->hmrc()->check( $this->gb( '553557881' ), $this->gb( '146295999727' ) );
		$this->assertSame( ValidationResult::VALID, $result->status() );
		$this->assertSame( 'ypAeKRPlW', $result->consultation() );
	}

	public function test_not_found_is_invalid(): void {
		$result = $this->hmrc()->check( $this->gb( '111111111' ), null );
		$this->assertSame( ValidationResult::INVALID, $result->status() );
		$this->assertSame( 'not_found', $result->detail() );
	}

	public function test_server_error_is_unavailable(): void {
		$result = $this->hmrc()->check( $this->gb( '222222222' ), null );
		$this->assertSame( ValidationResult::UNAVAILABLE, $result->status() );
		$this->assertSame( 'http_500', $result->detail() );
	}

	public function test_timeout_is_unavailable(): void {
		$result = $this->hmrc()->check( $this->gb( '333333333' ), null );
		$this->assertSame( ValidationResult::UNAVAILABLE, $result->status() );
		$this->assertSame( 'transport_error', $result->detail() );
	}

	public function test_rejected_credentials_are_unavailable(): void {
		$result = $this->hmrc( null, 'bad-id' )->check( $this->gb( '553557881' ), null );
		$this->assertSame( ValidationResult::UNAVAILABLE, $result->status() );
		$this->assertSame( 'token_unavailable', $result->detail() );
	}

	public function test_requester_rejected_retries_without_requester(): void {
		$result = $this->hmrc()->check( $this->gb( '553557881' ), $this->gb( '999999999' ) );
		$this->assertSame( ValidationResult::VALID, $result->status() );
		$this->assertSame( 'requester_rejected', $result->detail() );
	}

	public function test_hmrc_without_credentials_makes_no_request(): void {
		$transport = new class() implements HttpTransport {
			public $calls = 0;

			public function request( string $method, string $url, string $body = '', array $headers = array(), int $timeout = 5 ): array {
				++$this->calls;
				return array(
					'status' => 500,
					'body'   => '',
					'error'  => '',
				);
			}
		};
		$hmrc      = new Hmrc( $transport, '', '' );
		$result    = $hmrc->check( $this->gb( '553557881' ), null );
		$this->assertSame( ValidationResult::UNCHECKED, $result->status() );
		$this->assertSame( 'no_credentials', $result->detail() );
		$this->assertSame( 0, $transport->calls );
	}

	public function test_hmrc_token_is_reused(): void {
		$transport = $this->counting_transport(
			array(
				array( 200, '{"access_token":"tok-1","expires_in":14400}' ),
				array( 200, '{"target":{"name":"A","vatNumber":"1","address":{}},"processingDate":"x"}' ),
				array( 200, '{"target":{"name":"A","vatNumber":"1","address":{}},"processingDate":"x"}' ),
			)
		);
		$hmrc      = $this->hmrc( $transport );
		$hmrc->check( $this->gb( '553557881' ), null );
		$hmrc->check( $this->gb( '553557881' ), null );

		$tokens = array_filter(
			$transport->urls,
			function ( $url ) {
				return false !== strpos( $url, '/oauth/token' );
			}
		);
		$this->assertCount( 1, $tokens );
		$this->assertCount( 3, $transport->urls );
		$this->assertSame( 'Bearer tok-1', $transport->headers[1]['Authorization'] );
	}

	public function test_401_drops_token_and_retries_once(): void {
		$transport = $this->counting_transport(
			array(
				array( 200, '{"access_token":"stale","expires_in":14400}' ),
				array( 401, '{"code":"INVALID_CREDENTIALS","message":"Invalid Authentication information provided"}' ),
				array( 200, '{"access_token":"fresh","expires_in":14400}' ),
				array( 200, '{"target":{"name":"A","vatNumber":"1","address":{}},"processingDate":"x"}' ),
			)
		);
		$result    = $this->hmrc( $transport )->check( $this->gb( '553557881' ), null );

		$this->assertSame( ValidationResult::VALID, $result->status() );
		$this->assertSame( 'Bearer fresh', $transport->headers[3]['Authorization'] );
	}

	public function test_persistent_401_stops_after_one_retry(): void {
		$transport = $this->counting_transport(
			array(
				array( 200, '{"access_token":"a","expires_in":14400}' ),
				array( 401, '{}' ),
				array( 200, '{"access_token":"b","expires_in":14400}' ),
				array( 401, '{}' ),
			)
		);
		$result    = $this->hmrc( $transport )->check( $this->gb( '553557881' ), null );

		$this->assertSame( ValidationResult::UNAVAILABLE, $result->status() );
		$this->assertCount( 4, $transport->urls );
	}

	/**
	 * @param array<int,array{0:int,1:string}> $queue
	 */
	private function counting_transport( array $queue ): HttpTransport {
		return new class( $queue ) implements HttpTransport {
			/** @var array<int,array{0:int,1:string}> */
			private $queue;

			/** @var string[] */
			public $urls = array();

			/** @var array<int,array<string,string>> */
			public $headers = array();

			public function __construct( array $queue ) {
				$this->queue = $queue;
			}

			public function request( string $method, string $url, string $body = '', array $headers = array(), int $timeout = 5 ): array {
				$this->urls[]    = $url;
				$this->headers[] = $headers;
				$next            = array_shift( $this->queue );
				return array(
					'status' => $next[0],
					'body'   => $next[1],
					'error'  => '',
				);
			}
		};
	}
}
