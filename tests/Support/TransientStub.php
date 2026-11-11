<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Generic.CodeAnalysis.UnusedFunctionParameter

$GLOBALS['vatright_edd_transients'] = array();

function vatright_edd_test_reset_transients(): void {
	$GLOBALS['vatright_edd_transients'] = array();
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['vatright_edd_transients'][ $key ] ?? false;
	}

	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['vatright_edd_transients'][ $key ] = $value;
		return true;
	}

	function delete_transient( $key ) {
		unset( $GLOBALS['vatright_edd_transients'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $GLOBALS['vatright_edd_filter_overrides'][ $hook ] ?? $value;
	}

	function sanitize_text_field( $value ) {
		return trim( (string) $value );
	}

	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
}
