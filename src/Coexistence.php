<?php

namespace VatrightEdd;

class Coexistence {

	const BARN2_CLASS  = 'Barn2\Plugin\EDD_VAT\Plugin';
	const BARN2_PLUGIN = 'edd-eu-vat/edd-eu-vat.php';

	public static function barn2_active(): bool {
		if ( class_exists( self::BARN2_CLASS, false ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( self::BARN2_PLUGIN ) || is_plugin_active_for_network( self::BARN2_PLUGIN );
	}

	/**
	 * EDD Pro (3.5+) has its own EU VAT handling; on free EDD the toggle is an
	 * upsell and stays off.
	 */
	public static function edd_vat_active(): bool {
		return function_exists( 'edd_is_pro' ) && edd_is_pro() && (bool) edd_get_option( 'vat_enable', false );
	}

	/**
	 * Why the plugin stands down, or null when it runs.
	 */
	public static function blocker(): ?string {
		if ( self::barn2_active() ) {
			return 'barn2';
		}
		if ( self::edd_vat_active() ) {
			return 'edd_vat';
		}
		return null;
	}

	public static function notice(): void {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'EU VAT Handling for EDD is inactive because Barn2 EDD EU VAT is active. Deactivate one of them: running both would apply VAT twice.', 'eu-vat-handling-for-edd' ) . '</p></div>';
	}

	public static function edd_vat_notice(): void {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'EU VAT Handling for EDD is inactive because the EU VAT feature of Easy Digital Downloads (Pro) is turned on. Turn it off under Downloads > Settings > Taxes to use EU VAT Handling instead: running both would apply VAT twice.', 'eu-vat-handling-for-edd' ) . '</p></div>';
	}
}
