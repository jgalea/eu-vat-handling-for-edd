<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- a WP-CLI script, its variables are not globals of the plugin.

use VatrightEdd\Admin\DownloadVatCategory;
use VatrightEdd\Ledger\Schema;
use VatrightEdd\Rates\RateSync;
use VatrightEdd\Rates\Tedb;
use VatrightEdd\Registry\WpHttpTransport;

Schema::install();

// EDD before 3.5 creates its tables on admin_init, which WP-CLI never fires.
foreach ( EDD()->components as $component ) {
	foreach ( array( 'table', 'meta' ) as $interface ) {
		$table = $component->get_interface( $interface );
		if ( $table ) {
			$table->maybe_upgrade();
		}
	}
}

edd_update_option( 'base_country', 'MT' );
edd_update_option( 'currency', 'EUR' );
edd_update_option( 'enable_taxes', '1' );
edd_update_option( 'prices_include_tax', 'no' );
edd_update_option( 'test_mode', '1' );
edd_update_option( 'gateways', array( 'manual' => '1' ) );
edd_update_option( 'default_gateway', 'manual' );

$sync = new RateSync( new Tedb( new WpHttpTransport() ) );
$diff = $sync->plan( $sync->latest() );
$sync->apply( $diff, array_keys( $diff->entries() ), false );

$downloads = array(
	'software' => array( 'Software', 'standard' ),
	'e-book'   => array( 'E-book', 'reduced' ),
);
foreach ( $downloads as $slug => $download ) {
	$existing = get_page_by_path( $slug, OBJECT, 'download' );
	$id       = $existing ? $existing->ID : wp_insert_post(
		array(
			'post_type'   => 'download',
			'post_status' => 'publish',
			'post_title'  => $download[0],
			'post_name'   => $slug,
		)
	);
	update_post_meta( $id, 'edd_price', '100.00' );
	if ( 'standard' === $download[1] ) {
		delete_post_meta( $id, DownloadVatCategory::META_KEY );
	} else {
		update_post_meta( $id, DownloadVatCategory::META_KEY, $download[1] );
	}
}

if ( ! get_page_by_path( 'classic-checkout' ) ) {
	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Classic checkout',
			'post_name'    => 'classic-checkout',
			'post_content' => '[download_checkout]',
		)
	);
}
