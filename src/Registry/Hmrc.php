<?php

namespace VatrightEdd\Registry;

use VatrightEdd\Domain\ValidationResult;
use VatrightEdd\Domain\VatNumber;
use Throwable;

class Hmrc implements Registry {

	const LIVE = 'https://api.service.hmrc.gov.uk';

	const SANDBOX = 'https://test-api.service.hmrc.gov.uk';

	/** @var HttpTransport */
	private $transport;

	/** @var string */
	private $client_id;

	/** @var string */
	private $client_secret;

	/** @var bool */
	private $sandbox;

	/** @var int */
	private $timeout;

	public function __construct( HttpTransport $transport, string $client_id, string $client_secret, bool $sandbox = false, int $timeout = 5 ) {
		$this->transport     = $transport;
		$this->client_id     = $client_id;
		$this->client_secret = $client_secret;
		$this->sandbox       = $sandbox;
		$this->timeout       = $timeout;
	}

	public function id(): string {
		return 'hmrc';
	}

	public function supports( VatNumber $n ): bool {
		return 'GB' === $n->prefix();
	}

	public function check( VatNumber $n, ?VatNumber $requester ): ValidationResult {
		if ( '' === $this->client_id || '' === $this->client_secret ) {
			return $this->result( ValidationResult::UNCHECKED, null, null, 'no_credentials' );
		}
		try {
			$requester_vrn = null !== $requester && 'GB' === $requester->prefix() ? $requester->number() : null;
			return $this->lookup( $n->number(), $requester_vrn, true );
		} catch ( Throwable $e ) {
			return $this->result( ValidationResult::UNAVAILABLE, null, null, 'exception' );
		}
	}

	private function lookup( string $target, ?string $requester, bool $may_refresh_token ): ValidationResult {
		$token = $this->token();
		if ( null === $token ) {
			return $this->result( ValidationResult::UNAVAILABLE, null, null, 'token_unavailable' );
		}

		$url = $this->base() . '/organisations/vat/check-vat-number/lookup/' . rawurlencode( $target );
		if ( null !== $requester ) {
			$url .= '/' . rawurlencode( $requester );
		}
		$response = $this->transport->request(
			'GET',
			$url,
			'',
			array(
				'Accept'        => 'application/vnd.hmrc.2.0+json',
				'Authorization' => 'Bearer ' . $token,
			),
			$this->timeout
		);
		if ( '' !== $response['error'] ) {
			return $this->result( ValidationResult::UNAVAILABLE, null, null, 'transport_error' );
		}

		$status = $response['status'];
		$data   = json_decode( $response['body'], true );
		$code   = is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : '';

		if ( 401 === $status && $may_refresh_token ) {
			delete_transient( $this->token_key() );
			return $this->lookup( $target, $requester, false );
		}
		if ( 404 === $status && 'NOT_FOUND' === $code ) {
			return $this->result( ValidationResult::INVALID, null, null, 'not_found' );
		}
		if ( 400 === $status && 'INVALID_REQUEST' === $code ) {
			if ( null !== $requester && false !== strpos( (string) $data['message'], 'requesterVrn' ) ) {
				$retry = $this->lookup( $target, null, $may_refresh_token );
				return ValidationResult::UNAVAILABLE === $retry->status() ? $retry : $this->with_detail( $retry, 'requester_rejected' );
			}
			return $this->result( ValidationResult::INVALID, null, null, 'format_rejected' );
		}
		if ( 200 !== $status || ! is_array( $data ) || ! isset( $data['target']['name'] ) || ! is_string( $data['target']['name'] ) ) {
			return $this->result( ValidationResult::UNAVAILABLE, null, null, 'http_' . $status );
		}

		$address = $data['target']['address'] ?? array();
		return $this->result(
			ValidationResult::VALID,
			$data['target']['name'],
			is_array( $address ) ? $this->format_address( $address ) : null,
			'',
			isset( $data['consultationNumber'] ) && is_string( $data['consultationNumber'] ) ? $data['consultationNumber'] : null
		);
	}

	private function token(): ?string {
		$cached = get_transient( $this->token_key() );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = $this->transport->request(
			'POST',
			$this->base() . '/oauth/token',
			http_build_query(
				array(
					'grant_type'    => 'client_credentials',
					'client_id'     => $this->client_id,
					'client_secret' => $this->client_secret,
					'scope'         => 'read:vat',
				)
			),
			array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			$this->timeout
		);
		$data     = json_decode( $response['body'], true );
		if ( 200 !== $response['status'] || ! is_array( $data ) || ! isset( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
			return null;
		}

		$lifetime = max( 0, (int) ( $data['expires_in'] ?? 0 ) - 60 );
		if ( $lifetime > 0 ) {
			set_transient( $this->token_key(), $data['access_token'], $lifetime );
		}
		return $data['access_token'];
	}

	private function token_key(): string {
		return 'vatright_edd_hmrc_token_' . md5( $this->base() . '|' . $this->client_id );
	}

	private function base(): string {
		return $this->sandbox ? self::SANDBOX : self::LIVE;
	}

	/**
	 * @param array<mixed> $address
	 */
	private function format_address( array $address ): ?string {
		$parts = array();
		foreach ( array( 'line1', 'line2', 'line3', 'line4', 'postcode', 'countryCode' ) as $key ) {
			if ( isset( $address[ $key ] ) && is_string( $address[ $key ] ) && '' !== $address[ $key ] ) {
				$parts[] = $address[ $key ];
			}
		}
		return array() === $parts ? null : implode( ', ', $parts );
	}

	private function with_detail( ValidationResult $r, string $detail ): ValidationResult {
		return new ValidationResult( $r->status(), $r->registry(), $r->checked_at(), $r->name(), $r->address(), $r->consultation(), $r->from_cache(), $detail );
	}

	private function result( string $status, ?string $name, ?string $address, string $detail, ?string $consultation = null ): ValidationResult {
		return new ValidationResult( $status, $this->id(), gmdate( 'Y-m-d\TH:i:s\Z' ), $name, $address, $consultation, false, $detail );
	}
}
