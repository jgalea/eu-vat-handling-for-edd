<?php

namespace VatrightEdd\Integrations;

use EDD\Orders\Order;
use Throwable;
use VatrightEdd\Admin\DownloadVatCategory;
use VatrightEdd\Checkout\Decider;
use VatrightEdd\Checkout\InputGatherer;
use VatrightEdd\Checkout\TaxApplier;
use VatrightEdd\Domain\Decision;
use VatrightEdd\Domain\Rules;
use VatrightEdd\Domain\ValidationResult;
use VatrightEdd\Ledger\Backfill;
use VatrightEdd\Ledger\LedgerWriter;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Orders\OrderFinaliser;
use VatrightEdd\Rates\RateResolver;
use VatrightEdd\Settings;

/**
 * Inert unless EDD Recurring is active.
 *
 * At signup the cart's decision reaches the subscription through EDD's own
 * tax filters, except for lines in a reduced category, which are fixed here.
 *
 * A renewal is a new sale at an amount the gateway already fixed, so the VAT
 * is the part of that gross the decision calls for. Where the decision and
 * what the subscription was set up to charge disagree (the rate moved, the
 * threshold was crossed, a number stopped being valid), the order is recorded
 * as charged and flagged; the subscription itself is never touched.
 *
 * @phpstan-type Subscription object{id: int, parent_payment_id: int, product_id: int, recurring_tax_rate: string, recurring_tax: string, recurring_amount: string}
 */
class Recurring {

	const TRIGGER = 'renewal';

	const RATE_CHANGED_FLAG = 'renewal_rate_changed';

	const TREATMENT_CHANGED_FLAG = 'renewal_treatment_changed';

	const VAT_NOT_CHARGED_FLAG = 'renewal_vat_not_charged';

	/** @var InputGatherer */
	private $gatherer;

	/** @var Rules */
	private $rules;

	/** @var RateResolver */
	private $rates;

	/** @var LedgerWriter */
	private $ledger;

	/** @var Backfill */
	private $backfill;

	/** @var Decider */
	private $decider;

	/** @var bool */
	private $active;

	/** @var array<int,ValidationResult|null> Registry answers from the pre-order step, by subscription. */
	private $answers = array();

	public function __construct( InputGatherer $gatherer, Rules $rules, RateResolver $rates, LedgerWriter $ledger, Backfill $backfill, Decider $decider, bool $active ) {
		$this->gatherer = $gatherer;
		$this->rules    = $rules;
		$this->rates    = $rates;
		$this->ledger   = $ledger;
		$this->backfill = $backfill;
		$this->decider  = $decider;
		$this->active   = $active;
	}

	public static function is_installed(): bool {
		return defined( 'EDD_RECURRING_VERSION' );
	}

	public function register(): void {
		if ( ! $this->active ) {
			return;
		}
		add_filter( 'edd_recurring_subscription_recurring_amounts', array( $this, 'filter_recurring_amounts' ), 100, 2 );
		add_filter( 'edd_recurring_subscription_pre_gateway_args', array( $this, 'filter_gateway_args' ), 10, 2 );
		add_filter( 'edd_recurring_add_payment_pre_args', array( $this, 'set_renewal_tax' ), 10, 2 );
		add_action( 'edd_recurring_add_subscription_order', array( $this, 'record_renewal' ), 10, 2 );
	}

	/**
	 * The VAT inside a gross that includes it.
	 */
	public static function vat_inside( float $gross, float $percent, int $decimals ): float {
		if ( $percent <= 0 ) {
			return 0.0;
		}
		return round( $gross - $gross / ( 1 + $percent / 100 ), $decimals );
	}

