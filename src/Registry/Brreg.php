<?php

namespace VatrightEdd\Registry;

use VatrightEdd\Domain\ValidationResult;
use VatrightEdd\Domain\VatNumber;
use Throwable;

class Brreg implements Registry {

	const ENDPOINT = 'https://data.brreg.no/enhetsregisteret/api/enheter/';

	/** @var HttpTransport */
	private $transport;

	/** @var int */
	private $timeout;

	public function __construct( HttpTransport $transport, int $timeout = 5 ) {
		$this->transport = $transport;
		$this->timeout   = $timeout;
	}

	public function id(): string {
		return 'brreg';
	}

	public function supports( VatNumber $n ): bool {
		return 'NO' === $n->prefix();
	}

	public function check( VatNumber $n, ?VatNumber $requester ): ValidationResult {
		try {
			return $this->lookup( $n );
		} catch ( Throwable $e ) {
			return $this->result( ValidationResult::UNAVAILABLE, null, 'exception' );
		}
	}

	private function lookup( VatNumber $n ): ValidationResult {
		$response = $this->transport->request(
			'GET',
			self::ENDPOINT . rawurlencode( $n->number() ),
			'',
			array( 'Accept' => 'application/json' ),
			$this->timeout
		);
		if ( '' !== $response['error'] ) {
			return $this->result( ValidationResult::UNAVAILABLE, null, 'transport_error' );
		}
		if ( 404 === $response['status'] ) {
			return $this->result( ValidationResult::INVALID, null, 'not_found' );
		}
		if ( 200 !== $response['status'] ) {
			return $this->result( ValidationResult::UNAVAILABLE, null, 'http_' . $response['status'] );
		}

		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) || ! isset( $data['registrertIMvaregisteret'] ) || ! is_bool( $data['registrertIMvaregisteret'] ) ) {
			return $this->result( ValidationResult::UNAVAILABLE, null, 'malformed_response' );
		}

		$name = isset( $data['navn'] ) && is_string( $data['navn'] ) ? $data['navn'] : null;
		if ( ! $data['registrertIMvaregisteret'] ) {
			return $this->result( ValidationResult::INVALID, $name, 'not_vat_registered' );
		}
		return $this->result( ValidationResult::VALID, $name );
	}

	private function result( string $status, ?string $name, string $detail = '' ): ValidationResult {
		return new ValidationResult( $status, $this->id(), gmdate( 'Y-m-d\TH:i:s\Z' ), $name, null, null, false, $detail );
	}
}
