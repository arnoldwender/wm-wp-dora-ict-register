<?php
/**
 * WM Suite DORA ICT Register — Suite Hub Spoke Adapter
 *
 * Health, telemetry and actions for the WM Suite Hub, derived from the tables, the entity settings and a check of
 * the CIF engine and the LEI validator. Until 2026-09-15 health was a constant HEALTHY, telemetry reported an
 * "xbrl_csv_engine_status" of READY, the self test said "erfolgreich" whatever the engine returned, the settings
 * link opened a page that did not exist, and the SBOM properties declared DORA Art. 28 and BaFin conformance.
 *
 * @package WMCyber\DORA
 */

declare(strict_types=1);

namespace WMCyber\DORA\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface' ) ) {
	return;
}

use ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface;
use WMCyber\DORA\Core\Plugin;
use WMCyber\DORA\Register\Validator;

final class Dora_Spoke_Adapter implements WM_Plugin_Module_Interface {

	public function get_module_slug(): string {
		return 'wm-dora-ict-register';
	}

	public function get_name(): string {
		return 'WM DORA ICT Register';
	}

	public function get_version(): string {
		return defined( 'WM_DORA_VERSION' ) ? WM_DORA_VERSION : '1.2.2';
	}

	public function get_description(): string {
		return 'Supplier and contract list for ICT services with a CIF marking and a CSV export of the plugin tables.';
	}

	/** ERROR without tables, NEEDS_CONFIG without a valid entity LEI, DEGRADED with contracts whose supplier is missing. */
	public function get_health_status(): string {
		$repo = Plugin::instance()->get_repository();
		if ( ! $repo->tables_exist() ) {
			return 'ERROR';
		}
		if ( ! self::entity_lei_valid() ) {
			return 'NEEDS_CONFIG';
		}
		return $repo->counts()['orphan_contracts'] > 0 ? 'DEGRADED' : 'HEALTHY';
	}

	public function get_sbom_data(): array {
		return [
			'bom-ref'     => 'pkg:wordpress/wm-dora-ict-register@' . $this->get_version(),
			'type'        => 'application',
			'name'        => $this->get_name(),
			'version'     => $this->get_version(),
			'description' => $this->get_description(),
			'scope'       => 'required',
			// Hash of the loader file only, like the other spokes of the family; it does not cover includes/.
			'hashes'      => [
				[
					'alg'     => 'SHA-256',
					'content' => hash_file( 'sha256', WM_DORA_FILE ),
				],
			],
			// CycloneDX 1.6: "id" must be an SPDX identifier and cannot be combined with "name".
			'licenses'    => [
				[
					'license' => [
						'id' => 'GPL-2.0-or-later',
					],
				],
			],
			'properties'  => [
				[
					'name'  => 'wm:features:register',
					'value' => 'suppliers and contracts with validated LEI, country and spend',
				],
				[
					'name'  => 'wm:features:cif',
					'value' => 'manual CIF marking or a scoring heuristic of the plugin',
				],
				[
					'name'  => 'wm:features:export',
					'value' => 'CSV export of the plugin tables (not ITS (EU) 2024/2956 templates, not xBRL-CSV)',
				],
			],
		];
	}

	public function get_dependencies(): array {
		return [];
	}

	public function get_settings_url(): ?string {
		return admin_url( 'admin.php?page=wm-dora-register' );
	}

	public function get_telemetry_metrics(): array {
		$counts = Plugin::instance()->get_repository()->counts();
		return [
			'suppliers'             => $counts['suppliers'],
			'contracts'             => $counts['contracts'],
			'cif_contracts'         => $counts['cif_contracts'],
			'subcontractors'        => $counts['subcontractors'],
			'orphan_contracts'      => $counts['orphan_contracts'],
			'entity_lei_configured' => self::entity_lei_valid() ? 'YES' : 'NO',
		];
	}

	public function execute_action( string $action, array $params = [] ): array {
		switch ( $action ) {
			case 'self_test':
				$engine   = Plugin::instance()->get_cif_engine();
				$critical = $engine->evaluate_cif( [ 'handles_core_banking_data' => true, 'rto_hours' => 2, 'subcontractor_count' => 3, 'service_category' => 'cloud_hosting' ] );
				$standard = $engine->evaluate_cif( [ 'handles_core_banking_data' => false, 'rto_hours' => 48, 'subcontractor_count' => 0, 'service_category' => 'data_analytics' ] );
				$refused  = $engine->evaluate_cif( [ 'handles_core_banking_data' => 'n/a', 'rto_hours' => 2, 'subcontractor_count' => 0, 'service_category' => 'cloud_hosting' ] );
				$lei_ok   = null !== Validator::lei( 'TEST00FIXTURE0000149' ) && null === Validator::lei( 'TEST00FIXTURE0000148' );
				$tables   = Plugin::instance()->get_repository()->tables_exist();

				$checks = [
					'CIF-Beispiel kritisch (105 Punkte)'    => $critical['valid'] && $critical['is_cif'] && 105 === $critical['score'],
					'CIF-Beispiel Standard (0 Punkte)'      => $standard['valid'] && ! $standard['is_cif'] && 0 === $standard['score'],
					'ungültige Eingabe wird abgelehnt'      => ! $refused['valid'],
					'LEI-Prüfziffer'                        => $lei_ok,
					'Tabellen vorhanden'                    => $tables,
				];
				$failed = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
				return [
					'success' => [] === $failed,
					'message' => [] === $failed
						? 'Selbsttest bestanden: CIF-Heuristik, Eingabeprüfung, LEI-Prüfziffer und Tabellen.'
						: 'Selbsttest fehlgeschlagen: ' . implode( ', ', $failed ) . '.',
				];

			case 'cif_audit':
				$counts = Plugin::instance()->get_repository()->counts();
				return [
					'success' => true,
					'message' => sprintf( '%d Lieferanten, %d Verträge, davon %d als CIF markiert, %d ohne Lieferantendatensatz.', $counts['suppliers'], $counts['contracts'], $counts['cif_contracts'], $counts['orphan_contracts'] ),
				];

			default:
				return [
					'success' => false,
					'message' => sprintf( 'Unbekannte Spoke-Aktion "%s".', esc_html( $action ) ),
				];
		}
	}

	private static function entity_lei_valid(): bool {
		$settings = (array) get_option( 'wm_dora_settings', [] );
		return null !== Validator::lei( $settings['entity_lei'] ?? null );
	}
}
