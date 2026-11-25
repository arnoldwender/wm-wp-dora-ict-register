<?php
declare(strict_types=1);

namespace WMCyber\DORA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WMCyber\DORA\Export\RegisterCsvExporter;

final class RegisterCsvExporterTest extends TestCase {
	public function test_export_package_structure(): void {
		$pkg = ( new RegisterCsvExporter() )->export_package();

		$this->assertSame( [ 'suppliers', 'contracts', 'subcontractors', 'manifest' ], array_keys( $pkg ) );
		$this->assertStringStartsWith( 'LEI,LegalName,CountryCode,IsParentUndertaking,ISO27001Certified,ISO27001ValidUntil', $pkg['suppliers'] );
		$this->assertStringStartsWith( 'ContractRef,SupplierLEI,ServiceCategory,SupportsCIF,DataLocationCountry,SubcontractingPermitted,AnnualSpendEUR', $pkg['contracts'] );
		$this->assertStringStartsWith( 'ContractRef,SubcontractorLEI,SubcontractorName', $pkg['subcontractors'] );
	}
}
