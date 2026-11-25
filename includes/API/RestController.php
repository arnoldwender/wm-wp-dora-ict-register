<?php
declare(strict_types=1);

namespace WMCyber\DORA\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\DORA\Classifier\CifEngine;
use WMCyber\DORA\Export\RegisterCsvExporter;
use WMCyber\DORA\Register\RegisterRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST routes under wm-dora/v1, all for manage_options. Writes go through RegisterRepository and answer with the
 * status of what happened (201, 400, 404, 409, 500).
 */
final class RestController {
	private const REST_NAMESPACE = 'wm-dora/v1';

	public function __construct(
		private readonly CifEngine $cif_engine,
		private readonly RegisterCsvExporter $exporter,
		private readonly RegisterRepository $repository
	) {}

	public function register_routes(): void {
		add_action( 'rest_api_init', [ $this, 'register' ] );
	}

	public function register(): void {
		$admin = static fn(): bool => current_user_can( 'manage_options' );

		register_rest_route( self::REST_NAMESPACE, '/suppliers', [ 'methods' => 'GET', 'callback' => [ $this, 'get_suppliers' ], 'permission_callback' => $admin ] );
		register_rest_route( self::REST_NAMESPACE, '/suppliers/add', [ 'methods' => 'POST', 'callback' => [ $this, 'add_supplier' ], 'permission_callback' => $admin ] );
		register_rest_route( self::REST_NAMESPACE, '/contracts/add', [ 'methods' => 'POST', 'callback' => [ $this, 'add_contract' ], 'permission_callback' => $admin ] );
		// The plugin's own CSV tables as JSON strings. Was /export/xbrl-csv until 2026-09-15; it never was xBRL-CSV.
		register_rest_route( self::REST_NAMESPACE, '/export/csv', [ 'methods' => 'GET', 'callback' => [ $this, 'export_csv' ], 'permission_callback' => $admin ] );
		register_rest_route( self::REST_NAMESPACE, '/concentration-risk', [ 'methods' => 'GET', 'callback' => [ $this, 'get_concentration_risk' ], 'permission_callback' => $admin ] );
	}

	public function get_suppliers(): WP_REST_Response {
		return new WP_REST_Response( [ 'success' => true, 'suppliers' => $this->repository->suppliers() ] );
	}

	public function add_supplier( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->repository->add_supplier( (array) $request->get_json_params() );
		if ( ! $result['ok'] ) {
			return new WP_Error( 'wm_dora_supplier_refused', 'Supplier not stored: ' . implode( ', ', $result['errors'] ), [ 'status' => $result['status'], 'fields' => $result['errors'] ] );
		}
		return new WP_REST_Response( [ 'success' => true, 'supplier_id' => $result['id'] ], 201 );
	}

	public function add_contract( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->repository->add_contract( (array) $request->get_json_params() );
		if ( ! $result['ok'] ) {
			return new WP_Error( 'wm_dora_contract_refused', 'Contract not stored: ' . implode( ', ', $result['errors'] ), [ 'status' => $result['status'], 'fields' => $result['errors'] ] );
		}
		return new WP_REST_Response( [ 'success' => true, 'contract_id' => $result['id'], 'cif_status' => $result['cif'] ], 201 );
	}

	public function export_csv(): WP_REST_Response {
		return new WP_REST_Response( [ 'success' => true, 'files' => $this->exporter->export_package() ] );
	}

	public function get_concentration_risk(): WP_REST_Response {
		return new WP_REST_Response( [ 'success' => true, 'risk' => $this->cif_engine->calculate_concentration_risk() ] );
	}
}
