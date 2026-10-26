<?php

namespace VatrightEdd\Rates;

class Snapshot {

	public static function path(): string {
		return dirname( __DIR__, 2 ) . '/data/rates.json';
	}

	public static function load(): RateSet {
		$data = json_decode( (string) file_get_contents( self::path() ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return RateSet::from_array( $data );
	}
}
