<?php
declare(strict_types=1);

namespace WMCyber\DORA\Register;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\DORA\Classifier\CifEngine;

/**
 * Validated writes to the supplier and contract tables, shared by the admin form and the REST routes.
 *
 * Every write reports what happened: until 2026-09-15 the admin form redirected with "registrado correctamente"
 * and REST answered 201 {"success": true} when the insert had failed, REST accepted a contract for a supplier id
 * that did not exist, and the two paths wrote site time and UTC into the same columns.
 */
final class RegisterRepository {
	public function __construct( private readonly CifEngine $cif_engine ) {}

	private function t( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'wm_dora_' . $name;
	}

	public function tables_exist(): bool {
		return CifEngine::table_exists( $this->t( 'suppliers' ) ) && CifEngine::table_exists( $this->t( 'contracts' ) );
	}

	/**
	 * @param array<string, mixed> $input legal_name, lei_code, country_code, is_parent_undertaking,
	 *                                    iso27001_certified, iso27001_valid_until (optional Y-m-d)
	 * @return array{ok: bool, status: int, id: int, errors: list<string>}
	 */
	public function add_supplier( array $input ): array {
		global $wpdb;

		$values = [
			'legal_name'            => Validator::text( $input['legal_name'] ?? null, 255 ),
			'lei_code'              => Validator::lei( $input['lei_code'] ?? null ),
			'country_code'          => Validator::country( $input['country_code'] ?? null ),
			'is_parent_undertaking' => Validator::boolean( $input['is_parent_undertaking'] ?? null ),
			'iso27001_certified'    => Validator::boolean( $input['iso27001_certified'] ?? null ),
		];
		$errors = array_keys( array_filter( $values, static fn( $v ): bool => null === $v ) );

		$valid_until = $input['iso27001_valid_until'] ?? '';
		$valid_until = ( null === $valid_until || '' === $valid_until ) ? null : Validator::date( $valid_until );
		if ( null === $valid_until && '' !== (string) ( $input['iso27001_valid_until'] ?? '' ) ) {
			$errors[] = 'iso27001_valid_until';
		}

		if ( [] !== $errors ) {
			return [ 'ok' => false, 'status' => 400, 'id' => 0, 'errors' => $errors ];
		}
		if ( ! $this->tables_exist() ) {
			return [ 'ok' => false, 'status' => 500, 'id' => 0, 'errors' => [ 'tables_missing' ] ];
		}
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE lei_code = %s', $this->t( 'suppliers' ), $values['lei_code'] ) ) ) {
			return [ 'ok' => false, 'status' => 409, 'id' => 0, 'errors' => [ 'lei_code_exists' ] ];
		}

