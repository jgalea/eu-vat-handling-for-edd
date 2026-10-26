<?php

namespace VatrightEdd\Tests\Support;

use RuntimeException;

class HttpGuard {

	/** @var array<string,array> */
	private static $stubs = array();

	/**
	 * Filters hooked before install (the e2e registry stub mu-plugin is loaded
	 * in the tests site too) are dropped; filters a test adds afterwards answer
	 * in front of the guard.
	 */
	public static function install(): void {
		self::$stubs = array();
		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', array( self::class, 'intercept' ), PHP_INT_MAX, 3 );
	}

	public static function uninstall(): void {
		remove_filter( 'pre_http_request', array( self::class, 'intercept' ), PHP_INT_MAX );
		self::$stubs = array();
	}

	public static function stub( string $url_regex, array $response ): void {
		self::$stubs[ $url_regex ] = $response;
	}

	/**
	 * @param false|array $preempt
	 * @param array       $args
	 * @param string      $url
	 * @return array
	 */
	public static function intercept( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		foreach ( self::$stubs as $regex => $response ) {
			if ( preg_match( $regex, $url ) ) {
				return $response;
			}
		}
		throw new RuntimeException( "Unstubbed outbound request: $url" ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
