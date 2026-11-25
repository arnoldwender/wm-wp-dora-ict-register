# Developer Guide — WM DORA ICT Register

Measured from the code.

## WP-CLI

```bash
wp dora list                                        # contracts with supplier (empty LEI when the supplier row is missing)
wp dora export-csv --output-dir=/tmp/dora-register  # four files; a failed write is an error
wp dora cif-audit                                   # contracts, CIF contracts and spend per supplier LEI
```

`export-xbrl` was renamed `export-csv` on 2026-09-15; it never produced xBRL-CSV, and it printed "Success" when no file was written.

## REST

Namespace `wm-dora/v1`, all routes require `manage_options` (cookie authentication needs the `X-WP-Nonce` header).

| Route | Method | Body | Answers |
| --- | --- | --- | --- |
| `/suppliers` | GET | — | 200 `{ success, suppliers[] }` |
| `/suppliers/add` | POST | `legal_name`, `lei_code`, `country_code`, `is_parent_undertaking`, `iso27001_certified`, optional `iso27001_valid_until` | 201 `{ supplier_id }`; 400 invalid fields; 409 LEI exists; 500 database refused |
| `/contracts/add` | POST | `contract_ref`, `supplier_id`, `service_category`, `data_location_country`, `subcontracting_permitted`, `annual_spend`, and `supports_cif` **or** `handles_core_banking_data`, `rto_hours`, `subcontractor_count` | 201 `{ contract_id, cif_status }`; 400; 404 supplier missing; 409 reference exists; 500 |
| `/export/csv` | GET | — | 200 `{ files: { suppliers, contracts, subcontractors, manifest } }` |
| `/concentration-risk` | GET | — | 200 `{ risk: { <LEI>: { legal_name, total_contracts, cif_contracts, total_spend } } }` |

Errors come back as `WP_Error` with `data.fields` listing the refused fields. Booleans accept `true/false`, `1/0`, `yes/no`, `on/off`; `service_category` is one of `cloud_hosting`, `payment_processing`, `software_as_a_service`, `cybersecurity_service`, `data_analytics`, `other_ict_service` (`cloud_computing` and `cloud_infrastructure` map to `cloud_hosting`).

## Actions, Filters & Block Integrations

| Hook / Integration | Kind | Signature / Usage |
| --- | --- | --- |
| `wm_dora_ready` | Action | `do_action( 'wm_dora_ready', Plugin $plugin )` — fired after `Plugin::boot()` wires routes, bridges, admin and CLI. |
| `wm_dora_supplier_row` | Filter | `apply_filters( 'wm_dora_supplier_row', array $row )` — filters each supplier row returned by `RegisterRepository::suppliers()`. |
| `wm_dora_export_download_body` | Filter | `apply_filters( 'wm_dora_export_download_body', string $body, string $filename )` — filters the CSV/JSON payload streamed by `AdminDashboard::handle_export_download()`. |
| `wm-dora/metrics` | WP 6.5+ Block Binding | Bind block content to `{"source":"wm-dora/metrics","args":{"key":"contracts"}}`. Supported keys: `suppliers`, `contracts`, `cif_contracts`, `subcontractors`, `orphan_contracts`, `competent_authority`, `health_status`. |
| `{{wm_dora_metric}}` | GenerateBlocks 2.x Dynamic Tag | Dynamic tag `{{wm_dora_metric key:contracts}}` resolving the same computed metric keys via `EcosystemBridge`. |

## Uninstall Retention (`wm_dora_delete_data_on_uninstall`)

By default (`0`), `uninstall.php` leaves `wm_dora_settings` and the register tables untouched. When the administrator enables `wm_dora_delete_data_on_uninstall` (`1`) under *DORA IKT-Register › Unternehmen*, `uninstall.php` deletes the plugin options, drops the register tables (plus legacy `wm_dora_audit_log`), and removes guard-only legacy `uploads/wm-dora/exports` directories.

## WM Suite Hub

Health and `self_test` see ARCHITECTURE.md. Telemetry: `suppliers`, `contracts`, `cif_contracts`, `subcontractors`, `orphan_contracts`, `entity_lei_configured`. `cif_audit` returns the counts. Settings URL `admin.php?page=wm-dora-register`.

## Tests

| Command | Needs | Covers |
| --- | --- | --- |
| `php tests/test-suite.php` | PHP | Validator, CIF heuristic, supplier and contract writes with status codes, CSV quoting and neutralisation, admin settings/form/example data/export links/non-autoloaded options, multisite `Schema::activate()` and `wp_initialize_site`, developer hooks, Block Bindings and GenerateBlocks Dynamic Tag, hub health, REST, CLI, opt-in uninstall. Mock `$wpdb` throws on unknown SQL; warnings fail the run. |
| `bash tests/run-mutations.sh` | PHP, git, tar | Literal mutations in `tests/mutations.php`, each must turn the suite red. |
| `php tests/test-ui-family.php` | PHP | Family sheets identical to the hub. |
| `php tests/test-pot-freshness.php` | PHP, wp-cli | Every translatable string in source is present in `languages/wm-dora-ict-register.pot`. |
| `vendor/bin/phpunit` (in `tests/`) | Composer dev dependencies | CIF heuristic, CSV rendering. |
| `wp eval-file tests/wp-runtime-dora.php --user=1` | WordPress | Real SQL for counts and concentration, a write in a rolled-back transaction, export, admin, REST routes and a 400, hub. |
