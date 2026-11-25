<?php
declare(strict_types=1);

namespace WMCyber\DORA\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\DORA\Classifier\CifEngine;

/**
 * CSV export of the plugin's three tables plus a manifest.
 *
 * This is not the register of information in the format of Commission Implementing Regulation (EU) 2024/2956 and
 * not an xBRL-CSV report package: the ITS templates start with B_01.01 "Entity maintaining the register of
 * information" and place providers and the supply chain in B_05.01 and B_05.02, while this plugin keeps only
 * suppliers, contracts and subcontractors with its own columns. Until 2026-09-15 the class was called
 * XbrlCsvExporter, labelled the supplier table B_01.01 and the subcontractors B_03.01, and wrote fields unquoted,
 * so a comma shifted the columns and a leading "=" reached spreadsheets as a formula.
 */
final class RegisterCsvExporter {
	public const FILES = [
		'suppliers'      => 'dora-register-suppliers.csv',
		'contracts'      => 'dora-register-contracts.csv',
		'subcontractors' => 'dora-register-subcontractors.csv',
		'manifest'       => 'dora-register-manifest.json',
	];

	/**
	 * @return array{suppliers: string, contracts: string, subcontractors: string, manifest: string}
	 */
	public function export_package(): array {
		global $wpdb;
		$t_sup = $wpdb->prefix . 'wm_dora_suppliers';
		$t_con = $wpdb->prefix . 'wm_dora_contracts';
		$t_sub = $wpdb->prefix . 'wm_dora_subcontractors';

		$suppliers      = CifEngine::table_exists( $t_sup ) ? (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', $t_sup ), ARRAY_A ) : [];
		$contracts      = ( CifEngine::table_exists( $t_con ) && CifEngine::table_exists( $t_sup ) )
			? (array) $wpdb->get_results( $wpdb->prepare( 'SELECT c.*, s.lei_code FROM %i c LEFT JOIN %i s ON c.supplier_id = s.id ORDER BY c.id ASC', $t_con, $t_sup ), ARRAY_A )
			: [];
		$subcontractors = ( CifEngine::table_exists( $t_sub ) && CifEngine::table_exists( $t_con ) )
			? (array) $wpdb->get_results( $wpdb->prepare( 'SELECT sub.*, c.contract_ref FROM %i sub LEFT JOIN %i c ON sub.contract_id = c.id ORDER BY sub.id ASC', $t_sub, $t_con ), ARRAY_A )
			: [];

		$csv_suppliers = self::csv(
			[ 'LEI', 'LegalName', 'CountryCode', 'IsParentUndertaking', 'ISO27001Certified', 'ISO27001ValidUntil' ],
			array_map( static fn( array $s ): array => [
				$s['lei_code'],
				$s['legal_name'],
				$s['country_code'],
				$s['is_parent_undertaking'] ? 'TRUE' : 'FALSE',
				$s['iso27001_certified'] ? 'TRUE' : 'FALSE',
				self::date_or_empty( $s['iso27001_valid_until'] ?? null ),
			], $suppliers )
		);

		$csv_contracts = self::csv(
			[ 'ContractRef', 'SupplierLEI', 'ServiceCategory', 'SupportsCIF', 'DataLocationCountry', 'SubcontractingPermitted', 'AnnualSpendEUR' ],
			array_map( static fn( array $c ): array => [
				$c['contract_ref'],
				(string) ( $c['lei_code'] ?? '' ), // empty when the supplier row is missing
				$c['service_category'],
				$c['supports_cif'] ? 'TRUE' : 'FALSE',
				$c['data_location_country'],
				$c['subcontracting_permitted'] ? 'TRUE' : 'FALSE',
				number_format( (float) $c['annual_spend'], 2, '.', '' ),
			], $contracts )
		);

		$csv_subcontractors = self::csv(
			[ 'ContractRef', 'SubcontractorLEI', 'SubcontractorName', 'ServiceProvided', 'CountryLocation', 'TierLevel' ],
			array_map( static fn( array $s ): array => [
				(string) ( $s['contract_ref'] ?? '' ),
				$s['subcontractor_lei'],
				$s['subcontractor_name'],
				$s['service_provided'],
				$s['country_location'],
				(string) (int) $s['tier_level'],
			], $subcontractors )
		);

		$settings = (array) get_option( 'wm_dora_settings', [] );
		$manifest = [
			'format'       => 'CSV export of the WM DORA ICT Register tables. Not the templates of Implementing Regulation (EU) 2024/2956 and not an xBRL-CSV report package.',
			'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'entity'       => [
				'lei'                 => '' !== (string) ( $settings['entity_lei'] ?? '' ) ? (string) $settings['entity_lei'] : null,
				'name'                => '' !== (string) ( $settings['entity_name'] ?? '' ) ? (string) $settings['entity_name'] : null,
				'competent_authority' => '' !== (string) ( $settings['competent_authority'] ?? '' ) ? (string) $settings['competent_authority'] : null,
			],
			'files'        => [
				self::FILES['suppliers']      => count( $suppliers ),
				self::FILES['contracts']      => count( $contracts ),
				self::FILES['subcontractors'] => count( $subcontractors ),
			],
		];

		return [
			'suppliers'      => $csv_suppliers,
			'contracts'      => $csv_contracts,
			'subcontractors' => $csv_subcontractors,
			'manifest'       => (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
		];
	}

	/**
	 * RFC 4180 CSV with CRLF line ends. A cell starting with = + - @ tab or carriage return gets a leading
	 * apostrophe, so spreadsheets show it as text instead of evaluating it (OWASP "CSV Injection").
	 *
	 * @param list<string>       $header
	 * @param list<list<string>> $rows
	 */
	public static function csv( array $header, array $rows ): string {
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory buffer for fputcsv().
		if ( false === $handle ) {
			return '';
		}
		foreach ( array_merge( [ $header ], $rows ) as $row ) {
			fputcsv( $handle, array_map( [ self::class, 'neutralize' ], $row ), ',', '"', '', "\r\n" );
		}
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in-memory buffer.
		return $csv;
	}

	public static function neutralize( mixed $cell ): string {
		$cell = (string) $cell;
		return ( '' !== $cell && in_array( $cell[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) ? "'" . $cell : $cell;
	}

	private static function date_or_empty( mixed $value ): string {
		$value = (string) $value;
		return ( '' === $value || str_starts_with( $value, '0000-00-00' ) ) ? '' : $value;
	}
}
