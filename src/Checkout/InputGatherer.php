<?php

namespace VatrightEdd\Checkout;

use EDD\Orders\Order;
use VatrightEdd\Admin\DownloadVatCategory;
use VatrightEdd\Domain\DecisionInput;
use VatrightEdd\Domain\Evidence;
use VatrightEdd\Domain\Line;
use VatrightEdd\Domain\ValidationResult;
use VatrightEdd\Domain\VatNumber;
use VatrightEdd\Geo\IpCountry;
use VatrightEdd\Ledger\Fx;
use VatrightEdd\Ledger\ThresholdTracker;
use VatrightEdd\Rates\RateResolver;
use VatrightEdd\Registry\Validator;
use VatrightEdd\Settings;

/**
 * Everything EDD sells is a download, so every cart line is a digital
 * service taxed where the customer is.
 */
class InputGatherer {

	/**
	 * Triggers where the order is not in the ledger yet, so its own amount
	 * can push the year over the threshold.
	 */
	const NEW_SALE_TRIGGERS = array( 'cart', 'checkout' );

	/** @var Validator */
	private $validator;

	/** @var ThresholdTracker */
	private $threshold;

	/** @var Fx */
	private $fx;

	/** @var CartState */
	private $state;

	/** @var RateResolver */
	private $rates;

	public function __construct( Validator $validator, ThresholdTracker $threshold, Fx $fx, CartState $state, RateResolver $rates ) {
		$this->validator = $validator;
		$this->threshold = $threshold;
		$this->fx        = $fx;
		$this->state     = $state;
		$this->rates     = $rates;
	}

	public function from_cart( string $trigger, bool $allow_network ): DecisionInput {
		$lines = array();
		$net   = 0.0;
		foreach ( edd_get_cart_contents() as $key => $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}
			$lines[] = new Line( (string) $key, Line::DIGITAL, false );
			$net    += $this->net_price( $item );
		}

		$billing   = self::billing_country();
		$certified = $this->state->self_certified();
		$raw       = trim( $this->state->number() );
		$number    = '' === $raw ? null : VatNumber::parse( $raw, $billing );

		$validation = null;
		if ( null !== $number ) {
			$validation = 'checkout' === $trigger && $allow_network ? $this->validator->validate_for_visitor( $number, 'checkout' ) : $this->validator->validate( $number, $allow_network );
		}

