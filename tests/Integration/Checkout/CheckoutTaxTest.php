<?php

namespace VatrightEdd\Tests\Integration\Checkout;

use VatrightEdd\Admin\DownloadVatCategory;
use VatrightEdd\Checkout\VatField;
use VatrightEdd\Domain\Rule;
use VatrightEdd\Domain\Scheme;
use VatrightEdd\Ledger\Schema;
use VatrightEdd\Ledger\ThresholdTracker;
use VatrightEdd\Rates\RateResolver;
use VatrightEdd\Rates\RateSync;
use VatrightEdd\Rates\Tedb;
use VatrightEdd\Settings;
use VatrightEdd\Tests\Support\CheckoutHarness;
use VatrightEdd\Tests\Support\FixtureTransport;
use VatrightEdd\Tests\Support\IntegrationTestCase;
use VatrightEdd\Tests\Support\ViesStub;

require_once dirname( __DIR__, 2 ) . '/Support/FixtureTransport.php';
require_once dirname( __DIR__, 2 ) . '/Support/ViesStub.php';
require_once dirname( __DIR__, 2 ) . '/Support/CheckoutHarness.php';

/**
 * Drives EDD()->cart with the shop in Malta (18%), selling to France (20%,
 * e-books 5.5%) and Germany (19%).
 */
class CheckoutTaxTest extends IntegrationTestCase {

	const VALID_DE   = 'DE114103379';
	const INVALID_DE = 'DE123475223';
	const OUTAGE_DE  = 'DE129273398';
	const VALID_MT   = 'MT20000010';

	/** @var CheckoutHarness */
	private $h;

	/** @var array<string,mixed> */
	private $server;

	public function set_up() {
		parent::set_up();
		$this->server = $_SERVER;
		$_POST        = array();

		Schema::install();
		foreach ( edd_get_tax_rates() as $rate ) {
			edd_delete_tax_rate( $rate['id'] );
		}
		delete_option( RateSync::OPTION_LATEST );
		$sync = new RateSync( new Tedb( new FixtureTransport( sys_get_temp_dir() ) ) );
		$diff = $sync->plan( $sync->latest() );
		$sync->apply( $diff, array_keys( $diff->entries() ), true );

		edd_update_option( 'enable_taxes', true );
		edd_update_option( 'base_country', 'MT' );
		edd_update_option( 'currency', 'EUR' );
		edd_update_option( 'prices_include_tax', 'no' );
		edd_update_option( 'cart_caching', false );
		update_option( Settings::OPTION, array() );
		ThresholdTracker::flush( (int) wp_date( 'Y' ), (int) wp_date( 'Y' ) - 1 );

		$this->h = CheckoutHarness::install(
			new ViesStub(
				array(
					'114103379' => ViesStub::VALID,
					'123475223' => ViesStub::INVALID,
					'129273398' => ViesStub::UNAVAILABLE,
					'20000010'  => ViesStub::VALID,
				)
			)
		);
	}

	public function tear_down() {
		edd_empty_cart();
		edd_clear_errors();
		$_POST   = array();
		$_SERVER = $this->server;
		parent::tear_down();
	}

	public function test_b2c_below_the_threshold_pays_the_shop_rate(): void {
		$this->buy( $this->download( 'Software', 100 ) );
		$this->billing( 'FR' );

		$this->assertSame( 18.0, $this->tax() );
		$this->assertSame( Rule::B2C_ORIGIN_BELOW_THRESHOLD, $this->h->decider->current()->rule() );
	}

	public function test_b2c_above_the_threshold_pays_the_customer_rate(): void {
		$this->ledger_sale( (int) wp_date( 'Y' ) - 1, 12000.0 );
		$this->buy( $this->download( 'Software', 100 ) );
		$this->billing( 'FR' );

		$this->assertSame( 20.0, $this->tax() );
		$this->assertSame( Rule::B2C_DESTINATION, $this->h->decider->current()->rule() );
	}

