<?php

namespace VatrightEdd\Tests\Integration\Checkout;

use RuntimeException;
use VatrightEdd\Checkout\VatField;
use VatrightEdd\Orders\OrderFinaliser;
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

class VatFieldTest extends IntegrationTestCase {

	/** @var CheckoutHarness */
	private $h;

	public function set_up() {
		parent::set_up();
		$_POST = array();
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
		update_option( Settings::OPTION, array() );

		$this->h = CheckoutHarness::install( new ViesStub( array( '114103379' => ViesStub::VALID ) ) );

		$download = self::factory()->post->create(
			array(
				'post_type'   => 'download',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $download, 'edd_price', '100.00' );
		edd_add_to_cart( $download );

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function ( $message ) {
					throw new RuntimeException( esc_html( is_scalar( $message ) ? (string) $message : '' ) );
				};
			}
		);
	}

	public function tear_down() {
		edd_empty_cart();
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	public function test_the_check_refuses_a_request_without_its_nonce(): void {
		$_POST    = array(
			'nonce'                => 'forged',
			'billing_country'      => 'DE',
			VatField::NUMBER_FIELD => 'DE114103379',
		);
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the request under test.

		try {
			$this->h->field->ajax_check();
			$this->fail( 'the check answered a forged request' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( '-1', $e->getMessage() );
		}
		$this->assertSame( '', $this->h->state->number() );
		$this->assertSame( 0, $this->h->vies->calls );
	}

	public function test_the_check_stores_the_number_and_answers_with_the_notice(): void {
		$_POST    = array(
			'nonce'                => wp_create_nonce( VatField::NONCE ),
			'billing_country'      => 'DE',
			VatField::NUMBER_FIELD => 'de 114 103 379',
		);
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the request under test.

		$response = $this->call_check();

		$this->assertTrue( $response['success'] );
		$this->assertSame( 'verified', $response['data']['status'] );
		$this->assertStringContainsString( 'Reverse charge applies', $response['data']['html'] );
		$this->assertSame( 'DE114103379', $this->h->state->number() );
		$this->assertSame( 1, $this->h->vies->calls );
		$this->assertSame( 0.0, (float) edd_get_cart_tax() );
	}

	public function test_the_check_records_self_certification_for_the_billing_country(): void {
		$_POST    = array(
			'nonce'                       => wp_create_nonce( VatField::NONCE ),
			'billing_country'             => 'DE',
			VatField::SELF_CERTIFY_MARKER => '1',
			VatField::SELF_CERTIFY_FIELD  => '1',
		);
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the request under test.

		$this->call_check();

		$this->assertSame( 'DE', $this->h->state->self_certified() );
	}

	public function test_the_field_renders_escaped(): void {
		$this->h->state->set_number( '"><script>alert(1)</script>', 'DE' );

		ob_start();
		$this->h->field->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="vatright_edd_vat_number"', $html );
		$this->assertStringContainsString( 'data-nonce="', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	private function render_field(): string {
		ob_start();
		$this->h->field->render();
		return (string) ob_get_clean();
	}

	private function remembered_customer( string $number ): void {
		$user = self::factory()->user->create( array( 'user_email' => 'returning@example.com' ) );
		$id   = edd_add_customer(
			array(
				'user_id' => $user,
				'email'   => 'returning@example.com',
				'name'    => 'Returning',
			)
		);
		edd_update_customer_meta( $id, OrderFinaliser::CUSTOMER_NUMBER_META, $number );
		wp_set_current_user( $user );
	}

	public function test_a_returning_customers_number_prefills_the_field_and_is_checked_on_load(): void {
		$this->remembered_customer( 'DE114103379' );

		$html = $this->render_field();

		$this->assertStringContainsString( 'value="DE114103379"', $html );
		$this->assertStringContainsString( 'data-prefilled="1"', $html );
		$this->assertSame( '', $this->h->state->number(), 'Prefilling does not exempt the cart before the number is checked.' );
		$this->assertSame( 0, $this->h->vies->calls );
	}

	public function test_the_number_entered_this_visit_wins_over_the_remembered_one(): void {
		$this->remembered_customer( 'DE114103379' );
		$this->h->state->set_number( 'FR12345678901', 'FR' );

		$html = $this->render_field();

		$this->assertStringContainsString( 'value="FR12345678901"', $html );
		$this->assertStringNotContainsString( 'data-prefilled', $html );
	}

	public function test_a_guest_is_not_prefilled(): void {
		$this->assertStringNotContainsString( 'data-prefilled', $this->render_field() );
	}

	public function test_the_self_certification_label_is_escaped(): void {
		add_filter(
			'edd_countries',
			static function ( $countries ) {
				$countries['DE'] = 'Germany <b>bold</b>';
				return $countries;
			}
		);
		$status = array(
			'status'                   => 'none',
			'rule'                     => '',
			'message'                  => '',
			'needs_self_certification' => true,
			'self_certified'           => false,
			'self_certification_label' => VatField::self_certification_label( 'DE' ),
		);

		$html = $this->h->field->notice_html( $status );

		$this->assertStringContainsString( 'Germany &lt;b&gt;bold&lt;/b&gt;', $html );
		$this->assertStringContainsString( 'name="vatright_edd_self_certify"', $html );
	}

	public function test_the_field_is_not_rendered_without_taxes(): void {
		edd_update_option( 'enable_taxes', false );

		ob_start();
		$this->h->field->render();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function call_check(): array {
		ob_start();
		try {
			$this->h->field->ajax_check();
		} catch ( RuntimeException $e ) {
			unset( $e );
		}
		$response = json_decode( (string) ob_get_clean(), true );
		$this->assertIsArray( $response );
		return $response;
	}

	public function test_the_label_says_optional_while_business_only_mode_is_off(): void {
		$html = $this->render_field();

		$this->assertStringContainsString( 'VAT number (optional)', $html );
		$this->assertStringNotContainsString( 'edd-required-indicator', $html );
		$this->assertStringNotContainsString( 'aria-required', $html );
	}

	public function test_the_field_is_marked_required_for_the_countries_business_only_mode_covers(): void {
		update_option( Settings::OPTION, array( 'b2b_only' => 'everywhere' ) );

		$html = $this->render_field();

		$this->assertStringNotContainsString( '(optional)', $html );
		$this->assertStringContainsString( 'VAT number<span class="edd-required-indicator" aria-hidden="true">*</span><span class="screen-reader-text">Required</span>', $html, 'The same markup EDD gives its own required fields.' );
		$this->assertStringContainsString( 'aria-required="true"', $html );
	}

	public function test_a_country_the_mode_leaves_out_keeps_the_optional_label(): void {
		update_option(
			Settings::OPTION,
			array(
				'b2b_only'           => 'countries',
				'b2b_only_countries' => array( 'DE' ),
			)
		);
		$_POST['billing_country'] = 'FR';

		$this->assertStringContainsString( '(optional)', $this->render_field() );

		$_POST['billing_country'] = 'DE';
		$this->assertStringContainsString( 'edd-required-indicator', $this->render_field() );
	}

	public function test_the_script_gets_the_mode_for_the_country_switch(): void {
		update_option(
			Settings::OPTION,
			array(
				'b2b_only'           => 'countries',
				'b2b_only_countries' => array( 'DE', 'fr' ),
			)
		);

		$data = VatField::script_data();

		$this->assertSame( 'countries', $data['mode'] );
		$this->assertSame( array( 'DE', 'FR' ), $data['countries'] );
		$this->assertContains( 'MT', $data['eu'] );
		$this->assertSame( 'VAT number (optional)', $data['optional'] );
		$this->assertSame( 'VAT number', $data['required'] );
		$this->assertSame( 'Required', $data['required_text'] );
	}
}
