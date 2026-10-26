<?php

namespace VatrightEdd\Privacy;

use EDD\Orders\Order;
use VatrightEdd\Domain\VatNumber;
use VatrightEdd\Ledger\Schema;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Orders\OrderFinaliser;
use VatrightEdd\Registry\ValidationCache;
use VatrightEdd\Settings;

class Privacy {

	const GROUP    = 'vatright-edd';
	const PER_PAGE = 25;

	/** @var ValidationCache */
	private $cache;

	public function __construct( ValidationCache $cache ) {
		$this->cache = $cache;
	}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * @param array<string,mixed> $exporters
	 * @return array<string,mixed>
	 */
	public function add_exporter( array $exporters ): array {
		$exporters[ self::GROUP ] = array(
			'exporter_friendly_name' => __( 'EU VAT records', 'eu-vat-handling-for-edd' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array<string,mixed> $erasers
	 * @return array<string,mixed>
	 */
	public function add_eraser( array $erasers ): array {
		$erasers[ self::GROUP ] = array(
			'eraser_friendly_name' => __( 'EU VAT records', 'eu-vat-handling-for-edd' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * @return array{data: array<int,array<string,mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$orders   = $this->orders_for( $email, $page );
		$items    = array();
		$customer = 1 === $page ? edd_get_customer_by( 'email', $email ) : false;
		$saved    = $customer ? $this->saved_number( (int) $customer->id ) : '';

		if ( $customer && '' !== $saved ) {
			$items[] = array(
				'group_id'    => self::GROUP,
				'group_label' => __( 'EU VAT records', 'eu-vat-handling-for-edd' ),
				'item_id'     => 'vatright-edd-customer-' . (int) $customer->id,
				'data'        => array(
					array(
						'name'  => __( 'Saved VAT number', 'eu-vat-handling-for-edd' ),
						'value' => $saved,
					),
				),
			);
		}

		foreach ( $orders as $order ) {
			$order_id = (int) $order->id;
			$record   = json_decode( (string) edd_get_order_meta( $order_id, DecisionStore::META, true ), true );
			$record   = is_array( $record ) ? $record : array();
			$data     = array(
				array(
					'name'  => __( 'Order', 'eu-vat-handling-for-edd' ),
					'value' => (string) $order->get_number(),
				),
				array(
					'name'  => __( 'VAT number', 'eu-vat-handling-for-edd' ),
					'value' => (string) edd_get_order_meta( $order_id, DecisionStore::NUMBER_META, true ),
				),
				array(
					'name'  => __( 'Registered name', 'eu-vat-handling-for-edd' ),
					'value' => (string) ( $record['validation']['name'] ?? '' ),
				),
				array(
					'name'  => __( 'Registered address', 'eu-vat-handling-for-edd' ),
					'value' => (string) ( $record['validation']['address'] ?? '' ),
				),
				array(
					'name'  => __( 'Consultation number', 'eu-vat-handling-for-edd' ),
					'value' => (string) ( $record['validation']['consultation'] ?? '' ),
				),
				array(
					'name'  => __( 'Country of IP address', 'eu-vat-handling-for-edd' ),
					'value' => (string) ( $record['evidence']['ip'] ?? '' ),
				),
			);
			$items[]  = array(
				'group_id'    => self::GROUP,
				'group_label' => __( 'EU VAT records', 'eu-vat-handling-for-edd' ),
				'item_id'     => 'vatright-edd-order-' . $order_id,
				'data'        => array_values(
					array_filter(
						$data,
						static function ( array $row ): bool {
							return '' !== $row['value'];
						}
					)
				),
			);

			foreach ( $this->ledger_rows( $order_id ) as $row ) {
				$items[] = array(
					'group_id'    => self::GROUP . '-ledger',
					'group_label' => __( 'EU VAT ledger', 'eu-vat-handling-for-edd' ),
					'item_id'     => 'vatright-edd-ledger-' . $row['id'],
					'data'        => array(
						array(
							'name'  => __( 'Order', 'eu-vat-handling-for-edd' ),
							'value' => (string) $order->get_number(),
						),
						array(
							'name'  => __( 'Period', 'eu-vat-handling-for-edd' ),
							'value' => $row['period'],
						),
						array(
							'name'  => __( 'Scheme', 'eu-vat-handling-for-edd' ),
							'value' => $row['scheme'],
						),
						array(
							'name'  => __( 'VAT number', 'eu-vat-handling-for-edd' ),
							'value' => (string) $row['customer_vat'],
						),
						array(
							'name'  => __( 'Net', 'eu-vat-handling-for-edd' ),
							'value' => $row['net'] . ' ' . $row['currency'],
						),
						array(
							'name'  => __( 'VAT', 'eu-vat-handling-for-edd' ),
							'value' => $row['vat'] . ' ' . $row['currency'],
						),
					),
				);
			}
		}

		return array(
			'data' => $items,
			'done' => count( $orders ) < self::PER_PAGE,
		);
	}

	/**
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		$orders   = $this->orders_for( $email, $page );
		$removed  = false;
		$retained = false;
		$messages = array();
		$stores   = Settings::get()->store_requesters();
		$customer = 1 === $page ? edd_get_customer_by( 'email', $email ) : false;

		// The customer's copy is not a VAT record (each order keeps its own), so it goes regardless of retention.
		if ( $customer && '' !== $this->saved_number( (int) $customer->id ) ) {
			$number = VatNumber::parse( $this->saved_number( (int) $customer->id ), '' );
			if ( null !== $number ) {
				$this->cache->forget( $number, null );
				foreach ( $stores as $store ) {
					$this->cache->forget( $number, $store );
				}
			}
			edd_delete_customer_meta( (int) $customer->id, OrderFinaliser::CUSTOMER_NUMBER_META );
			edd_delete_customer_meta( (int) $customer->id, OrderFinaliser::CUSTOMER_VALIDATED_META );
			$removed = true;
		}

		foreach ( $orders as $order ) {
			if ( ! Retention::holds_personal_data( $order ) ) {
				continue;
			}

			$number = VatNumber::parse( (string) edd_get_order_meta( (int) $order->id, DecisionStore::NUMBER_META, true ), '' );
			if ( null !== $number ) {
				$this->cache->forget( $number, null );
				foreach ( $stores as $store ) {
					$this->cache->forget( $number, $store );
				}
			}

			if ( Retention::is_retained( $order ) ) {
				$retained   = true;
				$messages[] = sprintf(
					/* translators: 1: order number, 2: date */
					__( 'The VAT number and registry check for order %1$s are kept until %2$s because tax law requires VAT records to be kept.', 'eu-vat-handling-for-edd' ),
					$order->get_number(),
					wp_date( get_option( 'date_format' ), Retention::retained_until( $order )->getTimestamp() )
				);
				continue;
			}

			$removed = Retention::scrub( $order ) || $removed;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => count( $orders ) < self::PER_PAGE,
		);
	}

	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( 'EU VAT Handling for EDD', wp_kses_post( wpautop( $this->policy_text() ) ) );
	}

	public function policy_text(): string {
		return sprintf(
			/* translators: %d: number of years */
			__(
				'When a customer enters a VAT number at checkout, we check it with the official registry for its country: VIES (European Commission) for EU numbers, and HMRC (UK), the Swiss UID register or the Brønnøysund Register (Norway) for those countries. The registry receives the VAT number and, for VIES, our own VAT number as the requester. The registry returns whether the number is valid and, where it publishes them, the registered name and address.

We store the VAT number, the registry result including that name and address and the consultation number, and the country of the customer\'s IP address with the order. We use the IP country, together with the billing country, as evidence of where the sale takes place. Registry results are also cached for a short period.

EU VAT rules require us to keep these records for %d years after the end of the year of the sale. Within that period a request to erase them cannot be met, and we keep only what the VAT records need. After that period the VAT number, registered name and address, consultation number and IP country are removed from the order and the VAT ledger automatically.',
				'eu-vat-handling-for-edd'
			),
			Settings::get()->retention_years()
		);
	}

	private function saved_number( int $customer_id ): string {
		return (string) edd_get_customer_meta( $customer_id, OrderFinaliser::CUSTOMER_NUMBER_META, true );
	}

	/**
	 * @return Order[]
	 */
	private function orders_for( string $email, int $page ): array {
		$args     = array(
			'type'    => 'sale',
			'orderby' => 'id',
			'order'   => 'ASC',
			'number'  => self::PER_PAGE,
			'offset'  => ( max( 1, $page ) - 1 ) * self::PER_PAGE,
		);
		$customer = edd_get_customer_by( 'email', $email );
		if ( $customer ) {
			$args['customer_id'] = (int) $customer->id;
		} else {
			$args['email'] = $email;
		}
		return array_values( array_filter( edd_get_orders( $args ), 'is_object' ) );
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private function ledger_rows( int $order_id ): array {
		global $wpdb;

		$table = Schema::ledger_table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, period, scheme, customer_vat, net, vat, currency FROM {$table} WHERE order_id = %d ORDER BY id", $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}
}