	public function test_this_order_pushing_the_year_over_the_threshold_pays_the_customer_rate(): void {
		$this->ledger_sale( (int) wp_date( 'Y' ), 9950.0 );
		$this->buy( $this->download( 'Software', 100 ) );
		$this->billing( 'FR' );

		$this->assertSame( 20.0, $this->tax() );
	}

	public function test_an_oss_registered_shop_charges_the_customer_rate(): void {
		$this->settings( array( 'oss_registered' => true ) );
		$this->buy( $this->download( 'Software', 100 ) );
		$this->billing( 'DE' );

		$this->assertSame( 19.0, $this->tax() );
	}

	public function test_a_valid_de_number_checked_in_the_cart_removes_vat(): void {
		$this->buy( $this->download( 'Software', 100 ) );
		$this->enter_number( self::VALID_DE, 'DE' );

		$this->assertSame( 0.0, $this->tax() );
		$this->assertSame( 100.0, (float) edd_get_cart_total() );
		$this->assertTrue( $this->h->decider->current()->is_vat_exempt() );
		$this->assertStringContainsString( 'reverse charge', (string) edd_cart_tax() );

		$this->assertSame( array(), $this->checkout( array( VatField::NUMBER_FIELD => self::VALID_DE ) ) );
		$this->assertSame( 0.0, $this->tax() );
		$this->assertSame( 1, $this->h->vies->calls, 'the checkout reuses the verdict cached by the cart check' );
	}

	public function test_a_valid_de_number_entered_only_at_checkout_is_checked_there(): void {
		$this->buy( $this->download( 'Software', 100 ) );
		$this->billing( 'DE' );
		$this->assertSame( 18.0, $this->tax() );

		$this->assertSame( array(), $this->checkout( array( VatField::NUMBER_FIELD => self::VALID_DE ) ) );
		$this->assertSame( 0.0, $this->tax() );
		$this->assertSame( Rule::RC_SERVICES_ART196, $this->h->decider->current()->rule() );
	}

	public function test_a_number_from_the_shop_country_is_charged(): void {
		$this->buy( $this->download( 'Software', 100 ) );
		$this->enter_number( self::VALID_MT, 'MT' );

		$this->assertSame( 18.0, $this->tax() );
		$this->assertSame( Rule::DOMESTIC_B2B_GUARD, $this->h->decider->current()->rule() );
		$this->assertSame( 'domestic', VatField::status( $this->h->decider->current() )['status'] );
		$this->assertSame( array(), $this->checkout( array( VatField::NUMBER_FIELD => self::VALID_MT ) ) );
	}

	public function test_an_invalid_number_blocks_the_checkout(): void {
		$this->buy( $this->download( 'Software', 100 ) );

		$errors = $this->checkout( array( VatField::NUMBER_FIELD => self::INVALID_DE ), 'DE' );

		$this->assertArrayHasKey( 'vatright_edd_invalid_vat_number', $errors );
	}

	public function test_vies_down_under_the_charge_policy_charges_and_lets_the_order_through(): void {
		$this->settings( array( 'outage_policy' => 'charge' ) );
		$this->buy( $this->download( 'Software', 100 ) );
		$this->enter_number( self::OUTAGE_DE, 'DE' );

		$this->assertSame( 18.0, $this->tax() );
		$this->assertSame( array(), $this->checkout( array( VatField::NUMBER_FIELD => self::OUTAGE_DE ) ) );
		$this->assertSame( 18.0, $this->tax() );
		$this->assertSame( Rule::PENDING_CHARGED, $this->h->decider->current()->rule() );
	}

