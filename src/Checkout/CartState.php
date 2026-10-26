<?php

namespace VatrightEdd\Checkout;

/**
 * What the shopper told us about VAT for this cart, kept in EDD's session:
 * the number as entered, the billing country it was entered for and the
 * country they self-certified. A change of billing country or an emptied
 * cart drops all of it.
 */
class CartState {

	const SESSION_KEY = 'vatright_edd_vat';

	/**
	 * The session is only written when something changes; this holds the
	 * request's copy so a guest without a session cookie yet reads back what
	 * was set earlier in the same request.
	 *
	 * @var array{number: string, country: string, self_certified: string}|null
	 */
	private $data = null;

	public function register(): void {
		add_action( 'edd_empty_cart', array( $this, 'clear' ) );
	}

	public function number(): string {
		return $this->data()['number'];
	}

	public function country(): string {
		return $this->data()['country'];
	}

	public function self_certified(): ?string {
		$certified = $this->data()['self_certified'];
		return '' === $certified ? null : $certified;
	}

	public function set_number( string $number, string $country ): void {
		$data = $this->data();
		if ( '' !== $data['country'] && strtoupper( $country ) !== $data['country'] ) {
			$data['self_certified'] = '';
		}
		$data['number']  = $number;
		$data['country'] = strtoupper( $country );
		$this->save( $data );
	}

	public function set_self_certified( bool $certified, string $country ): void {
		$data                   = $this->data();
		$data['self_certified'] = $certified && '' !== $country ? strtoupper( $country ) : '';
		if ( '' === $data['country'] ) {
			$data['country'] = strtoupper( $country );
		}
		$this->save( $data );
	}

	/**
	 * @return bool Whether anything was cleared.
	 */
	public function clear_if_country_changed( string $country ): bool {
		$stored = $this->country();
		if ( '' === $stored || '' === $country || strtoupper( $country ) === $stored ) {
			return false;
		}
		$this->clear();
		return true;
	}

	public function clear(): void {
		$this->save( self::empty_state() );
	}

	/**
	 * @return array{number: string, country: string, self_certified: string}
	 */
	private function data(): array {
		if ( null !== $this->data ) {
			return $this->data;
		}
		$session = self::session();
		$stored  = null === $session ? null : $session->get( self::SESSION_KEY );
		$data    = self::empty_state();
		if ( is_array( $stored ) ) {
			foreach ( array_keys( $data ) as $key ) {
				$data[ $key ] = isset( $stored[ $key ] ) && is_string( $stored[ $key ] ) ? $stored[ $key ] : '';
			}
		}
		$this->data = $data;
		return $data;
	}

	/**
	 * @param array{number: string, country: string, self_certified: string} $data
	 */
	private function save( array $data ): void {
		$this->data = $data;
		$session    = self::session();
		if ( null !== $session ) {
			$session->set( self::SESSION_KEY, self::empty_state() === $data ? null : $data );
		}
	}

	/**
	 * @return array{number: string, country: string, self_certified: string}
	 */
	private static function empty_state(): array {
		return array(
			'number'         => '',
			'country'        => '',
			'self_certified' => '',
		);
	}

	/**
	 * @return \EDD\Sessions\Handler|null
	 */
	private static function session() {
		if ( ! function_exists( 'EDD' ) || ! method_exists( EDD()->session, 'set' ) ) {
			return null;
		}
		// @phpstan-ignore return.type (Before EDD 3.3 this is an EDD_Session, which has the same get() and set().)
		return EDD()->session;
	}
}
