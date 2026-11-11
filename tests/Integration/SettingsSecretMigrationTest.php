<?php

namespace VatrightEdd\Tests\Integration;

use VatrightEdd\Settings;
use VatrightEdd\Tests\Support\IntegrationTestCase;

class SettingsSecretMigrationTest extends IntegrationTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Settings::SECRET_OPTION );
		Settings::reset();
	}

	public function tear_down() {
		delete_option( Settings::SECRET_OPTION );
		delete_option( Settings::OPTION );
		Settings::reset();
		parent::tear_down();
	}

	public function test_a_secret_stored_in_the_autoloaded_settings_moves_to_its_own_option(): void {
		update_option(
			Settings::OPTION,
			array(
				'store_vat_number'   => 'MT12345634',
				'hmrc_client_secret' => 'legacy-secret',
			)
		);

		Settings::move_secret_out();

		$this->assertArrayNotHasKey( 'hmrc_client_secret', (array) get_option( Settings::OPTION ) );
		$this->assertSame( 'MT12345634', Settings::get()->store_vat_number() );
		$this->assertSame( 'legacy-secret', Settings::get()->hmrc_client_secret() );
		$this->assertArrayNotHasKey( Settings::SECRET_OPTION, wp_load_alloptions( true ) );
	}

	public function test_a_secret_already_in_its_own_option_is_not_overwritten(): void {
		update_option( Settings::SECRET_OPTION, 'newer-secret', false );
		update_option( Settings::OPTION, array( 'hmrc_client_secret' => 'legacy-secret' ) );

		Settings::move_secret_out();

		$this->assertSame( 'newer-secret', Settings::get()->hmrc_client_secret() );
		$this->assertArrayNotHasKey( 'hmrc_client_secret', (array) get_option( Settings::OPTION ) );
	}

	public function test_running_it_again_changes_nothing(): void {
		update_option( Settings::OPTION, array( 'hmrc_client_secret' => 'legacy-secret' ) );

		Settings::move_secret_out();
		Settings::move_secret_out();

		$this->assertSame( 'legacy-secret', Settings::get()->hmrc_client_secret() );
	}
}
