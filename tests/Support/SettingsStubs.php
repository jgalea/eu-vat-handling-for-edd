<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Generic.CodeAnalysis.UnusedFunctionParameter

require_once __DIR__ . '/TransientStub.php';

$GLOBALS['vatright_edd_options']         = array();
$GLOBALS['vatright_edd_multisite']       = false;
$GLOBALS['vatright_edd_site_options']    = array();
$GLOBALS['vatright_edd_roles']           = array( 'subscriber', 'customer', 'administrator' );
$GLOBALS['vatright_edd_settings_errors'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $fallback = false ) {
		return $GLOBALS['vatright_edd_options'][ $key ] ?? $fallback;
	}

	function is_multisite() {
		return ! empty( $GLOBALS['vatright_edd_multisite'] );
	}

	function get_site_option( $key, $fallback = false ) {
		return $GLOBALS['vatright_edd_site_options'][ $key ] ?? $fallback;
	}

	function edd_get_option( $key, $fallback = false ) {
		return $GLOBALS['vatright_edd_options'][ 'edd:' . $key ] ?? $fallback;
	}

	function edd_get_country_list() {
		return array(
			''   => '',
			'DE' => 'Germany',
			'US' => 'United States',
		);
	}

	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
	}

	function __( $text ) {
		return $text;
	}

	function translate_user_role( $name ) {
		return $name;
	}

	function add_settings_error( $setting, $code, $message, $type = 'error' ) {
		$GLOBALS['vatright_edd_settings_errors'][] = $code;
	}

	function wp_roles() {
		return new class() {
			public function is_role( $role ) {
				return in_array( $role, $GLOBALS['vatright_edd_roles'], true );
			}

			public function get_names() {
				return array_combine( $GLOBALS['vatright_edd_roles'], $GLOBALS['vatright_edd_roles'] );
			}
		};
	}
}
