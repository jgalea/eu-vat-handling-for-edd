<?php

namespace VatrightEdd\Tests\Unit\Registry;

use VatrightEdd\Domain\ValidationResult;
use VatrightEdd\Domain\VatNumber;
use VatrightEdd\Registry\HttpTransport;
use VatrightEdd\Registry\SoapEnvelope;
use VatrightEdd\Registry\SwissUid;
use VatrightEdd\Tests\Support\FixtureTransport;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/FixtureTransport.php';

class SwissUidTest extends TestCase {

	private function uid(): SwissUid {
		return new SwissUid( new FixtureTransport( dirname( __DIR__, 2 ) . '/fixtures/uid' ) );
	}

	private function che( string $number ): VatNumber {
		return VatNumber::parse( 'CHE' . $number, 'CH' );
	}

	public function test_supports_che_only(): void {
		$this->assertTrue( $this->uid()->supports( $this->che( '101654423' ) ) );
		$this->assertFalse( $this->uid()->supports( VatNumber::parse( 'NO923609016', 'NO' ) ) );
	}

	public function test_valid_number(): void {
		$result = $this->uid()->check( $this->che( '101654423' ), null );
		$this->assertSame( ValidationResult::VALID, $result->status() );
		$this->assertSame( 'uid', $result->registry() );
	}

	public function test_unregistered_number_is_invalid(): void {
		$result = $this->uid()->check( $this->che( '105909036' ), null );
		$this->assertSame( ValidationResult::INVALID, $result->status() );
	}

	public function test_format_fault_is_invalid(): void {
		$result = $this->uid()->check( $this->che( '10165442' ), null );
		$this->assertSame( ValidationResult::INVALID, $result->status() );
		$this->assertSame( 'format_rejected', $result->detail() );
	}

	/**
	 * @dataProvider failures
	 */
	public function test_failures_are_unavailable( string $number, string $detail ): void {
		$result = $this->uid()->check( $this->che( $number ), null );
		$this->assertSame( ValidationResult::UNAVAILABLE, $result->status() );
		$this->assertSame( $detail, $result->detail() );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function failures(): array {
		return array(
			'internal fault' => array( '100000001', 'uid_unavailable' ),
			'http 503'       => array( '100000002', 'malformed_response' ),
			'timeout'        => array( '100000003', 'transport_error' ),
			'html body'      => array( '100000004', 'uid_unavailable' ),
			'xxe payload'    => array( '100000005', 'malformed_response' ),
		);
	}

	public function test_soap_parser_rejects_external_entities(): void {
		$payload = (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/uid/xxe.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$body    = json_decode( $payload, true )['response']['body'];

		$this->assertNull( SoapEnvelope::xpath( $body ) );
		$this->assertNotNull( SoapEnvelope::xpath( '<a/>' ), 'control: plain XML still parses' );
	}

	public function test_request_carries_canonical_number_and_soap_action(): void {
		$transport = new class() implements HttpTransport {
			/** @var string */
			public $body = '';

			/** @var array<string,string> */
			public $headers = array();

			public function request( string $method, string $url, string $body = '', array $headers = array(), int $timeout = 5 ): array {
				$this->body    = $body;
				$this->headers = $headers;
				return array(
					'status' => 200,
					'body'   => '',
					'error'  => '',
				);
			}
		};
		( new SwissUid( $transport ) )->check( VatNumber::parse( 'CHE-101.654.423 MWST', 'CH' ), null );

		$this->assertStringContainsString( '<vatNumber>CHE101654423</vatNumber>', $transport->body );
		$this->assertSame( '"http://www.uid.admin.ch/xmlns/uid-wse/IPublicServices/ValidateVatNumber"', $transport->headers['SOAPAction'] );
	}

	public function test_envelope_escapes_parameter_values(): void {
		$xml = SoapEnvelope::build( 'urn:x', 'Op', array( 'a' => '<&"' ) );
		$this->assertStringContainsString( '<a>&lt;&amp;&quot;</a>', $xml );
		$this->assertNotNull( SoapEnvelope::xpath( $xml ) );
	}
}
