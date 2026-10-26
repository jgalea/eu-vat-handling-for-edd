<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile, WordPress.Files.FileName

/**
 * Stands in for EDD Recurring's subscription, which is licensed code and
 * is never copied here. It has the properties the plugin reads, fires the
 * same filters and actions in the same order, and creates the renewal order
 * the way the real class does: the gross is what the gateway charged, and the
 * tax is backed out of it.
 */
class EDD_Subscription {

	/** @var int */
	private static $next_id = 1;

	/** @var int */
	public $id = 0;

	/** @var int */
	public $parent_payment_id = 0;

	/** @var int */
	public $product_id = 0;

	/** @var string */
	public $recurring_amount = '';

	/** @var string */
	public $recurring_tax_rate = '';

	/** @var string */
	public $recurring_tax = '';

	/**
	 * A subscription as signup leaves it: the gross it renews at and the rate
	 * that gross was priced with.
	 */
	public static function signed_up( int $parent_order_id, int $product_id, float $gross, float $percent ): self {
		$sub                     = new self();
		$sub->id                 = self::$next_id++;
		$sub->parent_payment_id  = $parent_order_id;
		$sub->product_id         = $product_id;
		$sub->recurring_amount   = (string) $gross;
		$sub->recurring_tax_rate = $percent > 0 ? (string) ( $percent / 100 ) : '';
		$sub->recurring_tax      = (string) ( $percent > 0 ? round( $gross - $gross / ( 1 + $percent / 100 ), 2 ) : 0 );
		return $sub;
	}

	/**
	 * What checkout hands the gateway for a cart line: the recurring amounts
	 * from the cart's own tax, then the subscription arguments.
	 *
	 * @param array<string,mixed> $item A line of edd_get_cart_content_details().
	 * @return array<string,mixed>
	 */
	public static function signup_arguments( array $item ): array {
		$price   = (float) $item['item_price'];
		$tax     = edd_calculate_tax( $price );
		$amounts = array(
			'amount' => edd_prices_include_tax() ? $price : $price + $tax,
			'tax'    => $tax,
		);
		$amounts = apply_filters( 'edd_recurring_subscription_recurring_amounts', $amounts, $item, 0, array(), array() );
		$rate    = edd_get_tax_rate();

		$args = array(
			'id'                 => $item['id'],
			'recurring_amount'   => $amounts['amount'],
			'recurring_tax'      => $amounts['tax'],
			'initial_tax'        => $item['tax'],
			'initial_tax_rate'   => $rate,
			'recurring_tax_rate' => $rate,
		);
		return apply_filters( 'edd_recurring_subscription_pre_gateway_args', $args, $item );
	}

	/**
	 * @param array<string,mixed> $args
	 * @return int|false The renewal order's ID.
	 */
	public function add_payment( $args = array() ) {
		$parent = edd_get_order( $this->parent_payment_id );
		if ( ! $parent ) {
			return false;
		}

		$args = wp_parse_args(
			$args,
			array(
				'amount'         => '',
				'tax'            => null,
				'transaction_id' => '',
				'date'           => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$args = apply_filters( 'edd_recurring_add_payment_pre_args', $args, $this );

		$gross = (float) $args['amount'];
		$rate  = $this->rate_row( $parent );
		$tax   = $this->tax_inside( $args, $rate, $parent->id );

		$order_id = edd_add_order(
			array(
				'parent'       => $parent->id,
				'type'         => 'sale',
				'status'       => 'edd_subscription',
				'user_id'      => $parent->user_id,
				'customer_id'  => $parent->customer_id,
				'email'        => $parent->email,
				'currency'     => $parent->currency,
				'gateway'      => $parent->gateway,
				'subtotal'     => $gross - $tax,
				'tax'          => $tax,
				'total'        => $gross,
				'tax_rate_id'  => $rate ? $rate->id : $parent->tax_rate_id,
				'date_created' => $args['date'],
				'payment_key'  => md5( uniqid( '', true ) ),
			)
		);

		$address = $parent->get_address();
		if ( $address ) {
			edd_add_order_address(
				array(
					'order_id'    => $order_id,
					'type'        => 'billing',
					'name'        => $address->name,
					'address'     => $address->address,
					'city'        => $address->city,
					'postal_code' => $address->postal_code,
					'country'     => $address->country,
				)
			);
		}

		edd_add_order_item(
			array(
				'order_id'     => $order_id,
				'product_id'   => $this->product_id,
				'product_name' => 'Renewal',
				'quantity'     => 1,
				'amount'       => edd_prices_include_tax() ? $gross : $gross - $tax,
				'subtotal'     => $gross - $tax,
				'tax'          => $tax,
				'total'        => $gross,
				'status'       => 'complete',
			)
		);

		$copied = edd_get_order_meta( $parent->id, 'tax_rate', true );
		if ( ! empty( $copied ) ) {
			edd_update_order_meta( $order_id, 'tax_rate', $copied );
		}

		do_action( 'edd_recurring_add_subscription_order', edd_get_order( $order_id ), $this );
		return $order_id;
	}

	/**
	 * The parent's own rate row while it is active, else the rate now active
	 * where the parent lives.
	 *
	 * @param object $original
	 * @return object|false
	 */
	private function rate_row( $original ) {
		if ( ! $original->tax_rate_id ) {
			return false;
		}
		$row = $original->get_tax_rate_object();
		if ( $row && 'active' === $row->status ) {
			return $row;
		}
		$address = $original->get_address();
		return edd_get_tax_rate_by_location( array( 'country' => $address->country ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $args
	 * @param object|false        $rate
	 */
	private function tax_inside( array $args, $rate, int $parent_id ): float {
		if ( null !== $args['tax'] ) {
			return (float) $args['tax'];
		}
		if ( filter_var( edd_get_order_meta( $parent_id, '_edd_payment_vat_reverse_charged', true ), FILTER_VALIDATE_BOOLEAN ) ) {
			return 0.0;
		}
		if ( '' !== $this->recurring_tax_rate ) {
			$percent = $rate && $rate->amount > 0 ? $rate->amount / 100 : (float) $this->recurring_tax_rate;
			return round( (float) $args['amount'] - (float) $args['amount'] / ( 1 + $percent ), 2 );
		}
		return (float) $this->recurring_tax;
	}
}
