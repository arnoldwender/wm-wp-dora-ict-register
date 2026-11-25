<?php
/**
 * The rows of the CSV export, not only its headers.
 *
 * The export is the plugin's own CSV, not a supervisory filing. A file with correct headers and mangled rows is
 * still unusable, so the rows are parsed back and counted.
 *
 * @package WMCyber\DORA
 */

declare(strict_types=1);

namespace WMCyber\DORA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WMCyber\DORA\Export\RegisterCsvExporter;

final class RegisterCsvRenderingTest extends TestCase {

	private RegisterCsvExporter $exporter;

	protected function setUp(): void {
		parent::setUp();
		$this->exporter = new RegisterCsvExporter();
	}

	public function test_supplier_rows_are_rendered_not_just_headers(): void {
		$pkg  = $this->exporter->export_package();
		$rows = array_values( array_filter( explode( "\r\n", $pkg['suppliers'] ) ) );

		$this->assertGreaterThan( 1, count( $rows ), 'The supplier file contains only a header.' );
		$this->assertStringContainsString( 'TEST00FIXTURE0000149', $pkg['suppliers'] );
		$this->assertStringContainsString( 'Nordwind Cloud Services GmbH', $pkg['suppliers'] );
	}

	public function test_embedded_quotes_are_doubled_per_rfc_4180(): void {
		$pkg = $this->exporter->export_package();

		// The fixture supplier is literally: Data "Vault" Nordics AB
		$this->assertStringContainsString(
			'"Data ""Vault"" Nordics AB"',
			$pkg['suppliers'],
			'Embedded quotes must be doubled, or the CSV field count shifts and the filing is rejected.'
		);
	}

	public function test_boolean_columns_use_true_and_false(): void {
		$pkg = $this->exporter->export_package();

		$this->assertMatchesRegularExpression( '/,TRUE,/', $pkg['suppliers'] );
		$this->assertMatchesRegularExpression( '/,FALSE,/', $pkg['suppliers'] );
		$this->assertStringNotContainsString( ',1,', $pkg['suppliers'] );
	}

	public function test_every_row_has_the_same_field_count_as_its_header(): void {
		$pkg = $this->exporter->export_package();

		foreach ( array( 'suppliers', 'contracts', 'subcontractors' ) as $table ) {
			$rows = array_values( array_filter( explode( "\r\n", $pkg[ $table ] ) ) );
			$this->assertGreaterThan( 1, count( $rows ), "{$table} rendered no data rows." );

			$expected = count( str_getcsv( $rows[0], ',', '"', '\\' ) );
			foreach ( array_slice( $rows, 1 ) as $i => $row ) {
				$this->assertCount(
					$expected,
					str_getcsv( $row, ',', '"', '\\' ),
					sprintf( '%s row %d has a different field count than its header.', $table, $i + 1 )
				);
			}
		}
	}

	public function test_manifest_is_valid_json(): void {
		$pkg      = $this->exporter->export_package();
		$manifest = json_decode( $pkg['manifest'], true );

		$this->assertIsArray( $manifest, 'The manifest is not decodable JSON.' );
		$this->assertSame( 'TEST00FIXTURE0000343', $manifest['entity']['lei'] );
	}
}
