<?php

namespace VatrightEdd\Orders;

use EDD\Orders\Order;
use Throwable;
use VatrightEdd\Checkout\CartState;
use VatrightEdd\Checkout\Decider;
use VatrightEdd\Checkout\InputGatherer;
use VatrightEdd\Checkout\TaxApplier;
use VatrightEdd\Domain\Decision;
use VatrightEdd\Domain\Rules;
use VatrightEdd\Domain\ValidationResult;
use VatrightEdd\Geo\IpCountry;

/**
 * Records the VAT decision on every order once EDD has built it, whichever
 * way it was built. Checkout errors are checked on a hook that Stripe's wallet
 * and Buy Now paths remove, so the order is decided again from its own data
 * here. EDD's tax_rate_id comes from a plain lookup by billing country and
 * is corrected to the rate the decision charged.
 *
 * A decision that disagrees with the tax the order was charged cannot
 * change that tax: it is flagged for the admin instead.
 */
class OrderFinaliser {

	const CUSTOMER_NUMBER_META = '_vatright_edd_vat_number';

	const CUSTOMER_VALIDATED_META = '_vatright_edd_vat_validated_at';

	const TAX_DIFFERS_FLAG = 'order_tax_differs';

	const NO_ADDRESS_FLAG = 'no_billing_address';

	const BUILT = 'order_built';

	const MANUAL = 'manual';

	const EDITED = 'admin_edit';

	const RECHECK = 'recheck';

	/** @var InputGatherer */
	private $gatherer;

	/** @var Rules */
	private $rules;

	/** @var CartState */
	private $state;

	/** @var Decider */
	private $decider;

	public function __construct( InputGatherer $gatherer, Rules $rules, CartState $state, Decider $decider ) {
		$this->gatherer = $gatherer;
		$this->rules    = $rules;
		$this->state    = $state;
		$this->decider  = $decider;
	}

	public function register(): void {
		add_action( 'edd_built_order', array( $this, 'on_built' ), 10, 1 );
		add_action( 'edd_post_add_manual_order', array( $this, 'on_manual' ), 10, 1 );
		add_action( 'edd_updated_edited_purchase', array( $this, 'on_edited' ), 10, 1 );
	}

	/**
	 * @param mixed $order_id
	 */
	public function on_built( $order_id ): void {
		$this->guarded( (int) $order_id, self::BUILT );
	}

	/**
	 * @param mixed $order_id
	 */
	public function on_manual( $order_id ): void {
		$this->guarded( (int) $order_id, self::MANUAL );
	}

	/**
	 * @param mixed $order_id
	 */
	public function on_edited( $order_id ): void {
		$this->guarded( (int) $order_id, self::EDITED );
	}

	/**
	 * Decides the order again, asking the registry afresh. An empty number
	 * keeps the one on the order. Amounts are never touched.
	 *
	 * @return Decision|null Null when the order cannot carry a decision.
	 */
	public function recheck( int $order_id, string $raw_number, int $actor ): ?Decision {
		$order = self::sale( $order_id );
		if ( null === $order ) {
			return null;
		}
		return $this->decide( $order, self::RECHECK, trim( $raw_number ), true, $actor );
	}

	private function guarded( int $order_id, string $trigger ): void {
		try {
			$order = self::sale( $order_id );
			if ( null !== $order ) {
				$this->decide( $order, $trigger, null, false, get_current_user_id() );
			}
		} catch ( Throwable $e ) {
			edd_debug_log( 'EU VAT Handling: could not record the VAT decision for order ' . $order_id . ': ' . $e->getMessage() );
		}
	}

	private static function sale( int $order_id ): ?Order {
		$order = $order_id > 0 ? edd_get_order( $order_id ) : false;
		if ( ! $order instanceof Order || 'sale' !== $order->type || ! edd_use_taxes() ) {
			return null;
		}
		return $order;
	}

	/**
	 * @param string|null $number Typed by an admin; null reads it from checkout or the stored decision.
	 */
	private function decide( Order $order, string $trigger, ?string $number, bool $fresh, int $actor ): Decision {
		$previous = DecisionStore::get( (int) $order->id );
		$carry    = $this->carry( $order, $trigger, $number, $previous );
		$decision = $this->rules->decide( $this->gatherer->from_order( $order, $trigger, $carry, self::BUILT === $trigger || self::MANUAL === $trigger, $fresh ) );

		if ( self::MANUAL === $trigger ) {
			$decision = $decision->with_flag( self::MANUAL );
		}
		$billing = $order->get_address();
		if ( ! $billing || empty( $billing->country ) ) {
			$decision = $decision->with_flag( self::NO_ADDRESS_FLAG );
		}
		$consistent = $this->charged_as_decided( $order, $decision );
		if ( ! $consistent ) {
			$decision = $decision->with_flag( self::TAX_DIFFERS_FLAG );
		}

		if ( $consistent && self::BUILT === $trigger ) {
			self::correct_rate( $order, $decision );
		}
		DecisionStore::apply( (int) $order->id, $decision, $trigger, $actor );
		self::remember_customer( $order, $decision, $trigger );
		return $decision;
	}

