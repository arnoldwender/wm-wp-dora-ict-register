# Changelog — WM DORA ICT Register

Before 2026-09-15 changes were recorded only in `readme.txt`.

## [Unreleased]

### Fixed
- Relaxed `EcosystemBridge` GenerateBlocks `generateblocks_dynamic_tag_replacement` filter parameter to `mixed`.
- Renamed `RestController::NAMESPACE` to `REST_NAMESPACE` for PHP 8.5/8.6 compatibility.
- Added `Schema::maybe_upgrade()` in `Plugin::boot()` (`plugins_loaded`) with non-autoloaded `wm_dora_db_version` option and opt-in cleanup in `uninstall.php`.

## [1.2.2] - 2026-11-21

### Fixed
- Screenshot 1 showed the WM Suite menu from before WM Suite Hub 1.2.3, with entries the hub no longer has («Marketplace (Lösungspakete)», «Merkle-Audit-Protokoll», «CRA- & SBOM-Zentrale»). It is retaken with the current menu; the plugin's code is unchanged.

## [1.2.1] - 2026-11-21

### Changed
- The bundled family stylesheet is identical to WM Suite Hub 1.2.5 again: it starts the admin header below WordPress's help toggle instead of shortening it. This plugin's own screens register no help tab, so they change only where another plugin adds one.
- The translation catalogs are regenerated from the code: the `.pot` header names the current version, the Author URI entry follows the plugin header (https://arnoldwender.com instead of the www address), and the Plugin URI entry, empty until now, is filled in all 28 locales.

## [1.2.0] - 2026-11-20

### Changed
- The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
- The `.wordpress-org/` banners show the current version.
- `README.de.md` and `README.es.md` stated version 1.1.3 since release 1.1.4; the German description of the first screenshot said "CF" for the CIF marking.

### Removed
- The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

## [1.1.4] - 2026-11-19

- Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.

- Added root `SUPPORT.md`, `.github/ISSUE_TEMPLATE/{config.yml,bug_report.yml}`, and explicit zero-support / "AS IS" liability notices across `README.md`, `README.de.md`, `README.es.md`, `readme.txt`, `SECURITY.md`, and `llms.txt`.
- Migrated plugin author/catalog/repository metadata to personal open-source distribution (`Arnold Wender`, `https://arnoldwender.com/wordpress-plugins/wm-dora-ict-register/`, `https://github.com/arnoldwender/wm-wp-dora-ict-register`), normalized FSF GPL-2.0 `LICENSE` metadata where applicable, and updated `.wordpress-org/` banner branding to `ARNOLD WENDER · OPEN SOURCE`.

## [Unreleased]

## [1.1.3] - 2026-11-19

### Added
- Official `.wordpress-org/` directory visual kit (`icon.svg`, `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png`) and live Playwright screenshots (`screenshot-1.png`, `screenshot-2.png`) with `== Screenshots ==` in `readme.txt`.
- Added German (`README.de.md`) and Spanish (`README.es.md`) documentation with language switcher badges, root `llms.txt`, visual previews, and direct links to `https://arnoldwender.com/wordpress-plugins/wm-dora-ict-register/`.

### Changed
- Archived superseded pre-2026-11-19 banners and mockups non-destructively in `versionen/visuals-pre-2026-11-19/` and excluded `versionen/` from distribution packages via `.distignore`.
- Updated release metadata, adapter fallback, test bootstrap, and SBOM (`bom.json`) to `1.1.3`.

## [1.1.2] - 2026-11-19

### Changed
- Point `Plugin URI` to `https://arnoldwender.com/wordpress-plugins/wm-dora-ict-register/` and align release metadata at `v1.1.2`. Added `.github/scripts/security-audit.py` CI gate.

## [1.1.1] - 2026-11-18

### Fixed
- **Multisite new-site provisioning (`wp_initialize_site`):** hooked `wp_initialize_site` in `wm-dora-ict-register.php` so newly created sites automatically create the three register tables via `Database\Schema::activate()` inside `try/finally` (`switch_to_blog()` / `restore_current_blog()`) when the plugin is network-active, and guarded the `wp-admin/includes/upgrade.php` / `dbDelta()` and `wp-admin/includes/plugin.php` requires.
- **Explicit non-autoloaded admin options:** passed explicit `$autoload = false` on `update_option()` calls for `wm_dora_settings` and `wm_dora_delete_data_on_uninstall` in `AdminDashboard::save_settings()`.

## [1.1.0] - 2026-11-16

### Added
- **Opt-in uninstall (`wm_dora_delete_data_on_uninstall`):** `uninstall.php` preserves `wm_dora_settings` and the register tables by default; options, tables and legacy guard-only export folders are removed only when the administrator enables the opt-in checkbox under *DORA IKT-Register › Unternehmen*.
- **Developer hooks and block integrations:** added `wm_dora_export_download_body` and `wm_dora_supplier_row` filters, `wm_dora_ready` action on boot, WordPress 6.5+ Block Bindings source `wm-dora/metrics`, and GenerateBlocks 2.x Dynamic Tag `{{wm_dora_metric}}` (`includes/Integrations/EcosystemBridge.php`).
- **Translations:** complete `.po` and compiled `.mo` catalogs across all 28 locales (`ar`, `bg_BG`, `cs_CZ`, `da_DK`, `de_DE`, `el`, `en_GB`, `en_US`, `es_ES`, `et`, `fi`, `fi_FI`, `fr_FR`, `ga`, `hr_HR`, `hu_HU`, `it_IT`, `lt_LT`, `lv`, `mt_MT`, `nl_NL`, `pl_PL`, `pt_PT`, `ro_RO`, `sk_SK`, `sl_SI`, `sv_SE`, `tr_TR`).
- **Supply-chain and Playground metadata:** added CycloneDX 1.6 `bom.json`, `.wordpress-org/blueprints/blueprint.json`, and `AGENTS.md` symlink to `CLAUDE.md`.

### Translation catalog (2026-09-20)
- **The catalog and the code shared not one entry.** All 96 translatable strings were missing from the `.pot`, so no translator could see any of them in any language, and the 217 translated entries across the 28 catalogs belonged to the Spanish admin screen the plugin no longer has.
- Regenerated from the code with `wp i18n make-pot`; `tests/test-pot-freshness.php` and CI keep the catalog matching the code. Its definition of "fresh" comes from `wp i18n make-pot`, reads the text domain from the plugin header, treats 0 extracted strings as a failure, ignores the plugin header fields, requires wp-cli instead of skipping itself, and self-tests before judging the plugin.

### Family sheets (2026-09-16)
- Re-synced from the hub: the badge variants carry the state tokens on body surfaces, where the command-banner inks measured 1.49:1 to 2.21:1 against the composed background (hub PR #19), and the canonical sheet dropped five classes that belonged to a single plugin (hub PR #21). `tests/test-ui-family.php` re-pins both md5s.

### Fixed (2026-09-15)
- **The download button led nowhere.** It linked to `wm-dora/v1/export-xbrl`; the route was `/export/xbrl-csv`, a plain link carries no REST nonce, and the answer would have been JSON. Downloads go through `admin-post.php?action=wm_dora_export` with a nonce and send CSV/JSON files.
- **Unvalidated register data.** The admin form stored `ABC` as LEI, `DEU` as country and negative spend, recorded every supplier as certified and every contract with subcontracting permitted, and pre-checked the CIF box. `Validator` checks LEI format and ISO 7064 check digits, ISO 3166-1 alpha-2 format, amounts and dates; security-certificate status, parent undertaking, subcontracting and CIF are explicit yes/no answers; one invalid field means nothing is stored.
- **REST reported success for failures.** `suppliers/add` and `contracts/add` answered 201 `{"success":true}` when the insert failed, accepted a contract for a supplier id that did not exist, wrote `''` into DATE columns (exported as `0000-00-00`) and defaulted missing countries to DE. They answer 400, 404, 409 or 500 now.
- **The CIF heuristic read loose input.** `"no"`, `"false"` and `"n/a"` scored as yes, an RTO of 0 hours scored nothing, the cloud criterion matched a category neither the form nor REST sent, and the rationale said "gem. DORA Art. 28(3)" and "unter 4 Stunden" for `<= 4`. Input is parsed strictly and refused when unreadable; the rationale names the heuristic as the plugin's own.
- **CSV.** Fields were unquoted: a comma shifted the columns (6 header fields, 8 in the row) and a leading `=` reached spreadsheets as a formula. RFC 4180 quoting with CRLF, cells starting with `= + - @` get an apostrophe, contracts without supplier keep their row, and the manifest carries the entity LEI.
- **Fixed status text.** Fixed status banners were shown whatever the data; the supplier card counted contracts; the authority card cut "Banco de España" inside the "ñ" and went blank. States come from `setup_issue()` and the counts.
- **Settings.** A save reset `bafin_id` and `cif_spend_threshold`; an unsaved install pre-filled a 22-character "LEI" `529900MUSTERFINANZLEI1`. Keys the form does not contain are kept; the entity LEI is empty until entered and must pass the check digits.
- **Hub adapter.** Health follows tables, entity LEI and orphan contracts; the self test fails when a check fails; settings link points to `page=wm-dora-register`.
- **Example data.** Seed loads two fictional "Beispiel" suppliers with LEIs that pass the check digits and are not registered at GLEIF. Rows already loaded are not touched.
- **WP-CLI export** fails loudly when no file can be written.
- **Time zones.** Both admin and REST write UTC.
- **Uninstall** removes legacy guard-only `uploads/wm-dora/exports` directories and runs per site when opted in.

### Removed (2026-09-15)
- `RequestShield`, `DirectoryProtector`, `UploadShield`, `RateLimiter`, `ErrorSanitizer`, and the unused `wm_dora_audit_log` table.

### Changed (2026-09-15)
- `XbrlCsvExporter` is `RegisterCsvExporter`; files `dora-register-{suppliers,contracts,subcontractors}.csv` and `dora-register-manifest.json`. `wp dora export-xbrl` is `wp dora export-csv` (requires `--output-dir`), REST `/export/xbrl-csv` is `/export/csv`.
- Admin screen and widget in German, labelled form fields and yes/no fieldsets, `aria-current` on the active tab; the family sheets also load on the WordPress dashboard for the widget.
- Network activation creates the tables on every site.

### Documented (2026-09-15)
- README, readme.txt, `docs/ARCHITECTURE.md`, `docs/DEVELOPER-GUIDE.md`, `public/humans.txt` rewritten from the code; `docs/LEGAL-COMPLIANCE.md` with sources (Regulation (EU) 2022/2554, Implementing Regulation (EU) 2024/2956, GLEIF).

## [1.0.0]
- Initial release.
