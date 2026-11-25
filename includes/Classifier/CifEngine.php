<?php
declare(strict_types=1);

namespace WMCyber\DORA\Classifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\DORA\Register\Validator;

/**
 * A scoring aid for "supports a critical or important function". The weights and the threshold are this plugin's
 * own heuristic: neither Regulation (EU) 2022/2554 nor its technical standards define a score. Art. 28(4)(a)
 * leaves the assessment to the financial entity; the result is a suggestion stored with its reasons.
 *
 * Until 2026-09-15 the class said its criteria were "mapped from EBA/ESMA RTS on DORA Art. 28(3)", read loose
 * input ("no" and "n/a" scored, an RTO of 0 hours scored nothing) and matched a cloud category the form never
 * sent.
 */
final class CifEngine {
	public const THRESHOLD = 50;

	/**
	 * @param array<string, mixed> $contract_data handles_core_banking_data (bool), rto_hours (>= 0),
	 *                                            subcontractor_count (>= 0), service_category.
	 * @return array{valid: bool, errors: list<string>, is_cif: bool, score: int, rationale: string}
	 */
	public function evaluate_cif( array $contract_data ): array {
		$banking  = Validator::boolean( $contract_data['handles_core_banking_data'] ?? null );
		$rto      = Validator::non_negative_number( $contract_data['rto_hours'] ?? null );
		$subs     = Validator::non_negative_number( $contract_data['subcontractor_count'] ?? null );
		$category = Validator::service_category( $contract_data['service_category'] ?? null );

		$errors = [];
		foreach ( [ 'handles_core_banking_data' => $banking, 'rto_hours' => $rto, 'subcontractor_count' => $subs, 'service_category' => $category ] as $field => $value ) {
			if ( null === $value ) {
				$errors[] = $field;
			}
		}
		if ( [] !== $errors ) {
			// Missing or unreadable input gives no classification, never "not critical".
			return [ 'valid' => false, 'errors' => $errors, 'is_cif' => false, 'score' => 0, 'rationale' => '' ];
		}

		$score   = 0;
		$reasons = [];
		if ( true === $banking ) {
			$score    += 40;
			$reasons[] = 'Verarbeitet Kernbanken- oder Zahlungsdaten (+40).';
		}
		if ( $rto <= 4.0 ) {
			$score    += 30;
			$reasons[] = 'Wiederanlaufzeit (RTO) höchstens 4 Stunden (+30).';
		}
		if ( $subs > 2 ) {
			$score    += 20;
			$reasons[] = 'Mehr als zwei Unterauftragnehmer (+20).';
		}
		if ( 'cloud_hosting' === $category ) {
			$score    += 15;
			$reasons[] = 'Cloud- oder Hosting-Dienst (+15).';
		}

		$is_cif    = $score >= self::THRESHOLD;
		$rationale = sprintf(
			'%s Punktzahl %d nach der Heuristik des Plugins (Schwelle %d; keine Vorgabe aus DORA oder den technischen Standards). %s',
			$is_cif ? 'Vorschlag: unterstützt eine kritische oder wichtige Funktion.' : 'Vorschlag: keine kritische oder wichtige Funktion.',
			$score,
			self::THRESHOLD,
			implode( ' ', $reasons )
		);

		return [ 'valid' => true, 'errors' => [], 'is_cif' => $is_cif, 'score' => $score, 'rationale' => trim( $rationale ) ];
	}

	/**
	 * Contracts, contracts marked CIF and spend per supplier LEI.
	 *
	 * @return array<string, array{legal_name: string, total_contracts: int, cif_contracts: int, total_spend: float}>
	 */
	public function calculate_concentration_risk(): array {
		global $wpdb;
		$table_suppliers = $wpdb->prefix . 'wm_dora_suppliers';
		$table_contracts = $wpdb->prefix . 'wm_dora_contracts';

		if ( ! self::table_exists( $table_suppliers ) || ! self::table_exists( $table_contracts ) ) {
			return [];
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.lei_code, s.legal_name, COUNT(c.id) AS total_contracts, SUM(CASE WHEN c.supports_cif = 1 THEN 1 ELSE 0 END) AS cif_contracts, COALESCE(SUM(c.annual_spend), 0) AS total_spend FROM %i s LEFT JOIN %i c ON s.id = c.supplier_id GROUP BY s.lei_code, s.legal_name',
				$table_suppliers,
				$table_contracts
			),
			ARRAY_A
		);

		$summary = [];
		foreach ( (array) $results as $row ) {
			$summary[ (string) $row['lei_code'] ] = [
				'legal_name'      => (string) $row['legal_name'],
				'total_contracts' => (int) $row['total_contracts'],
				'cif_contracts'   => (int) $row['cif_contracts'],
				'total_spend'     => (float) $row['total_spend'],
			];
		}
		return $summary;
	}

	public static function table_exists( string $table ): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}
}
