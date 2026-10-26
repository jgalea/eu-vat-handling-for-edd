<?php

namespace VatrightEdd\Import;

/**
 * Imports from Barn2 EDD EU VAT.
 */
class ImportCommand {

	/** @var ImportRunner */
	private $runner;

	public function __construct( ImportRunner $runner ) {
		$this->runner = $runner;
	}

	/**
	 * Imports the settings and order history of Barn2 EDD EU VAT.
	 *
	 * Settings are copied on the server and reported by field name only. Order
	 * history is read from the meta Barn2 saved on each order. Running it twice
	 * changes nothing the second time.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would be imported and write nothing.
	 *
	 * [--since=<date>]
	 * : First day of order history to import, as YYYY-MM-DD. Defaults to 1 January of the previous year.
	 *
	 * [--settings-only]
	 * : Import the settings and skip the order history.
	 *
	 * [--history-only]
	 * : Import the order history and skip the settings.
	 *
	 * [--overwrite]
	 * : Replace settings that are already filled in here.
	 *
	 * @subcommand import-barn2
	 *
	 * @param array<int,string>   $args  Positional arguments.
	 * @param array<string,mixed> $assoc Flags and options.
	 */
	public function import_barn2( $args, $assoc ): void {
		$this->runner->run( $args, $assoc );
	}
}
