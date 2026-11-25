<?php
declare(strict_types=1);

namespace WMCyber\DORA\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\DORA\Export\RegisterCsvExporter;
use WMCyber\DORA\Register\RegisterRepository;
use WMCyber\DORA\Register\Validator;

/**
 * Admin screen, dashboard widget, form actions and CSV downloads.
 *
 * Until 2026-09-15 the screen was Spanish and showed "Validación xBRL-CSV: CONFORME", "RESILIENCIA DORA ACTIVA" and a
 * widget banner "IKT-Register aktiv" regardless of data; counted contracts as "suppliers"; offered a download link to a
 * REST route that did not exist; pre-filled a made-up 22-character entity LEI; recorded every supplier as ISO 27001
 * certified; and loaded "example suppliers" named Hetzner Online GmbH (with the LEI of Ubisecure Oy, checked at GLEIF)
 * and Stripe Technology Europe Ltd (with an LEI that fails the check digits), both marked certified and critical.
 */
final class AdminDashboard {
	private const PAGE = 'wm-dora-register';

	public function __construct(
		private readonly RegisterCsvExporter $exporter,
		private readonly RegisterRepository $repository
	) {}

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'handle_form_actions' ] );
		add_action( 'admin_post_wm_dora_export', [ $this, 'handle_export_download' ] );
		add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
	}

	// -------------------------------------------------------------------------------------------------------------
	// Widget
	// -------------------------------------------------------------------------------------------------------------

	public function register_dashboard_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'wm_dora_ict_widget', __( 'DORA: IKT-Dienstleister und Verträge', 'wm-dora-ict-register' ), [ $this, 'render_dashboard_widget' ] );
	}

	public function render_dashboard_widget(): void {
		$counts = $this->repository->counts();
		$issue  = $this->setup_issue( $counts );
		?>
		<div class="wm-dash-widget-box" data-wm-theme="<?php echo esc_attr( function_exists( 'wm_get_suite_theme' ) ? wm_get_suite_theme() : 'cyber-events' ); ?>">
			<div class="wm-dash-banner <?php echo '' === $issue ? 'is-valid' : 'is-warning'; ?>">
				<span class="wm-dash-banner-icon dashicons <?php echo '' === $issue ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
				<div>
					<div class="wm-dash-banner-title"><?php echo '' === $issue ? esc_html__( 'Register eingerichtet', 'wm-dora-ict-register' ) : esc_html( $issue ); ?></div>
				</div>
			</div>
			<div class="wm-dash-kpi-grid">
				<div class="wm-dash-kpi-card">
					<div class="wm-dash-kpi-val"><?php echo esc_html( (string) $counts['suppliers'] ); ?></div>
					<div class="wm-dash-kpi-lbl"><?php esc_html_e( 'Dienstleister', 'wm-dora-ict-register' ); ?></div>
				</div>
				<div class="wm-dash-kpi-card">
					<div class="wm-dash-kpi-val"><?php echo esc_html( (string) $counts['contracts'] ); ?></div>
					<div class="wm-dash-kpi-lbl"><?php esc_html_e( 'Verträge', 'wm-dora-ict-register' ); ?></div>
				</div>
				<div class="wm-dash-kpi-card">
					<div class="wm-dash-kpi-val"><?php echo esc_html( (string) $counts['cif_contracts'] ); ?></div>
					<div class="wm-dash-kpi-lbl"><?php esc_html_e( 'als CIF markiert', 'wm-dora-ict-register' ); ?></div>
				</div>
			</div>
			<div class="wm-dash-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>" class="wm-dash-btn"><?php esc_html_e( 'Register öffnen', 'wm-dora-ict-register' ); ?></a>
			</div>
		</div>
		<?php
	}

	/** The first thing that is not set up, or ''. */
	private function setup_issue( array $counts ): string {
		if ( ! $this->repository->tables_exist() ) {
			return __( 'Tabellen fehlen: Plugin deaktivieren und wieder aktivieren', 'wm-dora-ict-register' );
		}
		if ( null === Validator::lei( $this->settings()['entity_lei'] ?? null ) ) {
			return __( 'LEI des eigenen Unternehmens fehlt', 'wm-dora-ict-register' );
		}
		if ( $counts['orphan_contracts'] > 0 ) {
			/* translators: %d: number of contracts whose supplier row is missing */
			return sprintf( __( '%d Verträge ohne Dienstleister-Datensatz', 'wm-dora-ict-register' ), $counts['orphan_contracts'] );
		}
		return '';
	}

	// -------------------------------------------------------------------------------------------------------------
	// Menu and assets
	// -------------------------------------------------------------------------------------------------------------

	/**
	 * Family menu icon: a monochrome hub-and-spoke mark, filled shapes only so the WordPress
	 * svg-painter can recolour it with the admin colour scheme.
	 */
	public static function family_menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><circle cx="10" cy="10" r="2.6" fill="black"/><circle cx="10" cy="3" r="1.8" fill="black"/><circle cx="16.1" cy="13.5" r="1.8" fill="black"/><circle cx="3.9" cy="13.5" r="1.8" fill="black"/><rect x="9.3" y="4.5" width="1.4" height="3.4" fill="black"/><rect x="9.3" y="12.1" width="1.4" height="3.4" fill="black" transform="rotate(-60 10 10)"/><rect x="9.3" y="12.1" width="1.4" height="3.4" fill="black" transform="rotate(60 10 10)"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public function register_admin_menu(): void {
		add_menu_page(
			__( 'DORA Register', 'wm-dora-ict-register' ),
			__( 'DORA IKT-Register', 'wm-dora-ict-register' ),
			'manage_options',
			self::PAGE,
			[ $this, 'render_dashboard_page' ],
			self::family_menu_icon(),
			29
		);
	}

	public function enqueue_admin_assets( string $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) );
		// index.php: the dashboard widget uses the family classes too (until 2026-09-15 it rendered unstyled there).
		if ( 'index.php' !== $hook && ! str_contains( $hook, 'wm-dora' ) && ! str_contains( $page, 'wm-dora' ) ) {
			return;
		}
		$root = dirname( __DIR__, 2 ) . '/assets/css/';
		wp_enqueue_style( 'wm-admin-tokens', WM_DORA_URL . 'assets/css/wm-admin-tokens.css', [], (string) ( @filemtime( $root . 'wm-admin-tokens.css' ) ?: WM_DORA_VERSION ) );
		wp_enqueue_style( 'wm-admin-ui', WM_DORA_URL . 'assets/css/wm-admin-ui.css', [ 'wm-admin-tokens' ], (string) ( @filemtime( $root . 'wm-admin-ui.css' ) ?: WM_DORA_VERSION ) );
	}

	// -------------------------------------------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------------------------------------------

	public function register_settings(): void {
		register_setting(
			'wm_dora_settings_group',
			'wm_dora_delete_data_on_uninstall',
			[
				'type'              => 'boolean',
				'sanitize_callback' => [ self::class, 'sanitize_uninstall_flag' ],
				'default'           => 0,
			]
		);
	}

	public static function sanitize_uninstall_flag( mixed $value ): int {
		return ! empty( $value ) ? 1 : 0;
	}

	/** @return array<string, mixed> */
	private function settings(): array {
		return (array) get_option( 'wm_dora_settings', [] );
	}

	/**
	 * Writes the fields of the form and keeps every other key of the option (until 2026-09-15 a save reset
	 * bafin_id to empty and cif_spend_threshold to 50000). The entity LEI may stay empty; a value must be valid.
	 *
	 * @param array<string, mixed> $post Unslashed $_POST.
	 * @return list<string> Error codes; empty when saved.
	 */
	public function save_settings( array $post ): array {
		$lei_raw = trim( (string) ( $post['entity_lei'] ?? '' ) );
		$lei     = '' === $lei_raw ? '' : Validator::lei( $lei_raw );
		if ( null === $lei ) {
			return [ 'entity_lei' ];
		}
		$settings                        = $this->settings();
		$settings['entity_lei']          = $lei;
		$settings['entity_name']         = sanitize_text_field( (string) ( $post['entity_name'] ?? '' ) );
		$settings['competent_authority'] = sanitize_text_field( (string) ( $post['competent_authority'] ?? '' ) );
		update_option( 'wm_dora_settings', $settings, false );
		update_option( 'wm_dora_delete_data_on_uninstall', self::sanitize_uninstall_flag( $post['delete_data_on_uninstall'] ?? 0 ), false );
		return [];
	}

	// -------------------------------------------------------------------------------------------------------------
	// Form actions
	// -------------------------------------------------------------------------------------------------------------

	public function handle_form_actions(): void {
		if ( ! isset( $_POST['wm_dora_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'wm_dora_admin_action', 'wm_dora_nonce' );
		$action = sanitize_key( wp_unslash( $_POST['wm_dora_action'] ) );
		$post   = wp_unslash( $_POST );

		if ( 'seed_demo_data' === $action ) {
			$errors = $this->seed_demo_data();
			$this->redirect( 'overview', [] === $errors ? [ 'seeded' => '1' ] : [ 'errors' => implode( ',', $errors ) ] );
		}
		if ( 'add_supplier_contract' === $action ) {
			$errors = $this->add_supplier_contract( $post );
			$this->redirect( [] === $errors ? 'overview' : 'add', [] === $errors ? [ 'added' => '1' ] : [ 'errors' => implode( ',', $errors ) ] );
		}
		if ( 'save_settings' === $action ) {
			$errors = $this->save_settings( $post );
			$this->redirect( 'settings', [] === $errors ? [ 'saved' => '1' ] : [ 'errors' => implode( ',', $errors ) ] );
		}
	}

	/**
	 * Supplier (reused when its LEI exists) and contract from the form. The CIF marking is the entity's choice.
	 *
	 * @param array<string, mixed> $post
	 * @return list<string> Error codes; empty when both rows are stored.
	 */
	public function add_supplier_contract( array $post ): array {
		$lei = Validator::lei( $post['lei_code'] ?? null );
		if ( null === $lei ) {
			return [ 'lei_code' ];
		}

		$supplier_id = $this->repository->supplier_id_by_lei( $lei );
		if ( 0 === $supplier_id ) {
			$supplier = $this->repository->add_supplier( [
				'legal_name'            => $post['supplier_name'] ?? null,
				'lei_code'              => $lei,
				'country_code'          => $post['country_code'] ?? null,
				'is_parent_undertaking' => $post['is_parent_undertaking'] ?? null,
				'iso27001_certified'    => $post['iso27001_certified'] ?? null,
				'iso27001_valid_until'  => $post['iso27001_valid_until'] ?? '',
			] );
			if ( ! $supplier['ok'] ) {
				return $supplier['errors'];
			}
			$supplier_id = $supplier['id'];
		}

		$ref = trim( (string) ( $post['contract_ref'] ?? '' ) );
		if ( '' === $ref ) {
			do {
				$ref = 'ICT-' . gmdate( 'Y' ) . '-' . strtoupper( wp_generate_password( 6, false, false ) );
			} while ( $this->repository->contract_ref_exists( $ref ) );
		}

		$contract = $this->repository->add_contract( [
			'contract_ref'             => $ref,
			'supplier_id'              => $supplier_id,
			'service_category'         => $post['service_category'] ?? null,
			'data_location_country'    => $post['data_location_country'] ?? null,
			'subcontracting_permitted' => $post['subcontracting_permitted'] ?? null,
			'annual_spend'             => $post['annual_spend'] ?? null,
			'supports_cif'             => $post['supports_cif'] ?? null,
		] );
		return $contract['ok'] ? [] : $contract['errors'];
	}

	/**
	 * Two fictional suppliers, named "Beispiel" and with LEIs that pass the check digits and are not registered at
	 * GLEIF (checked 2026-09-15). They go into the same tables and the export; delete them before real use.
	 *
	 * @return list<string>
	 */
	public function seed_demo_data(): array {
		$rows = [
			[ 'Beispiel Musterhosting GmbH', 'DEMO00BEISPIEL000113', 'DE', 'BEISPIEL-2026-001', 'cloud_hosting', 'DE', false, 12000 ],
			[ 'Beispiel Musterzahlungen AG', 'DEMO00BEISPIEL000210', 'AT', 'BEISPIEL-2026-002', 'payment_processing', 'AT', true, 36000 ],
		];
		$errors = [];
		foreach ( $rows as [ $name, $lei, $country, $ref, $category, $location, $cif, $spend ] ) {
			if ( $this->repository->contract_ref_exists( $ref ) ) {
				continue;
			}
			$result = $this->add_supplier_contract( [
				'supplier_name'            => $name,
				'lei_code'                 => $lei,
				'country_code'             => $country,
				'is_parent_undertaking'    => '0',
				'iso27001_certified'       => '0',
				'contract_ref'             => $ref,
				'service_category'         => $category,
				'data_location_country'    => $location,
				'subcontracting_permitted' => '0',
				'annual_spend'             => (string) $spend,
				'supports_cif'             => $cif ? '1' : '0',
			] );
			$errors = array_merge( $errors, $result );
		}
		return array_values( array_unique( $errors ) );
	}

	/** @param array<string, string> $args */
	private function redirect( string $tab, array $args ): void {
		wp_safe_redirect( add_query_arg( array_merge( [ 'page' => self::PAGE, 'tab' => $tab ], $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// -------------------------------------------------------------------------------------------------------------
	// CSV download
	// -------------------------------------------------------------------------------------------------------------

	/** @return array{name: string, type: string, body: string}|null */
	public function download_payload( string $file ): ?array {
		if ( ! isset( RegisterCsvExporter::FILES[ $file ] ) ) {
			return null;
		}
		$package = $this->exporter->export_package();
		return [
			'name' => RegisterCsvExporter::FILES[ $file ],
			'type' => 'manifest' === $file ? 'application/json; charset=utf-8' : 'text/csv; charset=utf-8',
			'body' => $package[ $file ],
		];
	}

	public function handle_export_download(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'wm-dora-ict-register' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'wm_dora_export' );
		$payload = $this->download_payload( sanitize_key( wp_unslash( $_GET['file'] ?? '' ) ) );
		if ( null === $payload ) {
			wp_die( esc_html__( 'Unbekannte Datei.', 'wm-dora-ict-register' ), '', [ 'response' => 400 ] );
		}
		nocache_headers();
		header( 'Content-Type: ' . $payload['type'] );
		header( 'Content-Disposition: attachment; filename="' . $payload['name'] . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- file download; the CSV cells are neutralised in RegisterCsvExporter::csv().
		echo (string) apply_filters( 'wm_dora_export_download_body', $payload['body'], $payload['name'] );
		exit;
	}

	// -------------------------------------------------------------------------------------------------------------
	// Page
	// -------------------------------------------------------------------------------------------------------------

	public static function error_label( string $code ): string {
		$labels = [
			'lei_code'                 => __( 'LEI des Dienstleisters (20 Zeichen mit gültigen Prüfziffern)', 'wm-dora-ict-register' ),
			'legal_name'               => __( 'Name des Dienstleisters', 'wm-dora-ict-register' ),
			'country_code'             => __( 'Land des Dienstleisters (zwei Buchstaben)', 'wm-dora-ict-register' ),
			'is_parent_undertaking'    => __( 'Mutterunternehmen ja/nein', 'wm-dora-ict-register' ),
			'iso27001_certified'       => __( 'ISO 27001 ja/nein', 'wm-dora-ict-register' ),
			'iso27001_valid_until'     => __( 'ISO 27001 gültig bis', 'wm-dora-ict-register' ),
			'contract_ref'             => __( 'Vertragsreferenz', 'wm-dora-ict-register' ),
			'contract_ref_exists'      => __( 'Vertragsreferenz schon vergeben', 'wm-dora-ict-register' ),
			'service_category'         => __( 'Art des Dienstes', 'wm-dora-ict-register' ),
			'data_location_country'    => __( 'Ort der Datenverarbeitung (zwei Buchstaben)', 'wm-dora-ict-register' ),
			'subcontracting_permitted' => __( 'Unterauftragsvergabe ja/nein', 'wm-dora-ict-register' ),
			'annual_spend'             => __( 'Jährliche Kosten (nicht negativ)', 'wm-dora-ict-register' ),
			'supports_cif'             => __( 'Kritische oder wichtige Funktion ja/nein', 'wm-dora-ict-register' ),
			'entity_lei'               => __( 'LEI des eigenen Unternehmens', 'wm-dora-ict-register' ),
			'tables_missing'           => __( 'Tabellen fehlen', 'wm-dora-ict-register' ),
			'insert_failed'            => __( 'Speichern in der Datenbank fehlgeschlagen', 'wm-dora-ict-register' ),
		];
		return $labels[ $code ] ?? $code;
	}

	public function render_dashboard_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only tab routing and result notices.
		$tab    = sanitize_key( wp_unslash( $_GET['tab'] ?? 'overview' ) );
		$errors = isset( $_GET['errors'] ) ? array_filter( explode( ',', sanitize_text_field( wp_unslash( $_GET['errors'] ) ) ) ) : [];
		$notice = isset( $_GET['added'] ) ? 'added' : ( isset( $_GET['seeded'] ) ? 'seeded' : ( isset( $_GET['saved'] ) ? 'saved' : '' ) );
		// phpcs:enable

		$tabs = [
			'overview' => [ __( 'Register', 'wm-dora-ict-register' ), 'dashicons-list-view' ],
			'add'      => [ __( 'Eintragen', 'wm-dora-ict-register' ), 'dashicons-plus-alt' ],
			'export'   => [ __( 'CSV-Export', 'wm-dora-ict-register' ), 'dashicons-download' ],
			'settings' => [ __( 'Unternehmen', 'wm-dora-ict-register' ), 'dashicons-admin-settings' ],
			'glossary' => [ __( 'Begriffe', 'wm-dora-ict-register' ), 'dashicons-book' ],
		];
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'overview';
		}
		$counts = $this->repository->counts();
		$issue  = $this->setup_issue( $counts );
		?>
		<div class="wrap wm-admin wm-dora-admin-wrap">
			<header class="wm-admin-header">
				<div class="wm-header-brand">
					<div class="wm-header-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
					</div>
					<div>
						<h1><?php esc_html_e( 'DORA: IKT-Dienstleister und Verträge', 'wm-dora-ict-register' ); ?></h1>
						<p class="wm-subtitle"><?php esc_html_e( 'Dienstleister mit LEI · Verträge mit CIF-Markierung · CSV-Export der Plugin-Tabellen', 'wm-dora-ict-register' ); ?></p>
					</div>
				</div>
				<div class="wm-header-badges">
					<span class="wm-badge-family" title="<?php esc_attr_e( 'Part of the WM Suite plugin family', 'wm-dora-ict-register' ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="4" r="2"/><circle cx="19" cy="16" r="2"/><circle cx="5" cy="16" r="2"/><path d="M12 7v2M13.9 13.2l3.4 2M10.1 13.2l-3.4 2"/></svg>
						<?php esc_html_e( 'WM Suite', 'wm-dora-ict-register' ); ?>
						<span class="wm-badge-family-state"><?php echo defined( 'WM_SUITE_HUB_VERSION' ) ? esc_html__( 'Hub', 'wm-dora-ict-register' ) : esc_html__( 'Standalone', 'wm-dora-ict-register' ); ?></span>
					</span>
					<span class="wm-badge <?php echo '' === $issue ? 'wm-badge-green' : 'wm-badge-amber'; ?>"><?php echo '' === $issue ? esc_html__( 'Eingerichtet', 'wm-dora-ict-register' ) : esc_html( $issue ); ?></span>
				</div>
			</header>

			<div class="wm-quick-guide">
				<div class="wm-quick-guide-title"><?php esc_html_e( 'Was dieses Plugin tut', 'wm-dora-ict-register' ); ?></div>
				<p class="wm-quick-guide-desc"><?php esc_html_e( 'Es führt eine Liste von IKT-Dienstleistern und Verträgen mit geprüfter LEI, Land, Kosten und der Markierung, ob ein Vertrag eine kritische oder wichtige Funktion unterstützt, und exportiert die Tabellen als CSV. Es erstellt nicht das Informationsregister in den Vorlagen der Durchführungsverordnung (EU) 2024/2956, keine xBRL-CSV-Meldung und keine Einstufung, die das Unternehmen nach Artikel 28 Absatz 4 selbst treffen muss.', 'wm-dora-ict-register' ); ?></p>
				<div class="wm-quick-guide-actions">
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'add' ], admin_url( 'admin.php' ) ) ); ?>" class="wm-preset-pill-btn"><?php esc_html_e( 'Dienstleister eintragen', 'wm-dora-ict-register' ); ?></a>
					<form method="post">
						<?php wp_nonce_field( 'wm_dora_admin_action', 'wm_dora_nonce' ); ?>
						<input type="hidden" name="wm_dora_action" value="seed_demo_data" />
						<button type="submit" class="wm-preset-pill-btn"><?php esc_html_e( 'Beispieldaten laden (zwei fiktive Dienstleister)', 'wm-dora-ict-register' ); ?></button>
					</form>
				</div>
			</div>

			<?php if ( 'added' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Dienstleister und Vertrag gespeichert.', 'wm-dora-ict-register' ); ?></p></div>
			<?php elseif ( 'seeded' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Beispieldaten geladen. Sie stehen im Register und im Export; vor echter Nutzung löschen.', 'wm-dora-ict-register' ); ?></p></div>
			<?php elseif ( 'saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Unternehmensangaben gespeichert.', 'wm-dora-ict-register' ); ?></p></div>
			<?php endif; ?>
			<?php if ( [] !== $errors ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Nichts gespeichert. Bitte prüfen:', 'wm-dora-ict-register' ); ?> <?php echo esc_html( implode( '; ', array_map( [ self::class, 'error_label' ], $errors ) ) ); ?></p></div>
			<?php endif; ?>

			<nav class="wm-nav-tabs" aria-label="<?php esc_attr_e( 'Bereiche', 'wm-dora-ict-register' ); ?>">
				<?php foreach ( $tabs as $slug => [ $label, $icon ] ) : ?>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE, 'tab' => $slug ], admin_url( 'admin.php' ) ) ); ?>" class="wm-tab-link <?php echo $tab === $slug ? 'active' : ''; ?>"<?php echo $tab === $slug ? ' aria-current="page"' : ''; ?>>
						<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span> <?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			match ( $tab ) {
				'add'      => $this->render_add(),
				'export'   => $this->render_export( $counts ),
				'settings' => $this->render_settings(),
				'glossary' => $this->render_glossary(),
				default    => $this->render_overview( $counts ),
			};
			?>
		</div>
		<?php
	}

	private function render_overview( array $counts ): void {
		$contracts = $this->repository->contracts();
		$settings  = $this->settings();
		?>
		<div class="wm-grid-4">
			<div class="wm-stat-card">
				<span class="wm-stat-label"><?php esc_html_e( 'Dienstleister', 'wm-dora-ict-register' ); ?></span>
				<span class="wm-stat-value"><?php echo esc_html( (string) $counts['suppliers'] ); ?></span>
			</div>
			<div class="wm-stat-card">
				<span class="wm-stat-label"><?php esc_html_e( 'Verträge', 'wm-dora-ict-register' ); ?></span>
				<span class="wm-stat-value"><?php echo esc_html( (string) $counts['contracts'] ); ?></span>
			</div>
			<div class="wm-stat-card">
				<span class="wm-stat-label"><?php esc_html_e( 'als CIF markiert', 'wm-dora-ict-register' ); ?></span>
				<span class="wm-stat-value"><?php echo esc_html( (string) $counts['cif_contracts'] ); ?></span>
			</div>
			<div class="wm-stat-card">
				<span class="wm-stat-label"><?php esc_html_e( 'Zuständige Behörde', 'wm-dora-ict-register' ); ?></span>
				<span class="wm-stat-delta"><?php echo '' !== (string) ( $settings['competent_authority'] ?? '' ) ? esc_html( (string) $settings['competent_authority'] ) : esc_html__( 'nicht angegeben', 'wm-dora-ict-register' ); ?></span>
			</div>
		</div>

		<div class="wm-card">
			<h2><?php esc_html_e( 'Verträge', 'wm-dora-ict-register' ); ?></h2>
			<?php if ( [] === $contracts ) : ?>
				<p class="wm-empty-state"><?php esc_html_e( 'Noch keine Verträge eingetragen.', 'wm-dora-ict-register' ); ?></p>
			<?php else : ?>
				<div class="wm-table-container">
					<table class="wm-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Referenz', 'wm-dora-ict-register' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Dienstleister', 'wm-dora-ict-register' ); ?></th>
								<th scope="col"><?php esc_html_e( 'LEI', 'wm-dora-ict-register' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Art des Dienstes', 'wm-dora-ict-register' ); ?></th>
								<th scope="col"><?php esc_html_e( 'CIF', 'wm-dora-ict-register' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Kosten pro Jahr (EUR)', 'wm-dora-ict-register' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $contracts as $c ) : ?>
								<tr>
									<td><code><?php echo esc_html( (string) $c['contract_ref'] ); ?></code></td>
									<td><?php echo null === $c['legal_name'] ? esc_html__( 'Datensatz fehlt', 'wm-dora-ict-register' ) : esc_html( $c['legal_name'] . ' (' . $c['country_code'] . ')' ); ?></td>
									<td><code><?php echo esc_html( (string) ( $c['lei_code'] ?? '' ) ); ?></code></td>
									<td><?php echo esc_html( (string) $c['service_category'] ); ?></td>
									<td><span class="wm-badge <?php echo ! empty( $c['supports_cif'] ) ? 'wm-badge-amber' : 'wm-badge-cyan'; ?>"><?php echo ! empty( $c['supports_cif'] ) ? esc_html__( 'ja', 'wm-dora-ict-register' ) : esc_html__( 'nein', 'wm-dora-ict-register' ); ?></span></td>
									<td><?php echo esc_html( number_format_i18n( (float) $c['annual_spend'], 2 ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function yes_no( string $name, string $legend ): void {
		?>
		<fieldset class="wm-field-card">
			<legend class="wm-field-title"><?php echo esc_html( $legend ); ?></legend>
			<label><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="1" required /> <?php esc_html_e( 'ja', 'wm-dora-ict-register' ); ?></label>
			<label><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="0" /> <?php esc_html_e( 'nein', 'wm-dora-ict-register' ); ?></label>
		</fieldset>
		<?php
	}

	private function render_add(): void {
		$categories = [
			'cloud_hosting'         => __( 'Cloud und Hosting (IaaS/PaaS)', 'wm-dora-ict-register' ),
			'payment_processing'    => __( 'Zahlungsabwicklung', 'wm-dora-ict-register' ),
			'software_as_a_service' => __( 'Software als Dienst (SaaS)', 'wm-dora-ict-register' ),
			'cybersecurity_service' => __( 'Sicherheitsdienst / SOC', 'wm-dora-ict-register' ),
			'data_analytics'        => __( 'Datenanalyse', 'wm-dora-ict-register' ),
			'other_ict_service'     => __( 'Anderer IKT-Dienst', 'wm-dora-ict-register' ),
		];
		?>
		<div class="wm-card">
			<h2><?php esc_html_e( 'Dienstleister und Vertrag eintragen', 'wm-dora-ict-register' ); ?></h2>
			<p class="wm-field-desc"><?php esc_html_e( 'Ist die LEI schon eingetragen, wird der vorhandene Dienstleister verwendet und nur der Vertrag angelegt.', 'wm-dora-ict-register' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'wm_dora_admin_action', 'wm_dora_nonce' ); ?>
				<input type="hidden" name="wm_dora_action" value="add_supplier_contract" />

				<div class="wm-field-card">
					<label class="wm-field-title" for="wm-dora-name"><?php esc_html_e( 'Name des Dienstleisters', 'wm-dora-ict-register' ); ?></label>
					<input type="text" id="wm-dora-name" name="supplier_name" class="wm-input" maxlength="255" />
				</div>
				<div class="wm-field-card">
					<label class="wm-field-title" for="wm-dora-lei"><?php esc_html_e( 'LEI des Dienstleisters', 'wm-dora-ict-register' ); ?></label>
					<p class="wm-field-desc" id="wm-dora-lei-desc"><?php esc_html_e( '20 Zeichen nach ISO 17442; die letzten zwei Ziffern werden geprüft. Nachschlagen bei gleif.org.', 'wm-dora-ict-register' ); ?></p>
					<input type="text" id="wm-dora-lei" name="lei_code" class="wm-input" maxlength="20" pattern="[A-Za-z0-9]{18}[0-9]{2}" aria-describedby="wm-dora-lei-desc" required />
				</div>
				<div class="wm-field-card">
					<label class="wm-field-title" for="wm-dora-country"><?php esc_html_e( 'Land des Dienstleisters (ISO 3166-1, zwei Buchstaben)', 'wm-dora-ict-register' ); ?></label>
					<input type="text" id="wm-dora-country" name="country_code" class="wm-input" maxlength="2" pattern="[A-Za-z]{2}" />
				</div>
				<?php self::yes_no( 'is_parent_undertaking', __( 'Ist der Dienstleister ein Mutterunternehmen?', 'wm-dora-ict-register' ) ); ?>
				<?php self::yes_no( 'iso27001_certified', __( 'Liegt ein ISO-27001-Zertifikat vor?', 'wm-dora-ict-register' ) ); ?>
				<div class="wm-field-card">
					<label class="wm-field-title" for="wm-dora-iso-until"><?php esc_html_e( 'ISO 27001 gültig bis (optional)', 'wm-dora-ict-register' ); ?></label>
					<input type="date" id="wm-dora-iso-until" name="iso27001_valid_until" class="wm-input" />
				</div>

				<div class="wm-field-card">
					<label class="wm-field-title" for="wm-dora-ref"><?php esc_html_e( 'Vertragsreferenz (leer = automatisch)', 'wm-dora-ict-register' ); ?></label>
					<input type="text" id="wm-dora-ref" name="contract_ref" class="wm-input" maxlength="64" />
				</div>
				<div class="wm-field-card">
					<label class="wm-field-title" for="wm-dora-category"><?php esc_html_e( 'Art des Dienstes', 'wm-dora-ict-register' ); ?></label>
					<select id="wm-dora-category" name="service_category" class="wm-select" required>
						<option value=""><?php esc_html_e( 'Bitte wählen', 'wm-dora-ict-register' ); ?></option>
						<?php foreach ( $categories as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="wm-field-card">
					<label class="wm-field-title" for="wm-dora-location"><?php esc_html_e( 'Ort der Datenverarbeitung (zwei Buchstaben)', 'wm-dora-ict-register' ); ?></label>
					<input type="text" id="wm-dora-location" name="data_location_country" class="wm-input" maxlength="2" pattern="[A-Za-z]{2}" required />
				</div>
				<?php self::yes_no( 'subcontracting_permitted', __( 'Ist die Vergabe an Unterauftragnehmer erlaubt?', 'wm-dora-ict-register' ) ); ?>
				<div class="wm-field-card">
					<label class="wm-field-title" for="wm-dora-spend"><?php esc_html_e( 'Jährliche Kosten in EUR', 'wm-dora-ict-register' ); ?></label>
					<input type="number" id="wm-dora-spend" name="annual_spend" class="wm-input" min="0" step="0.01" required />
				</div>
				<?php self::yes_no( 'supports_cif', __( 'Unterstützt der Dienst eine kritische oder wichtige Funktion (Einstufung des Unternehmens)?', 'wm-dora-ict-register' ) ); ?>

				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Speichern', 'wm-dora-ict-register' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	private function render_export( array $counts ): void {
		$files = [
			'suppliers'      => __( 'Dienstleister', 'wm-dora-ict-register' ),
			'contracts'      => __( 'Verträge', 'wm-dora-ict-register' ),
			'subcontractors' => __( 'Unterauftragnehmer (leer: noch keine Eingabe möglich)', 'wm-dora-ict-register' ),
			'manifest'       => __( 'Manifest (JSON)', 'wm-dora-ict-register' ),
		];
		?>
		<div class="wm-card">
			<h2><?php esc_html_e( 'CSV-Export der Plugin-Tabellen', 'wm-dora-ict-register' ); ?></h2>
			<p class="wm-field-desc"><?php esc_html_e( 'Die Dateien enthalten die Spalten dieses Plugins. Sie sind nicht das Informationsregister in den Vorlagen der Durchführungsverordnung (EU) 2024/2956 (B_01.01 bis B_99.01) und keine xBRL-CSV-Meldung; für eine Einreichung müssen die Daten in das von der Behörde verlangte Format übertragen werden.', 'wm-dora-ict-register' ); ?></p>
			<?php if ( '' === (string) ( $this->settings()['entity_lei'] ?? '' ) ) : ?>
				<div class="wm-inline-notice is-warning"><?php esc_html_e( 'Die LEI des eigenen Unternehmens fehlt; das Manifest enthält dann keine.', 'wm-dora-ict-register' ); ?></div>
			<?php endif; ?>
			<ul>
				<?php foreach ( $files as $key => $label ) : ?>
					<li>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'wm_dora_export', 'file' => $key ], admin_url( 'admin-post.php' ) ), 'wm_dora_export' ) ); ?>"><?php echo esc_html( RegisterCsvExporter::FILES[ $key ] ); ?></a>
						<?php echo esc_html( $label ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	private function render_settings(): void {
		$s = $this->settings();
		?>
		<div class="wm-card">
			<h2><?php esc_html_e( 'Eigenes Unternehmen', 'wm-dora-ict-register' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'wm_dora_admin_action', 'wm_dora_nonce' ); ?>
				<input type="hidden" name="wm_dora_action" value="save_settings" />
				<div class="wm-field-card">
					<label class="wm-field-title" for="wm-dora-entity-lei"><?php esc_html_e( 'LEI des Unternehmens', 'wm-dora-ict-register' ); ?></label>
					<input type="text" id="wm-dora-entity-lei" name="entity_lei" value="<?php echo esc_attr( (string) ( $s['entity_lei'] ?? '' ) ); ?>" class="wm-input" maxlength="20" pattern="[A-Za-z0-9]{18}[0-9]{2}" />
				</div>
				<div class="wm-field-card">
					<label class="wm-field-title" for="wm-dora-entity-name"><?php esc_html_e( 'Name des Unternehmens', 'wm-dora-ict-register' ); ?></label>
					<input type="text" id="wm-dora-entity-name" name="entity_name" value="<?php echo esc_attr( (string) ( $s['entity_name'] ?? '' ) ); ?>" class="wm-input" />
				</div>
				<div class="wm-field-card">
					<label class="wm-field-title" for="wm-dora-authority"><?php esc_html_e( 'Zuständige Behörde', 'wm-dora-ict-register' ); ?></label>
					<input type="text" id="wm-dora-authority" name="competent_authority" value="<?php echo esc_attr( (string) ( $s['competent_authority'] ?? '' ) ); ?>" class="wm-input" />
				</div>
				<?php $delete_on_uninstall = 1 === (int) get_option( 'wm_dora_delete_data_on_uninstall', 0 ); ?>
				<div class="wm-field-card">
					<label class="wm-field-title" for="wm-dora-delete-on-uninstall">
						<input type="checkbox" id="wm-dora-delete-on-uninstall" name="delete_data_on_uninstall" value="1" <?php checked( $delete_on_uninstall ); ?> />
						<?php esc_html_e( 'Alle Registerdaten und Einstellungen beim Deinstallieren des Plugins löschen', 'wm-dora-ict-register' ); ?>
					</label>
					<p class="wm-field-desc"><?php esc_html_e( 'Ohne Auswahl bleiben Dienstleister, Verträge und Unternehmenseinstellungen erhalten, wenn das Plugin gelöscht wird.', 'wm-dora-ict-register' ); ?></p>
				</div>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Speichern', 'wm-dora-ict-register' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	private function render_glossary(): void {
		$terms = [
			__( 'DORA', 'wm-dora-ict-register' ) => __( 'Verordnung (EU) 2022/2554 über die digitale operationale Resilienz im Finanzsektor, anwendbar seit dem 17. Januar 2025. Sie gilt für die in Artikel 2 genannten Finanzunternehmen und für IKT-Drittdienstleister; dieses Plugin prüft nicht, ob sie für ein Unternehmen gilt.', 'wm-dora-ict-register' ),
			__( 'Informationsregister (Art. 28 Abs. 3)', 'wm-dora-ict-register' ) => __( 'Finanzunternehmen führen auf Einzel-, teilkonsolidierter und konsolidierter Ebene ein Register aller vertraglichen Vereinbarungen über IKT-Dienstleistungen, berichten mindestens jährlich über neue Vereinbarungen und stellen das vollständige Register auf Anfrage der Behörde zur Verfügung.', 'wm-dora-ict-register' ),
			__( 'Kritische oder wichtige Funktion (Art. 3 Nr. 22)', 'wm-dora-ict-register' ) => __( 'Eine Funktion, deren Störung die finanzielle Leistungsfähigkeit, die Solidität oder die Fortführung der Dienstleistungen eines Finanzunternehmens erheblich beeinträchtigen würde oder die Einhaltung seiner Zulassungsbedingungen und Pflichten. Die Punktzahl dieses Plugins ist eine eigene Heuristik, keine Vorgabe der Verordnung.', 'wm-dora-ict-register' ),
			__( 'Vorlagen (Durchführungsverordnung (EU) 2024/2956)', 'wm-dora-ict-register' ) => __( 'Standardvorlagen für das Register, beginnend mit B_01.01 (Unternehmen, das das Register führt); Dienstleister stehen in B_05.01, die Lieferkette in B_05.02. Dieses Plugin erzeugt diese Vorlagen nicht.', 'wm-dora-ict-register' ),
			__( 'LEI', 'wm-dora-ict-register' ) => __( 'Legal Entity Identifier nach ISO 17442: 20 Zeichen, die letzten beiden sind Prüfziffern. Öffentlich nachschlagbar bei der GLEIF.', 'wm-dora-ict-register' ),
		];
		?>
		<div class="wm-card">
			<h2><?php esc_html_e( 'Begriffe', 'wm-dora-ict-register' ); ?></h2>
			<div class="wm-glossary-grid">
				<?php foreach ( $terms as $term => $definition ) : ?>
					<div class="wm-glossary-card">
						<div class="wm-glossary-term"><?php echo esc_html( $term ); ?></div>
						<p class="wm-glossary-def"><?php echo esc_html( $definition ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
