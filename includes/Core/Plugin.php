<?php
declare(strict_types=1);

namespace WMCyber\DORA\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\DORA\Classifier\CifEngine;
use WMCyber\DORA\Export\RegisterCsvExporter;
use WMCyber\DORA\Integrations\EcosystemBridge;
use WMCyber\DORA\Register\RegisterRepository;
use WMCyber\DORA\API\RestController;
use WMCyber\DORA\CLI\DoraCommand;
use WMCyber\DORA\Admin\AdminDashboard;

final class Plugin {
	private static ?Plugin $instance = null;
	private CifEngine $cif_engine;
	private RegisterCsvExporter $exporter;
	private RegisterRepository $repository;
	private EcosystemBridge $ecosystem_bridge;

	private function __construct() {
		$this->cif_engine       = new CifEngine();
		$this->exporter         = new RegisterCsvExporter();
		$this->repository       = new RegisterRepository( $this->cif_engine );
		$this->ecosystem_bridge = new EcosystemBridge( $this->repository );
	}

	public static function instance(): Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		\WMCyber\DORA\Database\Schema::maybe_upgrade();

		add_action( 'init', function (): void {
			load_plugin_textdomain(
				'wm-dora-ict-register',
				false,
				dirname( plugin_basename( WM_DORA_FILE ) ) . '/languages'
			);
		} );

		// Until 2026-09-15 every request also ran DirectoryProtector (created uploads/wm-dora/exports, which nothing
		// writes to) and RequestShield (logged IP and user agent whenever a request value contained PHP code
		// tokens, front-end comments included, and blocked nothing). Both and three classes without a caller were
		// removed; the plugin's routes are for administrators and validate their input.

		( new RestController( $this->cif_engine, $this->exporter, $this->repository ) )->register_routes();
		$this->ecosystem_bridge->register();

		if ( is_admin() ) {
			( new AdminDashboard( $this->exporter, $this->repository ) )->init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			DoraCommand::register();
		}

		do_action( 'wm_dora_ready', $this );
	}

	public function get_ecosystem_bridge(): EcosystemBridge {
		return $this->ecosystem_bridge;
	}

	public function get_cif_engine(): CifEngine {
		return $this->cif_engine;
	}

	public function get_exporter(): RegisterCsvExporter {
		return $this->exporter;
	}

	public function get_repository(): RegisterRepository {
		return $this->repository;
	}
}
