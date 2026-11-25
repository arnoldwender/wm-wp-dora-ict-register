<?php
declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * WM Suite DORA ICT Register — uninstall.
 *
 * Preserves options and the register tables by default. Only when the explicit opt-in option
 * `wm_dora_delete_data_on_uninstall` is enabled does uninstall remove the settings options, the three
 * register tables, the legacy audit table older installs created, and the guard-only uploads/wm-dora/exports
 * directory. On multisite each site is checked independently.
 *
 * @package WMCyber\DORA
 */
( static function (): void {
	global $wpdb;

	$cleanup_site = static function () use ( $wpdb ): void {
		$opt_in = filter_var( get_option( 'wm_dora_delete_data_on_uninstall', 0 ), FILTER_VALIDATE_BOOLEAN );
		if ( ! $opt_in ) {
			return;
		}

		delete_option( 'wm_dora_settings' );
		delete_option( 'wm_dora_delete_data_on_uninstall' );
		delete_option( 'wm_dora_db_version' );
		foreach ( [ 'wm_dora_subcontractors', 'wm_dora_contracts', 'wm_dora_suppliers', 'wm_dora_audit_log' ] as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- opt-in uninstall removes the plugin's own tables.
		}

		$uploads = wp_upload_dir( null, false );
		$basedir = (string) ( $uploads['basedir'] ?? '' );
		$base    = trailingslashit( $basedir ) . 'wm-dora';
		$dir     = $base . '/exports';
		if ( '' !== $basedir && is_dir( $dir ) ) {
			$entries = array_values( array_diff( (array) scandir( $dir ), [ '.', '..' ] ) );
			if ( [] === array_diff( $entries, [ '.htaccess', 'web.config', 'index.php', 'index.html' ] ) ) {
				foreach ( $entries as $entry ) {
					wp_delete_file( $dir . '/' . $entry );
				}
				@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				if ( is_dir( $base ) && [] === array_diff( (array) scandir( $base ), [ '.', '..' ] ) ) {
					@rmdir( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				}
			}
		}
	};

	if ( is_multisite() ) {
		foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
			switch_to_blog( (int) $site_id );
			$cleanup_site();
			restore_current_blog();
		}
		if ( filter_var( get_site_option( 'wm_dora_delete_data_on_uninstall', 0 ), FILTER_VALIDATE_BOOLEAN ) ) {
			delete_site_option( 'wm_dora_settings' );
			delete_site_option( 'wm_dora_delete_data_on_uninstall' );
			delete_site_option( 'wm_dora_db_version' );
		}
	} else {
		$cleanup_site();
	}
} )();
