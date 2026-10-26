<?php

global $wpdb;

update_option( 'vatright_edd_settings', array() );
delete_option( 'vatright_edd_e2e_vies_calls' );
delete_option( 'vatright_edd_e2e_force_invalid' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%vatright_edd_v_%' OR option_name LIKE '%vatright_edd_t_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
// Sessions of logged-in users outlive their browser context and would carry a number into the next test.
// EDD before 3.6 keeps sessions in options instead of its own table.
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'edd_sessions' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->prefix}edd_sessions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
} else {
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_wp\\_session\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
echo '@@1@@';
