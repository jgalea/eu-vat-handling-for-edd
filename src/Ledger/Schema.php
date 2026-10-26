<?php

namespace VatrightEdd\Ledger;

class Schema {

	const DB_VERSION        = '2';
	const DB_VERSION_OPTION = 'vatright_edd_db_version';

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$ledger  = self::ledger_table();
		$fx      = self::fx_table();
		$rates   = self::rate_rows_table();

		dbDelta(
			"CREATE TABLE {$ledger} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				order_id BIGINT UNSIGNED NOT NULL,
				refund_id BIGINT UNSIGNED NULL,
				entry_type VARCHAR(8) NOT NULL,
				decision_id CHAR(36) NOT NULL,
				scheme VARCHAR(24) NOT NULL,
				rule VARCHAR(40) NOT NULL,
				supply_type VARCHAR(8) NOT NULL,
				customer_country CHAR(2) NOT NULL,
				place_of_supply CHAR(2) NOT NULL,
				customer_vat VARCHAR(32) NULL,
				rate_percent DECIMAL(7,4) NOT NULL,
				net DECIMAL(19,4) NOT NULL,
				vat DECIMAL(19,4) NOT NULL,
				currency CHAR(3) NOT NULL,
				eur_rate DECIMAL(18,8) NULL,
				net_eur DECIMAL(19,4) NULL,
				vat_eur DECIMAL(19,4) NULL,
				rate_missing TINYINT(1) NOT NULL DEFAULT 0,
				supply_at DATETIME NOT NULL,
				period CHAR(7) NOT NULL,
				relates_to_period CHAR(7) NOT NULL,
				source VARCHAR(10) NOT NULL DEFAULT 'live',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY period_scheme (period,scheme),
				KEY order_id (order_id),
				KEY customer_vat (customer_vat)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$fx} (
				currency CHAR(3) NOT NULL,
				rate_date DATE NOT NULL,
				rate DECIMAL(18,8) NULL,
				PRIMARY KEY  (currency,rate_date)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$rates} (
				edd_rate_id BIGINT UNSIGNED NOT NULL,
				country CHAR(2) NOT NULL,
				imported_rate DECIMAL(7,4) NOT NULL,
				source VARCHAR(20) NOT NULL,
				source_date VARCHAR(10) NOT NULL,
				PRIMARY KEY  (edd_rate_id)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::install();
		}
	}

	public static function ledger_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'vatright_edd_ledger';
	}

	public static function fx_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'vatright_edd_fx';
	}

	public static function rate_rows_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'vatright_edd_rate_rows';
	}
}
