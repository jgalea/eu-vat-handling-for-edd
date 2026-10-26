<?php

namespace VatrightEdd\Tests\Integration\Admin;

use VatrightEdd\Admin\SettingsSection;
use VatrightEdd\Settings;
use VatrightEdd\Tests\Support\IntegrationTestCase;

class SettingsSectionTest extends IntegrationTestCase {

	/** @var SettingsSection */
	private $section;

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		Settings::reset();
		update_option( 'edd_settings', array_merge( (array) get_option( 'edd_settings', array() ), array( 'base_country' => 'MT' ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->section = new SettingsSection();
		$this->section->register();
	}

	public function tear_down() {
		unset( $_POST[ SettingsSection::PRESENT ], $_POST[ SettingsSection::FIELD ], $_POST['_wpnonce'] );
		Settings::reset();
		parent::tear_down();
	}

	/**
	 * @param array<string,mixed> $values
	 */
	private function post( array $values, bool $with_nonce = true ): void {
		$_POST[ SettingsSection::PRESENT ] = '1';
		$_POST[ SettingsSection::FIELD ]   = wp_slash( $values );
		if ( $with_nonce ) {
			$_POST['_wpnonce'] = wp_create_nonce( SettingsSection::WP_NONCE_ACTION );
		}
	}

	private function submit(): void {
		apply_filters( 'edd_settings_taxes-vatright_sanitize', array() );
		Settings::reset();
	}

	private function stored(): Settings {
		return Settings::get();
	}

	public function test_section_is_listed_under_taxes(): void {
		$sections = apply_filters( 'edd_settings_sections_taxes', array( 'main' => 'General' ) );

		$this->assertSame( 'EU VAT', $sections['vatright'] );
		$this->assertSame( 'General', $sections['main'] );
	}

	public function test_saving_stores_the_posted_values(): void {
		$this->post(
			array(
				'store_vat_number'         => 'mt 1234 5634',
				'outage_policy'            => 'reject',
				'cache_ttl_valid_days'     => '14',
				'oss_registered'           => '1',
				'allow_domestic_exemption' => '1',
				'retention_years'          => '7',
				'hmrc_client_id'           => 'client-id',
				'hmrc_client_secret'       => 'sec%ret&<b>',
				'report_currency'          => 'usd',
			)
		);
		$this->submit();

		$s = $this->stored();
		$this->assertSame( 'MT12345634', $s->store_vat_number() );
		$this->assertSame( 'reject', $s->outage_policy() );
		$this->assertSame( 14, $s->cache_ttl_valid_days() );
		$this->assertTrue( $s->oss_registered() );
		$this->assertTrue( $s->allow_domestic_exemption() );
		$this->assertFalse( $s->self_certification() );
		$this->assertFalse( $s->delete_data_on_uninstall() );
		$this->assertSame( 7, $s->retention_years() );
		$this->assertSame( 'client-id', $s->hmrc_client_id() );
		$this->assertSame( 'sec%ret&<b>', $s->hmrc_client_secret() );
		$this->assertSame( 'USD', $s->report_currency() );
	}

	public function test_unchecked_boxes_save_as_false(): void {
		update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'oss_registered' => true ) ) );

		$this->post( array( 'outage_policy' => 'charge' ) );
		$this->submit();

