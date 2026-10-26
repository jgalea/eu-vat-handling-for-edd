<?php

namespace VatrightEdd\Tests\Support;

/**
 * The few TCPDF calls the integration makes.
 */
class RecordingPdf {

	/** @var float */
	public $x = 61.0;

	/** @var array<int,array{text: string, ln: int, x: mixed, w: float}> */
	public $cells = array();

	public function GetX(): float { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- TCPDF's name.
		return $this->x;
	}

	/**
	 * @param mixed $x
	 */
	public function MultiCell( float $w, float $h, string $text, int $border = 0, string $align = 'J', bool $fill = false, int $ln = 1, $x = '' ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- TCPDF's name.
		$this->cells[] = array(
			'text' => $text,
			'ln'   => $ln,
			'x'    => $x,
			'w'    => $w,
		);
	}

	/**
	 * @return string[]
	 */
	public function texts(): array {
		return array_column( $this->cells, 'text' );
	}
}
