<?php

namespace VatrightEdd\Tests\Support;

use VatrightEdd\Registry\HttpTransport;

/**
 * Answers VIES by the number's digits. requestDate is always now, so a cached
 * verdict never ages out of a reuse window because the test file got older.
 */
class ViesStub implements HttpTransport {

	const VALID       = 'valid';
	const INVALID     = 'invalid';
	const UNAVAILABLE = 'unavailable';

	/** @var array<string,string> */
	private $answers;

	/** @var int */
	public $calls = 0;

	/**
	 * @param array<string,string> $answers digits => one of the constants
	 */
	public function __construct( array $answers ) {
		$this->answers = $answers;
	}

	public function set( string $digits, string $answer ): void {
		$this->answers[ $digits ] = $answer;
	}

	public function request( string $method, string $url, string $body = '', array $headers = array(), int $timeout = 5 ): array {
		++$this->calls;
		$payload = json_decode( $body, true );
		$digits  = is_array( $payload ) ? (string) ( $payload['vatNumber'] ?? '' ) : '';
		$answer  = $this->answers[ $digits ] ?? self::INVALID;

		if ( self::UNAVAILABLE === $answer ) {
			$data = array(
				'actionSucceed' => false,
				'errorWrappers' => array( array( 'error' => 'MS_UNAVAILABLE' ) ),
			);
		} else {
			$data = array(
				'countryCode'       => (string) ( $payload['countryCode'] ?? '' ),
				'vatNumber'         => $digits,
				'requestDate'       => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
				'valid'             => self::VALID === $answer,
				'requestIdentifier' => '',
				'name'              => self::VALID === $answer ? 'Example GmbH' : '---',
				'address'           => self::VALID === $answer ? 'Example Strasse 1' : '---',
			);
		}

		return array(
			'status' => 200,
			'body'   => (string) wp_json_encode( $data ),
			'error'  => '',
		);
	}
}
