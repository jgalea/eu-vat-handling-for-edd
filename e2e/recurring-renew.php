<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- a WP-CLI script, its variables are not globals of the plugin.

use VatrightEdd\Orders\DecisionStore;

// WP-CLI passes the extra arguments of eval-file in $args.
$parent_id = (int) ( $args[0] ?? 0 );
$gross     = (float) ( $args[1] ?? 0 );
$txn       = 'e2e-' . wp_generate_password( 8, false );

$subs = ( new EDD_Subscriptions_DB() )->get_subscriptions(
	array(
		'parent_payment_id' => $parent_id,
		'number'            => 1,
	)
);
if ( ! $subs ) {
	echo '@@' . wp_json_encode( array( 'error' => 'no subscription for the parent order' ) ) . '@@';
	return;
}
if ( $parent_id <= 0 || $gross <= 0 ) {
	echo '@@' . wp_json_encode( array( 'error' => 'missing arguments' ) ) . '@@';
	return;
}
$sub    = $subs[0];
$before = array( (string) $sub->recurring_tax_rate, (string) $sub->recurring_tax );

$order_id = $sub->add_payment(
	array(
		'amount'         => $gross,
		'transaction_id' => $txn,
		'gateway'        => 'manual',
	)
);
$order    = $order_id ? edd_get_order( (int) $order_id ) : null;
$decision = $order ? DecisionStore::get( (int) $order->id ) : null;

global $wpdb;
$rows = $order ? $wpdb->get_results( $wpdb->prepare( "SELECT scheme, vat, net, entry_type, source FROM {$wpdb->prefix}vatright_edd_ledger WHERE order_id = %d", $order->id ), ARRAY_A ) : array(); // phpcs:ignore WordPress.DB

echo '@@' . wp_json_encode(
	array(
		'order'    => $order ? array(
			'id'          => (int) $order->id,
			'status'      => $order->status,
			'tax'         => (float) $order->tax,
			'total'       => (float) $order->total,
			'tax_rate_id' => (int) $order->tax_rate_id,
		) : null,
		'decision' => $decision ? array(
			'scheme' => $decision->scheme(),
			'exempt' => $decision->is_vat_exempt(),
			'flags'  => $decision->flags(),
		) : null,
		'rows'     => $rows,
		'sub'      => array(
			'unchanged' => $before === array( (string) $sub->recurring_tax_rate, (string) $sub->recurring_tax ),
			'rate'      => (string) $sub->recurring_tax_rate,
			'tax'       => (string) $sub->recurring_tax,
		),
	)
) . '@@';