	public function test_vies_down_under_the_exempt_pending_policy_removes_vat(): void {
		$this->settings( array( 'outage_policy' => 'exempt_pending' ) );
		$this->buy( $this->download( 'Software', 100 ) );
		$this->enter_number( self::OUTAGE_DE, 'DE' );

		$this->assertSame( 0.0, $this->tax() );
		$this->assertSame( array(), $this->checkout( array( VatField::NUMBER_FIELD => self::OUTAGE_DE ) ) );
		$this->assertSame( 0.0, $this->tax() );
		$this->assertSame( Rule::PENDING_EXEMPT, $this->h->decider->current()->rule() );
		$this->assertContains( 'validation_pending', $this->h->decider->current()->flags() );
	}

	public function test_vies_down_under_the_reject_policy_blocks_the_checkout_only(): void {
		$this->settings( array( 'outage_policy' => 'reject' ) );
		$this->buy( $this->download( 'Software', 100 ) );
		$this->enter_number( self::OUTAGE_DE, 'DE' );

		$this->assertSame( 18.0, $this->tax(), 'the cart is charged while the number waits' );
		$errors = $this->checkout( array( VatField::NUMBER_FIELD => self::OUTAGE_DE ) );
		$this->assertArrayHasKey( 'vatright_edd_registry_unavailable', $errors );
	}

