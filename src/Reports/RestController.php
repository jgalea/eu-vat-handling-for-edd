<?php

namespace VatrightEdd\Reports;

use RuntimeException;
use VatrightEdd\Orders\DecisionStore;
use VatrightEdd\Orders\OrderDetails;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class RestController {

	const NAMESPACE_V1 = 'vatright-edd/v1';

	/** @var OssReport */
	private $oss;

	/** @var EcSalesList */
	private $esl;

	public function __construct( OssReport $oss, EcSalesList $esl ) {
		$this->oss = $oss;
		$this->esl = $esl;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$period = array(
			'year'    => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 2000,
				'maximum'  => 2100,
			),
			'quarter' => array(
				'type'     => 'integer',
				'required' => true,
				'enum'     => array( 1, 2, 3, 4 ),
			),
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/reports/oss',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'oss' ),
				'permission_callback' => array( $this, 'can_view_reports' ),
				'args'                => array_merge(
					$period,
					array(
						'currency' => array(
							'type'    => 'string',
							'default' => 'EUR',
							'pattern' => '^[A-Za-z]{3}$',
						),
					)
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/reports/ec-sales-list',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'ec_sales_list' ),
				'permission_callback' => array( $this, 'can_view_reports' ),
				'args'                => $period,
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/orders/(?P<id>\d+)/vat',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'order_vat' ),
				'permission_callback' => array( $this, 'can_read_order' ),
				'args'                => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			)
		);
	}

	public function can_view_reports(): bool {
		return current_user_can( ReportsScreen::CAPABILITY );
	}

	public function can_read_order(): bool {
		return current_user_can( OrderDetails::CAPABILITY );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function oss( WP_REST_Request $request ) {
		try {
			return rest_ensure_response( $this->oss->build( (int) $request['year'], (int) $request['quarter'], strtoupper( (string) $request['currency'] ) ) );
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'vatright_edd_rate_unavailable', $e->getMessage(), array( 'status' => 502 ) );
		}
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function ec_sales_list( WP_REST_Request $request ) {
		$year    = (int) $request['year'];
		$quarter = (int) $request['quarter'];
		try {
			$rows = $this->esl->build( $year, $quarter );
		} catch ( IncompleteReport $e ) {
			return new WP_Error(
				'vatright_edd_incomplete_report',
				$e->getMessage(),
				array(
					'status'    => 502,
					'order_ids' => $e->order_ids(),
				)
			);
		}
		$unknown = $this->esl->unknown_number_orders( $year, $quarter );
		return rest_ensure_response(
			array(
				'year'     => $year,
				'quarter'  => $quarter,
				'period'   => OssReport::period( $year, $quarter ),
				'rows'     => $rows,
				'excluded' => array(
					'count'  => count( $unknown ),
					'orders' => $unknown,
				),
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function order_vat( WP_REST_Request $request ) {
		$order_id = (int) $request['id'];
		$order    = edd_get_order( $order_id );
		if ( ! $order || 'sale' !== $order->type ) {
			return new WP_Error( 'vatright_edd_no_order', __( 'Order not found.', 'eu-vat-handling-for-edd' ), array( 'status' => 404 ) );
		}

		$decision = DecisionStore::get( $order_id );
		return rest_ensure_response(
			array(
				'order_id' => $order_id,
				'decision' => null === $decision ? null : $decision->to_array(),
				'log'      => DecisionStore::log( $order_id ),
			)
		);
	}
}
