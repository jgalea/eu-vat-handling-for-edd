<?php

namespace VatrightEdd\Reports;

use RuntimeException;

class IncompleteReport extends RuntimeException {

	/** @var int[] */
	private $order_ids;

	/**
	 * @param int[] $order_ids
	 */
	public function __construct( array $order_ids ) {
		parent::__construct( 'No ECB rate was available for some orders.' );
		$this->order_ids = $order_ids;
	}

	/**
	 * @return int[]
	 */
	public function order_ids(): array {
		return $this->order_ids;
	}
}
