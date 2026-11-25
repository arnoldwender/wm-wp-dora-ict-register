<?php
/**
 * Mutations for tests/run-mutations.sh: each entry undoes one correction of 2026-09-15 with literal
 * [file, search, replace] replacements. The suite must report at least one failure for every entry.
 *
 * @package WMCyber\DORA
 */

declare(strict_types=1);

return [
	[
		'any 20 characters pass as LEI again (no check digits)',
		[
			[ 'includes/Register/Validator.php', 'return 1 === $remainder ? $lei : null;', 'return $lei;' ],
		],
	],
	[
		'a missing or three-letter country becomes DE again',
		[
			[ 'includes/Register/Validator.php', 'return preg_match( \'/^[A-Z]{2}$/\', $code ) ? $code : null;', 'return \'\' !== $code ? substr( $code, 0, 2 ) : \'DE\';' ],
		],
	],
	[
		'negative amounts accepted again',
		[
			[ 'includes/Register/Validator.php', '\'/^\\d+(?:\\.\\d{1,2})?$/\'', '\'/^-?\\d+(?:\\.\\d{1,2})?$/\'' ],
			[ 'includes/Register/Validator.php', 'return $number >= 0 ? round( $number, 2 ) : null;', 'return round( $number, 2 );' ],
		],
	],
	[
		'an empty answer counts as no',
		[
			[ 'includes/Register/Validator.php', 'if ( ! is_string( $value ) || \'\' === trim( $value ) ) {', 'if ( ! is_string( $value ) ) {' ],
		],
	],
	[
		'unreadable CIF input is classified as not critical',
		[
			[ 'includes/Classifier/CifEngine.php', 'if ( [] !== $errors ) {', 'if ( false ) {' ],
		],
	],
	[
		'an RTO of 0 hours scores nothing again',
		[
			[ 'includes/Classifier/CifEngine.php', 'if ( $rto <= 4.0 ) {', 'if ( ! empty( $rto ) && $rto <= 4.0 ) {' ],
		],
	],
	[
		'the heuristic claims a DORA Art. 28(3) source again',
		[
			[ 'includes/Classifier/CifEngine.php', 'Punktzahl %d nach der Heuristik des Plugins (Schwelle %d; keine Vorgabe aus DORA oder den technischen Standards).', 'gem. DORA Art. 28(3): Punktzahl %d (Schwelle %d).' ],
		],
	],
	[
		'a failed supplier insert answers 201 success',
		[
			[ 'includes/Register/RegisterRepository.php', 'return [ \'ok\' => false, \'status\' => 500, \'id\' => 0, \'errors\' => [ \'insert_failed\' ] ];', 'return [ \'ok\' => true, \'status\' => 201, \'id\' => 0, \'errors\' => [] ];' ],
		],
	],
	[
		'a contract for a missing supplier is stored',
		[
			[ 'includes/Register/RegisterRepository.php', 'if ( ! $wpdb->get_var( $wpdb->prepare( \'SELECT id FROM %i WHERE id = %d\', $this->t( \'suppliers\' ), $supplier_id ) ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'unreadable engine input is stored as not critical',
		[
			[ 'includes/Register/RegisterRepository.php', '$errors[] = \'supports_cif\';', '' ],
		],
	],
	[
		'contracts written in site time again',
		[
			[ 'includes/Register/RegisterRepository.php', '$now      = current_time( \'mysql\', true );
		$inserted = $wpdb->insert(
			$this->t( \'contracts\' ),', '$now      = current_time( \'mysql\' );
		$inserted = $wpdb->insert(
			$this->t( \'contracts\' ),' ],
		],
	],
	[
		'CSV cells starting with = reach spreadsheets as formulas',
		[
			[ 'includes/Export/RegisterCsvExporter.php', 'in_array( $cell[0], [ \'=\', \'+\', \'-\', \'@\', "\\t", "\\r" ], true )', 'false' ],
		],
	],
	[
		'CSV lines end in LF only',
		[
			[ 'includes/Export/RegisterCsvExporter.php', '\',\', \'"\', \'\', "\\r\\n" );', '\',\', \'"\', \'\', "\\n" );' ],
		],
	],
	[
		'the manifest drops the entity LEI',
		[
			[ 'includes/Export/RegisterCsvExporter.php', '\'lei\'                 => \'\' !== (string) ( $settings[\'entity_lei\'] ?? \'\' ) ? (string) $settings[\'entity_lei\'] : null,', '\'lei\' => null,' ],
		],
	],
	[
		'0000-00-00 is exported as a date',
		[
			[ 'includes/Export/RegisterCsvExporter.php', 'str_starts_with( $value, \'0000-00-00\' )', 'false' ],
		],
	],
	[
		'saving settings wipes keys the form does not contain',
		[
			[ 'includes/Admin/AdminDashboard.php', '$settings                        = $this->settings();', '$settings                        = [];' ],
		],
	],
	[
		'an invalid entity LEI is saved',
		[
			[ 'includes/Admin/AdminDashboard.php', 'return [ \'entity_lei\' ];', '$lei = $lei_raw;' ],
		],
	],
	[
		'the add form duplicates a supplier for a known LEI',
		[
			[ 'includes/Admin/AdminDashboard.php', '$supplier_id = $this->repository->supplier_id_by_lei( $lei );', '$supplier_id = 0;' ],
		],
	],
	[
		'seed data names a real company with another company\'s LEI',
		[
			[ 'includes/Admin/AdminDashboard.php', '\'Beispiel Musterhosting GmbH\', \'DEMO00BEISPIEL000113\'', '\'Hetzner Online GmbH\', \'529900T8BM49AURSDO55\'' ],
		],
	],
	[
		'the supplier card counts contracts',
		[
			[ 'includes/Admin/AdminDashboard.php', '<span class="wm-stat-value"><?php echo esc_html( (string) $counts[\'suppliers\'] ); ?></span>', '<span class="wm-stat-value"><?php echo esc_html( (string) $counts[\'contracts\'] ); ?></span>' ],
		],
	],
	[
		'the export button links to the missing REST route',
		[
			[ 'includes/Admin/AdminDashboard.php', 'wp_nonce_url( add_query_arg( [ \'action\' => \'wm_dora_export\', \'file\' => $key ], admin_url( \'admin-post.php\' ) ), \'wm_dora_export\' )', 'admin_url( \'wm-dora/v1/export-xbrl\' )' ],
		],
	],
	[
		'an unknown download name is not refused',
		[
			[ 'includes/Admin/AdminDashboard.php', 'if ( ! isset( RegisterCsvExporter::FILES[ $file ] ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'the widget renders unstyled on the WordPress dashboard',
		[
			[ 'includes/Admin/AdminDashboard.php', '\'index.php\' !== $hook && ', '' ],
		],
	],
	[
		'a missing entity LEI is not reported',
		[
			[ 'includes/Admin/AdminDashboard.php', 'if ( null === Validator::lei( $this->settings()[\'entity_lei\'] ?? null ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'health ignores the missing entity LEI',
		[
			[ 'includes/Adapters/Dora_Spoke_Adapter.php', 'if ( ! self::entity_lei_valid() ) {', 'if ( false ) {' ],
		],
	],
	[
		'health ignores contracts without supplier',
		[
			[ 'includes/Adapters/Dora_Spoke_Adapter.php', 'return $repo->counts()[\'orphan_contracts\'] > 0 ? \'DEGRADED\' : \'HEALTHY\';', 'return \'HEALTHY\';' ],
		],
	],
	[
		'self test succeeds whatever the checks say',
		[
			[ 'includes/Adapters/Dora_Spoke_Adapter.php', '\'success\' => [] === $failed,', '\'success\' => true,' ],
		],
	],
	[
		'the hub settings link opens a page that does not exist',
		[
			[ 'includes/Adapters/Dora_Spoke_Adapter.php', 'admin.php?page=wm-dora-register', 'admin.php?page=wm-dora-dashboard' ],
		],
	],
	[
		'the export route is named xbrl-csv again',
		[
			[ 'includes/API/RestController.php', '\'/export/csv\'', '\'/export/xbrl-csv\'' ],
		],
	],
	[
		'a failed CLI write prints Success',
		[
			[ 'includes/CLI/DoraCommand.php', 'if ( false === @file_put_contents( $output_dir . \'/\' . $name, $files[ $key ] ) ) {', '@file_put_contents( $output_dir . \'/\' . $name, $files[ $key ] ); if ( false ) {' ],
		],
	],
	[
		'uninstall leaves the old audit table',
		[
			[ 'uninstall.php', '\'wm_dora_audit_log\' ]', ']' ],
		],
	],
	[
		'uninstall drops tables and options without opt-in',
		[
			[ 'uninstall.php', 'if ( ! $opt_in ) {', 'if ( false ) {' ],
		],
	],
	[
		'settings save ignores the delete_data_on_uninstall checkbox',
		[
			[ 'includes/Admin/AdminDashboard.php', 'self::sanitize_uninstall_flag( $post[\'delete_data_on_uninstall\'] ?? 0 )', '0' ],
		],
	],
	[
		'supplier rows bypass the wm_dora_supplier_row filter',
		[
			[ 'includes/Register/RegisterRepository.php', 'apply_filters( \'wm_dora_supplier_row\', $row )', '$row' ],
		],
	],
	[
		'boot skips firing wm_dora_ready',
		[
			[ 'includes/Core/Plugin.php', 'do_action( \'wm_dora_ready\', $this );', '' ],
		],
	],
	[
		'block bindings source wm-dora/metrics is not registered',
		[
			[ 'includes/Integrations/EcosystemBridge.php', "register_block_bindings_source(\n\t\t\t'wm-dora/metrics',", "register_block_bindings_source(\n\t\t\t'wm-dora/disabled'," ],
		],
	],
	[
		'GenerateBlocks dynamic tag wm_dora_metric is not registered',
		[
			[ 'includes/Integrations/EcosystemBridge.php', '\'tag\'         => self::DYNAMIC_TAG,', '\'tag\'         => \'wm_dora_disabled\',' ],
		],
	],
	[
		'wm_dora_settings option is saved without explicit autoload=false',
		[
			[ 'includes/Admin/AdminDashboard.php', 'update_option( \'wm_dora_settings\', $settings, false );', 'update_option( \'wm_dora_settings\', $settings );' ],
		],
	],
	[
		'wm_dora_delete_data_on_uninstall option is saved without explicit autoload=false',
		[
			[ 'includes/Admin/AdminDashboard.php', 'update_option( \'wm_dora_delete_data_on_uninstall\', self::sanitize_uninstall_flag( $post[\'delete_data_on_uninstall\'] ?? 0 ), false );', 'update_option( \'wm_dora_delete_data_on_uninstall\', self::sanitize_uninstall_flag( $post[\'delete_data_on_uninstall\'] ?? 0 ) );' ],
		],
	],
	[
		'wp_initialize_site does not provision tables for new network sites',
		[
			[ 'wm-dora-ict-register.php', 'add_action( \'wp_initialize_site\', function ( object $new_site ): void {', 'add_action( \'wp_initialize_site_disabled\', function ( object $new_site ): void {' ],
		],
	],
	[
		'wp_initialize_site provisions tables even when plugin is not network-active',
		[
			[ 'wm-dora-ict-register.php', 'if ( ! function_exists( \'is_plugin_active_for_network\' ) || ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'wp_initialize_site skips try/finally blog restore on exception',
		[
			[ 'wm-dora-ict-register.php', "\ttry {\n\t\tDatabase\\Schema::activate();\n\t} finally {\n\t\trestore_current_blog();\n\t}", "\tDatabase\\Schema::activate();\n\trestore_current_blog();" ],
		],
	],
	[
		'Schema::activate ignores network_wide in multisite',
		[
			[ 'includes/Database/Schema.php', 'if ( $network_wide && is_multisite() ) {', 'if ( false ) {' ],
		],
	],
	[
		'Schema::activate skips try/finally blog restore on exception',
		[
			[ 'includes/Database/Schema.php', "\t\t\t\ttry {\n\t\t\t\t\tself::activate_site();\n\t\t\t\t} finally {\n\t\t\t\t\trestore_current_blog();\n\t\t\t\t}", "\t\t\t\tself::activate_site();\n\t\t\t\trestore_current_blog();" ],
		],
	],
];
