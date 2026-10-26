<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- a WP-CLI script, its variables are not globals of the plugin.

$user_id = username_exists( 'returning' );
if ( ! $user_id ) {
	$user_id = wp_create_user( 'returning', 'returning-pass', 'returning@example.com' );
}
$customer = edd_get_customer_by( 'user_id', $user_id );
if ( $customer ) {
	$customer_id = (int) $customer->id;
} else {
	$customer_id = (int) edd_add_customer(
		array(
			'user_id' => $user_id,
			'email'   => 'returning@example.com',
			'name'    => 'Returning',
		)
	);
}
if ( ! edd_get_customer_addresses( array( 'customer_id' => $customer_id ) ) ) {
	edd_add_customer_address(
		array(
			'customer_id' => $customer_id,
			'type'        => 'billing',
			'is_primary'  => 1,
			'address'     => 'Example Strasse 1',
			'city'        => 'Berlin',
			'postal_code' => '10115',
			'country'     => 'DE',
		)
	);
}
edd_update_customer_meta( $customer_id, '_vatright_edd_vat_number', 'DE114103379' );

echo '@@' . (int) $customer_id . '@@';
