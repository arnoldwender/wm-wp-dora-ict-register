<?php
declare(strict_types=1);

namespace WMCyber\DORA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WMCyber\DORA\Database\Schema;

final class SchemaTest extends TestCase {
	public function test_activate_site_returns_safely_when_upgrade_php_and_dbdelta_are_absent(): void {
		Schema::activate_site();
		$this->assertFalse( function_exists( 'dbDelta' ) );
	}
}
