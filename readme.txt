=== WM DORA ICT Register ===
Contributors: arnoldwender
Tags: dora, ict, register, third-party-risk, lei
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

List of ICT third-party service providers and contracts with validated LEI, CIF marking and CSV export.

== Description ==

* **Suppliers and contracts:** LEI with check digits, country, spend, parent undertaking, security-certificate status as answered, subcontracting permitted, data location.
* **CIF marking:** the entity's yes/no in the form; over REST either that or a scoring heuristic of the plugin (not defined by DORA). Missing input is refused.
* **CSV export:** suppliers, contracts, subcontractors and a manifest, downloadable in wp-admin, via `wp dora export-csv` and REST.
* **Checks:** tables, entity LEI, contracts without supplier; hub health and self test follow them.
* **Integrations and hooks:** WordPress 6.5+ Block Bindings source `wm-dora/metrics`, GenerateBlocks 2.x Dynamic Tag `{{wm_dora_metric}}`, filters `wm_dora_export_download_body` and `wm_dora_supplier_row`, and action `wm_dora_ready`.
* **Safe uninstall:** tables and settings are preserved by default when uninstalling unless data deletion is explicitly enabled in the plugin settings.
* **Translations:** 28 compiled `.po`/`.mo` locale catalogs.

**What it does not do.** It does not produce the register templates of Implementing Regulation (EU) 2024/2956 or an xBRL-CSV report, has no input for subcontractors, and does not assess whether DORA applies or whether a function is critical.

== Frequently Asked Questions ==

= Is technical or private support provided? =

No. This plugin comes with zero support (`0` support). Private inquiries sent via email, `arnoldwender.com`, or social/chat channels are not answered. Reproducible bugs belong solely on GitHub Issues (`https://github.com/arnoldwender/wm-wp-dora-ict-register/issues`) with no guarantee of reply or resolution. The software is provided **"AS IS"** at your sole risk with zero warranty or liability (see `SUPPORT.md` in the repository).

== Screenshots ==

1. ICT third-party provider register with LEI check-digit verification, critical function flags and CSV export
2. Dashboard widget reporting registered ICT providers and contract count

== Changelog ==


= 1.2.2 =
* Fixed: Screenshot 1 showed the WM Suite menu from before WM Suite Hub 1.2.3, with entries the hub no longer has («Marketplace (Lösungspakete)», «Merkle-Audit-Protokoll», «CRA- & SBOM-Zentrale»). It is retaken with the current menu; the plugin's code is unchanged.

= 1.2.1 =
* The bundled family stylesheet is identical to WM Suite Hub 1.2.5 again: it starts the admin header below WordPress's help toggle instead of shortening it. This plugin's own screens register no help tab, so they change only where another plugin adds one.

= 1.2.0 =
* The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
* The `.wordpress-org/` banners show the current version.
* Removed: The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

= 1.1.4 =
* Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.
* Added root `SUPPORT.md`, GitHub Issue templates, explicit zero-support & "AS IS" liability notices in READMEs and `readme.txt`, and normalized canonical GPLv2+ `arnoldwender.com` / `arnoldwender/wm-wp-dora-ict-register` links.
* Updated `.wordpress-org/` visual banners to personal `ARNOLD WENDER · OPEN SOURCE` branding.

= 1.1.3 =
* Add `.wordpress-org/` vector icons, banners (`772x250`, `1544x500`), live WordPress screenshots (`screenshot-1..2.png`) and `== Screenshots ==` descriptions.
* Add `README.de.md`, `README.es.md`, root `llms.txt`, live previews, and direct plugin catalog link (`https://arnoldwender.com/wordpress-plugins/wm-dora-ict-register/`).

= 1.1.2 =
* Point Plugin URI to the official plugin page and enabled lexer-based security-audit CI step.

= 1.1.1 =
* Fixed: multisite network activation now provisions register tables automatically on newly created sites via `wp_initialize_site` (with `try/finally` blog restoration and guarded `dbDelta` loading).
* Fixed: settings saves pass explicit `$autoload = false` for `wm_dora_settings` and `wm_dora_delete_data_on_uninstall`.

= 1.1.0 =
* Added: opt-in uninstall data retention (`wm_dora_delete_data_on_uninstall`, disabled by default) with an admin checkbox so register tables and settings are preserved unless deletion is requested.
* Added: developer hooks `wm_dora_export_download_body`, `wm_dora_supplier_row` and `wm_dora_ready`, plus WordPress 6.5+ Block Bindings (`wm-dora/metrics`) and GenerateBlocks 2.x Dynamic Tag (`{{wm_dora_metric}}`).
* Added: complete `.po`/`.mo` translations across all 28 supported locales, CycloneDX 1.6 `bom.json` and WordPress Playground blueprint.
* Fixed: the admin download linked to a REST route that did not exist; downloads go through admin-post with a nonce and `wm_dora_export_download_body` filter.
* Fixed: LEI check digits, country, spend and yes/no answers are validated strictly, and nothing is stored when a field is invalid.
* Fixed: REST answers 400, 404, 409 or 500 on validation, missing supplier, duplicate or database failure; CSV fields use RFC 4180 quoting with formula neutralisation and entity LEI in the manifest.
* Fixed: status indicators and hub health reflect live setup checks (`tables_exist()`, entity LEI, orphan contracts); demo seed uses fictional suppliers with unregistered valid LEIs.
* Changed: admin screen in German on the shared WM Suite family stylesheets; `wp dora export-csv` and REST `/export/csv`; timestamps in UTC.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.2 =
Screenshot 1 retaken with the current WM Suite menu. No code, data or settings change.

= 1.2.1 =
Stylesheet update only (the family sheet of WM Suite Hub 1.2.5). No data or settings change.

= 1.2.0 =
Hub users: update WM Suite Hub to 1.2.0 together with this plugin. The hub contract moved to a new PHP namespace; next to an older hub the plugin keeps working, but it is not listed in the cockpit and its admin page has no menu entry until the hub is updated.
