<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- a WP-CLI script, its variables are not globals of the plugin.

/**
 * A stub Barn2 EDD EU VAT: its options, and three orders carrying the meta it
 * writes, plus one without. Dates are fixed so the counts never move.
 */
require __DIR__ . '/barn2-reset.php';

$software = (int) get_page_by_path( 'software', OBJECT, 'download' )->ID;
$customer = edd_add_customer(
	array(
		'email' => 'barn2-e2e@example.com',
		'name'  => 'Barn Two',
	)
);

$make = static function ( string $country, string $when, float $tax, array $meta ) use ( $software, $customer ) {
	$rate     = edd_get_tax_rate_by_location( array( 'country' => $country ) );
	$order_id = edd_add_order(
		array(
			'type'           => 'sale',
			'status'         => 'complete',
			'customer_id'    => $customer,
			'email'          => 'barn2-e2e@example.com',
			'ip'             => '127.0.0.1',
			'gateway'        => 'manual',
			'mode'           => 'live',
			'currency'       => 'EUR',
			'payment_key'    => md5( uniqid( $country . $when, true ) ),
			'subtotal'       => 100,
			'tax'            => $tax,
			'total'          => 100 + $tax,
			'tax_rate_id'    => ! empty( $rate->id ) ? (int) $rate->id : 0,
			'date_created'   => $when,
			'date_completed' => $when,
		)
	);
	edd_add_order_item(
		array(
			'order_id'     => $order_id,
			'product_id'   => $software,
			'product_name' => 'Software',
			'cart_index'   => 0,
			'type'         => 'download',
			'status'       => 'complete',
			'quantity'     => 1,
			'amount'       => 100,
			'subtotal'     => 100,
			'discount'     => 0,
			'tax'          => $tax,
			'total'        => 100 + $tax,
		)
	);
	edd_add_order_address(
		array(
			'order_id'    => $order_id,
			'name'        => 'Barn Two',
			'address'     => '1 Example Street',
			'city'        => 'Berlin',
			'postal_code' => '10115',
			'country'     => $country,
		)
	);
	edd_update_order_meta( $order_id, '_vatright_edd_e2e', 'e2e-barn2' );
	foreach ( $meta as $key => $value ) {
		edd_update_order_meta( $order_id, $key, $value );
	}
	return $order_id;
};

$make(
	'DE',
	'2025-08-05 10:00:00',
	0,
	array(
		'_edd_payment_vat_number'              => 'DE114103379',
		'_edd_payment_vat_number_valid'        => '1',
		'_edd_payment_vat_reversed_charged'    => '1',
		'_edd_payment_vat_is_eu'               => '1',
		'_edd_payment_vat_company_name'        => 'Beispiel GmbH',
		'_edd_payment_vat_consultation_number' => 'WAPIAAAAY1234567',
	)
);
$make( 'DE', '2025-09-02 10:00:00', 19, array( '_edd_payment_vat_is_eu' => '1' ) );
$make( 'MT', '2025-11-10 10:00:00', 18, array( '_edd_payment_vat_is_eu' => '1' ) );
$make( 'FR', '2025-12-03 10:00:00', 20, array() );

edd_update_option( 'edd_vat_number', 'MT12345678' );
edd_update_option( 'edd_vat_company_name', 'Example Ltd' );
edd_update_option( 'edd_vat_address_country', 'EU' );
edd_update_option( 'edd_vat_hmrc_client_id', 'e2e-client-id-5d1e88a4c7f2' );
edd_update_option( 'edd_vat_hmrc_client_secret', 'e2e-secret-7f3a91c0d2b84e56' );
edd_update_option( 'edd_vat_vatsense_api_key', 'e2e-vatsense-0b6c4d9e1a7f' );
update_option( 'vatright_edd_e2e_barn2', 1 );
echo '@@1@@';
