<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- a WP-CLI script, its variables are not globals of the plugin.

use VatrightEdd\Admin\DownloadVatCategory;

$products = array(
	'monthly'       => array( 'Monthly', 'standard' ),
	'monthly-ebook' => array( 'Monthly e-book', 'reduced' ),
);
foreach ( $products as $slug => $product ) {
	$existing = get_page_by_path( $slug, OBJECT, 'download' );
	$id       = $existing ? $existing->ID : wp_insert_post(
		array(
			'post_type'   => 'download',
			'post_status' => 'publish',
			'post_title'  => $product[0],
			'post_name'   => $slug,
		)
	);
	update_post_meta( $id, 'edd_price', '100.00' );
	update_post_meta( $id, 'edd_recurring', 'yes' );
	update_post_meta( $id, 'edd_period', 'month' );
	update_post_meta( $id, 'edd_times', '0' );
	update_post_meta( $id, 'edd_signup_fee', '0' );
	if ( 'standard' === $product[1] ) {
		delete_post_meta( $id, DownloadVatCategory::META_KEY );
	} else {
		update_post_meta( $id, DownloadVatCategory::META_KEY, $product[1] );
	}
}
echo '@@1@@';
