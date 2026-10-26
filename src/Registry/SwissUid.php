<?php

namespace VatrightEdd\Registry;

use VatrightEdd\Domain\ValidationResult;
use VatrightEdd\Domain\VatNumber;
use Throwable;

class SwissUid implements Registry {

	const ENDPOINT = 'https://www.uid-wse.admin.ch/V5.0/PublicServices.svc';

	const NS = 'http://www.uid.admin.ch/xmlns/uid-wse';

	const ACTION = '"http://www.uid.admin.ch/xmlns/uid-wse/IPublicServices/ValidateVatNumber"';

	/** @var HttpTransport */
	private $transport;

	/** @var int */
	private $timeout;

	public function __construct( HttpTransport $transport, int $timeout = 5 ) {
		$this->transport = $transport;
		$this->timeout   = $timeout;
	}

	public function id(): string {
		return 'uid';
	}

	public function supports( VatNumber $n ): bool {
		return 'CHE' === $n->prefix();
	}

	public function check( VatNumber $n, ?VatNumber $requester ): ValidationResult {
		try {
			return $this->lookup( $n );
		} catch ( Throwable $e ) {
			return $this->result( ValidationResult::UNAVAILABLE, 'exception' );
		}
	}

	private function lookup( VatNumber $n ): ValidationResult {
		$response = $this->transport->request(
			'POST',
			self::ENDPOINT,
			SoapEnvelope::build( self::NS, 'ValidateVatNumber', array( 'vatNumber' => $n->canonical() ) ),
			array(
				'Content-Type' => 'text/xml; charset=utf-8',
				'SOAPAction'   => self::ACTION,
			),
			$this->timeout
		);
		if ( '' !== $response['error'] ) {
			return $this->result( ValidationResult::UNAVAILABLE, 'transport_error' );
		}

		$xpath = SoapEnvelope::xpath( $response['body'] );
		if ( null === $xpath ) {
			return $this->result( ValidationResult::UNAVAILABLE, 'malformed_response' );
		}

		$answer = (string) $xpath->evaluate( 'normalize-space(//*[local-name()="ValidateVatNumberResult"])' );
		if ( 'true' === $answer ) {
			return $this->result( ValidationResult::VALID );
		}
		if ( 'false' === $answer ) {
			return $this->result( ValidationResult::INVALID );
		}

		$error = (string) $xpath->evaluate( 'normalize-space(//*[local-name()="businessFault"]/*[local-name()="error"])' );
		if ( 'Data_validation_failed' === $error ) {
			return $this->result( ValidationResult::INVALID, 'format_rejected' );
		}

		return $this->result( ValidationResult::UNAVAILABLE, 'uid_unavailable' );
	}

	private function result( string $status, string $detail = '' ): ValidationResult {
		return new ValidationResult( $status, $this->id(), gmdate( 'Y-m-d\TH:i:s\Z' ), null, null, null, false, $detail );
	}
}
