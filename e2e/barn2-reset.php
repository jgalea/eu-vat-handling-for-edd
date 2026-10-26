<?php

global $wpdb;

foreach ( edd_get_orders(
	array(
		'number' => 200,
		'type'   => 'sale',
	)
) as $order ) {
	if ( 'e2e-barn2' === edd_get_order_meta( $order->id, '_vatright_edd_e2e', true ) ) {
		edd_delete_order( $order->id );
	}
}
$wpdb->query( "DELETE FROM {$wpdb->prefix}vatright_edd_ledger" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
foreach ( array( 'edd_vat_number', 'edd_vat_company_name', 'edd_vat_hmrc_client_id', 'edd_vat_hmrc_client_secret', 'edd_vat_vatsense_api_key', 'edd_vat_address_country' ) as $key ) {
	edd_delete_option( $key );
}
delete_option( 'vatright_edd_e2e_barn2' );
delete_option( 'vatright_edd_import_barn2' );
delete_option( 'vatright_edd_import_barn2_settings' );
delete_transient( 'vatright_edd_import_dry' );
update_option( 'vatright_edd_settings', array() );
echo '@@1@@';
