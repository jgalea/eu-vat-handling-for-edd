<?php

namespace VatrightEdd\Ledger;

final class ThresholdStatus {

	/** @var float */
	public $current_eur;

	/** @var float */
	public $previous_eur;

	/** @var bool */
	public $exceeded;

	/** @var bool */
	public $forced;

	public function __construct( float $current_eur, float $previous_eur, bool $exceeded, bool $forced ) {
		$this->current_eur  = $current_eur;
		$this->previous_eur = $previous_eur;
		$this->exceeded     = $exceeded;
		$this->forced       = $forced;
	}

	/**
	 * @return array{current_eur:string,previous_eur:string,exceeded:bool,forced:bool}
	 */
	public function to_array(): array {
		return array(
			'current_eur'  => number_format( $this->current_eur, 2, '.', '' ),
			'previous_eur' => number_format( $this->previous_eur, 2, '.', '' ),
			'exceeded'     => $this->exceeded,
			'forced'       => $this->forced,
		);
	}
}
