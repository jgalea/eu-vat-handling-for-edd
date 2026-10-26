<?php

namespace VatrightEdd\Import;

use DateTimeImmutable;
use InvalidArgumentException;
use VatrightEdd\Admin\SettingsSection;
use VatrightEdd\Ledger\Backfill;
use VatrightEdd\Settings;

class ImportRunner {

	/** @var Barn2Settings */
	private $settings;

	/** @var Importer */
	private $importer;

	public function __construct( Barn2Settings $settings, Importer $importer ) {
		$this->settings = $settings;
		$this->importer = $importer;
	}

	public function register(): void {
		add_action( 'cli_init', array( $this, 'add_command' ) );
	}

	public function add_command(): void {
		\WP_CLI::add_command( 'eu-vat-handling-for-edd', new ImportCommand( $this ) );
	}

	/**
	 * @param array<int,string>   $args
	 * @param array<string,mixed> $assoc
	 */
	public function run( array $args, array $assoc ): void {
		unset( $args );
		try {
			$lines = $this->execute( $assoc );
		} catch ( InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}
		foreach ( $lines as $line ) {
			\WP_CLI::log( $line );
		}
	}

	/**
	 * The output of a run: field names with their outcomes and counts, never
	 * a setting's value.
	 *
	 * @param array<string,mixed> $assoc
	 * @return string[]
	 * @throws InvalidArgumentException On options that cannot be combined or understood.
	 */
	public function execute( array $assoc ): array {
		$dry       = ! empty( $assoc['dry-run'] );
		$settings  = empty( $assoc['history-only'] );
		$history   = empty( $assoc['settings-only'] );
		$overwrite = ! empty( $assoc['overwrite'] );
		$oss       = ! empty( $assoc['oss-registered'] );
		$since     = $this->since( isset( $assoc['since'] ) ? (string) $assoc['since'] : '' );
		$lines     = array();

		if ( ! $settings && ! $history ) {
			throw new InvalidArgumentException( 'Use only one of --settings-only and --history-only.' );
		}

		if ( $settings ) {
			$lines[] = $dry ? 'Settings (dry run, nothing written):' : 'Settings:';
			$report  = $this->settings->import( $overwrite, ! $dry );
			if ( array() === $report ) {
				$lines[] = '  Barn2 has no settings saved.';
			}
			foreach ( $report as $field => $outcome ) {
				$lines[] = '  ' . $field . ': ' . $outcome;
			}
		}

		if ( $oss ) {
			$lines[] = $this->register_oss( $dry );
		}

		$destination = false;
		if ( $history ) {
			$lines[] = $dry ? 'History from ' . $since->format( 'Y-m-d' ) . ' (dry run, nothing written):' : 'History from ' . $since->format( 'Y-m-d' ) . ':';
			if ( $dry ) {
				$plan        = $this->importer->dry_run( $since );
				$destination = $plan['destination'];
				$lines       = array_merge( $lines, self::describe( $plan ) );
			} else {
				$state = $this->importer->run_all( $since );
				if ( null === $state ) {
					throw new InvalidArgumentException( 'An import is already running. Wait for it to finish.' );
				}
				$destination = ! empty( $state['destination'] );
				$lines[]     = sprintf( '  Imported %d orders and %d ledger rows; %d failed.', (int) $state['orders'], (int) $state['rows'], (int) $state['failed'] );
			}
		}

		$reason = $oss ? null : OssAdvice::for_store( $destination );
		if ( null !== $reason ) {
			$lines[] = 'Recommendation: ' . OssAdvice::message( $reason ) . ' Run again with --oss-registered, or change it at ' . SettingsSection::url();
		}

		return $lines;
	}

	private function register_oss( bool $dry ): string {
		if ( Settings::get()->oss_registered() ) {
			return 'OSS registration: already on.';
		}
		if ( $dry ) {
			return 'OSS registration (dry run, nothing written): would turn on "' . OssAdvice::setting_label() . '".';
		}
		OssAdvice::enable();
		return 'OSS registration: turned on "' . OssAdvice::setting_label() . '".';
	}

	/**
	 * @param array{orders:int,rows:int,barn2_orders:int,backfill_orders:int,quarters:array<string,array{orders:int,rows:int,schemes:array<string,int>}>} $plan
	 * @return string[]
	 */
	public static function describe( array $plan ): array {
		$lines = array(
			sprintf( '  %d orders (%d with Barn2 data, %d without) would write %d ledger rows.', $plan['orders'], $plan['barn2_orders'], $plan['backfill_orders'], $plan['rows'] ),
		);
		foreach ( $plan['quarters'] as $quarter => $counts ) {
			$schemes = array();
			foreach ( $counts['schemes'] as $scheme => $rows ) {
				$schemes[] = $scheme . ' ' . $rows;
			}
			$lines[] = sprintf( '  %s: %d orders, %d rows (%s)', $quarter, $counts['orders'], $counts['rows'], implode( ', ', $schemes ) );
		}
		return $lines;
	}

	/**
	 * @throws InvalidArgumentException When the date is not a valid YYYY-MM-DD.
	 */
	private function since( string $value ): DateTimeImmutable {
		if ( '' === $value ) {
			return Backfill::default_since();
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			throw new InvalidArgumentException( '--since must be a date as YYYY-MM-DD.' );
		}
		return $date;
	}
}