	/**
	 * @param string|null $number
	 * @return array{number: string, validation: ValidationResult|null, self_certified: string|null, ip: string|null, threshold_exceeded: bool}
	 */
	private function carry( Order $order, string $trigger, ?string $number, ?Decision $previous ): array {
		$stored = null === $previous ? array() : $previous->to_array();
		$carry  = array(
			'number'             => '',
			'validation'         => null,
			'self_certified'     => null,
			'ip'                 => null,
			'threshold_exceeded' => ! empty( $stored['store']['threshold']['exceeded'] ) && self::BUILT !== $trigger,
		);

		$address = $order->get_address();
		$billing = $address && ! empty( $address->country ) ? strtoupper( (string) $address->country ) : '';

		if ( self::BUILT === $trigger ) {
			// What the shopper entered belongs to the billing country it was entered for.
			$entered_for = $this->state->country();
			if ( '' === $entered_for || '' === $billing || $entered_for === $billing ) {
				$carry['number']         = $this->state->number();
				$carry['self_certified'] = $this->state->self_certified();
			}
			$carry['ip'] = self::visitor_request() ? IpCountry::current() : null;
			$live        = $this->decider->is_checkout() ? $this->decider->current() : null;
			if ( null !== $live ) {
				$record = $live->to_array();
				if ( ! empty( $record['validation'] ) && ( $record['vat_number']['raw'] ?? '' ) === $carry['number'] ) {
					$carry['validation'] = ValidationResult::from_array( $record['validation'] );
				}
			}
			return $carry;
		}

		$kept = (string) ( $stored['vat_number']['raw'] ?? '' );
		if ( null !== $number && '' !== $number ) {
			$carry['number'] = $number;
		} elseif ( self::MANUAL !== $trigger ) {
			$carry['number'] = $kept;
		}

		$evidence = (array) ( $stored['evidence'] ?? array() );
		if ( self::MANUAL !== $trigger ) {
			$carry['ip']             = isset( $evidence['ip'] ) ? (string) $evidence['ip'] : null;
			$carry['self_certified'] = isset( $evidence['self_certified'] ) ? (string) $evidence['self_certified'] : null;
		}

		// An unchanged number keeps the verdict it had unless the registry is asked again.
		if ( ! empty( $stored['validation'] ) && '' !== $carry['number'] && $carry['number'] === $kept ) {
			$carry['validation'] = ValidationResult::from_array( $stored['validation'] );
		}
		return $carry;
	}

	/**
	 * Front-end requests that carry the shopper's own connection: not cron,
	 * the REST API, WP-CLI, admin screens or a gateway calling back.
	 */
	private static function visitor_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only tells a gateway callback apart.
		return TaxApplier::applies() && empty( $_GET['edd-listener'] );
	}

	/**
	 * Whether the tax on the order fits the decision: exempt decisions on an
	 * untaxed order, charged decisions on a taxed one. Free orders fit either.
	 */
	private function charged_as_decided( Order $order, Decision $decision ): bool {
		if ( (float) $order->subtotal - (float) $order->discount <= 0 ) {
			return true;
		}
		$taxed = abs( (float) $order->tax ) > 0.0;
		return $decision->is_vat_exempt() ? ! $taxed : $taxed;
	}

	/**
	 * Points the order at the rate row of the country the decision charged,
	 * or at none when nothing was charged. Renewals look this row up.
	 */
	public static function correct_rate( Order $order, Decision $decision ): void {
		$target = 0;
		if ( ! $decision->is_vat_exempt() ) {
			$line = TaxApplier::charged_line( $decision );
			if ( null === $line ) {
				return;
			}
			$row = edd_get_tax_rate_by_location( array( 'country' => $line->tax_country() ) );
			if ( empty( $row->id ) ) {
				$row = edd_get_tax_rate_by_location( array( 'country' => InputGatherer::store_country() ) );
			}
			if ( empty( $row->id ) ) {
				return;
			}
			$target = (int) $row->id;
		}

		if ( (int) $order->tax_rate_id !== $target ) {
			edd_update_order( (int) $order->id, array( 'tax_rate_id' => $target ) );
		}
		if ( 0 === $target ) {
			edd_delete_order_meta( (int) $order->id, 'tax_rate' );
		}
	}

	/**
	 * EDD attaches a guest order to the customer who owns the email address,
	 * so a number is only kept from the customer's own logged-in checkout or
	 * from an admin; a guest typing someone else's email must not change what
	 * that customer has saved.
	 */
	private static function remember_customer( Order $order, Decision $decision, string $trigger ): void {
		$record = $decision->to_array();
		$number = (string) ( $record['vat_number']['canonical'] ?? '' );
		if ( '' === $number || empty( $order->customer_id ) ) {
			return;
		}
		if ( self::BUILT === $trigger ) {
			$customer = edd_get_customer( (int) $order->customer_id );
			$user_id  = get_current_user_id();
			if ( ! $customer || $user_id <= 0 || (int) $customer->user_id !== $user_id ) {
				return;
			}
		} elseif ( ! current_user_can( OrderDetails::CAPABILITY ) ) {
			return;
		}
		edd_update_customer_meta( (int) $order->customer_id, self::CUSTOMER_NUMBER_META, $number );
		$valid = ValidationResult::VALID === ( $record['validation']['status'] ?? '' );
		edd_update_customer_meta( (int) $order->customer_id, self::CUSTOMER_VALIDATED_META, $valid ? (string) $record['validation']['checked_at'] : '' );
	}
}
