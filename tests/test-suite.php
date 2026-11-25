<?php
/**
 * Standalone test suite for wm-dora-ict-register. No WordPress, no MySQL.
 *
 * The mock $wpdb evaluates the statements the plugin sends and throws on any other, so a query it does not
 * understand fails the run; a PHP warning or notice fails the run too.
 *
 *   php tests/test-suite.php          exit 0 = green
 *   bash tests/run-mutations.sh       each entry in tests/mutations.php undoes one fix; the suite must turn red
 *
 * @package WMCyber\DORA
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('ABSPATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');
define('OBJECT', 'OBJECT');

// Fictional LEIs with valid check digits, not registered at GLEIF (checked 2026-09-15).
const LEI_A = 'TEST00FIXTURE0000149';
const LEI_B = 'TEST00FIXTURE0000246';
const LEI_C = 'TEST00FIXTURE0000343';

$GLOBALS['passed_count'] = 0;
$GLOBALS['failed_count'] = 0;

function assert_test(string $name, bool $condition, string $details = ''): void {
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $GLOBALS['passed_count']++;
    } else {
        echo "  [FAIL] {$name}" . ('' !== $details ? ": {$details}" : '') . "\n";
        $GLOBALS['failed_count']++;
    }
}

/**
 * A test that cannot run here says so, instead of reporting PASS.
 *
 * A check exempting itself with `|| 0 === posix_geteuid()` is green whether the code is right or
 * wrong, and the only visible trace is a mutation surviving in an environment where nobody looks.
 * Measured 2026-09-20: under `act`, which runs the container as root, "a failed CLI write prints
 * Success" came back SURVIVED with no skip and no failure to explain it. Green without variance in
 * the input is not a result.
 */
function skip_test(string $name, string $why): void {
    echo "  [SKIP] {$name} — {$why}\n";
    $GLOBALS['skipped_count'] = ($GLOBALS['skipped_count'] ?? 0) + 1;
}

set_error_handler(static function (int $errno, string $errstr, string $file, int $line): bool {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    assert_test('no PHP warning or notice', false, "{$errstr} in " . basename($file) . ":{$line}");
    return true;
});

function render(callable $fn): string {
    ob_start();
    $fn();
    return (string) ob_get_clean();
}

// -----------------------------------------------------------------------------------------------------------------
// Mock database
// -----------------------------------------------------------------------------------------------------------------
final class Mock_WPDB_DORA {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public string $last_error = '';
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $tables = [];
    private array $auto = [];
    public bool $fail_writes = false;

    public function get_charset_collate(): string { return ''; }
    public function esc_like(string $t): string { return addcslashes($t, '_%\\'); }

    public function prepare(string $query, ...$args): string {
        $i = 0;
        return (string) preg_replace_callback('/%[sdif]/', static function (array $m) use (&$i, $args): string {
            $v = $args[$i++] ?? null;
            return match ($m[0]) {
                '%i' => '`' . str_replace('`', '', (string) $v) . '`',
                '%d' => (string) (int) $v,
                '%f' => (string) (float) $v,
                default => "'" . addslashes((string) $v) . "'",
            };
        }, $query);
    }

    private function rows(string $t): array {
        if (!array_key_exists($t, $this->tables)) {
            throw new RuntimeException("Mock_WPDB: table {$t} queried without a check");
        }
        return array_values($this->tables[$t]);
    }

    private function contracts_with_suppliers(string $c, string $s): array {
        $sup = array_column($this->rows($s), null, 'id');
        return array_map(static function (array $row) use ($sup): array {
            $x = $sup[$row['supplier_id']] ?? null;
            return $row + ['legal_name' => $x['legal_name'] ?? null, 'lei_code' => $x['lei_code'] ?? null, 'country_code' => $x['country_code'] ?? null];
        }, $this->rows($c));
    }

    public function get_var(string $q) {
        if (preg_match("/^SHOW TABLES LIKE '(.+)'$/", $q, $m)) {
            $t = str_replace('\\', '', $m[1]);
            return array_key_exists($t, $this->tables) ? $t : null;
        }
        if (preg_match('/^SELECT COUNT\(\*\) FROM `(\w+)`$/', $q, $m)) {
            return (string) count($this->rows($m[1]));
        }
        if (preg_match('/^SELECT COUNT\(\*\) FROM `(\w+)` WHERE supports_cif = 1$/', $q, $m)) {
            return (string) count(array_filter($this->rows($m[1]), static fn($r) => 1 === (int) $r['supports_cif']));
        }
        if (preg_match('/^SELECT COUNT\(\*\) FROM `(\w+)` c LEFT JOIN `(\w+)` s ON c.supplier_id = s.id WHERE s.id IS NULL$/', $q, $m)) {
            return (string) count(array_filter($this->contracts_with_suppliers($m[1], $m[2]), static fn($r) => null === $r['lei_code']));
        }
        if (preg_match("/^SELECT id FROM `(\w+)` WHERE (lei_code|contract_ref) = '(.*)'$/", $q, $m)) {
            foreach ($this->rows($m[1]) as $r) {
                if ((string) $r[$m[2]] === stripslashes($m[3])) {
                    return (string) $r['id'];
                }
            }
            return null;
        }
        if (preg_match('/^SELECT id FROM `(\w+)` WHERE id = (\d+)$/', $q, $m)) {
            foreach ($this->rows($m[1]) as $r) {
                if ((int) $r['id'] === (int) $m[2]) {
                    return (string) $r['id'];
                }
            }
            return null;
        }
        throw new RuntimeException("Mock_WPDB: unsupported get_var: {$q}");
    }

