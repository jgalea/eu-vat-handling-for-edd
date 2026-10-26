<?php

namespace VatrightEdd\Rates;

class RateDiff {

	const ADD       = 'add';
	const CHANGE    = 'change';
	const EDITED    = 'edited';
	const UNCHANGED = 'unchanged';

	/** @var array<string,array{key:string,country:string,postcode:string,class:string,type:string,rate:float,current:float|null,rate_id:int|null,status:string}> */
	private $entries;

	/** @var string */
	private $source;

	/** @var string */
	private $source_date;

	/**
	 * @param array<string,array{key:string,country:string,postcode:string,class:string,type:string,rate:float,current:float|null,rate_id:int|null,status:string}> $entries
	 */
	public function __construct( array $entries, string $source, string $source_date ) {
		$this->entries     = $entries;
		$this->source      = $source;
		$this->source_date = $source_date;
	}

	/**
	 * @param array<int,array{country:string,class:string,type:string,rate:float,postcode?:string}> $targets
	 * @param array<string,array{rate_id:int,current:float,imported:float|null}> $existing Keyed by self::key().
	 */
	public static function build( array $targets, array $existing, string $source, string $source_date ): RateDiff {
		$entries = array();
		foreach ( $targets as $target ) {
			$key   = self::key( $target['country'], $target['class'] );
			$found = $existing[ $key ] ?? null;
			if ( null === $found ) {
				$status = self::ADD;
			} elseif ( self::same( $found['current'], $target['rate'] ) ) {
				$status = self::UNCHANGED;
			} elseif ( null !== $found['imported'] && self::same( $found['current'], $found['imported'] ) ) {
				$status = self::CHANGE;
			} else {
				$status = self::EDITED;
			}
			$entries[ $key ] = array(
				'key'      => $key,
				'country'  => $target['country'],
				'postcode' => $target['postcode'] ?? '',
				'class'    => $target['class'],
				'type'     => $target['type'],
				'rate'     => $target['rate'],
				'current'  => $found['current'] ?? null,
				'rate_id'  => $found['rate_id'] ?? null,
				'status'   => $status,
			);
		}
		return new self( $entries, $source, $source_date );
	}

	public static function key( string $country, string $tax_class ): string {
		return $country . '|' . $tax_class;
	}

	/**
	 * @return array<string,array{key:string,country:string,postcode:string,class:string,type:string,rate:float,current:float|null,rate_id:int|null,status:string}>
	 */
	public function entries(): array {
		return $this->entries;
	}

	/**
	 * @return array<string,array{key:string,country:string,postcode:string,class:string,type:string,rate:float,current:float|null,rate_id:int|null,status:string}>
	 */
	public function with_status( string $status ): array {
		return array_filter(
			$this->entries,
			static function ( array $entry ) use ( $status ): bool {
				return $status === $entry['status'];
			}
		);
	}

	public function source(): string {
		return $this->source;
	}

	public function source_date(): string {
		return $this->source_date;
	}

	public function pending_count(): int {
		return count( $this->entries ) - count( $this->with_status( self::UNCHANGED ) );
	}

	private static function same( float $a, float $b ): bool {
		return abs( $a - $b ) < 0.00005;
	}
}
