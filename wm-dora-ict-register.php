<?php
/**
 * Plugin Name:       WM DORA ICT Register & Supplier Portal
 * Plugin URI:        https://arnoldwender.com/wordpress-plugins/wm-dora-ict-register/
 * Description:       List of ICT third-party service providers and contracts with validated LEI, a CIF marking and a CSV export of the plugin tables. Not the register templates of Implementing Regulation (EU) 2024/2956 and not an xBRL-CSV report.
 * Version:           1.2.2
 * Requires at least: 6.4
 * Tested up to:      7.1
 * Requires PHP:      8.2
 * Author:            Arnold Wender
 * Author URI:        https://arnoldwender.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wm-dora-ict-register
 * Domain Path:       /languages
 */

declare(strict_types=1);

namespace WMCyber\DORA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WM_DORA_VERSION', '1.2.2' );
define( 'WM_DORA_FILE', __FILE__ );
define( 'WM_DORA_PATH', plugin_dir_path( __FILE__ ) );
define( 'WM_DORA_URL', plugin_dir_url( __FILE__ ) );

// Autoloader
spl_autoload_register( function ( string $class ): void {
	$prefix = 'WMCyber\\DORA\\';
	$base_dir = WM_DORA_PATH . 'includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// Lifecycle Hooks
register_activation_hook( __FILE__, [ Database\Schema::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Database\Schema::class, 'deactivate' ] );
add_action( 'wp_initialize_site', function ( object $new_site ): void {
	if ( ! is_multisite() || ! isset( $new_site->blog_id ) || (int) $new_site->blog_id <= 0 ) {
		return;
	}
	if ( ! function_exists( 'is_plugin_active_for_network' ) && is_file( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
		return;
	}
	switch_to_blog( (int) $new_site->blog_id );
	try {
		Database\Schema::activate();
	} finally {
		restore_current_blog();
	}
} );

// Register as Spoke in WM Suite Hub
add_filter( 'wm_register_suite_module', function ( array $modules ): array {
	if ( ! class_exists( 'WMCyber\DORA\Adapters\Dora_Spoke_Adapter' ) && file_exists( WM_DORA_PATH . 'includes/Adapters/Dora_Spoke_Adapter.php' ) ) {
		require_once WM_DORA_PATH . 'includes/Adapters/Dora_Spoke_Adapter.php';
	}
	if ( class_exists( 'WMCyber\DORA\Adapters\Dora_Spoke_Adapter' ) ) {
		$modules[] = new Adapters\Dora_Spoke_Adapter();
	}
	return $modules;
} );

add_action( 'plugins_loaded', function (): void {
	Core\Plugin::instance()->boot();
} );