    public function get_results(string $q, string $output = 'OBJECT'): array {
        if (preg_match('/^SELECT \* FROM `(\w+)` ORDER BY id (ASC|DESC)$/', $q, $m)) {
            $rows = $this->rows($m[1]);
            return 'DESC' === $m[2] ? array_reverse($rows) : $rows;
        }
        if (preg_match('/^SELECT c\.\*, s\.lei_code FROM `(\w+)` c LEFT JOIN `(\w+)` s ON c\.supplier_id = s\.id ORDER BY c\.id ASC$/', $q, $m)) {
            return array_map(static fn($r) => array_diff_key($r, ['legal_name' => 1, 'country_code' => 1]), $this->contracts_with_suppliers($m[1], $m[2]));
        }
        if (preg_match('/^SELECT c\.\*, s\.legal_name, s\.lei_code, s\.country_code FROM `(\w+)` c LEFT JOIN `(\w+)` s ON c\.supplier_id = s\.id ORDER BY c\.id DESC$/', $q, $m)) {
            return array_reverse($this->contracts_with_suppliers($m[1], $m[2]));
        }
        if (preg_match('/^SELECT sub\.\*, c\.contract_ref FROM `(\w+)` sub LEFT JOIN `(\w+)` c ON sub\.contract_id = c\.id ORDER BY sub\.id ASC$/', $q, $m)) {
            $con = array_column($this->rows($m[2]), null, 'id');
            return array_map(static fn($r) => $r + ['contract_ref' => $con[$r['contract_id']]['contract_ref'] ?? null], $this->rows($m[1]));
        }
        if (preg_match('/^SELECT s\.lei_code, s\.legal_name, COUNT\(c\.id\) AS total_contracts, .* FROM `(\w+)` s LEFT JOIN `(\w+)` c ON s\.id = c\.supplier_id GROUP BY s\.lei_code, s\.legal_name$/', $q, $m)) {
            $out = [];
            foreach ($this->rows($m[1]) as $s) {
                $cs = array_filter($this->rows($m[2]), static fn($c) => (int) $c['supplier_id'] === (int) $s['id']);
                $out[] = ['lei_code' => $s['lei_code'], 'legal_name' => $s['legal_name'], 'total_contracts' => count($cs), 'cif_contracts' => count(array_filter($cs, static fn($c) => 1 === (int) $c['supports_cif'])), 'total_spend' => array_sum(array_column($cs, 'annual_spend'))];
            }
            return $out;
        }
        throw new RuntimeException("Mock_WPDB: unsupported get_results: {$q}");
    }

    public function insert(string $t, array $data, $format = null) {
        if ($this->fail_writes || !array_key_exists($t, $this->tables)) {
            return false;
        }
        foreach (['lei_code', 'contract_ref'] as $unique) {
            if (isset($data[$unique]) && in_array($data[$unique], array_column($this->tables[$t], $unique), true)) {
                return false;
            }
        }
        $this->auto[$t] = ($this->auto[$t] ?? 0) + 1;
        $this->insert_id = $this->auto[$t];
        $this->tables[$t][$this->insert_id] = ['id' => $this->insert_id] + $data;
        return 1;
    }

    public function query(string $q) {
        if (preg_match('/^DROP TABLE IF EXISTS `(\w+)`$/', $q, $m)) {
            unset($this->tables[$m[1]]);
            return true;
        }
        throw new RuntimeException("Mock_WPDB: unsupported query: {$q}");
    }
}

function reset_db(bool $tables = true): Mock_WPDB_DORA {
    $db = new Mock_WPDB_DORA();
    if ($tables) {
        $db->tables = ['wp_wm_dora_suppliers' => [], 'wp_wm_dora_contracts' => [], 'wp_wm_dora_subcontractors' => []];
    }
    $GLOBALS['wpdb'] = $db;
    $GLOBALS['mock_current_blog_id'] = 1;
    $GLOBALS['mock_blog_stack'] = [];
    $GLOBALS['mock_dbdelta_queries'] = [];
    $GLOBALS['mock_dbdelta_throw'] = false;
    return $db;
}
reset_db();

// -----------------------------------------------------------------------------------------------------------------
// WordPress stubs
// -----------------------------------------------------------------------------------------------------------------
$GLOBALS['mock_options']             = [];
$GLOBALS['mock_option_autoloads']    = [];
$GLOBALS['mock_styles']              = [];
$GLOBALS['mock_routes']              = [];
$GLOBALS['mock_actions']             = [];
$GLOBALS['mock_filters']             = [];
$GLOBALS['mock_fired_actions']       = [];
$GLOBALS['mock_registered_settings'] = [];
$GLOBALS['mock_block_bindings']      = [];
$GLOBALS['mock_gb_tags']             = [];
$GLOBALS['mock_multisite']           = false;
$GLOBALS['mock_network_plugins']     = [];
$GLOBALS['mock_sites']               = [1];
$GLOBALS['mock_current_blog_id']     = 1;
$GLOBALS['mock_blog_stack']          = [];
$GLOBALS['mock_activation_hooks']    = [];
$GLOBALS['mock_deactivation_hooks']  = [];
$GLOBALS['mock_dbdelta_queries']     = [];
$GLOBALS['mock_dbdelta_throw']       = false;

