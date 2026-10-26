<?php

namespace VatrightEdd\Ledger;

use Throwable;
use VatrightEdd\Settings;

/**
 * Emails the store admin when this year's cross-border EU consumer sales, as
 * the threshold tracker counts them, reach a share of the EUR 10,000
 * threshold and again when they pass it. Each level goes out once a year.
 */
class ThresholdWarning {

	const WARNING = 'warning';
	const LIMIT   = 'limit';

	const OPTION_PREFIX = 'vatright_edd_threshold_warning_';

	/** @var ThresholdTracker */
	private $tracker;

	/** @var ThresholdWarningEmail */
	private $email;

	public function __construct( ThresholdTracker $tracker, ThresholdWarningEmail $email ) {
		$this->tracker = $tracker;
		$this->email   = $email;
	}

	public function register(): void {
		add_action( 'vatright_edd_sale_recorded', array( $this, 'check' ), 10, 2 );
	}

	public static function level( float $current, float $threshold, int $percent ): ?string {
		$current = round( $current, 2 );
		if ( $current > $threshold ) {
			return self::LIMIT;
		}
		return $current >= round( $threshold * $percent / 100, 2 ) ? self::WARNING : null;
	}

	public static function sent( int $year, string $level ): bool {
		return false !== get_option( self::OPTION_PREFIX . $year . '_' . $level, false );
	}

	/**
	 * @param mixed $order_id
	 * @param mixed $year     The year of the sale just recorded.
	 */
	public function check( $order_id, $year ): void {
		$year     = (int) $year;
		$settings = Settings::get();
		if ( (int) wp_date( 'Y' ) !== $year || ! $settings->threshold_warning() || $settings->oss_registered() ) {
			return;
		}

		try {
			$status    = $this->tracker->status( $year );
			$threshold = $this->tracker->threshold();
			if ( $status->previous_eur > $threshold ) {
				return;
			}
			$level = self::level( $status->current_eur, $threshold, $settings->threshold_warning_percent() );
			if ( null === $level || self::sent( $year, $level ) ) {
				return;
			}

			$claimed = $this->claim( $year, $level );
			if ( ! $this->email->send( $level, $status->current_eur, $threshold, $settings->threshold_warning_percent() ) ) {
				foreach ( $claimed as $claim ) {
					delete_option( self::OPTION_PREFIX . $year . '_' . $claim );
				}
			}
		} catch ( Throwable $e ) {
			edd_debug_log( 'EU VAT Handling: could not send the threshold warning: ' . $e->getMessage() );
		}
	}

	/**
	 * Records the level as sent before the mail goes, so a second request
	 * finishing a sale at the same moment does not send it twice. Passing the
	 * threshold also uses up the lower level, which would be stale after it.
	 *
	 * @return string[] The levels newly recorded.
	 */
	private function claim( int $year, string $level ): array {
		$claimed = array();
		foreach ( self::LIMIT === $level ? array( self::WARNING, self::LIMIT ) : array( $level ) as $key ) {
			if ( ! self::sent( $year, $key ) ) {
				update_option( self::OPTION_PREFIX . $year . '_' . $key, gmdate( 'Y-m-d\TH:i:s\Z' ), false );
				$claimed[] = $key;
			}
		}
		return $claimed;
	}
}
