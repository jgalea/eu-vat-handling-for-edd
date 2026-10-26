<?php

namespace VatrightEdd\Checkout;

use VatrightEdd\Domain\Decision;
use VatrightEdd\Domain\Rule;
use VatrightEdd\Domain\Scheme;
use VatrightEdd\Domain\ValidationResult;
use VatrightEdd\Domain\VatNumber;
use VatrightEdd\Orders\OrderFinaliser;
use VatrightEdd\Registry\Validator;
use VatrightEdd\Settings;

/**
 * The VAT number input and self-certification checkbox, at the bottom of
 * EDD's billing address fields on both the shortcode and the block checkout.
 * Typing a number asks the registry through our AJAX check, which stores the
 * verdict and tells EDD to recalculate the cart.
 *
 * @phpstan-type Status array{status: string, rule: string, message: string, needs_self_certification: bool, self_certified: bool, self_certification_label: string}
 */
class VatField {

	const NUMBER_FIELD = 'vatright_edd_vat_number';

	const SELF_CERTIFY_FIELD = 'vatright_edd_self_certify';

	const SELF_CERTIFY_MARKER = 'vatright_edd_self_certify_field';

	const AJAX_ACTION = 'vatright_edd_check';

	const NONCE = 'vatright_edd_check';

	/** @var Validator */
	private $validator;

	/** @var CartState */
	private $state;

	/** @var Decider */
	private $decider;

	public function __construct( Validator $validator, CartState $state, Decider $decider ) {
		$this->validator = $validator;
		$this->state     = $state;
		$this->decider   = $decider;
	}

