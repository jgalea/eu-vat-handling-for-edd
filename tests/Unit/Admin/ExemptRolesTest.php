<?php

namespace VatrightEdd\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use VatrightEdd\Admin\SettingsSection;
use VatrightEdd\Settings;

require_once dirname( __DIR__, 2 ) . '/Support/SettingsStubs.php';

class ExemptRolesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['vatright_edd_options']         = array( 'default_role' => 'subscriber' );
		$GLOBALS['vatright_edd_settings_errors'] = array();
		$GLOBALS['vatright_edd_multisite']       = false;
		$GLOBALS['vatright_edd_site_options']    = array();
	}

	/**
	 * @param string[] $stored
	 * @return string[]
	 */
	private function read_at_checkout( array $stored ): array {
		$GLOBALS['vatright_edd_options'][ Settings::OPTION ] = array( 'exempt_roles' => $stored );
		return Settings::get()->exempt_roles();
	}

	/**
	 * @param string[] $posted
	 * @return string[]
	 */
	private function sanitize( array $posted ): array {
		$method = new ReflectionMethod( SettingsSection::class, 'sanitize_roles' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		return $method->invoke( new SettingsSection(), $posted );
	}

	public function test_the_default_role_is_refused_while_registration_is_open(): void {
		$GLOBALS['vatright_edd_options']['users_can_register'] = 1;
		$this->assertSame( array( 'customer' ), $this->sanitize( array( 'subscriber', 'customer' ) ) );
		$this->assertSame( array( 'exempt_role_signup' ), $GLOBALS['vatright_edd_settings_errors'] );
	}

	public function test_the_default_role_is_refused_when_edd_registers_buyers_at_checkout(): void {
		$GLOBALS['vatright_edd_options']['edd:show_register_form'] = 'both';
		$this->assertSame( array(), $this->sanitize( array( 'subscriber' ) ) );
	}

	public function test_the_default_role_is_kept_when_nobody_can_sign_up(): void {
		$this->assertSame( array( 'subscriber' ), $this->sanitize( array( 'subscriber' ) ) );
		$this->assertSame( array(), $GLOBALS['vatright_edd_settings_errors'] );
	}

	public function test_unknown_roles_are_dropped(): void {
		$this->assertSame( array( 'customer' ), $this->sanitize( array( 'nope', 'customer' ) ) );
	}

	public function test_a_stored_default_role_is_not_exempt_once_wordpress_sign_up_is_open(): void {
		$GLOBALS['vatright_edd_options']['users_can_register'] = 1;
		$this->assertSame( array( 'customer' ), $this->read_at_checkout( array( 'subscriber', 'customer' ) ) );
	}

	public function test_a_stored_default_role_is_not_exempt_when_edd_auto_register_creates_accounts(): void {
		$GLOBALS['vatright_edd_options']['edd:logged_in_only'] = 'auto';
		$this->assertSame( array(), $this->read_at_checkout( array( 'subscriber' ) ) );
	}

	public function test_a_stored_default_role_is_not_exempt_when_edd_shows_the_register_form(): void {
		$GLOBALS['vatright_edd_options']['edd:show_register_form'] = 'registration';
		$this->assertSame( array(), $this->read_at_checkout( array( 'subscriber' ) ) );
	}

	public function test_a_stored_default_role_is_not_exempt_with_network_registration(): void {
		$GLOBALS['vatright_edd_multisite']                    = true;
		$GLOBALS['vatright_edd_site_options']['registration'] = 'user';
		$this->assertSame( array(), $this->read_at_checkout( array( 'subscriber' ) ) );
	}

	public function test_a_stored_default_role_stays_exempt_when_no_sign_up_path_is_open(): void {
		$GLOBALS['vatright_edd_multisite']                    = true;
		$GLOBALS['vatright_edd_site_options']['registration'] = 'none';
		$this->assertSame( array( 'subscriber' ), $this->read_at_checkout( array( 'subscriber' ) ) );
	}

	public function test_auto_register_makes_the_save_refuse_the_default_role(): void {
		$GLOBALS['vatright_edd_options']['edd:logged_in_only'] = 'auto';
		$this->assertSame( array(), $this->sanitize( array( 'subscriber' ) ) );
	}
}
