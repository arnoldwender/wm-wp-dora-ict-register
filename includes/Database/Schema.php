<?php
declare(strict_types=1);

namespace WMCyber\DORA\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's tables. They hold a supplier list, contracts and subcontractors with the plugin's own columns; they
 * are not the templates of Implementing Regulation (EU) 2024/2956 (until 2026-09-15 the comments here named them
 * B_01.01, B_02.01 and B_03.01, and a "GoBD" audit table was created that nothing ever wrote).
 */
final class Schema {
	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					self::activate_site();
				} finally {
					restore_current_blog();
				}
			}
			return;
		}
		self::activate_site();
	}

	public static function activate_site(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			$upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( is_file( $upgrade_file ) ) {
				require_once $upgrade_file;
			}
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			return;
		}

		$charset_collate      = $wpdb->get_charset_collate();
		$table_suppliers      = $wpdb->prefix . 'wm_dora_suppliers';
		$table_contracts      = $wpdb->prefix . 'wm_dora_contracts';
		$table_subcontractors = $wpdb->prefix . 'wm_dora_subcontractors';

		// Suppliers (one row per LEI).
		dbDelta( "CREATE TABLE $table_suppliers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lei_code varchar(20) NOT NULL,
			legal_name varchar(255) NOT NULL,
			country_code varchar(2) NOT NULL,
			is_parent_undertaking tinyint(1) NOT NULL DEFAULT 0,
			iso27001_certified tinyint(1) NOT NULL DEFAULT 0,
			iso27001_valid_until date DEFAULT NULL,
			soc2_type2_valid tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uq_lei_code (lei_code)
		) $charset_collate;" );

		// Contracts with the CIF marking and its reason.
		dbDelta( "CREATE TABLE $table_contracts (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			contract_ref varchar(64) NOT NULL,
			supplier_id bigint(20) unsigned NOT NULL,
			service_category varchar(64) NOT NULL,
			supports_cif tinyint(1) NOT NULL DEFAULT 0,
			cif_rationale text DEFAULT NULL,
			data_location_country varchar(2) NOT NULL,
			subcontracting_permitted tinyint(1) NOT NULL DEFAULT 0,
			annual_spend decimal(12,2) NOT NULL DEFAULT 0.00,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uq_contract_ref (contract_ref),
			KEY idx_supplier_id (supplier_id),
			KEY idx_supports_cif (supports_cif)
		) $charset_collate;" );

		// Subcontractors per contract. No admin form, REST route or CLI command writes to it yet.
		dbDelta( "CREATE TABLE $table_subcontractors (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			contract_id bigint(20) unsigned NOT NULL,
			subcontractor_lei varchar(20) NOT NULL,
			subcontractor_name varchar(255) NOT NULL,
			service_provided varchar(128) NOT NULL,
			country_location varchar(2) NOT NULL,
			tier_level tinyint(2) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY idx_contract_id (contract_id)
		) $charset_collate;" );
		update_option( 'wm_dora_db_version', 2, false );
	}

	/**
	 * Ensure tables exist on already-active installs where activation does not re-run.
	 */
	public static function maybe_upgrade(): void {
		if ( 2 <= (int) get_option( 'wm_dora_db_version', 0 ) ) {
			return;
		}
		self::activate_site();
	}

	public static function deactivate(): void {
		// Nothing is scheduled or cached.
	}
}