		$this->assertFalse( $this->stored()->oss_registered() );
		$this->assertFalse( $this->stored()->self_certification() );
	}

	public function test_numbers_are_clamped_and_choices_fall_back_to_defaults(): void {
		$this->post(
			array(
				'outage_policy'        => 'wave-through',
				'cache_ttl_valid_days' => '9999',
				'retention_years'      => '-4',
				'report_currency'      => 'ZZZ',
			)
		);
		$this->submit();

		$s = $this->stored();
		$this->assertSame( 'charge', $s->outage_policy() );
		$this->assertSame( 90, $s->cache_ttl_valid_days() );
		$this->assertSame( 1, $s->retention_years() );
		$this->assertSame( 'EUR', $s->report_currency() );
	}

	public function test_a_uk_store_number_is_saved_as_a_gb_number(): void {
		$this->post(
			array(
				'store_vat_number'    => 'MT20000010',
				'store_vat_number_gb' => 'gb 333 2894 54',
			)
		);
		$this->submit();

		$this->assertSame( 'GB333289454', $this->stored()->store_vat_number_gb() );
		$this->assertSame( 'MT20000010', $this->stored()->store_vat_number() );

		$this->post( array( 'store_vat_number_gb' => '333289454' ) );
		$this->submit();
		$this->assertSame( 'GB333289454', $this->stored()->store_vat_number_gb(), 'Bare digits are a GB number in this field.' );
	}

	public function test_the_uk_store_number_can_be_cleared_and_only_gb_numbers_are_kept(): void {
		update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'store_vat_number_gb' => 'GB333289454' ) ) );

		$this->post( array( 'store_vat_number_gb' => 'DE114103379' ) );
		$this->submit();
		$this->assertSame( 'GB333289454', $this->stored()->store_vat_number_gb(), 'An EU number is not a UK number.' );

		$this->post( array( 'store_vat_number_gb' => 'GB12' ) );
		$this->submit();
		$this->assertSame( 'GB333289454', $this->stored()->store_vat_number_gb(), 'A malformed number is not saved.' );

		$this->post( array( 'store_vat_number_gb' => '' ) );
		$this->submit();
		$this->assertSame( '', $this->stored()->store_vat_number_gb() );
	}

	public function test_the_form_shows_the_uk_store_number(): void {
		ob_start();
		$this->section->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="vatright_edd_settings[store_vat_number_gb]"', $html );
	}

	public function test_the_threshold_warning_is_saved_with_its_share(): void {
		$this->post(
			array(
				'threshold_warning'         => '1',
				'threshold_warning_percent' => '65',
			)
		);
		$this->submit();

		$this->assertTrue( $this->stored()->threshold_warning() );
		$this->assertSame( 65, $this->stored()->threshold_warning_percent() );

		$this->post( array( 'threshold_warning_percent' => '65' ) );
		$this->submit();
		$this->assertFalse( $this->stored()->threshold_warning(), 'An unchecked box turns it off.' );
	}

	public function test_the_threshold_share_stays_under_the_threshold_itself(): void {
		$this->post( array( 'threshold_warning_percent' => '250' ) );
		$this->submit();
		$this->assertSame( 99, $this->stored()->threshold_warning_percent() );

		$this->post( array( 'threshold_warning_percent' => '0' ) );
		$this->submit();
		$this->assertSame( 1, $this->stored()->threshold_warning_percent() );

		$this->post( array( 'threshold_warning_percent' => 'lots' ) );
		$this->submit();
		$this->assertSame( 80, $this->stored()->threshold_warning_percent() );
	}

	public function test_the_form_shows_the_threshold_warning_fields(): void {
		ob_start();
		$this->section->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="vatright_edd_settings[threshold_warning]"', $html );
		$this->assertMatchesRegularExpression( '/name="vatright_edd_settings\[threshold_warning_percent\]" value="80"/', $html );
	}

	public function test_an_empty_secret_field_keeps_the_saved_secret(): void {
		update_option(
			Settings::OPTION,
			array_merge(
				Settings::defaults(),
				array(
					'hmrc_client_id'     => 'abc',
					'hmrc_client_secret' => 'keep-me',
				)
			)
		);

		$this->post(
			array(
				'hmrc_client_id'     => 'abc',
				'hmrc_client_secret' => '',
			)
		);
		$this->submit();

		$this->assertSame( 'keep-me', $this->stored()->hmrc_client_secret() );
	}

	public function test_clearing_the_client_id_removes_the_secret(): void {
		update_option(
			Settings::OPTION,
			array_merge(
				Settings::defaults(),
				array(
					'hmrc_client_id'     => 'abc',
					'hmrc_client_secret' => 'keep-me',
				)
			)
		);

		$this->post(
			array(
				'hmrc_client_id'     => '',
				'hmrc_client_secret' => '',
			)
		);
		$this->submit();

		$this->assertSame( '', $this->stored()->hmrc_client_id() );
		$this->assertSame( '', $this->stored()->hmrc_client_secret() );
	}

	public function test_an_implausible_store_number_keeps_the_previous_one(): void {
		update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'store_vat_number' => 'MT12345634' ) ) );

		$this->post( array( 'store_vat_number' => 'not a number' ) );
		$this->submit();

		$this->assertSame( 'MT12345634', $this->stored()->store_vat_number() );
		$this->assertNotEmpty( get_settings_errors( 'vatright-edd' ) );
	}

	public function test_saving_without_the_wordpress_nonce_changes_nothing(): void {
		$this->post( array( 'oss_registered' => '1' ), false );
		$this->submit();

		$this->assertFalse( $this->stored()->oss_registered() );
		$this->assertFalse( get_option( Settings::OPTION ) );
	}

	public function test_saving_with_a_nonce_for_another_action_changes_nothing(): void {
		$this->post( array( 'oss_registered' => '1' ), false );
		$_POST['_wpnonce'] = wp_create_nonce( 'something-else' );
		$this->submit();

		$this->assertFalse( get_option( Settings::OPTION ) );
	}

	public function test_saving_without_the_capability_changes_nothing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->post( array( 'oss_registered' => '1' ) );
		$this->submit();

		$this->assertFalse( get_option( Settings::OPTION ) );
	}

	public function test_saving_another_edd_section_leaves_the_settings_alone(): void {
		update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'oss_registered' => true ) ) );

		$_POST['_wpnonce'] = wp_create_nonce( SettingsSection::WP_NONCE_ACTION );
		$this->submit();

		$this->assertTrue( $this->stored()->oss_registered() );
	}

	public function test_render_prints_escaped_current_values_and_never_the_secret(): void {
		update_option(
			Settings::OPTION,
			array_merge(
				Settings::defaults(),
				array(
					'hmrc_client_id'     => '"><script>alert(1)</script>',
					'hmrc_client_secret' => 'super-secret-value',
				)
			)
		);

		ob_start();
		$this->section->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="vatright_edd_settings[store_vat_number]"', $html );
		$this->assertStringContainsString( 'name="vatright_edd_settings_present"', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&quot;&gt;&lt;script&gt;', $html );
		$this->assertStringNotContainsString( 'super-secret-value', $html );
		$this->assertStringContainsString( 'Saved. Leave empty to keep it.', $html );
	}

	public function test_render_is_empty_for_users_without_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		$this->section->render();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_ip_country_source_defaults_to_none_and_rejects_unknown_values(): void {
		$this->assertSame( 'none', $this->stored()->ip_country_source() );

		$this->post( array( 'ip_country_source' => 'cloudflare' ) );
		$this->submit();
		$this->assertSame( 'cloudflare', $this->stored()->ip_country_source() );

		$this->post( array( 'ip_country_source' => 'fastly' ) );
		$this->submit();
		$this->assertSame( 'none', $this->stored()->ip_country_source() );
	}

	public function test_a_hint_appears_only_when_a_cdn_header_is_present_and_no_source_is_chosen(): void {
		$server = $_SERVER;
		$render = function (): string {
			ob_start();
			$this->section->render();
			return (string) ob_get_clean();
		};

		$this->assertStringNotContainsString( 'country header from a CDN', $render() );

		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'FR';
		$this->assertStringContainsString( 'country header from a CDN', $render() );

		update_option( Settings::OPTION, array( 'ip_country_source' => 'cloudflare' ) );
		Settings::reset();
		$this->assertStringNotContainsString( 'country header from a CDN', $render() );

		$_SERVER = $server;
	}

	public function test_the_seller_details_are_saved_as_text_and_the_email_as_an_email(): void {
		$this->post(
			array(
				'receipt_vat_details' => '1',
				'seller_company'      => 'Shop <b>Ltd</b>',
				'seller_email'        => 'not an email',
				'seller_address_1'    => '1 High Street',
				'seller_city'         => 'Valletta',
			)
		);
		$this->submit();

		$s = $this->stored();
		$this->assertTrue( $s->receipt_vat_details() );
		$this->assertSame( 'Shop Ltd', $s->text( 'seller_company' ) );
		$this->assertSame( '', $s->text( 'seller_email' ) );
		$this->assertSame(
			array(
				'company' => 'Shop Ltd',
				'address' => '1 High Street, Valletta',
			),
			$s->seller_details()
		);
	}

	public function test_unticking_the_receipt_details_switches_them_off(): void {
		$this->post( array( 'seller_company' => 'Shop' ) );
		$this->submit();

		$this->assertFalse( $this->stored()->receipt_vat_details() );
	}
}
