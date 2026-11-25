<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wordpress/' );
}
if ( ! defined( 'WM_DORA_PATH' ) ) {
	define( 'WM_DORA_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'WM_DORA_VERSION' ) ) {
	define( 'WM_DORA_VERSION', '1.2.2' );
}

require_once dirname( __DIR__ ) . '/includes/Register/Validator.php';
require_once dirname( __DIR__ ) . '/includes/Classifier/CifEngine.php';
require_once dirname( __DIR__ ) . '/includes/Export/RegisterCsvExporter.php';
require_once dirname( __DIR__ ) . '/includes/Database/Schema.php';

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		return trim( strip_tags( (string) $s ) );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $o, $d = false ) {
		return 'wm_dora_settings' === $o ? array( 'entity_lei' => 'TEST00FIXTURE0000343' ) : $d;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = 'name' ) {
		return 'Test Financial Entity';
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, $value, ...$args ) {
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $tag, ...$args ): void {}
}

/**
 * Minimal $wpdb double.
 *
 * RegisterCsvExporter reads three tables through the global $wpdb. Without a double the test only
 * proved that the method throws, which is why it sat red the moment the suite was first able to
 * run at all. The fixtures below are shaped like the real schema so the CSV rendering is
 * exercised, not just the header strings.
 */
final class WM_DORA_Test_WPDB {

	public string $prefix = 'wp_';

	/** @var array<string, array<int, array<string, mixed>>> */
	public array $fixtures = array();

	public function __construct() {
		$this->fixtures['suppliers'] = array(
			array(
				'id'                    => 1,
				// Fictional LEIs with valid check digits, not registered at GLEIF (until 2026-09-15 this fixture
				// used 529900T8BM49AURSDO55, which belongs to Ubisecure Oy).
				'lei_code'              => 'TEST00FIXTURE0000149',
				'legal_name'            => 'Nordwind Cloud Services GmbH',
				'country_code'          => 'DE',
				'is_parent_undertaking' => 1,
				'iso27001_certified'    => 1,
				'iso27001_valid_until'  => '2027-03-31',
			),
			array(
				'id'                    => 2,
				// Embedded quote: the exporter must double it per RFC 4180.
				'lei_code'              => 'TEST00FIXTURE0000246',
				'legal_name'            => 'Data "Vault" Nordics AB',
				'country_code'          => 'SE',
				'is_parent_undertaking' => 0,
				'iso27001_certified'    => 0,
				'iso27001_valid_until'  => null,
			),
		);

		$this->fixtures['contracts'] = array(
			array(
				'id'               => 1,
				'contract_ref'     => 'ICT-2026-0001',
				'supplier_id'      => 1,
				'lei_code'         => 'TEST00FIXTURE0000149',
				'service_category'      => 'cloud_hosting',
				'supports_cif'          => 1,
				'data_location_country' => 'DE',
				'subcontracting_permitted' => 0,
				'annual_spend'          => 480000.00,
			),
		);

		$this->fixtures['subcontractors'] = array(
			array(
				'id'                  => 1,
				'contract_ref'       => 'ICT-2026-0001',
				'subcontractor_lei'  => 'TEST00FIXTURE0000343',
				'subcontractor_name' => 'Hanse Rechenzentrum GmbH',
				'service_provided'   => 'Physisches Hosting, Tier III',
				'country_location'   => 'DE',
				'tier_level'         => 2,
			),
		);
	}

	/**
	 * Route a query to its fixture by the table it names.
	 *
	 * @param string $query  SQL.
	 * @param string $output Ignored; ARRAY_A shape is always returned.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results( $query, $output = null ) {
		if ( str_contains( $query, 'wm_dora_subcontractors' ) ) {
			return $this->fixtures['subcontractors'];
		}
		if ( str_contains( $query, 'wm_dora_contracts' ) ) {
			return $this->fixtures['contracts'];
		}
		if ( str_contains( $query, 'wm_dora_suppliers' ) ) {
			return $this->fixtures['suppliers'];
		}
		return array();
	}

	/**
	 * @param string $query SQL.
	 * @return mixed
	 */
	public function get_var( $query ) {
		if ( preg_match( "/^SHOW TABLES LIKE '(.+)'$/", $query, $m ) ) {
			return str_replace( '\\', '', $m[1] );
		}
		return count( $this->fixtures['suppliers'] );
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * @param string $query SQL.
	 * @param mixed  ...$args Placeholder values.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[dsfFi]/', is_string( $arg ) ? "'" . $arg . "'" : (string) $arg, $query, 1 );
		}
		return $query;
	}
}

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new WM_DORA_Test_WPDB();
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

