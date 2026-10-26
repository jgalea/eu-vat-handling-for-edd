<?php

namespace VatrightEdd\Checkout;

use VatrightEdd\Admin\DownloadVatCategory;
use VatrightEdd\Domain\Decision;
use VatrightEdd\Domain\LineDecision;
use VatrightEdd\Domain\Scheme;
use VatrightEdd\Rates\RateResolver;

/**
 * Applies the decision through EDD's own tax filters. EDD has one rate per
 * cart, which carries the standard rate of the country VAT is due in; lines
 * in a reduced category get their tax recomputed one by one.
 *
 * When the rules ask for VAT in a country EDD has no rate for, EDD would
 * charge nothing. The shop country's rate is charged instead and the
 * decision flagged rate_missing: too much VAT can be refunded, VAT never
 * charged cannot be collected.
 */
class TaxApplier {

	const MISSING_FLAG = 'rate_missing';

	const MISSING_OPTION = 'vatright_edd_rate_missing';

	const UNTAXED_SCHEMES = array(
		Scheme::EXPORT,
		Scheme::OUTSIDE_TERRITORY,
		Scheme::REVERSE_CHARGE_SERVICES,
		Scheme::INTRA_EU_GOODS,
	);

	/**
	 * Admin AJAX actions that price an order rather than the visitor's cart.
	 */
	const ADMIN_AJAX_ACTIONS = array( 'edd_get_tax_rate' );

	/** @var Decider */
	private $decider;

	/** @var RateResolver */
	private $rates;

	public function __construct( Decider $decider, RateResolver $rates ) {
		$this->decider = $decider;
		$this->rates   = $rates;
	}

	public function register(): void {
		add_filter( 'edd_tax_rate', array( $this, 'filter_rate' ), 500 );
		add_filter( 'edd_get_cart_item_tax', array( $this, 'filter_item_tax' ), 20, 4 );
		add_filter( 'edd_cart_item_price', array( $this, 'filter_item_price' ), 1000, 2 );
		add_filter( 'edd_cart_tax', array( $this, 'filter_tax_label' ) );
	}

	/**
	 * Only the visitor's own cart is decided here: admin screens, manual
	 * orders, cron, REST and WP-CLI keep EDD's rate.
	 */
	public static function applies(): bool {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( wp_doing_ajax() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads which action is running.
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			return ! in_array( $action, self::ADMIN_AJAX_ACTIONS, true );
		}
		return ! is_admin();
	}

	/**
	 * @param float|int|string $rate EDD's rate as a fraction.
	 * @return float|int|string
	 */
	public function filter_rate( $rate ) {
		$decision = $this->decision();
		if ( null === $decision ) {
			return $rate;
		}
		$line = self::charged_line( $decision );
		if ( null === $line ) {
			return 0.0;
		}
		$percent = $this->line_rate( $line, RateResolver::STANDARD );
		return null === $percent ? $rate : $percent / 100;
	}

	/**
	 * @param float|int|string    $tax
	 * @param int|string          $download_id
	 * @param array<string,mixed> $options
	 * @param float|int|string    $subtotal Line amount after discounts.
	 * @return float|int|string
	 */
	public function filter_item_tax( $tax, $download_id, $options = array(), $subtotal = 0 ) {
		$decision = $this->decision();
		if ( null === $decision || edd_download_is_tax_exclusive( (int) $download_id ) ) {
			return $tax;
		}
		$category = DownloadVatCategory::for_download( (int) $download_id );
		$line     = self::charged_line( $decision );
		if ( RateResolver::STANDARD === $category || null === $line ) {
			return $tax;
		}
		$percent = $this->line_rate( $line, $category );
		if ( null === $percent ) {
			return $tax;
		}

		$amount = max( 0.0, (float) $subtotal );
		$rate   = $percent / 100;
		$tax    = edd_prices_include_tax() ? $amount - $amount / ( 1 + $rate ) : $amount * $rate;
		return round( $tax, (int) edd_currency_decimal_filter() );
	}

	/**
	 * EDD keeps a tax-inclusive price at the same gross for every customer, so
	 * a reverse-charged customer would pay the shop's VAT as part of the price.
	 * The VAT the price includes at the shop country's rate is taken out.
	 *
	 * @param float|int|string|false $price
	 * @param int|string             $download_id
	 * @return float|int|string|false
	 */
	public function filter_item_price( $price, $download_id ) {
		if ( false === $price || ! is_numeric( $price ) || ! edd_prices_include_tax() || edd_download_is_tax_exclusive( (int) $download_id ) ) {
			return $price;
		}
		$decision = $this->decision();
		if ( null === $decision || ! $decision->is_vat_exempt() ) {
			return $price;
		}
		$percent = $this->rates->rate( InputGatherer::store_country(), DownloadVatCategory::for_download( (int) $download_id ) );
		if ( null === $percent || $percent <= 0 ) {
			return $price;
		}
		return round( (float) $price / ( 1 + $percent / 100 ), (int) edd_currency_decimal_filter() );
	}

	/**
	 * @param string $tax Formatted tax amount.
	 * @return string
	 */
	public function filter_tax_label( $tax ) {
		$decision = $this->decision();
		if ( null === $decision || ! $decision->is_vat_exempt() || null === self::exempt_line( $decision ) ) {
			return $tax;
		}
		/* translators: %s: formatted tax amount, zero */
		return sprintf( __( '%s (reverse charge)', 'eu-vat-handling-for-edd' ), (string) $tax );
	}

	/**
	 * The line whose country and scheme the cart is taxed by. Everything EDD
	 * sells is decided the same way, so the first charged line stands for all.
	 */
	public static function charged_line( Decision $decision ): ?LineDecision {
		if ( $decision->is_vat_exempt() ) {
			return null;
		}
		foreach ( $decision->lines() as $line ) {
			if ( ! in_array( $line->scheme(), self::UNTAXED_SCHEMES, true ) ) {
				return $line;
			}
		}
		return null;
	}

	private static function exempt_line( Decision $decision ): ?LineDecision {
		foreach ( $decision->lines() as $line ) {
			if ( in_array( $line->scheme(), array( Scheme::REVERSE_CHARGE_SERVICES, Scheme::INTRA_EU_GOODS ), true ) ) {
				return $line;
			}
		}
		return null;
	}

	private function decision(): ?Decision {
		return self::applies() && edd_use_taxes() ? $this->decider->current() : null;
	}

	/**
	 * The rate in the line's tax country, else the shop country's rate with
	 * the decision flagged. Null when the shop country has no rate either.
	 */
	private function line_rate( LineDecision $line, string $category ): ?float {
		$country = $line->tax_country();
		$percent = $this->rates->rate( $country, $category );
		if ( null !== $percent ) {
			return $percent;
		}
		$store   = InputGatherer::store_country();
		$percent = $country === $store ? null : $this->rates->rate( $store, $category );
		if ( null !== $percent ) {
			$this->decider->with_flag( self::MISSING_FLAG );
			self::note_missing( $country );
		}
		return $percent;
	}

	private static function note_missing( string $country ): void {
		$seen = get_option( self::MISSING_OPTION, array() );
		$seen = is_array( $seen ) ? $seen : array();
		if ( ! isset( $seen[ $country ] ) ) {
			$seen[ $country ] = gmdate( 'Y-m-d\TH:i:s\Z' );
			update_option( self::MISSING_OPTION, $seen, false );
		}
	}
}
