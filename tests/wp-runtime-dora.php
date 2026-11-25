<?php
/**
 * Runtime check inside a real WordPress and MySQL: the queries, validation, export, admin screen, REST routes and
 * hub adapter.
 *
 *   wp eval-file tests/wp-runtime-dora.php --user=1
 *
 * The one write (a supplier and a contract through RegisterRepository) runs inside a transaction that is rolled
 * back; the row counts before and after are compared. Exit 1 (WP_CLI::error) on failure.
 *
 * `wp eval-file` runs the file inside a method: state lives in $GLOBALS, and there is no declare() because a
 * declare after the docblock is fatal there.
 *
 * @package WMCyber\DORA
 */

defined( 'ABSPATH' ) || exit; // the local install serves the plugin folder over HTTP; this file is for wp eval-file only.

use WMCyber\DORA\Adapters\Dora_Spoke_Adapter;
use WMCyber\DORA\Admin\AdminDashboard;
use WMCyber\DORA\Core\Plugin;
use WMCyber\DORA\Export\RegisterCsvExporter;

$GLOBALS['wm_dora_rt'] = [ 'pass' => 0, 'fail' => 0 ];

function wm_dora_rt_check( string $name, bool $ok, string $details = '' ): void {
	$GLOBALS['wm_dora_rt'][ $ok ? 'pass' : 'fail' ]++;
	WP_CLI::log( ( $ok ? '[PASS] ' : '[FAIL] ' ) . $name . ( $ok || '' === $details ? '' : ': ' . $details ) );
}

if ( ! class_exists( Plugin::class ) ) {
	WP_CLI::error( 'wm-dora-ict-register is not loaded.' );
}
if ( ! current_user_can( 'manage_options' ) ) {
	WP_CLI::error( 'Run with --user=<an administrator>.' );
}

global $wpdb;
$plugin = Plugin::instance();
$repo   = $plugin->get_repository();

wm_dora_rt_check( 'tables exist', $repo->tables_exist() );

// Real SQL: counts (with the orphan LEFT JOIN) and the concentration query.
$wpdb->last_error = '';
$counts           = $repo->counts();
$matrix           = $plugin->get_cif_engine()->calculate_concentration_risk();
WP_CLI::log( 'counts ' . wp_json_encode( $counts ) . ' | concentration rows ' . count( $matrix ) );
wm_dora_rt_check( 'counts and concentration queries run without a database error', '' === $wpdb->last_error && count( $matrix ) === $counts['suppliers'], $wpdb->last_error );

// Validation without writing.
$bad = $repo->add_supplier( [ 'legal_name' => 'X', 'lei_code' => 'ZZZZZZZZZZZZZZZZZZZZ', 'country_code' => 'DE', 'is_parent_undertaking' => '0', 'iso27001_certified' => '0' ] );
wm_dora_rt_check( 'a 20-character LEI with wrong check digits is refused', ! $bad['ok'] && in_array( 'lei_code', $bad['errors'], true ) && $repo->counts() === $counts );

// One write inside a rolled-back transaction.
$wpdb->query( 'START TRANSACTION' );
$sup = $repo->add_supplier( [ 'legal_name' => 'Runtime Fixture GmbH', 'lei_code' => 'TEST00FIXTURE0000149', 'country_code' => 'DE', 'is_parent_undertaking' => '0', 'iso27001_certified' => '0' ] );
$con = $sup['ok'] ? $repo->add_contract( [ 'contract_ref' => 'RUNTIME-FIXTURE-1', 'supplier_id' => $sup['id'], 'service_category' => 'cloud_hosting', 'data_location_country' => 'DE', 'subcontracting_permitted' => '0', 'annual_spend' => '10.00', 'handles_core_banking_data' => 'no', 'rto_hours' => '8', 'subcontractor_count' => '0' ] ) : [ 'ok' => false, 'errors' => [ 'supplier' ] ];
$dup = $repo->add_supplier( [ 'legal_name' => 'Runtime Fixture GmbH', 'lei_code' => 'TEST00FIXTURE0000149', 'country_code' => 'DE', 'is_parent_undertaking' => '0', 'iso27001_certified' => '0' ] );
$row = $sup['ok'] ? $wpdb->get_row( $wpdb->prepare( 'SELECT created_at, iso27001_valid_until FROM %i WHERE id = %d', $wpdb->prefix . 'wm_dora_suppliers', $sup['id'] ), ARRAY_A ) : null;
$wpdb->query( 'ROLLBACK' );
WP_CLI::log( 'write in transaction: supplier ' . wp_json_encode( $sup ) . ' contract ' . wp_json_encode( $con ) );
wm_dora_rt_check( 'supplier and engine-scored contract stored, duplicate LEI 409, NULL date', $sup['ok'] && $con['ok'] && 'engine' === ( $con['cif']['source'] ?? '' ) && 409 === $dup['status'] && is_array( $row ) && null === $row['iso27001_valid_until'] );
wm_dora_rt_check( 'rollback leaves the counts as they were', $repo->counts() === $counts, wp_json_encode( $repo->counts() ) );

