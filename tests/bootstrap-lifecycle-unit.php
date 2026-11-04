<?php
declare(strict_types=1);

namespace PESM\Database {
    final class Migrator {
        public const VERSION = 18;
        public static function run(): void { $GLOBALS['migration_runs']++; }
    }
}
namespace PESM\Security {
    final class Capabilities { public static function install(): void {} }
}
namespace PESM\Support {
    final class I18n { public static function register(): void { $GLOBALS['locale_ready'] = true; } }
}
namespace PESM\Integrations {
    final class ElementorWidgets { public static function register(): void {} }
}
namespace {
    define('ABSPATH', '/disposable-wordpress/');
    $actions = [];
    $options = ['pesm_schema_version'=>18, 'pesm_capabilities_version'=>3];
    $migration_runs = 0;
    $scheduled = [];
    $locale_ready = false;
    $checks = 0;
    function plugin_dir_path(string $path): string { return dirname($path) . '/'; }
    function plugin_dir_url(string $path): string { return 'https://example.test/plugins/event-seat-manager/'; }
    function plugin_basename(string $path): string { return 'event-seat-manager/event-seat-manager.php'; }
    function register_activation_hook(string $file, callable $callback): void { $GLOBALS['activate'] = $callback; }
    function register_deactivation_hook(string $file, callable $callback): void { $GLOBALS['deactivate'] = $callback; }
    function add_action(string $hook, $callback, int $priority = 10): void { $GLOBALS['actions'][$hook][$priority][] = $callback; }
    function add_filter(string $hook, $callback, int $priority = 10): void { add_action($hook, $callback, $priority); }
    function get_option(string $key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
    function update_option(string $key, $value, $autoload = null): void { $GLOBALS['options'][$key] = $value; }
    function is_admin(): bool { return false; }
    function current_user_can(string $capability): bool { return true; }
    function load_plugin_textdomain(...$arguments): void { $GLOBALS['domain_loaded'] = true; }
    function __(string $message, string $domain): string { return $message; }
    function esc_html__(string $message, string $domain): string { return htmlspecialchars($message); }
    function wp_die(string $message): void { throw new RuntimeException($message); }
    function wp_next_scheduled(string $hook): bool { return false; }
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook): void { $GLOBALS['scheduled'][] = $hook; }
    function wp_clear_scheduled_hook(string $hook): void { $GLOBALS['cleared'][] = $hook; }
    function check(bool $condition, string $message): void {
        $GLOBALS['checks']++;
        if (!$condition) { throw new RuntimeException($message); }
    }
    function throws(callable $callback, string $message): void {
        try { $callback(); } catch (RuntimeException $error) { check(true, $message); return; }
        throw new RuntimeException($message);
    }

    if (($argv[1] ?? '') === 'duplicate') { define('PESM_FILE', '/old/plugin.php'); }
    require __DIR__ . '/../event-seat-manager.php';
    if (($argv[1] ?? '') === 'duplicate') {
        check(!defined('PESM_VERSION'), 'A duplicate copy must not bootstrap domain code');
        check(!isset($actions['plugins_loaded']), 'A duplicate copy must not register booking hooks');
        throws($activate, 'Duplicate activation must stop with a useful error');
        echo "PASS: duplicate plugin activation protection\n";
        exit;
    }

    $loaded = $actions['plugins_loaded'][10][0];
    $actions = [];
    $loaded();
    check(isset($actions['rest_api_init']), 'Healthy schema must expose the REST controller');
    check($migration_runs === 0, 'Healthy requests must not run schema DDL');
    check(!$locale_ready && !$scheduled, 'Locale loading and cron scheduling wait for init');
    foreach ([0, 1, 2] as $priority) {
        foreach ($actions['init'][$priority] ?? [] as $callback) { $callback(); }
    }
    check($locale_ready, 'Locale runtime must register before rendering');
    check(count($scheduled) === 2, 'Both recurring maintenance hooks must be scheduled');
    $deactivate();
    check(count($GLOBALS['cleared']) === 2, 'Deactivation must clear both maintenance hooks');
    check($options['pesm_schema_version'] === 18, 'Deactivation must retain the database version');

    $actions = [];
    $options['pesm_schema_version'] = 17;
    $options['pesm_schema_error'] = 'Missing unique index';
    $loaded();
    check($migration_runs === 1, 'An outdated schema must attempt upgrade');
    check(!isset($actions['rest_api_init']), 'Failed migration must not register mutation routes');
    check(!isset($actions['init'][2]), 'Failed migration must not schedule maintenance');
    throws(static fn () => $activate(false), 'Failed activation must stop rather than report success');
    throws(static fn () => $activate(true), 'Network activation must stop explicitly');

    $status = 0;
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' duplicate', $status);
    check($status === 0, 'Duplicate copy subprocess must pass');
    echo "PASS: {$checks} lifecycle and migration safety checks\n";
}
