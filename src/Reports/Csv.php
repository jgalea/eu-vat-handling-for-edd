<?php

namespace VatrightEdd\Reports;

class Csv {

	/**
	 * Strings a spreadsheet would read as a formula get a leading apostrophe.
	 * Numbers, including negative ones, pass through.
	 *
	 * @param mixed $value
	 */
	public static function cell( $value ): string {
		$text = (string) $value;
		if ( '' !== $text && ! is_numeric( $text ) && false !== strpos( "=+-@\t\r", $text[0] ) ) {
			return "'" . $text;
		}
		return $text;
	}

	/**
	 * @param string[]                 $header
	 * @param array<int,array<mixed>>  $rows
	 */
	public static function render( array $header, array $rows ): string {
		/** @var resource $stream */
		$stream = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		foreach ( array_merge( array( $header ), $rows ) as $row ) {
			fputcsv( $stream, array_map( array( self::class, 'cell' ), $row ), ',', '"', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		rewind( $stream );
		$csv = (string) stream_get_contents( $stream );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $csv;
	}

	/**
	 * @param string[]                 $header
	 * @param array<int,array<mixed>>  $rows
	 */
	public static function send( string $filename, array $header, array $rows ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		echo self::render( $header, $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