	/**
	 * Recurring computes the renewal amounts at the cart's single rate; a line
	 * in a reduced category is recomputed at its own rate.
	 *
	 * @param mixed $amounts
	 * @param mixed $item
	 * @return mixed
	 */
	public function filter_recurring_amounts( $amounts, $item = array() ) {
		$percent = $this->signup_rate( $item );
		if ( null === $percent || ! is_array( $amounts ) || ! isset( $amounts['amount'], $amounts['tax'] ) ) {
			return $amounts;
		}
		$decimals = (int) edd_currency_decimal_filter();
		$amount   = (float) $amounts['amount'];
		if ( edd_prices_include_tax() ) {
			$tax = self::vat_inside( $amount, $percent, $decimals );
		} else {
			$net    = $amount - (float) $amounts['tax'];
			$tax    = round( $net * $percent / 100, $decimals );
			$amount = $net + $tax;
		}
		return array_merge(
			$amounts,
			array(
				'amount' => $amount,
				'tax'    => $tax,
			)
		);
	}

	/**
	 * @param mixed $args
	 * @param mixed $item
	 * @return mixed
	 */
	public function filter_gateway_args( $args, $item = array() ) {
		$percent = $this->signup_rate( $item );
		if ( null === $percent || ! is_array( $args ) ) {
			return $args;
		}
		$rate                       = round( $percent / 100, 6 );
		$args['initial_tax_rate']   = $rate;
		$args['recurring_tax_rate'] = $rate;
		return $args;
	}

	/**
	 * Sets the VAT inside the gross the gateway charged. Left alone when an
	 * admin entered the tax of a manual renewal, or when the parent order has
	 * no decision, which Recurring then splits as it always did.
	 *
	 * @param mixed             $args
	 * @param Subscription|null $subscription
	 * @return mixed
	 */
	public function set_renewal_tax( $args, $subscription = null ) {
		if ( ! is_array( $args ) || ! is_object( $subscription ) || null !== ( $args['tax'] ?? null ) || ! edd_use_taxes() ) {
			return $args;
		}
		try {
			$parent   = edd_get_order( (int) $subscription->parent_payment_id );
			$previous = $parent instanceof Order ? DecisionStore::get( (int) $parent->id ) : null;
			if ( ! $parent instanceof Order || null === $previous ) {
				return $args;
			}

			$stored   = self::reusable_validation( $previous );
			$decision = $this->decide( $parent, $previous, $stored, null === $stored );
			$record   = $decision->to_array();

			$this->answers[ (int) $subscription->id ] = ! empty( $record['validation'] ) ? ValidationResult::from_array( $record['validation'] ) : null;

			$gross = (float) edd_sanitize_amount( sanitize_text_field( (string) ( $args['amount'] ?? '' ) ) );
			$tax   = $this->charged_vat( $gross, $decision, $subscription );
			if ( null !== $tax ) {
				$args['tax'] = $tax;
			}
		} catch ( Throwable $e ) {
			edd_debug_log( 'EU VAT Handling: could not work out the renewal VAT, leaving it to EDD Recurring: ' . $e->getMessage() );
		}
		return $args;
	}

	/**
	 * @param mixed             $order
	 * @param Subscription|null $subscription
	 */
	public function record_renewal( $order, $subscription = null ): void {
		if ( ! $order instanceof Order || 'sale' !== $order->type || ! is_object( $subscription ) || ! edd_use_taxes() ) {
			return;
		}
		try {
			$this->record( $order, $subscription );
		} catch ( Throwable $e ) {
			edd_debug_log( 'EU VAT Handling: could not record the VAT decision for renewal ' . (int) $order->id . ': ' . $e->getMessage() );
		}
	}

