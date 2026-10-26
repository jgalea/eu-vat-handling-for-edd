<?php

namespace VatrightEdd\Tests\Integration\Ledger;

use VatrightEdd\Ledger\ThresholdTracker;
use VatrightEdd\Ledger\ThresholdWarning;
use VatrightEdd\Ledger\ThresholdWarningEmail;
use VatrightEdd\Settings;
use VatrightEdd\Tests\Support\LedgerTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/LedgerTestCase.php';

/**
 * The shop is in Malta, so a sale to France or Germany counts toward the
 * EUR 10,000 threshold and a sale to Malta does not. A EUR 8,000 download is
 * 80% of it.
 */
class ThresholdWarningTest extends LedgerTestCase {

	/** @var int */
	private $year;

	/** @var array<int,array{to:mixed,subject:string,message:string,headers:mixed}> */
	private $mails = array();

	/** @var bool */
	private $mail_works = true;

	public function set_up() {
		parent::set_up();
		( new ThresholdWarning( new ThresholdTracker(), new ThresholdWarningEmail() ) )->register();
		$this->year       = (int) wp_date( 'Y' );
		$this->mails      = array();
		$this->mail_works = true;
		delete_option( ThresholdWarning::OPTION_PREFIX . $this->year . '_' . ThresholdWarning::WARNING );
		delete_option( ThresholdWarning::OPTION_PREFIX . $this->year . '_' . ThresholdWarning::LIMIT );
		add_filter(
			'pre_wp_mail',
			function ( $short, $atts ) {
				// EDD 3.1 also sends the purchase receipt and the sale notice when an order completes; they are not what these tests are about.
				if ( 1 === preg_match( '/^(Purchase Receipt|New download purchase)/', (string) $atts['subject'] ) ) {
					return true;
				}
				$this->mails[] = array(
					'to'      => $atts['to'],
					'subject' => $atts['subject'],
					'message' => $atts['message'],
					'headers' => $atts['headers'],
				);
				return $this->mail_works;
			},
			10,
			2
		);
	}

	public function test_a_sale_below_the_share_sends_nothing(): void {
		$this->sell( 7900.0, 'FR' );

		$this->assertSame( array(), $this->mails );
		$this->assertFalse( ThresholdWarning::sent( $this->year, ThresholdWarning::WARNING ) );
	}

	public function test_reaching_the_share_emails_the_admin_once_through_edd(): void {
		$this->sell( 8000.0, 'FR' );

		$this->assertCount( 1, $this->mails );
		$mail = $this->mails[0];
		$this->assertSame( get_bloginfo( 'admin_email' ), $mail['to'] );
		$this->assertStringContainsString( '80%', $mail['subject'] );
		$this->assertStringContainsString( '8,000.00', $mail['message'] );
		$this->assertStringContainsString( '10,000.00', $mail['message'] );
		$this->assertStringContainsString( 'text/html', is_array( $mail['headers'] ) ? implode( "\n", $mail['headers'] ) : (string) $mail['headers'], 'EDD sent it, with its own headers.' );
		$this->assertStringContainsString( '<html', $mail['message'], 'EDD wrapped it in its email template.' );
		$this->assertTrue( ThresholdWarning::sent( $this->year, ThresholdWarning::WARNING ) );
		$this->assertFalse( ThresholdWarning::sent( $this->year, ThresholdWarning::LIMIT ) );
	}

	public function test_more_sales_between_the_two_levels_send_nothing_more(): void {
		$this->sell( 8000.0, 'FR' );
		$this->sell( 500.0, 'DE' );

		$this->assertCount( 1, $this->mails );
	}

	public function test_crossing_the_threshold_sends_the_second_email_and_then_stops(): void {
		$this->sell( 8000.0, 'FR' );
		$this->sell( 2500.0, 'DE' );

		$this->assertCount( 2, $this->mails );
		$this->assertStringContainsString( 'passed', $this->mails[1]['subject'] );
		$this->assertStringNotContainsString( '80%', $this->mails[1]['subject'] );
		$this->assertTrue( ThresholdWarning::sent( $this->year, ThresholdWarning::LIMIT ) );

		$this->sell( 100.0, 'FR' );
		$this->assertCount( 2, $this->mails );
	}

