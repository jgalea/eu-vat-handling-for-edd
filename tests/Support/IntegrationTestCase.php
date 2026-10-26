<?php

namespace VatrightEdd\Tests\Support;

use WP_UnitTestCase;

abstract class IntegrationTestCase extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		HttpGuard::install();
	}

	public function tear_down() {
		HttpGuard::uninstall();
		parent::tear_down();
	}
}
