<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- a WP-CLI script, its variables are not globals of the plugin.

global $wpdb;

define( 'WP_UNINSTALL_PLUGIN', 'eu-vat-handling-for-edd/eu-vat-handling-for-edd.php' );

$tables = array( $wpdb->prefix . 'vatright_edd_ledger', $wpdb->prefix . 'vatright_edd_fx', $wpdb->prefix . 'vatright_edd_rate_rows' );
$count  = static function () use ( $wpdb, $tables ): int {
	$found = 0;
	foreach ( $tables as $table ) {
		$found += $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ? 1 : 0; // phpcs:ignore WordPress.DB
	}
	return $found;
};
$opts   = static function () use ( $wpdb ): int {
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'vatright\_edd\_%'" ); // phpcs:ignore WordPress.DB
};

update_option( 'vatright_edd_settings', array( 'delete_data_on_uninstall' => false ) );
$before = array( $count(), $opts() );
require dirname( __DIR__ ) . '/uninstall.php';
$kept = array( $count(), $opts() );

update_option( 'vatright_edd_settings', array( 'delete_data_on_uninstall' => true ) );
require dirname( __DIR__ ) . '/uninstall.php';
$gone = array( $count(), $opts() );

echo '@@' . wp_json_encode( compact( 'before', 'kept', 'gone' ) ) . '@@';
