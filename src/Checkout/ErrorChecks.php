<?php

namespace VatrightEdd\Checkout;

/**
 * EDD runs these checks before it prices the purchase, so the posted VAT
 * details are taken in here and the final checkout decision is made before
 * the tax that goes to the gateway is calculated.
 *
 * Stripe's wallet and Buy Now paths remove every check on this hook; the
 * order is decided again when it is built.
 */
class ErrorChecks {

	/** @var Decider */
	private $decider;

	/** @var VatField */
	private $field;

	public function __construct( Decider $decider, VatField $field ) {
		$this->decider = $decider;
		$this->field   = $field;
	}

	public function register(): void {
		add_action( 'edd_checkout_error_checks', array( $this, 'check' ), 10, 2 );
	}

	/**
	 * @param mixed $valid_data
	 * @param mixed $post       The posted checkout form; EDD verified its nonce.
	 */
	public function check( $valid_data, $post ): void {
		$this->field->apply_posted( is_array( $post ) ? wp_unslash( $post ) : array() );
		$this->decider->arm_checkout();

		$decision = $this->decider->current();
		if ( null === $decision ) {
			return;
		}
		$code = $decision->blocking_error();
		if ( null === $code && VatField::needs_self_certification( $decision ) ) {
			$code = 'self_certification_required';
		}
		if ( null !== $code ) {
			// @phpstan-ignore argument.type (The stub types the id as int; EDD keys its errors by string.)
			edd_set_error( 'vatright_edd_' . $code, self::message( $code ) );
		}
	}

	public static function message( string $code ): string {
		switch ( $code ) {
			case 'invalid_vat_number':
				return __( 'This VAT number is not valid. Correct it, or leave the field empty to pay with VAT.', 'eu-vat-handling-for-edd' );
			case 'registry_unavailable':
				return __( 'We could not check your VAT number with the tax authority just now. Try again in a few minutes, or leave the field empty to pay with VAT.', 'eu-vat-handling-for-edd' );
			case 'self_certification_required':
				return __( 'Your billing country and your location do not match. Confirm the country you are located in to continue.', 'eu-vat-handling-for-edd' );
			default:
				return __( 'Your VAT details could not be accepted.', 'eu-vat-handling-for-edd' );
		}
	}
}
