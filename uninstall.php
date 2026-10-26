<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// VAT records must be kept for years: nothing is removed unless the store opted in. Order meta stays with the orders.
$vatright_edd_settings = get_option( 'vatright_edd_settings', array() );

if ( empty( $vatright_edd_settings['delete_data_on_uninstall'] ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

global $wpdb;

foreach ( array( \VatrightEdd\Ledger\Schema::ledger_table(), \VatrightEdd\Ledger\Schema::fx_table(), \VatrightEdd\Ledger\Schema::rate_rows_table() ) as $vatright_edd_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$vatright_edd_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
}

// delete_option() keeps the object cache in step, so no site-wide cache flush is needed.
$vatright_edd_options = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'vatright_edd_' ) . '%',
		$wpdb->esc_like( '_transient_vatright_edd_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_vatright_edd_' ) . '%'
	)
);
foreach ( $vatright_edd_options as $vatright_edd_option ) {
	delete_option( $vatright_edd_option );
}

delete_metadata( 'edd_customer', 0, '_vatright_edd_vat_number', '', true );
delete_metadata( 'edd_customer', 0, '_vatright_edd_vat_validated_at', '', true );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'vatright-edd' );
}
foreach ( (array) _get_cron_array() as $vatright_edd_events ) {
	foreach ( array_keys( (array) $vatright_edd_events ) as $vatright_edd_hook ) {
		if ( 0 === strpos( (string) $vatright_edd_hook, 'vatright_edd_' ) ) {
			wp_unschedule_hook( (string) $vatright_edd_hook );
		}
	}
}
