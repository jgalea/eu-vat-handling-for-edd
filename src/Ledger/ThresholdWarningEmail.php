<?php

namespace VatrightEdd\Ledger;

use VatrightEdd\Admin\SettingsSection;
use VatrightEdd\Reports\ReportsScreen;

class ThresholdWarningEmail {

	public function send( string $level, float $current, float $threshold, int $percent ): bool {
		$to = apply_filters( 'vatright_edd_threshold_warning_recipient', get_bloginfo( 'admin_email' ), $level );
		if ( ! is_string( $to ) || '' === $to ) {
			return false;
		}

		$emails = EDD()->emails;
		// @phpstan-ignore property.notFound (The stub types EDD()->emails as a bare object; EDD's mailer exposes the heading and send().)
		$emails->heading = $this->heading( $level, $percent );
		// @phpstan-ignore method.notFound (As above.)
		return (bool) $emails->send( $to, $this->subject( $level, $threshold, $percent ), $this->body( $level, $current, $threshold, $percent ) );
	}

	public function subject( string $level, float $threshold, int $percent ): string {
		$site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$amount = $this->money( $threshold, 0 );
		if ( ThresholdWarning::LIMIT === $level ) {
			/* translators: 1: site name, 2: threshold amount. */
			return sprintf( __( '[%1$s]: EU sales passed the %2$s OSS threshold', 'eu-vat-handling-for-edd' ), $site, $amount );
		}
		/* translators: 1: site name, 2: percentage, 3: threshold amount. */
		return sprintf( __( '[%1$s]: EU sales reached %2$d%% of the %3$s OSS threshold', 'eu-vat-handling-for-edd' ), $site, $percent, $amount );
	}

	public function heading( string $level, int $percent ): string {
		if ( ThresholdWarning::LIMIT === $level ) {
			return __( 'OSS threshold passed', 'eu-vat-handling-for-edd' );
		}
		/* translators: %d: percentage. */
		return sprintf( __( '%d%% of the OSS threshold', 'eu-vat-handling-for-edd' ), $percent );
	}

	public function body( string $level, float $current, float $threshold, int $percent ): string {
		$counted = sprintf(
			/* translators: 1: amount counted so far, 2: threshold amount, 3: share of the threshold. */
			__( 'Counted so far this year: %1$s of %2$s (%3$s%%).', 'eu-vat-handling-for-edd' ),
			$this->money( $current ),
			$this->money( $threshold ),
			number_format_i18n( $threshold > 0 ? $current / $threshold * 100 : 0, 1 )
		);

		if ( ThresholdWarning::LIMIT === $level ) {
			$lead = __( 'Your cross-border EU sales to consumers passed the threshold this year. The plugin now charges VAT at the customer\'s country rate on these sales.', 'eu-vat-handling-for-edd' );
			$next = __( 'To declare that VAT you need to be registered for the One Stop Shop (OSS), or for VAT in each country you sell to. Once you are registered for OSS, tick the OSS box in the plugin settings.', 'eu-vat-handling-for-edd' );
		} else {
			/* translators: %d: percentage. */
			$lead = sprintf( __( 'Your cross-border EU sales to consumers have reached %d%% of the threshold this year.', 'eu-vat-handling-for-edd' ), $percent );
			$next = __( 'Once they pass it, the plugin starts charging VAT at the customer\'s country rate and you need to be registered for the One Stop Shop (OSS), or for VAT in each country you sell to. If you are already registered for OSS, tick the OSS box in the plugin settings and these emails stop.', 'eu-vat-handling-for-edd' );
		}

		$reports  = admin_url( 'edit.php?post_type=download&page=' . ReportsScreen::SLUG );
		$settings = SettingsSection::url();
		return $lead . "\n\n" . $counted . "\n\n" . $next . "\n\n"
			. __( 'Reports', 'eu-vat-handling-for-edd' ) . ': ' . $reports . "\n"
			. __( 'Settings', 'eu-vat-handling-for-edd' ) . ': ' . $settings . "\n";
	}

	private function money( float $amount, int $decimals = 2 ): string {
		return 'EUR ' . number_format_i18n( $amount, $decimals );
	}
}