	/**
	 * @param Subscription $subscription
	 */
	private function record( Order $order, object $subscription ): void {
		$parent_id = (int) $subscription->parent_payment_id;
		$previous  = DecisionStore::get( $parent_id );
		if ( null === $previous ) {
			// A subscription from before the plugin ran: what the gateway charged is all there is to go on, unless an importer owns the parent's record.
			if ( ! Backfill::has_foreign_meta( $parent_id ) ) {
				$this->backfill->backfill_order( $order );
			}
			return;
		}

		$sub_id = (int) $subscription->id;
		if ( array_key_exists( $sub_id, $this->answers ) ) {
			$stored = $this->answers[ $sub_id ];
			unset( $this->answers[ $sub_id ] );
		} else {
			$stored = self::reusable_validation( $previous );
		}

		$decision = $this->decide( $order, $previous, $stored, null === $stored );
		$decision = $this->flag( $decision, $previous, $order, $subscription );

		DecisionStore::apply( (int) $order->id, $decision, self::TRIGGER );
		if ( ! in_array( OrderFinaliser::TAX_DIFFERS_FLAG, $decision->flags(), true ) && ! in_array( self::VAT_NOT_CHARGED_FLAG, $decision->flags(), true ) ) {
			$this->correct_rate( $order, $decision );
		}

		$fresh = edd_get_order( (int) $order->id );
		if ( $fresh instanceof Order ) {
			$this->ledger->record_order( $fresh, LedgerWriter::LIVE );
		}
	}

	private function decide( Order $basis, Decision $previous, ?ValidationResult $stored, bool $fresh ): Decision {
		$record   = $previous->to_array();
		$evidence = (array) ( $record['evidence'] ?? array() );
		$carry    = array(
			'number'         => (string) ( $record['vat_number']['raw'] ?? '' ),
			'validation'     => $stored,
			'self_certified' => isset( $evidence['self_certified'] ) ? (string) $evidence['self_certified'] : null,
			'ip'             => isset( $evidence['ip'] ) ? (string) $evidence['ip'] : null,
		);
		return $this->rules->decide( $this->gatherer->from_order( $basis, self::TRIGGER, $carry, true, $fresh ) );
	}

	/**
	 * The registry answer of the last decision while it is still fresh.
	 */
	private static function reusable_validation( Decision $previous ): ?ValidationResult {
		$record = $previous->to_array();
		if ( empty( $record['validation'] ) || '' === (string) ( $record['vat_number']['canonical'] ?? '' ) ) {
			return null;
		}
		$result = ValidationResult::from_array( $record['validation'] );
		$age    = time() - (int) strtotime( $result->checked_at() );
		$ttl    = Settings::get()->cache_ttl_valid_days() * DAY_IN_SECONDS;
		if ( ValidationResult::VALID !== $result->status() || $age < 0 || $age >= $ttl ) {
			return null;
		}
		return $result->with_from_cache( true );
	}

	/**
	 * The VAT inside the gross, or null when the plugin has no view on it.
	 *
	 * A subscription set up without VAT in its price has none to take out,
	 * whatever the decision now says; one set up with VAT keeps charging it
	 * even when the decision turns exempt, and the order is flagged.
	 */
	/**
	 * @param Subscription $subscription
	 */
	private function charged_vat( float $gross, Decision $decision, object $subscription ): ?float {
		$product_id = (int) $subscription->product_id;
		if ( edd_download_is_tax_exclusive( $product_id ) ) {
			return 0.0;
		}
		$signup  = self::signup_percent( $subscription );
		$decimal = (int) edd_currency_decimal_filter();
		if ( $signup <= 0 ) {
			return 0.0;
		}
		if ( $decision->is_vat_exempt() ) {
			return self::vat_inside( $gross, $signup, $decimal );
		}
		$percent = $this->decision_rate( $decision, $product_id );
		return null === $percent ? null : self::vat_inside( $gross, $percent, $decimal );
	}

