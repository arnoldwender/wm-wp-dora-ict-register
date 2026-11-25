# Architecture — WM DORA ICT Register

Measured from the code on 2026-09-15. The previous edition showed tables B_01.01/B_02.01/B_03.01, a "Subcontractor Tree (Tier 1..N Relational)", an RTO column on contracts and an "xBRL-CSV Packager"; the code has none of these.

## Classes

| File | Responsibility |
| --- | --- |
| `wm-dora-ict-register.php` | Header, constants, autoloader, activation (`Schema`), `wp_initialize_site` multisite table provisioning, hub registration, boot on `plugins_loaded` |
| `includes/Core/Plugin.php` | Singleton holding `CifEngine`, `RegisterCsvExporter`, `RegisterRepository`, `EcosystemBridge`; text domain; wires REST, ecosystem bridges, admin (admin only), WP-CLI; fires `wm_dora_ready` |
| `includes/Database/Schema.php` | Creates the three tables, per site on network activation (`try/finally`) and via `wp_initialize_site` with guarded `dbDelta()` loading |
| `includes/Register/Validator.php` | LEI (format and check digits), country, service category, amount, number, boolean, date, text |
| `includes/Register/RegisterRepository.php` | Validated inserts for suppliers and contracts with status codes, counts (including contracts without supplier), lists (`wm_dora_supplier_row` filter) |
| `includes/Classifier/CifEngine.php` | The scoring heuristic (refuses unreadable input) and the per-LEI concentration query |
| `includes/Export/RegisterCsvExporter.php` | Three CSV files and a JSON manifest |
| `includes/Integrations/EcosystemBridge.php` | WordPress 6.5+ Block Bindings source `wm-dora/metrics` and GenerateBlocks 2.x Dynamic Tag `{{wm_dora_metric}}` |
| `includes/API/RestController.php` | Five routes for `manage_options` |
| `includes/CLI/DoraCommand.php` | `wp dora list`, `export-csv`, `cif-audit` |
| `includes/Admin/AdminDashboard.php` | Menu `wm-dora-register`, tabs Register / Eintragen / CSV-Export / Unternehmen / Begriffe, form actions, `admin-post.php?action=wm_dora_export` downloads (`wm_dora_export_download_body` filter), dashboard widget, example data, `wm_dora_delete_data_on_uninstall` setting |
| `includes/Adapters/Dora_Spoke_Adapter.php` | WM Suite Hub module (defined only when the hub interface exists) |
| `uninstall.php` | Preserves options and tables by default; removes options, tables, legacy audit table and guard-only uploads directory per site only when `wm_dora_delete_data_on_uninstall` is enabled |

Removed on 2026-09-15: `includes/Security/` (RequestShield ran on every request and logged IP and user agent without blocking; DirectoryProtector created `uploads/wm-dora/exports` on every request; UploadShield, RateLimiter, ErrorSanitizer had no caller) and `XbrlCsvExporter`.

## Tables

| Table | Columns |
| --- | --- |
| `wm_dora_suppliers` | `lei_code` (unique), `legal_name`, `country_code`, `is_parent_undertaking`, `iso27001_certified`, `iso27001_valid_until` (NULL when unknown), `soc2_type2_valid` (no input), `created_at`, `updated_at` (UTC) |
| `wm_dora_contracts` | `contract_ref` (unique), `supplier_id`, `service_category`, `supports_cif`, `cif_rationale`, `data_location_country`, `subcontracting_permitted`, `annual_spend`, `created_at`, `updated_at` (UTC) |
| `wm_dora_subcontractors` | `contract_id`, `subcontractor_lei`, `subcontractor_name`, `service_provided`, `country_location`, `tier_level`; no writer |

Option `wm_dora_settings` (`autoload = false`): `entity_lei`, `entity_name`, `competent_authority`; other keys from older versions (`bafin_id`, `cif_spend_threshold`) are kept and unused. Option `wm_dora_delete_data_on_uninstall` (default `0`, `autoload = false`) controls whether `uninstall.php` drops the register tables (plus legacy `wm_dora_audit_log`) and removes options.

## Contract flow

```text
admin form / REST contracts/add
   │
   ├─ Validator: ref, category, data location, subcontracting yes/no, spend ≥ 0, supplier id
   ├─ supports_cif given?  yes → manual marking
   │                       no  → CifEngine::evaluate_cif() (all four inputs valid, else 400)
   ├─ supplier exists? (404), reference unique? (409)
   └─ insert → 201, or 500 when the database refuses
```