	/**
	 * @dataProvider outage_policies
	 */
	public function test_a_throttled_lookup_charges_vat_whatever_the_outage_policy( string $policy ): void {
		$this->settings( array( 'outage_policy' => $policy ) );
		add_filter(
			'vatright_edd_client_lookup_limit',
			static function () {
				return array( 0, 600 );
			}
		);
		$this->buy( $this->download( 'Software', 100 ) );

		$this->assertSame( array(), $this->checkout( array( VatField::NUMBER_FIELD => self::VALID_DE ), 'DE' ) );
		$this->assertSame( 18.0, $this->tax() );
		$this->assertSame( 0, $this->h->vies->calls );
		$this->assertSame( 'charge', $this->h->decider->current()->to_array()['policy']['outage'] );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function outage_policies(): array {
		return array(
			'charge'         => array( 'charge' ),
			'exempt_pending' => array( 'exempt_pending' ),
			'reject'         => array( 'reject' ),
		);
	}

	public function test_an_evidence_conflict_without_self_certification_blocks_the_checkout(): void {
		$this->settings(
			array(
				'oss_registered'    => true,
				'ip_country_source' => 'cloudflare',
			)
		);
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'FR';
		$this->buy( $this->download( 'Software', 100 ) );
		$this->billing( 'DE' );

		$this->assertTrue( VatField::status( $this->h->decider->current() )['needs_self_certification'] );
		$errors = $this->checkout();
		$this->assertArrayHasKey( 'vatright_edd_self_certification_required', $errors );
	}

	public function test_an_evidence_conflict_with_self_certification_goes_through_at_the_certified_rate(): void {
		$this->settings(
			array(
				'oss_registered'    => true,
				'ip_country_source' => 'cloudflare',
			)
		);
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'FR';
		$this->buy( $this->download( 'Software', 100 ) );

		$errors = $this->checkout(
			array(
				VatField::SELF_CERTIFY_MARKER => '1',
				VatField::SELF_CERTIFY_FIELD  => '1',
			),
			'DE'
		);

		$this->assertSame( array(), $errors );
		$this->assertSame( 19.0, $this->tax() );
		$decision = $this->h->decider->current();
		$this->assertContains( 'evidence_conflict_self_certified', $decision->flags() );
		$this->assertSame( 'FR', $decision->to_array()['evidence']['ip'] );
	}

	public function test_self_certification_can_be_turned_off(): void {
		$this->settings(
			array(
				'oss_registered'     => true,
				'self_certification' => false,
				'ip_country_source'  => 'cloudflare',
			)
		);
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'FR';
		$this->buy( $this->download( 'Software', 100 ) );

		$this->assertSame( array(), $this->checkout( array(), 'DE' ) );
	}

	public function test_a_reverse_charged_customer_is_not_asked_to_self_certify(): void {
		$this->settings( array( 'ip_country_source' => 'cloudflare' ) );
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'FR';
		$this->buy( $this->download( 'Software', 100 ) );

		$this->assertSame( array(), $this->checkout( array( VatField::NUMBER_FIELD => self::VALID_DE ), 'DE' ) );
		$this->assertSame( 0.0, $this->tax() );
	}

	public function test_prices_including_tax_are_net_for_a_reverse_charged_customer(): void {
		edd_update_option( 'prices_include_tax', 'yes' );
		$this->buy( $this->download( 'Software', 118 ) );
		$this->billing( 'FR' );

		$this->assertSame( 18.0, $this->tax() );
		$this->assertSame( 118.0, (float) edd_get_cart_total() );

		$this->enter_number( self::VALID_DE, 'DE' );
		$this->assertSame( 0.0, $this->tax() );
		$this->assertSame( 100.0, (float) edd_get_cart_subtotal() );
		$this->assertSame( 100.0, (float) edd_get_cart_total() );
	}

	public function test_a_mixed_cart_taxes_the_ebook_at_the_reduced_rate(): void {
		$this->settings( array( 'oss_registered' => true ) );
		$this->buy( $this->download( 'Software', 100 ) );
		$this->buy( $this->download( 'E-book', 100, RateResolver::REDUCED ) );
		$this->billing( 'FR' );

		$this->assertSame( 25.5, $this->tax() );
		$details = edd_get_cart_content_details();
		$this->assertSame( array( 20.0, 5.5 ), array_map( 'floatval', array_column( $details, 'tax' ) ) );
	}

	public function test_a_mixed_cart_with_tax_inclusive_prices_takes_the_reduced_vat_out_of_the_ebook(): void {
		edd_update_option( 'prices_include_tax', 'yes' );
		$this->settings( array( 'oss_registered' => true ) );
		$this->buy( $this->download( 'Software', 120 ) );
		$this->buy( $this->download( 'E-book', 105.5, RateResolver::REDUCED ) );
		$this->billing( 'FR' );

		$this->assertSame( 25.5, $this->tax() );
		$this->assertSame( 225.5, (float) edd_get_cart_total() );
	}

	public function test_a_customer_outside_the_eu_pays_no_vat(): void {
		$this->buy( $this->download( 'Software', 100 ) );
		$this->billing( 'US' );

		$this->assertSame( 0.0, $this->tax() );
		$this->assertSame( Scheme::EXPORT, $this->h->decider->current()->scheme() );
	}

	public function test_a_country_without_an_edd_rate_is_charged_the_shop_rate_and_flagged(): void {
		$this->settings( array( 'oss_registered' => true ) );
		foreach ( edd_get_tax_rates( array( 'status' => 'active' ) ) as $rate ) {
			if ( 'FR' === $rate['country'] ) {
				edd_delete_tax_rate( $rate['id'] );
			}
		}
		$this->buy( $this->download( 'Software', 100 ) );
		$this->billing( 'FR' );

		$this->assertSame( 18.0, $this->tax() );
		$this->assertContains( 'rate_missing', $this->h->decider->current()->flags() );
		$this->assertArrayHasKey( 'FR', (array) get_option( 'vatright_edd_rate_missing' ) );
	}

	public function test_admin_screens_keep_edds_own_rate(): void {
		set_current_screen( 'edit' );
		$this->buy( $this->download( 'Software', 100 ) );
		$this->billing( 'FR' );

		$this->assertSame( 20.0, $this->tax() );
	}

	public function test_a_cached_cart_is_taxed_again_when_the_decision_changes(): void {
		edd_update_option( 'cart_caching', true );
		$this->buy( $this->download( 'Software', 100 ) );
		$this->billing( 'DE' );
		$this->assertSame( 18.0, $this->tax() );

		$this->enter_number( self::VALID_DE, 'DE' );

		$this->assertSame( 0.0, $this->tax() );
	}

	public function test_a_change_of_billing_country_clears_the_number(): void {
		$this->buy( $this->download( 'Software', 100 ) );
		$this->enter_number( self::VALID_DE, 'DE' );
		$this->assertSame( 0.0, $this->tax() );

		$_POST = array(
			'nonce'           => 'not-a-nonce',
			'billing_country' => 'FR',
		);
		$this->h->field->on_recalculate_taxes();
		$this->assertSame( self::VALID_DE, $this->h->state->number(), 'a request without EDD\'s nonce changes nothing' );

		$_POST['nonce'] = wp_create_nonce( 'edd-checkout-address-fields' );
		$this->h->field->on_recalculate_taxes();

		$this->assertSame( '', $this->h->state->number() );
		$this->assertSame( 18.0, $this->tax() );
	}

	public function test_an_emptied_cart_clears_the_number(): void {
		$this->buy( $this->download( 'Software', 100 ) );
		$this->enter_number( self::VALID_DE, 'DE' );

		edd_empty_cart();

		$this->assertSame( '', $this->h->state->number() );
	}

	private function download( string $title, float $price, string $category = RateResolver::STANDARD ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'download',
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $id, 'edd_price', number_format( $price, 2, '.', '' ) );
		if ( RateResolver::STANDARD !== $category ) {
			update_post_meta( $id, DownloadVatCategory::META_KEY, $category );
		}
		return $id;
	}

