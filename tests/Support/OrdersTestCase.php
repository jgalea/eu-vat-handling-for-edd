<?php

namespace VatrightEdd\Tests\Support;

use EDD\Orders\Order;
use VatrightEdd\Admin\DownloadVatCategory;
use VatrightEdd\Checkout\VatField;
use VatrightEdd\Ledger\Fx;
use VatrightEdd\Ledger\Schema;
use VatrightEdd\Ledger\ThresholdTracker;
use VatrightEdd\Rates\RateSync;
use VatrightEdd\Rates\Tedb;
use VatrightEdd\Settings;

require_once __DIR__ . '/FixtureTransport.php';
require_once __DIR__ . '/ViesStub.php';
require_once __DIR__ . '/CheckoutHarness.php';

/**
 * A shop in Malta (18%) selling a EUR 100 download to France (20%) and
 * Germany (19%), with orders built through edd_build_order the way EDD's
 * gateways do.
 */
abstract class OrdersTestCase extends IntegrationTestCase {

	const VALID_DE   = 'DE114103379';
	const OUTAGE_DE  = 'DE129273398';
	const INVALID_DE = 'DE123475223';

	/** @var CheckoutHarness */
	protected $h;

	/** @var ViesStub */
	protected $vies;

	/** @var array<string,mixed> */
	private $server;

	/** @var string */
	protected $order_currency = 'EUR';

	/** @var string */
	protected $order_status = 'pending';

	public function set_up() {
		parent::set_up();
		$this->server         = $_SERVER;
		$this->order_currency = 'EUR';
		$this->order_status   = 'pending';
		$_POST                = array();

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

		$this->vies = new ViesStub(
			array(
				'114103379' => ViesStub::VALID,
				'123475223' => ViesStub::INVALID,
				'129273398' => ViesStub::UNAVAILABLE,
			)
		);
		$this->h    = CheckoutHarness::install( $this->vies, $this->checkout_fx() );
	}

	public function tear_down() {
		edd_empty_cart();
		edd_clear_errors();
		$_POST   = array();
		$_SERVER = $this->server;
		parent::tear_down();
	}

	/**
	 * The converter the checkout uses to size a non-euro cart against the threshold.
	 */
	protected function checkout_fx(): ?Fx {
		return null;
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	protected function settings( array $settings ): void {
		update_option( Settings::OPTION, $settings );
		$this->h->decider->forget();
	}

	protected function download( float $price = 100.0, string $category = 'standard' ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'download',
				'post_title'  => 'Software',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $id, 'edd_price', number_format( $price, 2, '.', '' ) );
		if ( 'standard' !== $category ) {
			update_post_meta( $id, DownloadVatCategory::META_KEY, $category );
		}
		return $id;
	}

	protected function buy( int $download_id, string $country ): void {
		edd_add_to_cart( $download_id );
		$_POST['billing_country'] = $country;
		$this->h->decider->forget();
	}

	protected function enter_number( string $number, string $country ): void {
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
	 * Builds the order for what is in the cart, as a gateway does after the
	 * checkout checks have passed.
	 *
	 * @param array<string,mixed> $user_info Merged over the buyer.
	 */
	protected function place_order( string $country, array $user_info = array(), bool $with_address = true ): Order {
		$buyer = array_merge(
			array(
				'id'         => 0,
				'email'      => 'buyer@example.com',
				'first_name' => 'Bea',
				'last_name'  => 'Buyer',
				'discount'   => 'none',
				'address'    => $with_address ? array(
					'line1'   => '1 Rue Exemple',
					'line2'   => '',
					'city'    => 'Paris',
					'zip'     => '75001',
					'state'   => '',
					'country' => $country,
				) : array(),
			),
			$user_info
		);

		$id = edd_build_order(
			array(
				'price'        => edd_get_cart_total(),
				'user_email'   => $buyer['email'],
				'purchase_key' => md5( uniqid( '', true ) ),
				'downloads'    => edd_get_cart_contents(),
				'user_info'    => $buyer,
				'cart_details' => edd_get_cart_content_details(),
				'status'       => $this->order_status,
				'gateway'      => 'manual',
				'currency'     => $this->order_currency,
			)
		);
		$this->assertNotFalse( $id, 'EDD built no order.' );

		$order = edd_get_order( (int) $id );
		$this->assertInstanceOf( Order::class, $order );
		return $order;
	}

	protected function ledger_sale( int $year, float $net_eur ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::ledger_table(),
			array(
				'order_id'          => 1,
				'entry_type'        => 'sale',
				'decision_id'       => wp_generate_uuid4(),
				'scheme'            => 'origin',
				'rule'              => 'B2C_ORIGIN_BELOW_THRESHOLD',
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

	protected function fresh( Order $order ): Order {
		$fresh = edd_get_order( (int) $order->id );
		$this->assertInstanceOf( Order::class, $fresh );
		return $fresh;
	}

	protected function active_rate_id( string $country ): int {
		$row = edd_get_tax_rate_by_location( array( 'country' => $country ) );
		$this->assertNotEmpty( $row->id, "No active rate row for {$country}." );
		return (int) $row->id;
	}
}