	/**
	 * Flags what differs between the new decision, the one the subscription
	 * was bought under and what the order was charged.
	 */
	/**
	 * @param Subscription $subscription
	 */
	private function flag( Decision $decision, Decision $previous, Order $order, object $subscription ): Decision {
		$product_id = (int) $subscription->product_id;
		$taxed      = abs( (float) $order->tax ) > 0.0;

		if ( $decision->is_vat_exempt() !== $previous->is_vat_exempt() || $decision->scheme() !== $previous->scheme() || $decision->tax_country() !== $previous->tax_country() ) {
			$decision = $decision->with_flag( self::TREATMENT_CHANGED_FLAG );
		}

		if ( ! $decision->is_vat_exempt() ) {
			$percent = $this->decision_rate( $decision, $product_id );
			$signup  = self::signup_percent( $subscription );
			if ( null !== $percent && $signup > 0 && abs( $percent - $signup ) >= 0.005 ) {
				$decision = $decision->with_flag( self::RATE_CHANGED_FLAG );
			}
			if ( null === $percent || null === $this->rates->rate( (string) $decision->tax_country(), RateResolver::STANDARD ) ) {
				$decision = $decision->with_flag( TaxApplier::MISSING_FLAG );
			}
			if ( ! $taxed && (float) $order->subtotal > 0 && ! edd_download_is_tax_exclusive( $product_id ) ) {
				$decision = $decision->with_flag( self::VAT_NOT_CHARGED_FLAG );
			}
		} elseif ( $taxed ) {
			$decision = $decision->with_flag( OrderFinaliser::TAX_DIFFERS_FLAG );
		}
		return $decision;
	}

	/**
	 * Points the order at the rate of the country the decision charged, and
	 * keeps the rate Recurring copied from the parent in step with it.
	 */
	private function correct_rate( Order $order, Decision $decision ): void {
		OrderFinaliser::correct_rate( $order, $decision );

		$order_id = (int) $order->id;
		if ( $decision->is_vat_exempt() || '' === (string) edd_get_order_meta( $order_id, 'tax_rate', true ) ) {
			return;
		}
		$percent = $this->decision_rate( $decision, $this->product_of( $order ) );
		if ( null !== $percent ) {
			edd_update_order_meta( $order_id, 'tax_rate', $percent );
		}
	}

	private function product_of( Order $order ): int {
		foreach ( $order->get_items() as $item ) {
			return (int) $item->product_id;
		}
		return 0;
	}

	/**
	 * The rate the decision charges for a download: its category's rate in the
	 * country VAT is due in, else the shop country's.
	 */
	private function decision_rate( Decision $decision, int $product_id ): ?float {
		$line = TaxApplier::charged_line( $decision );
		if ( null === $line ) {
			return null;
		}
		return $this->rate_in( $line->tax_country(), DownloadVatCategory::for_download( $product_id ) );
	}

	private function rate_in( string $country, string $category ): ?float {
		$percent = $this->rates->rate( $country, $category );
		if ( null !== $percent || InputGatherer::store_country() === $country ) {
			return $percent;
		}
		return $this->rates->rate( InputGatherer::store_country(), $category );
	}

	/**
	 * The rate for a cart line in a reduced category at signup, null when the
	 * cart's own rate is already right.
	 *
	 * @param mixed $item
	 */
	private function signup_rate( $item ): ?float {
		if ( ! is_array( $item ) || empty( $item['id'] ) || ! TaxApplier::applies() || ! edd_use_taxes() || edd_download_is_tax_exclusive( (int) $item['id'] ) ) {
			return null;
		}
		$category = DownloadVatCategory::for_download( (int) $item['id'] );
		if ( RateResolver::STANDARD === $category ) {
			return null;
		}
		$decision = $this->decider->current();
		$line     = null === $decision ? null : TaxApplier::charged_line( $decision );
		if ( null === $line ) {
			return null;
		}
		$percent = $this->rate_in( $line->tax_country(), $category );
		if ( null !== $percent && null === $this->rates->rate( $line->tax_country(), RateResolver::STANDARD ) ) {
			$this->decider->with_flag( TaxApplier::MISSING_FLAG );
		}
		return $percent;
	}

	/**
	 * The VAT rate the subscription was bought with, as a percentage.
	 */
	/**
	 * @param Subscription $subscription
	 */
	private static function signup_percent( object $subscription ): float {
		$rate = (float) $subscription->recurring_tax_rate;
		if ( $rate > 0 ) {
			return $rate * 100;
		}
		$tax = (float) $subscription->recurring_tax;
		$net = (float) $subscription->recurring_amount - $tax;
		return $tax > 0 && $net > 0 ? $tax / $net * 100 : 0.0;
	}
}