	private function buy( int $download_id ): void {
		edd_add_to_cart( $download_id );
	}

	private function billing( string $country ): void {
		$_POST['billing_country'] = $country;
		$this->h->decider->forget();
	}

	/**
	 * What the AJAX check does for a number typed at checkout.
	 */
	private function enter_number( string $number, string $country ): void {
		$_POST['billing_country'] = $country;
		$this->h->field->apply_posted(
			array(
				VatField::NUMBER_FIELD => $number,
				'billing_country'      => $country,
			),
			true
		);
		$this->h->decider->forget();
	}

	/**
	 * @param array<string,string> $post
	 * @return array<string,string>
	 */
	private function checkout( array $post = array(), string $country = '' ): array {
		if ( '' !== $country ) {
			$_POST['billing_country'] = $country;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- stands in for EDD's verified checkout post.
		$_POST = array_merge( $_POST, $post );
		edd_clear_errors();
		do_action( 'edd_checkout_error_checks', array(), $_POST );
		// phpcs:enable
		$errors = edd_get_errors();
		return is_array( $errors ) ? array_intersect_key( $errors, array_flip( preg_grep( '/^vatright_edd_/', array_keys( $errors ) ) ) ) : array();
	}

	private function tax(): float {
		return (float) edd_get_cart_tax();
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function settings( array $settings ): void {
		update_option( Settings::OPTION, $settings );
		$this->h->decider->forget();
	}

	private function ledger_sale( int $year, float $net_eur ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::ledger_table(),
			array(
				'order_id'          => 1,
				'entry_type'        => 'sale',
				'decision_id'       => wp_generate_uuid4(),
				'scheme'            => Scheme::ORIGIN,
				'rule'              => Rule::B2C_ORIGIN_BELOW_THRESHOLD,
				'supply_type'       => 'digital',
				'customer_country'  => 'FR',
				'place_of_supply'   => 'MT',
				'rate_percent'      => 18,
				'net'               => $net_eur,
				'vat'               => $net_eur * 0.18,
				'currency'          => 'EUR',
				'eur_rate'          => 1,
				'net_eur'           => $net_eur,
				'vat_eur'           => $net_eur * 0.18,
				'supply_at'         => $year . '-03-01 10:00:00',
				'period'            => $year . '-03',
				'relates_to_period' => $year . '-03',
				'created_at'        => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		ThresholdTracker::flush( $year );
	}
}