	public function register(): void {
		add_action( 'edd_cc_billing_bottom', array( $this, 'render' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_check' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax_check' ) );
		add_action( 'wp_ajax_edd_recalculate_taxes', array( $this, 'on_recalculate_taxes' ), 5 );
		add_action( 'wp_ajax_nopriv_edd_recalculate_taxes', array( $this, 'on_recalculate_taxes' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * The canonical form when the text parses as a VAT number, otherwise the
	 * trimmed upper-case text (a bare number that needs the billing country
	 * to be understood).
	 *
	 * @param mixed $value
	 */
	public static function normalise( $value ): string {
		$raw    = trim( (string) $value );
		$number = VatNumber::parse( $raw, '' );
		return null === $number ? strtoupper( $raw ) : $number->canonical();
	}

	public static function disclosure(): string {
		return __( 'Your VAT number is sent to the official tax registry for its country, such as the EU\'s VIES service, to confirm it before VAT is removed.', 'eu-vat-handling-for-edd' );
	}

	public function render(): void {
		if ( ! edd_use_taxes() ) {
			return;
		}
		$status    = self::status( $this->decider->current() );
		$value     = $this->state->number();
		$prefilled = false;
		if ( '' === $value ) {
			$value     = self::remembered_number();
			$prefilled = '' !== $value;
		}
		?>
		<div id="vatright-edd-vat" class="vatright-edd-vat"<?php echo $prefilled ? ' data-prefilled="1"' : ''; ?> data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>">
			<p id="vatright-edd-vat-number-wrap">
				<label class="edd-label" for="vatright-edd-vat-number"><?php esc_html_e( 'VAT number (optional)', 'eu-vat-handling-for-edd' ); ?></label>
				<span class="edd-description" id="vatright-edd-disclosure"><?php echo esc_html( self::disclosure() ); ?></span>
				<input class="edd-input" type="text" id="vatright-edd-vat-number" name="<?php echo esc_attr( self::NUMBER_FIELD ); ?>" value="<?php echo esc_attr( $value ); ?>" autocomplete="off" aria-describedby="vatright-edd-disclosure" />
			</p>
			<div id="vatright-edd-notice" class="vatright-edd-notice" aria-live="polite"><?php echo $this->notice_html( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?></div>
		</div>
		<?php
	}

	/**
	 * The number a logged-in customer used on their last order. It only fills
	 * the field: the cart is not exempted until the number has been checked.
	 */
	private static function remembered_number(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$customer = edd_get_customer_by( 'user_id', (string) get_current_user_id() );
		if ( ! $customer ) {
			return '';
		}
		return (string) edd_get_customer_meta( (int) $customer->id, OrderFinaliser::CUSTOMER_NUMBER_META, true );
	}

	/**
	 * Stores what the shopper changed, asks the registry for a new number, and
	 * answers with the notice for the decision that follows. The client then
	 * has EDD recalculate the cart.
	 */
	public function ajax_check(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		$this->apply_posted( wp_unslash( $_POST ), true );
		$this->decider->forget();

		$status = self::status( $this->decider->current() );
		wp_send_json_success(
			array(
				'status' => $status['status'],
				'html'   => $this->notice_html( $status ),
			)
		);
	}

	/**
	 * Runs before EDD answers its own recalculation request, which follows a
	 * change of billing country: a number or self-certification given for the
	 * old country no longer applies.
	 */
	public function on_recalculate_taxes(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( false === wp_verify_nonce( $nonce, 'edd-checkout-address-fields' ) ) {
			return;
		}
		$this->state->clear_if_country_changed( InputGatherer::billing_country() );
		$this->decider->forget();
	}

	/**
	 * @param array<string,mixed> $posted Unslashed.
	 * @param bool                $lookup Ask the registry for a new number now. Checkout leaves it to the checkout decision, which has its own allowance.
	 */
	public function apply_posted( array $posted, bool $lookup = false ): void {
		$country = isset( $posted['billing_country'] ) ? strtoupper( sanitize_text_field( (string) $posted['billing_country'] ) ) : '';
		if ( '' === $country ) {
			$country = InputGatherer::billing_country();
		}

		if ( array_key_exists( self::NUMBER_FIELD, $posted ) ) {
			$raw = sanitize_text_field( (string) $posted[ self::NUMBER_FIELD ] );
			$this->state->set_number( self::normalise( $raw ), $country );
			$number = VatNumber::parse( $raw, $country );
			if ( $lookup && null !== $number ) {
				$this->validator->validate_for_visitor( $number, 'update' );
			}
		}
		if ( array_key_exists( self::SELF_CERTIFY_MARKER, $posted ) ) {
			$this->state->set_self_certified( ! empty( $posted[ self::SELF_CERTIFY_FIELD ] ), $country );
		}
	}

	public function enqueue(): void {
		if ( ! edd_use_taxes() || ! edd_is_checkout() ) {
			return;
		}
		$script = self::asset( 'assets/checkout.js' );
		wp_enqueue_script( 'vatright-edd-checkout', $script['url'], array( 'jquery' ), $script['version'], true );
		$style = self::asset( 'assets/checkout.css' );
		wp_enqueue_style( 'vatright-edd-checkout', $style['url'], array(), $style['version'] );
	}

	/**
	 * @param Status $status
	 */
	public function notice_html( array $status ): string {
		$html = '';
		if ( '' !== $status['message'] ) {
			$html .= '<p class="vatright-edd-notice__message vatright-edd-notice__message--' . esc_attr( $status['status'] ) . '">' . esc_html( $status['message'] ) . '</p>';
		}
		if ( $status['needs_self_certification'] || $status['self_certified'] ) {
			$html .= '<input type="hidden" name="' . esc_attr( self::SELF_CERTIFY_MARKER ) . '" value="1" />';
			$html .= '<p class="vatright-edd-self-certify">';
			$html .= '<input type="checkbox" id="vatright-edd-self-certify" name="' . esc_attr( self::SELF_CERTIFY_FIELD ) . '" value="1"' . checked( $status['self_certified'], true, false ) . ' /> ';
			$html .= '<label for="vatright-edd-self-certify">' . esc_html( $status['self_certification_label'] ) . '</label>';
			$html .= '</p>';
		}
		return $html;
	}

	/**
	 * Location only matters while VAT is charged; a reverse-charged customer
	 * is never asked.
	 */
	public static function needs_self_certification( Decision $decision ): bool {
		if ( ! Settings::get()->self_certification() || $decision->is_vat_exempt() ) {
			return false;
		}
		$evidence = (array) $decision->to_array()['evidence'];
		return ! empty( $evidence['conflict'] ) && empty( $evidence['self_certified'] );
	}

	/**
	 * @return Status
	 */
	public static function status( ?Decision $decision ): array {
		$none = array(
			'status'                   => 'none',
			'rule'                     => '',
			'message'                  => '',
			'needs_self_certification' => false,
			'self_certified'           => false,
			'self_certification_label' => '',
		);
		if ( null === $decision ) {
			return $none;
		}

		$record   = $decision->to_array();
		$evidence = (array) $record['evidence'];
		$billing  = (string) ( $record['customer']['billing']['country'] ?? '' );
		$none     = array_merge(
			$none,
			array(
				'needs_self_certification' => self::needs_self_certification( $decision ),
				'self_certified'           => ! empty( $evidence['self_certified'] ),
				'self_certification_label' => self::self_certification_label( $billing ),
			)
		);

		if ( null === $record['vat_number'] ) {
			return $none;
		}
		list( $status, $message ) = self::describe( $decision, (string) ( $record['validation']['status'] ?? '' ), (string) ( $record['validation']['detail'] ?? '' ) );
		return array_merge(
			$none,
			array(
				'status'  => $status,
				'rule'    => $decision->rule(),
				'message' => $message,
			)
		);
	}

	public static function self_certification_label( string $country ): string {
		$names = edd_get_country_list();
		/* translators: %s: country name */
		return sprintf( __( 'I confirm I am located in %s.', 'eu-vat-handling-for-edd' ), $names[ $country ] ?? $country );
	}

	/**
	 * @return array{0: string, 1: string} Status key, shopper-facing message.
	 */
	private static function describe( Decision $decision, string $validation, string $detail ): array {
		$code = $decision->blocking_error();
		if ( null !== $code ) {
			return array( 'invalid_vat_number' === $code ? 'invalid' : 'pending', ErrorChecks::message( $code ) );
		}
		if ( $decision->is_vat_exempt() ) {
			if ( ValidationResult::VALID === $validation ) {
				return array( 'verified', __( 'VAT number verified. Reverse charge applies.', 'eu-vat-handling-for-edd' ) );
			}
			return array( 'pending', __( 'Your VAT number could not be checked just now. No VAT is charged for now and the number will be checked again.', 'eu-vat-handling-for-edd' ) );
		}
		if ( Rule::DOMESTIC_B2B_GUARD === $decision->rule() ) {
			return array( 'domestic', __( 'VAT is charged on sales to businesses in the shop\'s own country.', 'eu-vat-handling-for-edd' ) );
		}
		if ( Rule::PREFIX_MISMATCH_CHARGED === $decision->rule() ) {
			return array( 'mismatch', __( 'This VAT number does not match your billing country, so VAT is charged.', 'eu-vat-handling-for-edd' ) );
		}
		if ( ValidationResult::VALID === $validation ) {
			foreach ( $decision->lines() as $line ) {
				if ( in_array( $line->scheme(), array( Scheme::REVERSE_CHARGE_SERVICES, Scheme::INTRA_EU_GOODS ), true ) ) {
					return array( 'verified', __( 'VAT number verified. Reverse charge applies to part of this order.', 'eu-vat-handling-for-edd' ) );
				}
			}
			return array( 'verified', __( 'VAT number verified. VAT is charged on this order.', 'eu-vat-handling-for-edd' ) );
		}
		if ( in_array( $validation, array( ValidationResult::INVALID, ValidationResult::FORMAT_INVALID ), true ) ) {
			return array( 'invalid', ErrorChecks::message( 'invalid_vat_number' ) );
		}
		if ( ValidationResult::UNAVAILABLE === $validation ) {
			return array( 'pending', __( 'Your VAT number could not be checked just now, so VAT is charged on this order.', 'eu-vat-handling-for-edd' ) );
		}
		if ( in_array( $detail, array( 'cache_miss', 'throttled' ), true ) ) {
			return array( 'pending', __( 'Your VAT number has not been checked yet. VAT is charged until it is.', 'eu-vat-handling-for-edd' ) );
		}
		return array( 'unchecked', __( 'This VAT number cannot be checked automatically, so VAT is charged.', 'eu-vat-handling-for-edd' ) );
	}

	/**
	 * @return array{url: string, version: string}
	 */
	private static function asset( string $path ): array {
		$root = dirname( __DIR__, 2 );
		return array(
			'url'     => plugins_url( $path, $root . '/eu-vat-handling-for-edd.php' ),
			'version' => (string) filemtime( $root . '/' . $path ),
		);
	}
}
