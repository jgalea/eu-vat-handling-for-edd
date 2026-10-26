<?php

namespace VatrightEdd\Registry;

/**
 * Caps the registry lookups an anonymous visitor can set off, per client and
 * across the site, so a script typing VAT numbers into the cart cannot spend
 * the store's registry quota or fill the options table.
 *
 * Each counter is a transient holding a fixed window and expiring with it. A
 * counter is only written when a lookup is let through, so the rows that can
 * exist at once are bounded by the global cap. Reading and writing a counter
 * is not atomic: concurrent requests can overshoot a cap by a few lookups,
 * which still stops a flood without needing a lock.
 */
class LookupThrottle {

	const PREFIX = 'vatright_edd_t_';

	/**
	 * Each bucket has its own per-client and site-wide counters, so cart
	 * updates, which anyone can send, cannot use up what checkout is allowed.
	 *
	 * @param string $bucket Separates the cart-update allowance from the checkout one.
	 */
	public function allow( string $bucket ): bool {
		$now    = time();
		$client = $this->counter( self::PREFIX . md5( $bucket . '|' . self::client() ), (array) apply_filters( 'vatright_edd_client_lookup_limit', array( 10, 10 * MINUTE_IN_SECONDS ) ), $now );
		$global = $this->counter( self::PREFIX . 'global_' . $bucket, (array) apply_filters( 'vatright_edd_global_lookup_limit', array( 300, HOUR_IN_SECONDS ), $bucket ), $now );

		if ( $client['count'] >= $client['limit'] || $global['count'] >= $global['limit'] ) {
			return false;
		}
		foreach ( array( $client, $global ) as $counter ) {
			set_transient(
				$counter['key'],
				array(
					'count' => $counter['count'] + 1,
					'start' => $counter['start'],
				),
				max( 1, $counter['start'] + $counter['window'] - $now )
			);
		}
		return true;
	}

	/**
	 * @param array<mixed> $limits Lookups allowed, window in seconds.
	 * @return array{key: string, count: int, start: int, limit: int, window: int}
	 */
	private function counter( string $key, array $limits, int $now ): array {
		$limit  = max( 0, (int) ( $limits[0] ?? 0 ) );
		$window = max( 1, (int) ( $limits[1] ?? 1 ) );

		$stored = get_transient( $key );
		$fresh  = ! is_array( $stored ) || (int) ( $stored['start'] ?? 0 ) + $window <= $now;
		return array(
			'key'    => $key,
			'count'  => $fresh ? 0 : (int) ( $stored['count'] ?? 0 ),
			'start'  => $fresh ? $now : (int) $stored['start'],
			'limit'  => $limit,
			'window' => $window,
		);
	}

	/**
	 * REMOTE_ADDR unless the site says which proxy header to trust; a
	 * forwarded-for header is set by the client and never trusted by default.
	 *
	 * An IPv4-mapped (::ffff:0:0/96) or IPv4-compatible (::/96) address is
	 * that IPv4 client. A native IPv6 address counts as its /56: ISPs commonly
	 * give one subscriber a /56, which would otherwise be 256 separate /64
	 * allowances. Two subscribers sharing a /56 only share a cap whose worst
	 * case is VAT charged and the number checked later.
	 */
	private static function client(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip     = (string) apply_filters( 'vatright_edd_client_ip', $remote );
		$packed = false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? false : inet_pton( $ip );
		if ( false === $packed ) {
			return $ip;
		}
		$head = substr( $packed, 0, 12 );
		if ( str_repeat( "\0", 10 ) . "\xff\xff" === $head || str_repeat( "\0", 12 ) === $head ) {
			return (string) inet_ntop( substr( $packed, 12 ) );
		}
		return (string) inet_ntop( substr( $packed, 0, 7 ) . str_repeat( "\0", 9 ) );
	}
}