// Export.
$pkg       = $plugin->get_exporter()->export_package();
$fields_ok = true;
foreach ( [ 'suppliers', 'contracts', 'subcontractors' ] as $key ) {
	$lines = array_values( array_filter( explode( "\r\n", $pkg[ $key ] ), 'strlen' ) );
	$n     = count( str_getcsv( $lines[0] ?? '', ',', '"', '' ) );
	foreach ( array_slice( $lines, 1 ) as $line ) {
		$fields_ok = $fields_ok && count( str_getcsv( $line, ',', '"', '' ) ) === $n;
	}
}
$manifest = json_decode( $pkg['manifest'], true );
wm_dora_rt_check( 'export: equal field counts, CRLF, manifest says not xBRL-CSV, no B_ template codes', $fields_ok && is_array( $manifest ) && str_contains( (string) $manifest['format'], 'not an xBRL-CSV' ) && ! preg_match( '/B_0\d/', $pkg['suppliers'] . $pkg['contracts'] . $pkg['manifest'] ) );

// Admin.
$dash = new AdminDashboard( $plugin->get_exporter(), $repo );
$_GET = [ 'page' => 'wm-dora-register', 'tab' => 'overview' ];
ob_start();
$dash->render_dashboard_page();
$page = (string) ob_get_clean();
$_GET = [ 'page' => 'wm-dora-register', 'tab' => 'export' ];
ob_start();
$dash->render_dashboard_page();
$export_page = (string) ob_get_clean();
$_GET        = [];
wm_dora_rt_check( 'admin overview without fixed CONFORME / RESILIENCIA / invented LEI', ! preg_match( '/CONFORME|RESILIENCIA|529900MUSTERFINANZLEI1/u', $page ) );
wm_dora_rt_check( 'export tab links to admin-post downloads', str_contains( $export_page, 'admin-post.php?action=wm_dora_export' ) && null !== $dash->download_payload( 'suppliers' ) );

// REST routes.
$routes = array_keys( rest_get_server()->get_routes() );
wm_dora_rt_check( 'REST: /wm-dora/v1/export/csv registered, /export/xbrl-csv gone', in_array( '/wm-dora/v1/export/csv', $routes, true ) && ! in_array( '/wm-dora/v1/export/xbrl-csv', $routes, true ) );
$req = new WP_REST_Request( 'POST', '/wm-dora/v1/suppliers/add' );
$req->set_header( 'Content-Type', 'application/json' );
$req->set_body( wp_json_encode( [ 'legal_name' => 'X', 'lei_code' => 'ABC' ] ) );
$res = rest_do_request( $req );
wm_dora_rt_check( 'REST supplier with LEI "ABC" answers 400', 400 === $res->get_status(), (string) $res->get_status() );

// Hub adapter.
if ( ! class_exists( Dora_Spoke_Adapter::class ) ) {
	WP_CLI::log( '[SKIP] hub adapter: WM Suite Hub interface not loaded' );
} else {
	$adapter  = new Dora_Spoke_Adapter();
	$health   = $adapter->get_health_status();
	$settings = (array) get_option( 'wm_dora_settings', [] );
	$lei_ok   = null !== \WMCyber\DORA\Register\Validator::lei( $settings['entity_lei'] ?? null );
	$expect   = ! $lei_ok ? 'NEEDS_CONFIG' : ( $counts['orphan_contracts'] > 0 ? 'DEGRADED' : 'HEALTHY' );
	$self     = $adapter->execute_action( 'self_test' );
	WP_CLI::log( 'hub health ' . $health . ' | self_test ' . wp_json_encode( $self ) );
	wm_dora_rt_check( 'hub health follows entity LEI and orphan contracts', $expect === $health, $health . ' expected ' . $expect );
	wm_dora_rt_check( 'hub self test passes on a working install', true === $self['success'] );
}

WP_CLI::log( sprintf( '=== RUNTIME: %d passed, %d failed ===', $GLOBALS['wm_dora_rt']['pass'], $GLOBALS['wm_dora_rt']['fail'] ) );
if ( $GLOBALS['wm_dora_rt']['fail'] > 0 ) {
	WP_CLI::error( 'runtime check failed' );
}
