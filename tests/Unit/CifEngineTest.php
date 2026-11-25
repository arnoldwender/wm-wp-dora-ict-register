<?php
declare(strict_types=1);

namespace WMCyber\DORA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WMCyber\DORA\Classifier\CifEngine;

final class CifEngineTest extends TestCase {
	private CifEngine $cif_engine;

	protected function setUp(): void {
		parent::setUp();
		$this->cif_engine = new CifEngine();
	}

	public function test_evaluate_cif_critical_contract(): void {
		$contract = [
			'handles_core_banking_data' => true, // +40
			'rto_hours'                 => 2.0,  // +30
			'subcontractor_count'       => 3,    // +20
			'service_category'          => 'cloud_infrastructure', // +15
		];

		$res = $this->cif_engine->evaluate_cif( $contract );

		$this->assertTrue( $res['is_cif'] );
		$this->assertGreaterThanOrEqual( 50, $res['score'] );
		$this->assertTrue( $res['valid'] );
		$this->assertSame( 105, $res['score'] );
		$this->assertStringContainsString( 'Vorschlag: unterstützt eine kritische oder wichtige Funktion', $res['rationale'] );
		$this->assertStringContainsString( 'Heuristik des Plugins', $res['rationale'] );
	}

	public function test_evaluate_cif_standard_contract(): void {
		$contract = [
			'handles_core_banking_data' => false,
			'rto_hours'                 => 24.0,
			'subcontractor_count'       => 0,
			'service_category'          => 'data_analytics',
		];

		$res = $this->cif_engine->evaluate_cif( $contract );

		$this->assertFalse( $res['is_cif'] );
		$this->assertLessThan( 50, $res['score'] );
		$this->assertStringContainsString( 'Vorschlag: keine kritische oder wichtige Funktion', $res['rationale'] );
	}

	public function test_unreadable_input_gives_no_classification(): void {
		$res = $this->cif_engine->evaluate_cif( [ 'handles_core_banking_data' => 'n/a', 'rto_hours' => 2, 'subcontractor_count' => 0, 'service_category' => 'cloud_hosting' ] );

		$this->assertFalse( $res['valid'] );
		$this->assertSame( [ 'handles_core_banking_data' ], $res['errors'] );
	}
}
