<?php

namespace VatrightEdd\Ledger;

use DateTimeImmutable;
use EDD\Orders\Order;
use VatrightEdd\Checkout\InputGatherer;
use VatrightEdd\Checkout\TaxApplier;
use VatrightEdd\Domain\Decision;
use VatrightEdd\Domain\Evidence;
use VatrightEdd\Domain\Line;
use VatrightEdd\Domain\LineDecision;
use VatrightEdd\Domain\Rule;
use VatrightEdd\Domain\Scheme;
use VatrightEdd\Domain\Territory;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Rates\RateResolver;
use VatrightEdd\Scheduler;
use VatrightEdd\Settings;

/**
 * Brings orders placed before the plugin ran into the ledger. An order that
 * carries no decision gets one inferred from what was charged; one that has a
 * decision (stored by the plugin, or handed in by an importer) keeps it.
 */
class Backfill {

	const HOOK = 'vatright_edd_backfill_batch';

	const BATCH = 50;

	const SOURCE = 'backfill';

	const TRIGGER = 'backfill';

	const STATUSES = array( 'complete', 'partially_refunded', 'refunded', 'revoked', 'edd_subscription' );

	const UNKNOWN_NUMBER_FLAG = 'vat_number_unknown';

	/**
	 * Left behind by other EU VAT plugins. The scheduled run leaves orders that
	 * carry them to an importer, which reads the number and registry answer
	 * the plugin recorded.
	 */
	const FOREIGN_META = array(
		'_edd_payment_vat_number',
		'_edd_payment_vat_number_valid',
		'_edd_payment_vat_reverse_charged',
		'_edd_payment_vat_reversed_charged',
		'_edd_payment_vat_is_eu',
	);

	/** @var LedgerWriter */
	private $writer;

	/** @var RateResolver */
	private $rates;

	public function __construct( LedgerWriter $writer, RateResolver $rates ) {
		$this->writer = $writer;
		$this->rates  = $rates;
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run_batch' ) );
	}

	public static function schedule(): void {
		Scheduler::once( self::HOOK, array( 0 ) );
	}

	/**
	 * The default window starts on 1 January of the previous year, so the
	 * threshold has both years to read.
	 */
	public static function default_since(): DateTimeImmutable {
		return new DateTimeImmutable( ( (int) wp_date( 'Y' ) - 1 ) . '-01-01', wp_timezone() );
	}

	public function run_batch( int $offset ): void {
		$orders = $this->select( self::default_since(), $offset, self::BATCH );
		foreach ( $orders as $order ) {
			if ( $this->pending( $order ) && ! self::has_foreign_meta( (int) $order->id ) ) {
				$this->backfill_order( $order );
			}
		}

		if ( count( $orders ) === self::BATCH ) {
			Scheduler::once( self::HOOK, array( $offset + self::BATCH ) );
		}
	}

	/**
	 * @return Order[] Sales from $since on, oldest first.
	 */
	public function select( DateTimeImmutable $since, int $offset, int $limit ): array {
		$orders = edd_get_orders(
			array(
				'type'               => 'sale',
				'status__in'         => self::STATUSES,
				'date_created_query' => array(
					'after'     => $since->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
					'inclusive' => true,
				),
				'orderby'            => 'id',
				'order'              => 'ASC',
				'number'             => $limit,
				'offset'             => $offset,
			)
		);
		return array_values( array_filter( $orders, 'is_object' ) );
	}

	/**
	 * Whether the order still needs rows: it has none, and no earlier run
	 * already looked at it (an order of nothing writes no rows).
	 */
	public function pending( Order $order ): bool {
		if ( 0 !== $this->writer->count_rows( (int) $order->id ) ) {
			return false;
		}
		$existing = DecisionStore::get( (int) $order->id );
		return null === $existing || self::TRIGGER !== $existing->trigger();
	}