	public function test_jumping_past_the_threshold_sends_only_the_limit_email(): void {
		$this->sell( 12000.0, 'FR' );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'passed', $this->mails[0]['subject'] );
		$this->assertTrue( ThresholdWarning::sent( $this->year, ThresholdWarning::WARNING ), 'The lower level is spent too, so it never follows.' );

		$this->sell( 100.0, 'FR' );
		$this->assertCount( 1, $this->mails );
	}

	public function test_the_share_is_a_setting(): void {
		$this->settings( array( 'threshold_warning_percent' => 50 ) );

		$this->sell( 5000.0, 'FR' );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( '50%', $this->mails[0]['subject'] );
	}

	public function test_it_can_be_turned_off(): void {
		$this->settings( array( 'threshold_warning' => false ) );

		$this->sell( 9000.0, 'FR' );

		$this->assertSame( array(), $this->mails );
		$this->assertFalse( ThresholdWarning::sent( $this->year, ThresholdWarning::WARNING ), 'Nothing was spent while it was off.' );

		$this->settings( array() );
		$this->sell( 100.0, 'FR' );
		$this->assertCount( 1, $this->mails, 'Turned back on, the next sale sends it.' );
	}

	public function test_an_oss_registered_store_gets_no_email(): void {
		$this->settings( array( 'oss_registered' => true ) );

		$this->sell( 12000.0, 'FR' );

		$this->assertSame( array(), $this->mails );
	}

	public function test_a_store_already_over_the_threshold_last_year_gets_no_email(): void {
		$last = $this->sell( 10500.0, 'FR', ( $this->year - 1 ) . '-11-10 10:00:00' );
		$this->assertSame( 10500.0, ( new ThresholdTracker() )->status( $this->year - 1 )->current_eur );
		$this->assertSame( array(), $this->mails, 'A sale of an earlier year never emails.' );
		$this->assertSame( 'complete', $this->fresh( $last )->status );

		$this->sell( 9000.0, 'FR' );

		$this->assertSame( array(), $this->mails );
	}

	public function test_domestic_sales_do_not_count(): void {
		$this->sell( 9000.0, 'MT' );

		$this->assertSame( array(), $this->mails );
	}

	public function test_a_failed_send_is_tried_again_on_the_next_sale(): void {
		$this->mail_works = false;
		$this->sell( 8500.0, 'FR' );
		$this->assertCount( 1, $this->mails );
		$this->assertFalse( ThresholdWarning::sent( $this->year, ThresholdWarning::WARNING ), 'A mail that did not go is not recorded as sent.' );

		$this->mail_works = true;
		$this->sell( 100.0, 'FR' );

		$this->assertCount( 2, $this->mails );
		$this->assertTrue( ThresholdWarning::sent( $this->year, ThresholdWarning::WARNING ) );
	}

	public function test_the_recipient_can_be_changed(): void {
		add_filter(
			'vatright_edd_threshold_warning_recipient',
			static function () {
				return 'accounts@example.com';
			}
		);

		$this->sell( 8000.0, 'FR' );

		$this->assertSame( 'accounts@example.com', $this->mails[0]['to'] );
	}

	public function test_the_defaults_are_on_and_eighty_percent(): void {
		$s = Settings::get();

		$this->assertTrue( $s->threshold_warning() );
		$this->assertSame( 80, $s->threshold_warning_percent() );
	}

	private function sell( float $price, string $country, ?string $when = null ): \EDD\Orders\Order {
		edd_empty_cart();
		$this->h->state->clear();
		$this->buy( $this->download( $price ), $country );
		$order = $this->place_order( $country );
		if ( null !== $when ) {
			$this->dated( $order, $when );
		}
		$this->complete( $order );
		return $this->fresh( $order );
	}
}
