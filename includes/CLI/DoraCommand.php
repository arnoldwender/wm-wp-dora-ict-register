<?php
declare(strict_types=1);

namespace WMCyber\DORA\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_CLI;
use WP_CLI_Command;
use WMCyber\DORA\Core\Plugin;
use WMCyber\DORA\Export\RegisterCsvExporter;

final class DoraCommand extends WP_CLI_Command {
	public static function register(): void {
		WP_CLI::add_command( 'dora', self::class );
	}

	/**
	 * List the stored contracts with their supplier (contracts without a supplier row show an empty LEI).
	 *
	 * ## EXAMPLES
	 *
	 *     wp dora list
	 */
	public function list( array $args, array $assoc_args ): void {
		$rows = Plugin::instance()->get_repository()->contracts();
		if ( [] === $rows ) {
			WP_CLI::log( 'No contracts stored.' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $rows, [ 'contract_ref', 'legal_name', 'lei_code', 'service_category', 'supports_cif', 'data_location_country', 'annual_spend' ] );
	}

	/**
	 * Write the plugin's tables as CSV files and a manifest. Not the templates of Implementing Regulation
	 * (EU) 2024/2956 and not an xBRL-CSV report package.
	 *
	 * ## OPTIONS
	 *
	 * --output-dir=<dir>
	 * : Directory to write the files to (created when missing).
	 *
	 * ## EXAMPLES
	 *
	 *     wp dora export-csv --output-dir=/tmp/dora-register
	 *
	 * @subcommand export-csv
	 */
	public function export_csv( array $args, array $assoc_args ): void {
		$output_dir = rtrim( (string) ( $assoc_args['output-dir'] ?? '' ), '/' );
		if ( '' === $output_dir ) {
			WP_CLI::error( '--output-dir is required.' );
		}
		if ( ! is_dir( $output_dir ) && ! wp_mkdir_p( $output_dir ) ) {
			WP_CLI::error( sprintf( 'Could not create the output directory: %s', $output_dir ) );
		}

		$files = Plugin::instance()->get_exporter()->export_package();
		foreach ( RegisterCsvExporter::FILES as $key => $name ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI export to a directory the operator names.
			if ( false === @file_put_contents( $output_dir . '/' . $name, $files[ $key ] ) ) {
				WP_CLI::error( sprintf( 'Could not write %s/%s.', $output_dir, $name ) );
			}
		}
		WP_CLI::success( sprintf( '%d files written to %s (plugin CSV, not an xBRL-CSV report package).', count( RegisterCsvExporter::FILES ), $output_dir ) );
	}

	/**
	 * Contracts, contracts marked CIF and annual spend per supplier LEI.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dora cif-audit
	 *
	 * @subcommand cif-audit
	 */
	public function cif_audit( array $args, array $assoc_args ): void {
		$matrix = Plugin::instance()->get_cif_engine()->calculate_concentration_risk();
		if ( [] === $matrix ) {
			WP_CLI::log( 'No suppliers stored.' );
			return;
		}

		$rows = [];
		foreach ( $matrix as $lei => $data ) {
			$rows[] = [
				'lei_code'        => $lei,
				'legal_name'      => $data['legal_name'],
				'total_contracts' => $data['total_contracts'],
				'cif_contracts'   => $data['cif_contracts'],
				'total_spend'     => number_format( $data['total_spend'], 2, '.', '' ) . ' EUR',
			];
		}
		WP_CLI\Utils\format_items( 'table', $rows, [ 'lei_code', 'legal_name', 'total_contracts', 'cif_contracts', 'total_spend' ] );
	}
}