	public static function has_foreign_meta( int $order_id ): bool {
		foreach ( self::FOREIGN_META as $key ) {
			if ( '' !== (string) edd_get_order_meta( $order_id, $key, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Stores the decision and writes the sale, refund and void rows. An
	 * importer passes the decision it inferred from its own data.
	 *
	 * @return bool False when the order already has rows.
	 */
	public function backfill_order( Order $order, string $source = self::SOURCE, ?Decision $decision = null ): bool {
		$order_id = (int) $order->id;
		if ( 0 !== $this->writer->count_rows( $order_id ) ) {
			return false;
		}

		$existing = DecisionStore::get( $order_id );
		$decision = $decision ?? $existing ?? $this->infer( $order );
		if ( null === $existing || $decision->id() !== $existing->id() ) {
			DecisionStore::apply( $order_id, $decision, $decision->trigger() );
		}

		$this->writer->record_order( $order, $source );
		foreach ( edd_get_order_refunds( $order_id ) as $refund ) {
			$this->writer->record_refund( $order_id, (int) $refund->id );
		}
		if ( in_array( $order->status, LedgerWriter::VOID_STATUSES, true ) ) {
			$this->writer->void_order( $order );
		}
		return true;
	}

	/**
	 * What the order shows it was: the billing country, whether tax was
	 * charged and at which country's rate. A sale to another EU country with
	 * no tax can only have been reverse charged; the number was not kept, so
	 * the decision says so and the order shows up as incomplete in reports.
	 */
	public function infer( Order $order ): Decision {
		$settings = Settings::get();
		$store    = InputGatherer::store_country();
		$address  = $order->get_address();
		$billing  = $address && ! empty( $address->country ) ? strtoupper( (string) $address->country ) : '';
		$zip      = $address && ! empty( $address->zip ) ? (string) $address->zip : '';
		$customer = '' !== $billing ? $billing : $store;
		$taxed    = abs( (float) $order->tax ) > 0.0;
		$charged  = $taxed ? $this->charged_country( $order, $customer, $store ) : '';

		list( $scheme, $rule ) = $this->scheme_and_rule( $taxed, $store, $customer, $zip, $charged );

		// Origin and domestic sales are taxed where the store is; the rest where the customer is.
		$place = in_array( $scheme, array( Scheme::ORIGIN, Scheme::DOMESTIC ), true ) ? $store : Territory::vat_country( $customer );
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			$lines[] = new LineDecision( (string) $item->id, Line::DIGITAL, $place, $place, $scheme );
		}

		$flags = array( 'backfilled' );
		if ( Scheme::REVERSE_CHARGE_SERVICES === $scheme ) {
			$flags[] = self::UNKNOWN_NUMBER_FLAG;
		}
		if ( '' === $billing ) {
			$flags[] = 'no_billing_address';
		}

		$evidence = new Evidence( '' === $billing ? null : $billing, null, null, null );
		$resolved = $evidence->resolve();

		return new Decision(
			wp_generate_uuid4(),
			gmdate( 'Y-m-d\TH:i:s\Z' ),
			self::TRIGGER,
			array(
				'country'        => $store,
				'oss_registered' => $settings->oss_registered(),
				'threshold'      => array(
					'current_eur'  => '0.00',
					'previous_eur' => '0.00',
					'exceeded'     => false,
					'forced'       => false,
				),
			),
			array(
				'billing'      => array(
					'country'  => $billing,
					'postcode' => $zip,
				),
				'shipping'     => array(
					'country'  => '',
					'postcode' => '',
				),
				'local_pickup' => false,
			),
			null,
			null,
			array_merge(
				$evidence->to_array(),
				array(
					'resolved' => $customer,
					'conflict' => $resolved['conflict'],
					'pieces'   => $resolved['pieces'],
				)
			),
			array(
				'outage'                   => $settings->outage_policy(),
				'allow_domestic_exemption' => $settings->allow_domestic_exemption(),
				'fixed_prices'             => edd_prices_include_tax(),
			),
			$lines,
			$scheme,
			$rule,
			in_array( $scheme, TaxApplier::UNTAXED_SCHEMES, true ),
			$flags,
			array(),
			null
		);
	}

	/**
	 * @return array{0:string,1:string}
	 */
	private function scheme_and_rule( bool $taxed, string $store, string $customer, string $zip, string $charged ): array {
		if ( ! Territory::in_vat_area( $customer, $zip ) ) {
			return Territory::is_eu_member( Territory::vat_country( $customer ) )
				? array( Scheme::OUTSIDE_TERRITORY, Rule::OUTSIDE_TERRITORY )
				: array( Scheme::EXPORT, Rule::EXPORT_NON_EU );
		}
		$destination = Territory::vat_country( $customer );
		if ( Territory::vat_country( $store ) === $destination ) {
			return array( Scheme::DOMESTIC, Rule::DOMESTIC );
		}
		if ( ! $taxed ) {
			return array( Scheme::REVERSE_CHARGE_SERVICES, Rule::RC_SERVICES_ART196 );
		}
		return $charged === $destination
			? array( Scheme::OSS_DESTINATION, Rule::B2C_DESTINATION )
			: array( Scheme::ORIGIN, Rule::B2C_ORIGIN_BELOW_THRESHOLD );
	}

	/**
	 * The country of the rate row the order points at. Without one, the rate
	 * charged tells: the store's rate means origin VAT, anything else is taken
	 * to be the destination's.
	 */
	private function charged_country( Order $order, string $customer, string $store ): string {
		$row     = get_object_vars( (object) $order->get_tax_rate_object() );
		$country = (string) ( $row['country'] ?? $row['name'] ?? '' );
		if ( '' !== $country ) {
			return Territory::vat_country( $country );
		}

		$percent = $order->get_tax_rate();
		if ( $percent <= 0 ) {
			return '';
		}
		$store_rate = $this->rates->rate( $store, RateResolver::STANDARD );
		if ( null !== $store_rate && abs( $store_rate - $percent ) < 0.01 ) {
			return Territory::vat_country( $store );
		}
		return Territory::vat_country( $customer );
	}
}