		return $this->assemble(
			$trigger,
			$net,
			edd_get_currency(),
			$allow_network,
			in_array( $trigger, self::NEW_SALE_TRIGGERS, true ),
			$billing,
			self::billing_postcode(),
			'' === $raw ? null : $raw,
			$number,
			$validation,
			new Evidence(
				'' === $billing ? null : $billing,
				null,
				IpCountry::current(),
				null !== $certified && $certified === $billing ? $billing : null
			),
			$lines,
			false,
			self::exempt_roles_for_user( get_current_user_id() )
		);
	}

	/**
	 * The same inputs, read from an order that has been built. The facts that
	 * only exist while the shopper is checking out (number, self-certification,
	 * IP country, the registry answer) come in through $carry.
	 *
	 * @param array{number?: string, validation?: ValidationResult|null, self_certified?: string|null, ip?: string|null, threshold_exceeded?: bool, exempt_roles?: string[]} $carry
	 * @param bool                                                                                                                                   $own_sale Whether the order is not in the ledger yet, so its amount can push the year over the threshold.
	 * @param bool                                                                                                                                   $fresh    Ask the registry now instead of the cache.
	 */
	public function from_order( Order $order, string $trigger, array $carry, bool $own_sale, bool $fresh = false ): DecisionInput {
		$lines = array();
		$net   = 0.0;
		foreach ( $order->get_items() as $item ) {
			$lines[] = new Line( (string) $item->id, Line::DIGITAL, false );
			$net    += max( 0.0, (float) $item->subtotal - (float) $item->discount );
		}

		$address = $order->get_address();
		$billing = $address && ! empty( $address->country ) ? strtoupper( (string) $address->country ) : '';
		$zip     = $address && ! empty( $address->zip ) ? (string) $address->zip : '';

		$raw    = trim( (string) ( $carry['number'] ?? '' ) );
		$number = '' === $raw ? null : VatNumber::parse( $raw, $billing );

		$validation = $carry['validation'] ?? null;
		if ( null !== $number && ( null === $validation || $fresh ) ) {
			$validation = $this->validator->validate( $number, $fresh, $fresh );
		}
		if ( null === $number ) {
			$validation = null;
		}

		$certified = $carry['self_certified'] ?? null;
		return $this->assemble(
			$trigger,
			$net,
			(string) $order->currency,
			true,
			$own_sale,
			$billing,
			$zip,
			'' === $raw ? null : $raw,
			$number,
			$validation,
			new Evidence(
				'' === $billing ? null : $billing,
				null,
				$carry['ip'] ?? null,
				null !== $certified && $certified === $billing ? $billing : null
			),
			$lines,
			! empty( $carry['threshold_exceeded'] ),
			array_values( array_intersect( Settings::get()->exempt_roles(), array_map( 'strval', (array) ( $carry['exempt_roles'] ?? array() ) ) ) )
		);
	}

	/**
	 * @param Line[]   $lines
	 * @param string[] $exempt_roles
	 */
	private function assemble(
		string $trigger,
		float $net,
		string $currency,
		bool $allow_network,
		bool $own_sale,
		string $billing,
		string $postcode,
		?string $raw,
		?VatNumber $number,
		?ValidationResult $validation,
		Evidence $evidence,
		array $lines,
		bool $carried_exceeded,
		array $exempt_roles
	): DecisionInput {
		$settings = Settings::get();
		$status   = $this->threshold->status( (int) wp_date( 'Y' ) );
		$exceeded = $carried_exceeded || $status->exceeded || ( $own_sale && $this->crosses( $net, $currency, $allow_network ) );

		$threshold             = $status->to_array();
		$threshold['exceeded'] = $exceeded;

		// A throttled lookup is something a visitor can bring about, so the merchant's outage policy does not apply: an exemption or a refusal would be theirs to trigger.
		$outage = null !== $validation && 'throttled' === $validation->detail() ? 'charge' : $settings->outage_policy();

		return new DecisionInput(
			self::store_country(),
			$settings->oss_registered(),
			$exceeded,
			$threshold,
			$outage,
			$settings->allow_domestic_exemption(),
			edd_prices_include_tax(),
			$billing,
			$postcode,
			'',
			'',
			false,
			$number,
			$raw,
			$validation,
			$evidence,
			$lines,
			$trigger,
			gmdate( 'Y-m-d\TH:i:s\Z' ),
			$exempt_roles
		);
	}

	/**
	 * The merchant's exempt roles that an authenticated user holds. Only ever
	 * called with the user the request is running as: a customer record that
	 * EDD linked to an account by email proves nothing about who is buying.
	 *
	 * @return string[]
	 */
	public static function exempt_roles_for_user( int $user_id ): array {
		$configured = Settings::get()->exempt_roles();
		$user       = $user_id > 0 && array() !== $configured ? get_userdata( $user_id ) : false;
		return $user ? array_values( array_intersect( $configured, $user->roles ) ) : array();
	}

	public static function store_country(): string {
		return strtoupper( (string) edd_get_shop_country() );
	}

	/**
	 * The country EDD itself taxes the cart for: the posted billing country,
	 * else the logged-in customer's saved one. Empty when neither is known,
	 * where EDD falls back to the shop country and so do the rules.
	 */
	public static function billing_country(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read only; EDD checks the nonce of each request that posts the address.
		$posted = isset( $_POST['billing_country'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_country'] ) ) : '';
		if ( '' !== $posted ) {
			return strtoupper( $posted );
		}
		if ( is_user_logged_in() ) {
			$address = edd_get_customer_address();
			if ( ! empty( $address['country'] ) ) {
				return strtoupper( (string) $address['country'] );
			}
		}
		return '';
	}

	private static function billing_postcode(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read only, as above.
		$posted = isset( $_POST['card_zip'] ) ? sanitize_text_field( wp_unslash( $_POST['card_zip'] ) ) : '';
		if ( '' !== $posted || ! is_user_logged_in() ) {
			return $posted;
		}
		$address = edd_get_customer_address();
		return isset( $address['zip'] ) ? (string) $address['zip'] : '';
	}

	/**
	 * Read from the download itself rather than the cart, which would ask for
	 * the tax rate this input is being gathered to decide.
	 *
	 * @param array<string,mixed> $item
	 */
	private function net_price( array $item ): float {
		$id      = (int) $item['id'];
		$options = isset( $item['options'] ) && is_array( $item['options'] ) ? $item['options'] : array();
		$price   = 0.0;
		if ( edd_has_variable_prices( $id ) ) {
			$prices   = (array) edd_get_variable_prices( $id );
			$price_id = $options['price_id'] ?? null;
			if ( null !== $price_id && isset( $prices[ $price_id ]['amount'] ) ) {
				$price = (float) $prices[ $price_id ]['amount'];
			}
		} else {
			$price = (float) edd_get_download_price( $id );
		}
		$amount = $price * max( 1, (int) ( $item['quantity'] ?? 1 ) );

		if ( edd_prices_include_tax() ) {
			$rate = $this->rates->rate( self::store_country(), DownloadVatCategory::for_download( $id ) );
			if ( null !== $rate ) {
				$amount = $amount / ( 1 + $rate / 100 );
			}
		}
		return $amount;
	}

	/**
	 * Converting a non-euro amount may need an ECB request, which is only
	 * made when the caller allows network calls; without a rate the order's
	 * own amount is left out and only the ledger totals count.
	 */
	private function crosses( float $net, string $currency, bool $allow_network ): bool {
		if ( 'EUR' !== strtoupper( $currency ) ) {
			$rate = $allow_network ? $this->fx->rate_on_or_before( $currency, gmdate( 'Y-m-d' ) ) : null;
			if ( null === $rate || $rate <= 0 ) {
				return false;
			}
			$net = $net / $rate;
		}
		return $this->threshold->crossing( $net );
	}
}
