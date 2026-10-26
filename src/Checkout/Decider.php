<?php

namespace VatrightEdd\Checkout;

use Throwable;
use VatrightEdd\Domain\Decision;
use VatrightEdd\Domain\Rules;

/**
 * One decision per request, made the first time EDD asks for the cart's tax
 * and remade whenever something it depends on changes. Every change also
 * drops EDD's cached cart rate, so the cart is taxed again.
 */
class Decider {

	/** @var InputGatherer */
	private $gatherer;

	/** @var Rules */
	private $rules;

	/** @var Decision|null */
	private $current = null;

	/** @var bool */
	private $decided = false;

	/** @var bool */
	private $deciding = false;

	/** @var bool */
	private $checkout = false;

	public function __construct( InputGatherer $gatherer, Rules $rules ) {
		$this->gatherer = $gatherer;
		$this->rules    = $rules;
	}

	public function register(): void {
		foreach ( array( 'edd_post_add_to_cart', 'edd_post_remove_from_cart', 'edd_after_set_cart_item_quantity', 'edd_empty_cart' ) as $hook ) {
			add_action( $hook, array( $this, 'forget' ) );
		}
	}

	/**
	 * Null while a decision is being made, so code that asks for the cart's
	 * tax from inside the gathering sees EDD's own rate instead of looping,
	 * and null when deciding failed, which leaves VAT charged.
	 */
	public function current(): ?Decision {
		if ( $this->decided || $this->deciding ) {
			return $this->current;
		}

		$this->deciding = true;
		try {
			$decision = $this->rules->decide( $this->gatherer->from_cart( $this->checkout ? 'checkout' : 'cart', $this->checkout ) );
		} catch ( Throwable $e ) {
			edd_debug_log( 'EU VAT Handling: VAT decision failed, charging VAT: ' . $e->getMessage() );
			$decision = null;
		}
		$this->deciding = false;

		$this->current = $decision;
		$this->decided = true;
		return $decision;
	}

	/**
	 * From here on in this request, decisions are final checkout decisions:
	 * one live registry check is allowed on a cache miss.
	 */
	public function arm_checkout(): void {
		$this->checkout = true;
		$this->forget();
	}

	public function is_checkout(): bool {
		return $this->checkout;
	}

	public function with_flag( string $flag ): void {
		if ( null !== $this->current && ! in_array( $flag, $this->current->flags(), true ) ) {
			$this->current = $this->current->with_flag( $flag );
		}
	}

	public function forget(): void {
		$this->current = null;
		$this->decided = false;
		if ( function_exists( 'EDD' ) && EDD()->cart instanceof \EDD_Cart ) {
			EDD()->cart->set_tax_rate( null );
		}
	}

	public function reset(): void {
		$this->checkout = false;
		$this->forget();
	}
}