		$now      = current_time( 'mysql', true );
		$inserted = $wpdb->insert(
			$this->t( 'suppliers' ),
			[
				'lei_code'              => $values['lei_code'],
				'legal_name'            => $values['legal_name'],
				'country_code'          => $values['country_code'],
				'is_parent_undertaking' => $values['is_parent_undertaking'] ? 1 : 0,
				'iso27001_certified'    => $values['iso27001_certified'] ? 1 : 0,
				'iso27001_valid_until'  => $valid_until,
				'soc2_type2_valid'      => 0,
				'created_at'            => $now,
				'updated_at'            => $now,
			],
			[ '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s' ]
		);
		if ( false === $inserted || (int) $inserted < 1 ) {
			return [ 'ok' => false, 'status' => 500, 'id' => 0, 'errors' => [ 'insert_failed' ] ];
		}
		return [ 'ok' => true, 'status' => 201, 'id' => (int) $wpdb->insert_id, 'errors' => [] ];
	}

	/**
	 * CIF comes either from an explicit supports_cif (a decision of the entity) or from the engine when all its
	 * inputs are given; with neither the contract is refused instead of being stored as "not critical".
	 *
	 * @param array<string, mixed> $input contract_ref, supplier_id, service_category, data_location_country,
	 *                                    subcontracting_permitted, annual_spend, supports_cif | engine inputs
	 * @return array{ok: bool, status: int, id: int, errors: list<string>, cif: array<string, mixed>}
	 */
	public function add_contract( array $input ): array {
		global $wpdb;

		$values = [
			'contract_ref'             => Validator::text( $input['contract_ref'] ?? null, 64 ),
			'service_category'         => Validator::service_category( $input['service_category'] ?? null ),
			'data_location_country'    => Validator::country( $input['data_location_country'] ?? null ),
			'subcontracting_permitted' => Validator::boolean( $input['subcontracting_permitted'] ?? null ),
			'annual_spend'             => Validator::amount( $input['annual_spend'] ?? null ),
		];
		$supplier_id = filter_var( $input['supplier_id'] ?? null, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
		$errors      = array_keys( array_filter( $values, static fn( $v ): bool => null === $v ) );
		if ( false === $supplier_id ) {
			$errors[] = 'supplier_id';
		}

		$manual = Validator::boolean( $input['supports_cif'] ?? null );
		$cif    = [ 'source' => 'manual', 'is_cif' => (bool) $manual, 'score' => null, 'rationale' => 'Manuelle Einstufung.' ];
		if ( null === $manual ) {
			$eval = $this->cif_engine->evaluate_cif( array_merge( $input, [ 'service_category' => $values['service_category'] ?? ( $input['service_category'] ?? null ) ] ) );
			if ( ! $eval['valid'] ) {
				$errors[] = 'supports_cif';
			}
			$cif = [ 'source' => 'engine', 'is_cif' => $eval['is_cif'], 'score' => $eval['score'], 'rationale' => $eval['rationale'] ];
		}

		if ( [] !== $errors ) {
			return [ 'ok' => false, 'status' => 400, 'id' => 0, 'errors' => array_values( array_unique( $errors ) ), 'cif' => [] ];
		}
		if ( ! $this->tables_exist() ) {
			return [ 'ok' => false, 'status' => 500, 'id' => 0, 'errors' => [ 'tables_missing' ], 'cif' => [] ];
		}
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $this->t( 'suppliers' ), $supplier_id ) ) ) {
			return [ 'ok' => false, 'status' => 404, 'id' => 0, 'errors' => [ 'supplier_not_found' ], 'cif' => [] ];
		}
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE contract_ref = %s', $this->t( 'contracts' ), $values['contract_ref'] ) ) ) {
			return [ 'ok' => false, 'status' => 409, 'id' => 0, 'errors' => [ 'contract_ref_exists' ], 'cif' => [] ];
		}

		$now      = current_time( 'mysql', true );
		$inserted = $wpdb->insert(
			$this->t( 'contracts' ),
			[
				'contract_ref'             => $values['contract_ref'],
				'supplier_id'              => $supplier_id,
				'service_category'         => $values['service_category'],
				'supports_cif'             => $cif['is_cif'] ? 1 : 0,
				'cif_rationale'            => $cif['rationale'],
				'data_location_country'    => $values['data_location_country'],
				'subcontracting_permitted' => $values['subcontracting_permitted'] ? 1 : 0,
				'annual_spend'             => $values['annual_spend'],
				'created_at'               => $now,
				'updated_at'               => $now,
			],
			[ '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%f', '%s', '%s' ]
		);
		if ( false === $inserted || (int) $inserted < 1 ) {
			return [ 'ok' => false, 'status' => 500, 'id' => 0, 'errors' => [ 'insert_failed' ], 'cif' => [] ];
		}
		return [ 'ok' => true, 'status' => 201, 'id' => (int) $wpdb->insert_id, 'errors' => [], 'cif' => $cif ];
	}

	public function supplier_id_by_lei( string $lei ): int {
		global $wpdb;
		if ( ! CifEngine::table_exists( $this->t( 'suppliers' ) ) ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE lei_code = %s', $this->t( 'suppliers' ), $lei ) );
	}

	public function contract_ref_exists( string $ref ): bool {
		global $wpdb;
		return CifEngine::table_exists( $this->t( 'contracts' ) ) && (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE contract_ref = %s', $this->t( 'contracts' ), $ref ) );
	}

	/** @return array{suppliers: int, contracts: int, cif_contracts: int, subcontractors: int, orphan_contracts: int} */
	public function counts(): array {
		global $wpdb;
		$out = [ 'suppliers' => 0, 'contracts' => 0, 'cif_contracts' => 0, 'subcontractors' => 0, 'orphan_contracts' => 0 ];
		if ( ! $this->tables_exist() ) {
			return $out;
		}
		$out['suppliers']        = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->t( 'suppliers' ) ) );
		$out['contracts']        = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->t( 'contracts' ) ) );
		$out['cif_contracts']    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE supports_cif = 1', $this->t( 'contracts' ) ) );
		$out['orphan_contracts'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i c LEFT JOIN %i s ON c.supplier_id = s.id WHERE s.id IS NULL', $this->t( 'contracts' ), $this->t( 'suppliers' ) ) );
		if ( CifEngine::table_exists( $this->t( 'subcontractors' ) ) ) {
			$out['subcontractors'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->t( 'subcontractors' ) ) );
		}
		return $out;
	}

	/** @return list<array<string, mixed>> Contracts with their supplier; contracts without a supplier row included. */
	public function contracts(): array {
		global $wpdb;
		if ( ! $this->tables_exist() ) {
			return [];
		}
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT c.*, s.legal_name, s.lei_code, s.country_code FROM %i c LEFT JOIN %i s ON c.supplier_id = s.id ORDER BY c.id DESC', $this->t( 'contracts' ), $this->t( 'suppliers' ) ),
			ARRAY_A
		);
	}

	/** @return list<array<string, mixed>> */
	public function suppliers(): array {
		global $wpdb;
		if ( ! CifEngine::table_exists( $this->t( 'suppliers' ) ) ) {
			return [];
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC', $this->t( 'suppliers' ) ), ARRAY_A );
		return array_values(
			array_map(
				static fn( array $row ): array => (array) apply_filters( 'wm_dora_supplier_row', $row ),
				$rows
			)
		);
	}
}