function get_option(string $o, $d = false) { return array_key_exists($o, $GLOBALS['mock_options']) ? $GLOBALS['mock_options'][$o] : $d; }
function update_option(string $o, $v, $a = null): bool { $GLOBALS['mock_options'][$o] = $v; $GLOBALS['mock_option_autoloads'][$o] = $a; return true; }
function delete_option(string $o): bool { unset($GLOBALS['mock_options'][$o], $GLOBALS['mock_option_autoloads'][$o]); return true; }
function get_site_option(string $o, $d = false) { return get_option($o, $d); }
function delete_site_option(string $o): bool { return true; }
function is_multisite(): bool { return (bool) $GLOBALS['mock_multisite']; }
function is_admin(): bool { return true; }
function plugin_basename(string $f): string { return 'wm-dora-ict-register/' . basename($f); }
function plugin_dir_path(string $f): string { return rtrim(dirname($f), '/\\') . '/'; }
function plugin_dir_url(string $f): string { return 'https://demo.example.test/wp-content/plugins/' . basename(dirname($f)) . '/'; }
function register_activation_hook(string $f, $cb): void { $GLOBALS['mock_activation_hooks'][$f] = $cb; }
function register_deactivation_hook(string $f, $cb): void { $GLOBALS['mock_deactivation_hooks'][$f] = $cb; }
function is_plugin_active_for_network(string $p): bool { return in_array($p, $GLOBALS['mock_network_plugins'], true); }
function switch_to_blog(int $blog_id): bool {
    $GLOBALS['mock_blog_stack'][] = $GLOBALS['mock_current_blog_id'];
    $GLOBALS['mock_current_blog_id'] = $blog_id;
    $GLOBALS['wpdb']->prefix = 1 === $blog_id ? 'wp_' : 'wp_' . $blog_id . '_';
    return true;
}
function restore_current_blog(): bool {
    if ([] === $GLOBALS['mock_blog_stack']) {
        return false;
    }
    $prev = (int) array_pop($GLOBALS['mock_blog_stack']);
    $GLOBALS['mock_current_blog_id'] = $prev;
    $GLOBALS['wpdb']->prefix = 1 === $prev ? 'wp_' : 'wp_' . $prev . '_';
    return true;
}
function dbDelta($queries = '', bool $execute = true): array {
    if ($GLOBALS['mock_dbdelta_throw']) {
        throw new RuntimeException('dbDelta failed');
    }
    $sql = is_array($queries) ? implode("\n", $queries) : (string) $queries;
    $GLOBALS['mock_dbdelta_queries'][] = ['blog_id' => $GLOBALS['mock_current_blog_id'], 'prefix' => $GLOBALS['wpdb']->prefix, 'sql' => $sql];
    if (preg_match_all('/CREATE TABLE\s+`?([A-Za-z0-9_]+)`?/i', $sql, $matches)) {
        foreach ($matches[1] as $table) {
            $GLOBALS['wpdb']->tables[$table] ??= [];
        }
    }
    return [];
}
function load_plugin_textdomain(string $d, $dep = false, string $p = ''): bool { return true; }
function add_action(string $tag, $cb, int $p = 10, int $a = 1): void { $GLOBALS['mock_actions'][$tag][] = $cb; }
function do_action(string $tag, ...$args): void {
    $GLOBALS['mock_fired_actions'][$tag] = ($GLOBALS['mock_fired_actions'][$tag] ?? 0) + 1;
    foreach ($GLOBALS['mock_actions'][$tag] ?? [] as $cb) {
        $cb(...$args);
    }
}
function add_filter(string $tag, $cb, int $p = 10, int $a = 1): void { $GLOBALS['mock_filters'][$tag][] = $cb; }
function apply_filters(string $tag, $value, ...$args) {
    foreach ($GLOBALS['mock_filters'][$tag] ?? [] as $cb) {
        $value = $cb($value, ...$args);
    }
    return $value;
}
function register_setting(string $group, string $name, array $args = []): void { $GLOBALS['mock_registered_settings'][$name] = ['group' => $group] + $args; }
function checked($checked, $current = true, bool $display = true): string {
    $out = ((string) $checked === (string) $current) ? ' checked="checked"' : '';
    if ($display) {
        echo $out;
    }
    return $out;
}
function register_block_bindings_source(string $name, array $props): bool {
    $GLOBALS['mock_block_bindings'][$name] = $props;
    return true;
}
class GenerateBlocks_Register_Dynamic_Tag {
    public function __construct(array $config) {
        $GLOBALS['mock_gb_tags'][$config['tag'] ?? ''] = $config;
    }
}
function __(string $t, string $d = ''): string { return $t; }
function esc_html($t): string { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t): string { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_url($u): string { return htmlspecialchars((string) $u, ENT_QUOTES, 'UTF-8'); }
function esc_html__(string $t, string $d = ''): string { return esc_html($t); }
function esc_html_e(string $t, string $d = ''): void { echo esc_html($t); }
function esc_attr_e(string $t, string $d = ''): void { echo esc_attr($t); }
function sanitize_text_field($s): string { return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $s))); }
function sanitize_key($k): string { return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
function wp_unslash($v) { return $v; }
function admin_url(string $p = ''): string { return 'https://demo.example.test/wp-admin/' . ltrim($p, '/'); }
function add_query_arg(array $args, string $url): string { return $url . '?' . http_build_query($args); }
function wp_nonce_url(string $url, string $action): string { return $url . '&_wpnonce=nonce-' . $action; }
function wp_nonce_field(string $a, string $n): void { echo '<input type="hidden" name="' . esc_attr($n) . '" value="nonce" />'; }
function current_user_can(string $c): bool { return true; }
function check_admin_referer(string $a, string $n = '_wpnonce'): bool { return true; }
function wp_safe_redirect(string $url): bool { throw new RuntimeException('redirect:' . $url); }
// UTC and site time differ by two hours here, so a write in site time is visible.
function current_time(string $type, $gmt = 0) { return $gmt ? '2026-09-15 10:00:00' : '2026-09-15 12:00:00'; }
function wp_json_encode($d, int $f = 0) { return json_encode($d, $f); }
function wp_generate_password(int $len = 12, bool $s = true, bool $e = false): string { static $n = 0; return strtoupper(substr(md5((string) ++$n), 0, $len)); }
function wp_enqueue_style(string $h, ...$r): void { $GLOBALS['mock_styles'][] = $h; }
function wp_add_dashboard_widget(...$a): void {}
function add_menu_page(...$a): string { return ''; }
function number_format_i18n($n, int $d = 0): string { return number_format((float) $n, $d, ',', '.'); }
function register_rest_route(string $ns, string $route, array $args): void { $GLOBALS['mock_routes'][] = $ns . $route; }
function wp_mkdir_p(string $d): bool { return @mkdir($d, 0777, true) || is_dir($d); }
function trailingslashit(string $s): string { return rtrim($s, '/\\') . '/'; }
function wp_upload_dir($t = null, bool $c = true): array { return ['basedir' => $GLOBALS['mock_uploads_basedir'] ?? '']; }
function wp_delete_file(string $f): void { @unlink($f); }
function get_sites(array $a = []): array { return $GLOBALS['mock_sites']; }

class WP_Error {
    public function __construct(public string $code = '', public string $message = '', public array $data = []) {}
    public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
    public function __construct(public $data = null, public int $status = 200) {}
    public function get_status(): int { return $this->status; }
}
class WP_REST_Request {
    public function __construct(private array $json = []) {}
    public function get_json_params(): array { return $this->json; }
}

final class CliExit extends RuntimeException {}
class WP_CLI_Command {}
final class WP_CLI {
    public static array $out = [];
    public static function line(string $m = ''): void { self::$out[] = $m; }
    public static function log(string $m): void { self::$out[] = $m; }
    public static function success(string $m): void { self::$out[] = 'Success: ' . $m; }
    public static function error(string $m): void { self::$out[] = 'Error: ' . $m; throw new CliExit($m); }
    public static function add_command(...$a): void {}
    public static function run(callable $fn): string { self::$out = []; try { $fn(); return 'ok'; } catch (CliExit $e) { return 'error'; } }
}

require __DIR__ . '/stubs/hub-interface.php';
require dirname(__DIR__) . '/wm-dora-ict-register.php';

use WMCyber\DORA\Adapters\Dora_Spoke_Adapter;
use WMCyber\DORA\Admin\AdminDashboard;
use WMCyber\DORA\API\RestController;
use WMCyber\DORA\CLI\DoraCommand;
use WMCyber\DORA\Classifier\CifEngine;
use WMCyber\DORA\Core\Plugin;
use WMCyber\DORA\Database\Schema;
use WMCyber\DORA\Export\RegisterCsvExporter;
use WMCyber\DORA\Integrations\EcosystemBridge;
use WMCyber\DORA\Register\RegisterRepository;
use WMCyber\DORA\Register\Validator;

function supplier(array $over = []): array {
    return $over + ['legal_name' => 'Fixture Hosting GmbH', 'lei_code' => LEI_A, 'country_code' => 'DE', 'is_parent_undertaking' => '0', 'iso27001_certified' => '0'];
}
function contract(int $supplier_id, array $over = []): array {
    return $over + ['contract_ref' => 'ICT-1', 'supplier_id' => $supplier_id, 'service_category' => 'cloud_hosting', 'data_location_country' => 'DE', 'subcontracting_permitted' => '0', 'annual_spend' => '1200.00', 'supports_cif' => '0'];
}

echo "==============================================================================\n";
echo "  WM DORA ICT REGISTER — STANDALONE TEST SUITE\n";
echo "==============================================================================\n\n";

// =================================================================================================================
echo "[GROUP 1] Validator\n";
// =================================================================================================================
assert_test('a real LEI passes the ISO 17442 check digits (Hetzner Online GmbH at GLEIF)', '391200XMWUW8G8MXV439' === Validator::lei('391200xmwuw8g8mxv439'));
assert_test('wrong check digits, 22 characters and "ABC" are refused', null === Validator::lei('TEST00FIXTURE0000148') && null === Validator::lei('529900MUSTERFINANZLEI1') && null === Validator::lei('ABC') && null === Validator::lei('549300VGE7B0E68F0H78'));
assert_test('country: DE passes, DEU and Germany are refused, missing is refused', 'DE' === Validator::country('de') && null === Validator::country('DEU') && null === Validator::country('Germany') && null === Validator::country(null));
assert_test('amount: negative and three decimals refused', null === Validator::amount('-100') && null === Validator::amount('1.005') && 12.5 === Validator::amount('12.5'));
assert_test('boolean: "no" is false, "n/a" and empty are unknown', false === Validator::boolean('no') && null === Validator::boolean('n/a') && null === Validator::boolean('') && null === Validator::boolean(null));
assert_test('old REST category values map to cloud_hosting, unknown refused', 'cloud_hosting' === Validator::service_category('cloud_computing') && null === Validator::service_category('office_supplies'));
assert_test('impossible date refused', null === Validator::date('2027-02-30') && '2027-03-31' === Validator::date('2027-03-31'));

// =================================================================================================================
echo "\n[GROUP 2] CIF heuristic\n";
// =================================================================================================================
$engine = new CifEngine();
$r = $engine->evaluate_cif(['handles_core_banking_data' => 'n/a', 'rto_hours' => 'n/a', 'subcontractor_count' => 0, 'service_category' => 'cloud_hosting']);
assert_test('unreadable input gives no classification, not "not critical"', false === $r['valid'] && ['handles_core_banking_data', 'rto_hours'] === $r['errors'], (string) json_encode($r));
$r = $engine->evaluate_cif(['handles_core_banking_data' => false, 'rto_hours' => 0, 'subcontractor_count' => 0, 'service_category' => 'data_analytics']);
assert_test('an RTO of 0 hours scores (was ignored by empty())', $r['valid'] && 30 === $r['score'], (string) json_encode($r));
$r = $engine->evaluate_cif(['handles_core_banking_data' => 'no', 'rto_hours' => 4, 'subcontractor_count' => 2, 'service_category' => 'cloud_hosting']);
assert_test('"no" does not score; RTO exactly 4 scores; two subcontractors do not; cloud_hosting scores', 45 === $r['score'] && str_contains($r['rationale'], 'höchstens 4 Stunden') && !$r['is_cif'], (string) json_encode($r));
$r = $engine->evaluate_cif(['handles_core_banking_data' => true, 'rto_hours' => 2, 'subcontractor_count' => 3, 'service_category' => 'cloud_computing']);
assert_test('all criteria: 105 points, CIF suggestion, labelled as plugin heuristic without a DORA source claim', 105 === $r['score'] && $r['is_cif'] && str_contains($r['rationale'], 'Heuristik des Plugins') && !str_contains($r['rationale'], 'Art. 28(3)'));

// =================================================================================================================
echo "\n[GROUP 3] Supplier writes\n";
// =================================================================================================================
$db   = reset_db();
$repo = new RegisterRepository($engine);
$res  = $repo->add_supplier(supplier(['lei_code' => 'ABC']));
assert_test('LEI "ABC" is refused with 400 and nothing stored', !$res['ok'] && 400 === $res['status'] && in_array('lei_code', $res['errors'], true) && [] === $db->tables['wp_wm_dora_suppliers']);
$res = $repo->add_supplier(array_diff_key(supplier(), ['country_code' => 1, 'iso27001_certified' => 1]));
assert_test('missing country and ISO answer are refused, not defaulted to DE and certified', !$res['ok'] && in_array('country_code', $res['errors'], true) && in_array('iso27001_certified', $res['errors'], true));
$res = $repo->add_supplier(supplier(['iso27001_valid_until' => '']));
$row = array_values($db->tables['wp_wm_dora_suppliers'])[0] ?? [];
assert_test('valid supplier: 201, ISO as answered, no date instead of 0000-00-00, UTC time', $res['ok'] && 201 === $res['status'] && 0 === $row['iso27001_certified'] && null === $row['iso27001_valid_until'] && '2026-09-15 10:00:00' === $row['created_at'], (string) json_encode($row));
$res = $repo->add_supplier(supplier());
assert_test('the same LEI again: 409', !$res['ok'] && 409 === $res['status']);
$db->fail_writes = true;
$res = $repo->add_supplier(supplier(['lei_code' => LEI_B]));
assert_test('a failed insert is 500, not 201 success', !$res['ok'] && 500 === $res['status'] && 0 === $res['id']);

// =================================================================================================================
echo "\n[GROUP 4] Contract writes\n";
// =================================================================================================================
$db   = reset_db();
$sid  = $repo->add_supplier(supplier())['id'];
$res  = $repo->add_contract(contract(999));
assert_test('a contract for a supplier that does not exist: 404', !$res['ok'] && 404 === $res['status'] && [] === $db->tables['wp_wm_dora_contracts']);
$res = $repo->add_contract(contract($sid, ['annual_spend' => '-100', 'data_location_country' => '']));
assert_test('negative spend and missing data location are refused', !$res['ok'] && in_array('annual_spend', $res['errors'], true) && in_array('data_location_country', $res['errors'], true));
$res = $repo->add_contract(array_diff_key(contract($sid), ['supports_cif' => 1]) + ['handles_core_banking_data' => 'no', 'rto_hours' => 'n/a', 'subcontractor_count' => 0]);
assert_test('no manual CIF and unreadable engine input: refused, not stored as non-critical', !$res['ok'] && in_array('supports_cif', $res['errors'], true) && [] === $db->tables['wp_wm_dora_contracts']);
$res = $repo->add_contract(array_diff_key(contract($sid, ['contract_ref' => 'ICT-2']), ['supports_cif' => 1]) + ['handles_core_banking_data' => 'yes', 'rto_hours' => '1', 'subcontractor_count' => 0]);
assert_test('engine inputs complete: CIF from the heuristic with its reasons', $res['ok'] && 'engine' === $res['cif']['source'] && true === $res['cif']['is_cif'] && 85 === $res['cif']['score'], (string) json_encode($res));
$res = $repo->add_contract(contract($sid, ['supports_cif' => 'no']));
$row = array_values(array_filter($db->tables['wp_wm_dora_contracts'], static fn($r) => 'ICT-1' === $r['contract_ref']))[0] ?? [];
assert_test('manual "no" is stored as not CIF with UTC time', $res['ok'] && 0 === $row['supports_cif'] && '2026-09-15 10:00:00' === $row['created_at']);
assert_test('duplicate contract reference: 409', 409 === $repo->add_contract(contract($sid))['status']);
$db->fail_writes = true;
assert_test('failed contract insert: 500', 500 === $repo->add_contract(contract($sid, ['contract_ref' => 'ICT-9']))['status']);

// =================================================================================================================
echo "\n[GROUP 5] CSV export\n";
// =================================================================================================================
$db = reset_db();
$GLOBALS['mock_options']['wm_dora_settings'] = ['entity_lei' => LEI_C, 'entity_name' => 'Fixture Bank AG', 'competent_authority' => 'BaFin'];
$sid = $repo->add_supplier(supplier(['legal_name' => '=1+1, "Quote" GmbH']))['id'];
$repo->add_contract(contract($sid, ['contract_ref' => '=HYPERLINK("http://evil.test","x")', 'service_category' => 'cloud_hosting']));
$db->tables['wp_wm_dora_contracts'][99] = ['id' => 99, 'contract_ref' => 'ORPHAN-1', 'supplier_id' => 555, 'service_category' => 'data_analytics', 'supports_cif' => 0, 'data_location_country' => 'DE', 'subcontracting_permitted' => 0, 'annual_spend' => '5.00'];
$db->tables['wp_wm_dora_suppliers'][1]['iso27001_valid_until'] = '0000-00-00';
$pkg = (new RegisterCsvExporter())->export_package();
$lines = static fn(string $csv): array => array_values(array_filter(explode("\r\n", $csv), 'strlen'));
$counts_ok = true;
foreach (['suppliers', 'contracts', 'subcontractors'] as $key) {
    $ls = $lines($pkg[$key]);
    $n  = count(str_getcsv($ls[0] ?? '', ',', '"', ''));
    foreach (array_slice($ls, 1) as $l) {
        $counts_ok = $counts_ok && $n === count(str_getcsv($l, ',', '"', ''));
    }
}
assert_test('every row has as many fields as its header, lines end in CRLF', $counts_ok && str_contains($pkg['contracts'], "\r\n"), $pkg['contracts']);
$sup_row = str_getcsv($lines($pkg['suppliers'])[1] ?? '', ',', '"', '');
$con_row = str_getcsv($lines($pkg['contracts'])[1] ?? '', ',', '"', '');
assert_test('leading = is neutralised for spreadsheets', "'=1+1, \"Quote\" GmbH" === ($sup_row[1] ?? null) && str_starts_with((string) ($con_row[0] ?? ''), "'="), json_encode([$sup_row, $con_row]));
assert_test('0000-00-00 is exported as empty; the orphan contract keeps its row with an empty LEI', '' === ($sup_row[5] ?? null) && str_contains($pkg['contracts'], 'ORPHAN-1,,data_analytics'));
$manifest = json_decode($pkg['manifest'], true);
assert_test('manifest carries the entity LEI and says it is not ITS templates or xBRL-CSV; no B_ template codes', LEI_C === ($manifest['entity']['lei'] ?? null) && str_contains((string) $manifest['format'], 'not an xBRL-CSV') && !preg_match('/B_0\d/', $pkg['manifest'] . $pkg['suppliers'] . $pkg['contracts']));

// =================================================================================================================
echo "\n[GROUP 6] Admin\n";
// =================================================================================================================
$db   = reset_db();
$GLOBALS['mock_options'] = ['wm_dora_settings' => ['bafin_id' => 'BAFIN-777', 'cif_spend_threshold' => 120000, 'entity_lei' => '']];
$dash = new AdminDashboard(new RegisterCsvExporter(), $repo);
$errors = $dash->save_settings(['entity_lei' => LEI_C, 'entity_name' => 'Fixture Bank AG', 'competent_authority' => 'Banco de España']);
$s = $GLOBALS['mock_options']['wm_dora_settings'];
assert_test('settings save keeps keys the form does not contain', [] === $errors && 'BAFIN-777' === $s['bafin_id'] && 120000 === $s['cif_spend_threshold'] && LEI_C === $s['entity_lei']);
$before = $GLOBALS['mock_options'];
assert_test('an invalid entity LEI is refused and nothing is written', ['entity_lei'] === $dash->save_settings(['entity_lei' => '529900MUSTERFINANZLEI1']) && $before === $GLOBALS['mock_options']);

assert_test('add form with LEI "ABC" stores nothing', ['lei_code'] === $dash->add_supplier_contract(['lei_code' => 'ABC', 'supplier_name' => 'X']) && [] === $db->tables['wp_wm_dora_suppliers']);
$post = ['supplier_name' => 'Fixture Hosting GmbH', 'lei_code' => LEI_A, 'country_code' => 'de', 'is_parent_undertaking' => '0', 'iso27001_certified' => '0', 'service_category' => 'cloud_hosting', 'data_location_country' => 'DE', 'subcontracting_permitted' => '0', 'annual_spend' => '100', 'supports_cif' => '1'];
$e1 = $dash->add_supplier_contract($post);
$e2 = $dash->add_supplier_contract(['supplier_name' => 'ignored'] + $post);
$sup = array_values($db->tables['wp_wm_dora_suppliers']);
assert_test('add form stores ISO as answered and reuses the supplier for a known LEI', [] === $e1 && [] === $e2 && 1 === count($sup) && 0 === $sup[0]['iso27001_certified'] && 2 === count($db->tables['wp_wm_dora_contracts']));
$e3 = $dash->add_supplier_contract(array_diff_key($post, ['supports_cif' => 1, 'subcontracting_permitted' => 1]) + ['lei_code' => LEI_B]);
assert_test('add form without CIF or subcontracting answer stores nothing', in_array('supports_cif', $e3, true) && in_array('subcontracting_permitted', $e3, true) && 2 === count($db->tables['wp_wm_dora_contracts']));

$db = reset_db();
$GLOBALS['mock_options'] = [];
assert_test('seed data: fictional names and LEIs, nothing about real companies', [] === $dash->seed_demo_data() && [] === $dash->seed_demo_data() && 2 === count($db->tables['wp_wm_dora_suppliers']) && !preg_match('/Hetzner|Stripe|529900T8BM49AURSDO55/', (string) json_encode($db->tables)) && null !== Validator::lei(array_values($db->tables['wp_wm_dora_suppliers'])[0]['lei_code']));

$_GET = ['tab' => 'overview'];
$page = render(static fn() => $dash->render_dashboard_page());
assert_test('overview: no CONFORME, no RESILIENCIA, no invented entity LEI, missing LEI reported', !preg_match('/CONFORME|RESILIENCIA|529900MUSTERFINANZLEI1|xBRL-CSV: /u', $page) && str_contains($page, 'LEI des eigenen Unternehmens fehlt'));
$db->tables['wp_wm_dora_contracts'][50] = ['id' => 50, 'contract_ref' => 'EXTRA', 'supplier_id' => 1, 'service_category' => 'data_analytics', 'supports_cif' => 0, 'data_location_country' => 'DE', 'subcontracting_permitted' => 0, 'annual_spend' => '1.00'];
$GLOBALS['mock_options']['wm_dora_settings'] = ['entity_lei' => LEI_C, 'competent_authority' => 'Banco de España'];
$page = render(static fn() => $dash->render_dashboard_page());
preg_match_all('#<span class="wm-stat-value">(\d+)</span>#', $page, $m);
assert_test('supplier card counts suppliers (2), not contracts (3); authority shown in full', ['2', '3', '1'] === $m[1] && str_contains($page, 'Banco de España'), (string) json_encode($m[1]));
$_GET = ['tab' => 'export'];
$page = render(static fn() => $dash->render_dashboard_page());
assert_test('export tab downloads through admin-post with a nonce, not the missing REST route', str_contains($page, 'admin-post.php?action=wm_dora_export&amp;file=contracts&amp;_wpnonce=') && !str_contains($page, 'export-xbrl'));
$payload = $dash->download_payload('contracts');
assert_test('download payload is the CSV with a CSV type; unknown file refused', null !== $payload && 'dora-register-contracts.csv' === $payload['name'] && str_starts_with($payload['body'], 'ContractRef,') && null === $dash->download_payload('../wp-config'));
$_GET = [];
$GLOBALS['mock_styles'] = [];
$dash->enqueue_admin_assets('index.php');
assert_test('family sheets load on the WordPress dashboard for the widget', in_array('wm-admin-ui', $GLOBALS['mock_styles'], true));
$GLOBALS['mock_registered_settings'] = [];
$dash->register_settings();
assert_test('wm_dora_delete_data_on_uninstall setting is registered with default 0', isset($GLOBALS['mock_registered_settings']['wm_dora_delete_data_on_uninstall']) && 0 === ($GLOBALS['mock_registered_settings']['wm_dora_delete_data_on_uninstall']['default'] ?? null));
$GLOBALS['mock_option_autoloads'] = [];
$dash->save_settings(['entity_lei' => LEI_C, 'entity_name' => 'Fixture Bank AG', 'competent_authority' => 'BaFin', 'delete_data_on_uninstall' => '1']);
assert_test('save_settings stores wm_dora_settings and wm_dora_delete_data_on_uninstall with autoload=false', false === ($GLOBALS['mock_option_autoloads']['wm_dora_settings'] ?? null) && false === ($GLOBALS['mock_option_autoloads']['wm_dora_delete_data_on_uninstall'] ?? null));
$_GET = ['tab' => 'settings'];
$settings_html = render(static fn() => $dash->render_dashboard_page());
$_GET = [];
assert_test('settings form renders and saves delete_data_on_uninstall opt-in checkbox', 1 === $GLOBALS['mock_options']['wm_dora_delete_data_on_uninstall'] && str_contains($settings_html, 'name="delete_data_on_uninstall"') && str_contains($settings_html, 'checked="checked"'));
$GLOBALS['mock_option_autoloads'] = [];
$dash->save_settings(['entity_lei' => LEI_C, 'entity_name' => 'Fixture Bank AG', 'competent_authority' => 'BaFin']);
assert_test('unchecking delete_data_on_uninstall resets the option to 0', 0 === $GLOBALS['mock_options']['wm_dora_delete_data_on_uninstall'] && false === ($GLOBALS['mock_option_autoloads']['wm_dora_delete_data_on_uninstall'] ?? null));

// =================================================================================================================
echo "\n[GROUP 6B] Hooks and Ecosystem Bridges\n";
// =================================================================================================================
$db = reset_db();
$GLOBALS['mock_options'] = ['wm_dora_settings' => ['entity_lei' => LEI_C, 'competent_authority' => 'BaFin']];
$sid = $repo->add_supplier(supplier())['id'];
$repo->add_contract(contract($sid, ['supports_cif' => '1']));
$GLOBALS['mock_filters']['wm_dora_supplier_row'] = [static fn(array $r): array => $r + ['filtered_tag' => 'audited']];
$filtered_suppliers = $repo->suppliers();
$GLOBALS['mock_filters'] = [];
assert_test('wm_dora_supplier_row filter transforms returned supplier rows', 'audited' === ($filtered_suppliers[0]['filtered_tag'] ?? null));

$GLOBALS['mock_filters']['wm_dora_export_download_body'] = [static fn(string $body, string $name): string => $name . ':' . strlen($body)];
$dl_filtered = apply_filters('wm_dora_export_download_body', 'A,B', 'dora-register-contracts.csv');
$GLOBALS['mock_filters'] = [];
assert_test('wm_dora_export_download_body filter passes body and filename and AdminDashboard invokes it', 'dora-register-contracts.csv:3' === $dl_filtered && str_contains((string) file_get_contents(dirname(__DIR__) . '/includes/Admin/AdminDashboard.php'), "apply_filters( 'wm_dora_export_download_body'"));

$wp_init_site_hooks = $GLOBALS['mock_actions']['wp_initialize_site'] ?? [];
$GLOBALS['mock_actions'] = ['wp_initialize_site' => $wp_init_site_hooks];
$GLOBALS['mock_fired_actions'] = [];
$GLOBALS['mock_block_bindings'] = [];
$GLOBALS['mock_gb_tags'] = [];
Plugin::instance()->boot();
do_action('init');
$bridge = Plugin::instance()->get_ecosystem_bridge();
assert_test('Plugin::boot fires wm_dora_ready action', 1 === ($GLOBALS['mock_fired_actions']['wm_dora_ready'] ?? 0));
assert_test('Block Bindings source wm-dora/metrics registered and resolves computed counts', isset($GLOBALS['mock_block_bindings'][EcosystemBridge::BINDING_SOURCE]) && '1' === $bridge->resolve_block_binding(['key' => 'suppliers']) && '1' === $bridge->resolve_block_binding(['key' => 'cif_contracts']) && 'HEALTHY' === $bridge->resolve_block_binding(['key' => 'health_status']) && null === $bridge->resolve_block_binding(['key' => 'unknown']));
assert_test('GenerateBlocks dynamic tag wm_dora_metric registered and renders metric values', isset($GLOBALS['mock_gb_tags'][EcosystemBridge::DYNAMIC_TAG]) && 'BaFin' === $bridge->render_dynamic_tag(['key' => 'competent_authority']) && '1' === $bridge->filter_generateblocks_replacement('', ['tag' => 'wm_dora_metric', 'options' => ['key' => 'contracts']]) && null === $bridge->filter_generateblocks_replacement(null, ['tag' => 'other_tag']) && '' === $bridge->render_dynamic_tag(['key' => 'unknown']));

// =================================================================================================================
echo "\n[GROUP 7] Hub adapter\n";
// =================================================================================================================
$db = reset_db(false);
$GLOBALS['mock_options'] = [];
$adapter = new Dora_Spoke_Adapter();
assert_test('tables missing: ERROR and self test failure', 'ERROR' === $adapter->get_health_status() && false === $adapter->execute_action('self_test')['success']);
$db = reset_db();
assert_test('no entity LEI: NEEDS_CONFIG', 'NEEDS_CONFIG' === $adapter->get_health_status());
$GLOBALS['mock_options']['wm_dora_settings'] = ['entity_lei' => LEI_C];
assert_test('entity LEI and tables: HEALTHY, self test success', 'HEALTHY' === $adapter->get_health_status() && true === $adapter->execute_action('self_test')['success']);
$db->tables['wp_wm_dora_contracts'][7] = ['id' => 7, 'contract_ref' => 'ORPHAN', 'supplier_id' => 404, 'service_category' => 'data_analytics', 'supports_cif' => 0, 'data_location_country' => 'DE', 'subcontracting_permitted' => 0, 'annual_spend' => '0.00'];
assert_test('a contract without supplier: DEGRADED', 'DEGRADED' === $adapter->get_health_status());
$sbom = $adapter->get_sbom_data();
$lic  = $sbom['licenses'][0]['license'] ?? [];
assert_test('settings link, licence name only, properties without conformance, telemetry without READY', str_contains((string) $adapter->get_settings_url(), 'page=wm-dora-register') && !(isset($lic['id']) && isset($lic['name'])) && !preg_match('/compliance|BaFin/i', (string) json_encode($sbom['properties'])) && !in_array('READY', $adapter->get_telemetry_metrics(), true));

// =================================================================================================================
echo "\n[GROUP 8] REST and WP-CLI\n";
// =================================================================================================================
$db   = reset_db();
$rest = new RestController($engine, new RegisterCsvExporter(), $repo);
$GLOBALS['mock_routes'] = [];
$rest->register();
assert_test('export route is /export/csv; the xBRL-named route is gone', in_array('wm-dora/v1/export/csv', $GLOBALS['mock_routes'], true) && !in_array('wm-dora/v1/export/xbrl-csv', $GLOBALS['mock_routes'], true));
$bad = $rest->add_supplier(new WP_REST_Request(supplier(['lei_code' => 'ZZZZZZZZZZZZZZZZZZZZ'])));
assert_test('REST refuses a 20-character LEI with wrong check digits with 400', $bad instanceof WP_Error && 400 === ($bad->get_error_data()['status'] ?? 0));
$ok = $rest->add_supplier(new WP_REST_Request(supplier()));
assert_test('REST valid supplier: 201', $ok instanceof WP_REST_Response && 201 === $ok->get_status());

$cmd = new DoraCommand();
assert_test('export-csv without --output-dir is an error', 'error' === WP_CLI::run(static fn() => $cmd->export_csv([], [])));
$out = sys_get_temp_dir() . '/wm-dora-suite-export-' . getmypid();
assert_test('export-csv writes the four files', 'ok' === WP_CLI::run(static fn() => $cmd->export_csv([], ['output-dir' => $out])) && 4 === count(glob($out . '/dora-register-*')));
array_map('unlink', glob($out . '/*'));
@rmdir($out);
@mkdir($ro = sys_get_temp_dir() . '/wm-dora-suite-ro-' . getmypid(), 0555);
// root writes into a read-only directory, so the failure this asserts on cannot be provoked. It
// used to pass anyway via `|| 0 === posix_geteuid()`, which is a green with no variance in its
// input: under `act` the matching mutation came back SURVIVED with nothing on screen to explain it.
if (0 === posix_geteuid()) {
    skip_test('an unwritable directory is an error, not "Success"', 'running as root, which can write to a read-only directory');
} else {
    $ro_result = WP_CLI::run(static fn() => $cmd->export_csv([], ['output-dir' => $ro]));
    assert_test('an unwritable directory is an error, not "Success"', 'error' === $ro_result);
}
@chmod($ro, 0777);
@rmdir($ro);

// =================================================================================================================
echo "\n[GROUP 9] Source checks\n";
// =================================================================================================================
$root = dirname(__DIR__);
assert_test('no site-wide shield or directory writer, Security classes gone', !is_dir($root . '/includes/Security') && !preg_match('/RequestShield::|DirectoryProtector::/', (string) file_get_contents($root . '/includes/Core/Plugin.php')));
assert_test('ABSPATH guard in Schema.php', str_contains((string) file_get_contents($root . '/includes/Database/Schema.php'), "defined( 'ABSPATH' )"));
assert_test('Schema.php guards upgrade.php require and dbDelta availability', str_contains((string) file_get_contents($root . '/includes/Database/Schema.php'), 'is_file( $upgrade_file )') && str_contains((string) file_get_contents($root . '/includes/Database/Schema.php'), "function_exists( 'dbDelta' )"));
assert_test('wm-dora-ict-register.php guards plugin.php require for is_plugin_active_for_network', str_contains((string) file_get_contents($root . '/wm-dora-ict-register.php'), "is_file( ABSPATH . 'wp-admin/includes/plugin.php' )"));
assert_test('no audit table created on activation', !str_contains((string) file_get_contents($root . '/includes/Database/Schema.php'), 'audit_log'));
assert_test('no emoji in the admin source', 0 === preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', (string) file_get_contents($root . '/includes/Admin/AdminDashboard.php')));

// =================================================================================================================
echo "\n[GROUP 10] Uninstall\n";
// =================================================================================================================
$GLOBALS['mock_options'] = ['wm_dora_settings' => ['entity_lei' => LEI_C]];
$db = reset_db();
$db->tables['wp_wm_dora_audit_log'] = [];
$base = sys_get_temp_dir() . '/wm-dora-suite-uploads-' . getmypid();
@mkdir($base . '/wm-dora/exports', 0777, true);
foreach (['.htaccess', 'web.config', 'index.php', 'index.html'] as $g) {
    file_put_contents($base . '/wm-dora/exports/' . $g, 'x');
}
$GLOBALS['mock_uploads_basedir'] = $base;
define('WP_UNINSTALL_PLUGIN', 'wm-dora-ict-register/wm-dora-ict-register.php');
require $root . '/uninstall.php';
assert_test('default uninstall preserves options, all four tables, and directory when opt-in is disabled', isset($GLOBALS['mock_options']['wm_dora_settings']) && 4 === count($db->tables) && is_dir($base . '/wm-dora/exports'));
$GLOBALS['mock_options']['wm_dora_delete_data_on_uninstall'] = 1;
require $root . '/uninstall.php';
assert_test('opt-in uninstall removes options and all four tables', [] === $GLOBALS['mock_options'] && [] === $db->tables);
assert_test('opt-in uninstall removes guard-only uploads/wm-dora directory', !is_dir($base . '/wm-dora'));
@rmdir($base);

// =================================================================================================================
echo "\n[GROUP 11] Multisite and schema activation\n";
// =================================================================================================================
$db = reset_db(false);
$GLOBALS['mock_multisite'] = false;
$GLOBALS['mock_sites'] = [1, 2];
$GLOBALS['mock_network_plugins'] = [];
Schema::activate(false);
assert_test('single-site Schema::activate creates the three plugin tables via dbDelta', isset($db->tables['wp_wm_dora_suppliers'], $db->tables['wp_wm_dora_contracts'], $db->tables['wp_wm_dora_subcontractors']) && !isset($db->tables['wp_2_wm_dora_suppliers']));

$db = reset_db(false);
$GLOBALS['mock_multisite'] = true;
$GLOBALS['mock_sites'] = [1, 2];
Schema::activate(true);
assert_test('network-wide Schema::activate creates tables on every site and restores blog', isset($db->tables['wp_wm_dora_suppliers'], $db->tables['wp_wm_dora_contracts'], $db->tables['wp_wm_dora_subcontractors'], $db->tables['wp_2_wm_dora_suppliers'], $db->tables['wp_2_wm_dora_contracts'], $db->tables['wp_2_wm_dora_subcontractors']) && 1 === $GLOBALS['mock_current_blog_id'] && [] === $GLOBALS['mock_blog_stack']);

$db = reset_db(false);
$GLOBALS['mock_multisite'] = false;
$GLOBALS['mock_network_plugins'] = ['wm-dora-ict-register/wm-dora-ict-register.php'];
do_action('wp_initialize_site', (object) ['blog_id' => 3]);
assert_test('wp_initialize_site is a no-op outside multisite', !isset($db->tables['wp_3_wm_dora_suppliers']));

$db = reset_db(false);
$GLOBALS['mock_multisite'] = true;
$GLOBALS['mock_network_plugins'] = [];
do_action('wp_initialize_site', (object) ['blog_id' => 3]);
assert_test('wp_initialize_site is a no-op when plugin is not network-active', !isset($db->tables['wp_3_wm_dora_suppliers']));

$db = reset_db(false);
$GLOBALS['mock_multisite'] = true;
$GLOBALS['mock_network_plugins'] = ['wm-dora-ict-register/wm-dora-ict-register.php'];
do_action('wp_initialize_site', (object) ['blog_id' => 3]);
assert_test('wp_initialize_site provisions tables on new blog when network-active and restores blog', isset($db->tables['wp_3_wm_dora_suppliers'], $db->tables['wp_3_wm_dora_contracts'], $db->tables['wp_3_wm_dora_subcontractors']) && 1 === $GLOBALS['mock_current_blog_id'] && [] === $GLOBALS['mock_blog_stack']);

$db = reset_db(false);
$GLOBALS['mock_multisite'] = true;
$GLOBALS['mock_network_plugins'] = ['wm-dora-ict-register/wm-dora-ict-register.php'];
$GLOBALS['mock_dbdelta_throw'] = true;
$threw_init = false;
try {
    do_action('wp_initialize_site', (object) ['blog_id' => 4]);
} catch (RuntimeException $e) {
    $threw_init = true;
}
assert_test('wp_initialize_site restores current blog via try/finally when activation throws', $threw_init && 1 === $GLOBALS['mock_current_blog_id'] && [] === $GLOBALS['mock_blog_stack']);

$db = reset_db(false);
$GLOBALS['mock_multisite'] = true;
$GLOBALS['mock_sites'] = [1, 2];
$GLOBALS['mock_dbdelta_throw'] = true;
$threw_activate = false;
try {
    Schema::activate(true);
} catch (RuntimeException $e) {
    $threw_activate = true;
}
assert_test('Schema::activate restores current blog via try/finally when activation throws', $threw_activate && 1 === $GLOBALS['mock_current_blog_id'] && [] === $GLOBALS['mock_blog_stack']);

$GLOBALS['mock_multisite'] = false;
$GLOBALS['mock_network_plugins'] = [];
$GLOBALS['mock_dbdelta_throw'] = false;

echo "\n==============================================================================\n";
// The skipped count is printed even when it is zero: a run that skipped something has to say so on
// the summary line, or the difference between environments is only visible by reading every line.
echo "  TEST SUITE RESULTS: {$GLOBALS['passed_count']} PASSED | {$GLOBALS['failed_count']} FAILED"
    . ' | ' . ($GLOBALS['skipped_count'] ?? 0) . " SKIPPED\n";
echo "==============================================================================\n\n";

exit(0 === $GLOBALS['failed_count'] ? 0 : 1);