## Hub health

ERROR without tables, NEEDS_CONFIG without a valid entity LEI, DEGRADED with contracts whose supplier row is missing, otherwise HEALTHY. `self_test` scores two fixed examples (105 and 0 points), checks that unreadable input is refused, checks the LEI check digits and the tables, and fails when any of these does not hold.

---

<!-- wm-measured-inventory -->

## Measured inventory

Regenerated from the code, never hand-edited. Everything above this line is written by hand and stays.

```bash
python3 the fleet's architecture inventory tool wm-dora-ict-register --append-to docs/ARCHITECTURE.md
```

## Inventory by layer

**13 PHP files, 2036 lines** (excluding `tests/`, `vendor/`, `node_modules/`, `languages/`).

| Layer | Files | Lines |
|---|---:|---:|
| `(raíz)` | 2 | 148 |
| `includes/API` | 1 | 72 |
| `includes/Adapters` | 1 | 163 |
| `includes/Admin` | 1 | 660 |
| `includes/CLI` | 1 | 98 |
| `includes/Classifier` | 1 | 116 |
| `includes/Core` | 1 | 82 |
| `includes/Database` | 1 | 102 |
| `includes/Export` | 1 | 138 |
| `includes/Integrations` | 1 | 118 |
| `includes/Register` | 2 | 339 |

---

## Classes

| Class | File |
|---|---|
| `AdminDashboard` | [`includes/Admin/AdminDashboard.php`](../includes/Admin/AdminDashboard.php) |
| `CifEngine` | [`includes/Classifier/CifEngine.php`](../includes/Classifier/CifEngine.php) |
| `DoraCommand` | [`includes/CLI/DoraCommand.php`](../includes/CLI/DoraCommand.php) |
| `Dora_Spoke_Adapter` | [`includes/Adapters/Dora_Spoke_Adapter.php`](../includes/Adapters/Dora_Spoke_Adapter.php) |
| `EcosystemBridge` | [`includes/Integrations/EcosystemBridge.php`](../includes/Integrations/EcosystemBridge.php) |
| `Plugin` | [`includes/Core/Plugin.php`](../includes/Core/Plugin.php) |
| `RegisterCsvExporter` | [`includes/Export/RegisterCsvExporter.php`](../includes/Export/RegisterCsvExporter.php) |
| `RegisterRepository` | [`includes/Register/RegisterRepository.php`](../includes/Register/RegisterRepository.php) |
| `RestController` | [`includes/API/RestController.php`](../includes/API/RestController.php) |
| `Schema` | [`includes/Database/Schema.php`](../includes/Database/Schema.php) |
| `Validator` | [`includes/Register/Validator.php`](../includes/Register/Validator.php) |

---

## Entry points

**REST routes:** `/concentration-risk`, `/contracts/add`, `/export/csv`, `/suppliers`, `/suppliers/add`

**WP-CLI:** `dora`

---

## Persistence — who writes each key and who reads it

| Key | Written by | Read by |
|---|---|---|
| `wm_dora_delete_data_on_uninstall` | `includes/Admin/AdminDashboard.php` | `includes/Admin/AdminDashboard.php`, `uninstall.php` |
| `wm_dora_settings` | `includes/Admin/AdminDashboard.php` | `includes/Adapters/Dora_Spoke_Adapter.php`, `includes/Admin/AdminDashboard.php`, `includes/Export/RegisterCsvExporter.php`, `includes/Integrations/EcosystemBridge.php` |

Every key has exactly one writing layer and at least one reader.


**Tables** (prefixed with `$wpdb->prefix`): `wm_dora_`, `wm_dora_contracts`, `wm_dora_subcontractors`, `wm_dora_suppliers`

---

## Gates

- `tests/bootstrap.php`
- `tests/mutations.php`
- `tests/run-mutations.sh`
- `tests/test-pot-freshness.php`
- `tests/test-suite.php`
- `tests/test-ui-family.php`
- `tests/wp-runtime-dora.php`
- `.github/workflows/ci.yml`

What CI actually runs is in the workflow files above — a test file that no workflow invokes is not a gate.

---

## Drift detected against the documentation

Nothing measurable: versions agree and every class named in the documents exists.

