<?php

$vatright_edd_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $vatright_edd_tests_dir ) {
	$vatright_edd_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $vatright_edd_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$vatright_edd_tests_dir}/includes/functions.php" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput
	exit( 1 );
}

require_once $vatright_edd_tests_dir . '/includes/functions.php';

if ( ! defined( 'EDD_DOING_TESTS' ) ) {
	define( 'EDD_DOING_TESTS', true );
}

tests_add_filter(
	'muplugins_loaded',
	static function () {
		$edd = WP_PLUGIN_DIR . '/easy-digital-downloads/easy-digital-downloads.php';
		if ( file_exists( $edd ) ) {
			require $edd;
		}
		require dirname( __DIR__ ) . '/eu-vat-handling-for-edd.php';
	}
);

require $vatright_edd_tests_dir . '/includes/bootstrap.php';

// EDD before 3.5 keeps tax rates as adjustments and has no delete function for them.
if ( function_exists( 'edd_delete_adjustment' ) && ! function_exists( 'edd_delete_tax_rate' ) ) {
	function edd_delete_tax_rate( $id ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Squiz.Functions.GlobalFunction.Found, Universal.Files.SeparateFunctionsFromOO.Mixed
		return edd_delete_adjustment( $id );
	}
}

require_once __DIR__ . '/Support/HttpGuard.php';
require_once __DIR__ . '/Support/IntegrationTestCase.php';
