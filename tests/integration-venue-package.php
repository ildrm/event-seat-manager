<?php
declare(strict_types=1);

/**
 * Disposable WordPress package-upgrade acceptance. Run the same file twice:
 *
 * PESM_RUN_INTEGRATION=1 PESM_PACKAGE_PHASE=before wp --user=1 eval-file tests/integration-venue-package.php
 * Install/activate the release ZIP using WP-CLI, then:
 * PESM_RUN_INTEGRATION=1 PESM_PACKAGE_PHASE=after wp --user=1 eval-file tests/integration-venue-package.php
 *
 * The baseline lives only in this site's options table. Output contains counts,
 * IDs and SHA-256 fingerprints, never buyer data, QR payloads or access tokens.
 * This script does not install a package or claim production load acceptance.
 */
if (!defined('ABSPATH')) { echo "SKIP: run in disposable local WordPress.\n"; return; }
if (getenv('PESM_RUN_INTEGRATION') !== '1' || wp_get_environment_type() !== 'local' ||
    !defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Requires explicitly enabled disposable local WP-CLI integration.');
}
$phase = getenv('PESM_PACKAGE_PHASE');
if (!in_array($phase, ['before', 'after'], true)) { throw new RuntimeException('Set PESM_PACKAGE_PHASE=before or after.'); }
if (!defined('PESM_FILE') || !function_exists('wc_get_order')) { throw new RuntimeException('Activate Event Seat Manager and WooCommerce first.'); }
if (!current_user_can('manage_options')) { throw new RuntimeException('Run with an administrator using --user.'); }
// Local fixture creation cannot deliver email, SMS, webhooks or gateway requests.
add_filter('pre_wp_mail', static fn() => true, 99);
add_filter('pre_http_request', static fn() => new WP_Error('package_remote_disabled', 'Remote requests disabled in package acceptance.'), 99);
add_filter('option_pesm_notifications_enabled', static fn() => 'no');
add_filter('option_woocommerce_calc_taxes', static fn() => 'no');
add_filter('option_pesm_entry_before_seconds', static fn() => 7200);

global $wpdb;
$prefix = $wpdb->prefix . 'pesm_';
$baselineOption = 'pesm_venue_package_baseline';
$checks = [];
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('Package acceptance failed: ' . $label); }
    $checks[] = $label;
};
$identifier = static fn(string $value): string => '`' . str_replace('`', '``', $value) . '`';
$normalize = static function ($value) use (&$normalize) {
    if ($value instanceof DateTimeInterface) { return gmdate('Y-m-d\TH:i:s\Z', $value->getTimestamp()); }
    if (is_object($value) && method_exists($value, 'get_data')) { return $normalize($value->get_data()); }
    if (is_object($value)) { throw new RuntimeException('Unsupported object in canonical order snapshot.'); }
    if (is_array($value)) {
        if (!array_is_list($value)) { ksort($value, SORT_STRING); }
        foreach ($value as $key => $item) { $value[$key] = $normalize($item); }
    }
    return $value;
};
$encode = static fn($value): string => json_encode($normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$digest = static fn($value): string => hash('sha256', $encode($value));
$newTables = ['venue_layouts', 'venue_sections', 'venue_places', 'venue_groups', 'venue_tiles', 'venue_objects',
    'venue_commands', 'venue_jobs', 'venue_import_chunks', 'allocation_sessions', 'allocation_sections',
    'allocation_holds', 'allocation_lines', 'allocation_orders', 'allocation_tickets', 'allocation_checkins', 'allocation_requests'];
$canonicalTables = ['centers', 'center_users', 'halls', 'layout_versions', 'seats', 'layout_objects', 'events', 'sessions',
    'session_seats', 'session_capacity', 'holds', 'hold_items', 'hold_mutations', 'order_links', 'tickets', 'checkins',
    'capacity_holds', 'capacity_order_links', 'capacity_tickets', 'capacity_checkins', 'ticket_attendee_changes',
    'ticket_seat_transfers', 'ticket_owners', 'ticket_ownership_transfers', 'ticket_bundles', 'ticket_bundle_items',
    'discount_rules', 'discount_claims'];
$exists = static function (string $suffix) use ($wpdb, $prefix): bool {
    return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix . $suffix))) === $prefix . $suffix;
};
$snapshotTable = static function (string $suffix, ?array $original = null) use ($wpdb, $prefix, $identifier, $digest): array {
    $table = $identifier($prefix . $suffix);
    $columns = $original['columns'] ?? array_column($wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A), 'Field');
    $primary = $original['primary'] ?? [];
    if (!$primary) {
        $indexes = $wpdb->get_results("SHOW KEYS FROM {$table} WHERE Key_name='PRIMARY'", ARRAY_A);
        usort($indexes, static fn(array $a, array $b): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);
        $primary = array_column($indexes, 'Column_name');
    }
    if (!$columns || !$primary) { throw new RuntimeException('Canonical table lacks columns or primary key: ' . $suffix); }
    $projection = implode(',', array_map($identifier, $columns));
    $ordering = implode(',', array_map($identifier, $primary));
    $hashes = [];
    for ($offset = 0; ; $offset += 500) {
        $rows = $wpdb->get_results("SELECT {$projection} FROM {$table} ORDER BY {$ordering} LIMIT 500 OFFSET {$offset}", ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error !== '') { throw new RuntimeException('Cannot read canonical table: ' . $suffix); }
        foreach ($rows as $row) {
            $key = [];
            foreach ($primary as $field) { $key[$field] = $row[$field]; }
            $hashes[$digest($key)] = $digest($row);
        }
        if (count($rows) < 500) { break; }
    }
    ksort($hashes, SORT_STRING);
    return ['columns' => $columns, 'primary' => $primary, 'count' => count($hashes), 'rows' => $hashes, 'sha256' => $digest($hashes)];
};
$snapshotOrder = static function (int $orderId) use ($digest): array {
    $order = wc_get_order($orderId);
    if (!$order) { throw new RuntimeException('A historical Woo order is missing.'); }
    $data = $order->get_data();
    $items = [];
    foreach ($order->get_items(['line_item', 'fee', 'shipping', 'tax', 'coupon']) as $id => $item) { $items[(string) $id] = $item->get_data(); }
    $financial = [];
    foreach (['_pesm_unit_price_minor', '_pesm_total_minor', '_pesm_price_decimals', '_pesm_currency',
        '_pesm_attendees_v1', '_pesm_discount_rule_id', '_pesm_discount_minor', '_pesm_financial_snapshot'] as $name) {
        $financial[$name] = $order->get_meta($name);
    }
    return ['id' => $orderId, 'sha256' => $digest(['order' => $data, 'items' => $items]),
        'financial_sha256' => $digest($financial), 'currency' => $order->get_currency(),
        'total' => (string) $order->get_total(), 'status' => $order->get_status(), 'item_count' => count($items)];
};
$pluginInfo = static function (): array {
    $root = realpath(dirname(PESM_FILE));
    $plugins = realpath(WP_PLUGIN_DIR);
    return ['version' => defined('PESM_VERSION') ? PESM_VERSION : '', 'main_sha256' => hash_file('sha256', PESM_FILE),
        'migrator_sha256' => hash_file('sha256', dirname(PESM_FILE) . '/src/Database/Migrator.php'),
        'installed_copy' => $root !== false && $plugins !== false && str_starts_with($root . '/', $plugins . '/')];
};
$report = ['phase' => $phase, 'passed' => false, 'schema' => (int) get_option('pesm_schema_version', 0),
    'plugin' => $pluginInfo(), 'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : '',
    'hpos_enabled' => class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()];
$assert($report['plugin']['installed_copy'], 'plugin code is loaded from an installed copy inside wp-content/plugins');

if ($phase === 'before') {
    $assert($report['schema'] === 18, 'baseline is recorded at schema 18');
    foreach ($newTables as $table) { $assert(!$exists($table), 'v2 table absent before upgrade: ' . $table); }
    $assert(!is_array(get_option($baselineOption, null)), 'baseline has not already been recorded');
    // Always seed an independent pair of free/paid legacy orders. Existing site
    // rows are included too; no historical fixture is edited by this test.
    $actor = get_current_user_id();
    $center = \PESM\Domain\Hall\HallService::createCenter('Package schema18 ' . bin2hex(random_bytes(5)));
    $hall = \PESM\Domain\Hall\HallService::createHall($center, 'Package historical v1 hall', 2, 2);
    $event = \PESM\Domain\Hall\HallService::createEvent($center, 'Package historical event');
    \PESM\Domain\Event\LifecycleService::change('event', $event, 'published', $actor);
    $sessions = []; $orders = [];
    foreach ([0, 12345] as $price) {
        $session = \PESM\Domain\Hall\HallService::createSession($event, $hall, gmdate('Y-m-d H:i:s', time() + 86400),
            gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s', time() + 7200), $price, 'seated');
        \PESM\Domain\Event\LifecycleService::change('session', $session, 'published', $actor);
        $seat = (int) $wpdb->get_var($wpdb->prepare("SELECT seat_id FROM {$prefix}session_seats WHERE session_id=%d ORDER BY seat_id LIMIT 1", $session));
        $hold = (new \PESM\Domain\Booking\HoldService())->acquire($session, [$seat], bin2hex(random_bytes(24)));
        $prepared = (new \PESM\Domain\Checkout\CheckoutService())->prepare($session, $hold['token'],
            ['first_name' => 'Package', 'last_name' => 'Fixture', 'phone' => '09123456789', 'email' => 'package@example.test']);
        $orderId = (int) $prepared['order_id'];
        if ($price > 0) {
            $order = wc_get_order($orderId); $order->payment_complete('package-local-fixture');
            $issued = (new \PESM\Domain\Ticket\TicketService())->confirmOrder($orderId);
            $assert(in_array($issued, ['issued', 'already_issued'], true), 'paid schema18 fixture issues through verified local Woo callback');
        }
        $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}tickets WHERE order_id=%d", $orderId)) === 1,
            ($price === 0 ? 'free' : 'paid') . ' schema18 fixture has an issued legacy ticket');
        $sessions[] = $session; $orders[] = $orderId;
    }
    $tables = [];
    foreach ($canonicalTables as $table) { if ($exists($table)) { $tables[$table] = $snapshotTable($table); } }
    foreach (['centers', 'halls', 'sessions', 'layout_versions', 'seats', 'session_seats', 'holds', 'order_links', 'tickets'] as $table) {
        $assert(($tables[$table]['count'] ?? 0) > 0, 'nonempty baseline canonical rows: ' . $table);
    }
    $orderIds = [];
    foreach (['order_links', 'capacity_order_links', 'tickets', 'capacity_tickets'] as $table) {
        if ($exists($table)) { foreach ($wpdb->get_col("SELECT DISTINCT order_id FROM {$prefix}{$table} WHERE order_id>0") as $id) { $orderIds[(int) $id] = true; } }
    }
    ksort($orderIds, SORT_NUMERIC); $orderSnapshots = [];
    foreach (array_keys($orderIds) as $id) { $orderSnapshots[$id] = $snapshotOrder($id); }
    $fixture = ['center_id' => $center, 'hall_id' => $hall, 'event_id' => $event, 'session_ids' => $sessions, 'order_ids' => $orders];
    $baseline = ['schema' => 18, 'plugin' => $report['plugin'], 'tables' => $tables, 'orders' => $orderSnapshots, 'fixture' => $fixture];
    $assert(update_option($baselineOption, $baseline, false), 'canonical baseline persists in this disposable site');
    $report['fixture'] = $fixture;
    foreach ($tables as $table => $state) { $report['canonical_rows'][$table] = ['count' => $state['count'], 'sha256' => $state['sha256']]; }
    $report['orders'] = array_values($orderSnapshots);
    $report['baseline_sha256'] = $digest($baseline);
} else {
    $baseline = get_option($baselineOption, null);
    $assert(is_array($baseline) && ($baseline['schema'] ?? 0) === 18, 'schema18 baseline is available in the same site');
    $assert($report['schema'] === 19 && \PESM\Database\Migrator::VERSION === 19, 'installed package migrated schema18 to schema19');
    $assert(!get_option('pesm_schema_error'), 'migration reports no schema error');
    $assert($report['plugin']['migrator_sha256'] !== $baseline['plugin']['migrator_sha256'], 'installed migrator differs from the schema18 source');
    $assert(realpath((new ReflectionClass(\PESM\Domain\Allocation\SessionService::class))->getFileName()) ===
        realpath(dirname(PESM_FILE) . '/src/Domain/Allocation/SessionService.php'), 'allocated classes load from the installed package');
    foreach ($baseline['tables'] as $table => $original) {
        $assert($exists($table), 'historical table remains present: ' . $table);
        $current = $snapshotTable($table, $original);
        $preserved = array_intersect_key($current['rows'], $original['rows']);
        $assert(count($preserved) === $original['count'] && $preserved === $original['rows'], 'all original columns and row hashes preserved: ' . $table);
        $report['canonical_rows'][$table] = ['before' => $original['count'], 'after' => $current['count'],
            'preserved_sha256' => $digest($preserved), 'added_rows' => $current['count'] - $original['count']];
    }
    foreach ($baseline['orders'] as $id => $original) {
        $current = $snapshotOrder((int) $id);
        $assert($current === $original, 'Woo order, line identities and immutable financial metadata preserved: ' . (int) $id);
        $report['orders'][] = $current;
    }
    foreach (array_merge(\PESM\Database\VenueSchema::uniqueIndexes(), \PESM\Database\AllocationSchema::uniqueIndexes()) as $table => $index) {
        $assert($exists($table), 'new table installed: ' . $table);
        $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $prefix . $table));
        $indexCount = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME=%s AND NON_UNIQUE=0', $prefix . $table, $index));
        $assert(strcasecmp((string) $engine, 'InnoDB') === 0 && $indexCount > 0, 'new table uses InnoDB and expected unique key: ' . $table);
        $report['new_tables'][$table] = ['engine' => $engine, 'unique_index' => $index];
    }
    $default = $wpdb->get_var($wpdb->prepare('SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s', $prefix . 'layout_versions', 'format'));
    $assert(trim((string) $default, "'\"") === 'pesm.layout.v1', 'layout_versions.format defaults to pesm.layout.v1');
    $legacyLayouts = $wpdb->get_results("SELECT id,format FROM {$prefix}layout_versions ORDER BY id", ARRAY_A);
    foreach ($legacyLayouts as $layout) { $assert($layout['format'] === 'pesm.layout.v1', 'historical layout remains v1: ' . (int) $layout['id']); }
    $report['legacy_layout_count'] = count($legacyLayouts);
    $report['baseline_sha256'] = $digest($baseline);
    // Verify new functionality only AFTER comparing the entire old baseline.
    // This constant is effective solely in WP-CLI and never opens the site's
    // operational creation gate or records a false load-acceptance report.
    if (!defined('PESM_VENUE_ACCEPTANCE_TEST')) { define('PESM_VENUE_ACCEPTANCE_TEST', true); }
    $actor = get_current_user_id();
    $runJob = static function (int $id): array {
        for ($batch = 0; $batch < 100; $batch++) {
            \PESM\Domain\Venue\JobService::run($id);
            $state = \PESM\Domain\Venue\JobService::status($id);
            if ($state['status'] === 'complete') { return $state; }
            if ($state['status'] === 'failed') { throw new RuntimeException('Package allocated smoke job failed.'); }
        }
        throw new RuntimeException('Package allocated smoke job exceeded its bounded batch budget.');
    };
    $acceptanceBefore = get_option('pesm_venue_acceptance', null);
    $center = \PESM\Domain\Hall\HallService::createCenter('Package v2 smoke ' . bin2hex(random_bytes(5)));
    $hall = \PESM\Domain\Hall\HallService::createHall($center, 'Package v2 smoke hall', 1, 1);
    $defaultFormat = $wpdb->get_var($wpdb->prepare("SELECT l.format FROM {$prefix}halls h JOIN {$prefix}layout_versions l ON l.id=h.current_layout_id WHERE h.id=%d", $hall));
    $assert($defaultFormat === 'pesm.layout.v1', 'new legacy hall insert uses the v1 database default');
    $generation = \PESM\Domain\Venue\JobService::create($center, $hall, 'generate',
        ['preset_id' => 'restaurant_round_banquet', 'parameters' => ['capacity' => 8, 'group_size' => 4]], $actor);
    $generated = $runJob((int) $generation['id']); $layoutId = (int) $generated['layout_id'];
    $publish = \PESM\Domain\Venue\JobService::create($center, $hall, 'publish', ['layout_id' => $layoutId], $actor);
    $runJob((int) $publish['id']);
    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT seat_count FROM {$prefix}layout_versions WHERE id=%d AND format='pesm.layout.v2'", $layoutId)) === 8,
        'installed package generates and publishes exact eight-place v2 geometry');
    $event = \PESM\Domain\Hall\HallService::createEvent($center, 'Package v2 smoke event');
    \PESM\Domain\Event\LifecycleService::change('event', $event, 'published', $actor);
    $session = \PESM\Domain\Hall\HallService::createSession($event, $hall, gmdate('Y-m-d H:i:s', time() + 3600),
        gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s', time() + 1800), 0, 'allocated');
    \PESM\Domain\Allocation\SessionService::configure($session, ['ticket_mode' => 'group', 'group_entry_policy' => 'together']);
    $provision = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}venue_jobs WHERE session_id=%d AND kind='provision' ORDER BY id DESC LIMIT 1", $session));
    $runJob($provision); \PESM\Domain\Event\LifecycleService::change('session', $session, 'published', $actor);
    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}session_seats WHERE session_id=%d", $session)) === 8,
        'installed package provisions shared seat-backed allocated inventory');
    $group = $wpdb->get_row($wpdb->prepare("SELECT group_code,capacity FROM {$prefix}venue_groups WHERE layout_id=%d ORDER BY id LIMIT 1", $layoutId));
    $assert($group && (int) $group->capacity === 4, 'smoke group has four explicit member places');
    $hold = \PESM\Domain\Allocation\ReservationService::acquire($session, [['type' => 'group', 'id' => $group->group_code]], bin2hex(random_bytes(24)));
    $order = \PESM\Domain\Allocation\CheckoutService::prepare($session, $hold['hold_token'],
        ['first_name' => 'Package', 'last_name' => 'Smoke', 'phone' => '09123456789', 'email' => 'package-smoke@example.test']);
    $tickets = \PESM\Domain\Allocation\CheckoutService::tickets((int) $order['order_id'], $order['guest_access_token']);
    $line = $tickets['lines'][0];
    $assert(!$order['payment_required'] && count($line['tickets']) === 0 && $line['group_ticket']['capacity'] === 4,
        'installed package creates a real free Woo order with one exclusive group credential');
    $scanKey = bin2hex(random_bytes(24));
    $scan = \PESM\Domain\Allocation\GateService::scan($line['group_ticket']['qr_payload'], $actor, 'package-smoke', $scanKey, 1);
    $replay = \PESM\Domain\Allocation\GateService::scan($line['group_ticket']['qr_payload'], $actor, 'package-smoke', $scanKey, 1);
    $assert($scan['admitted'] === 4 && $scan['remaining'] === 0 && $replay['idempotent_replay'], 'packaged together-entry scan and idempotent retry admit exactly four');
    $assert(get_option('pesm_venue_acceptance', null) === $acceptanceBefore, 'test-only smoke leaves operational acceptance unchanged');
    $report['smoke'] = ['center_id' => $center, 'hall_id' => $hall, 'layout_id' => $layoutId, 'event_id' => $event,
        'session_id' => $session, 'order_id' => (int) $order['order_id'], 'capacity' => 8, 'admitted' => 4];
}
$report['passed'] = true; $report['checks'] = count($checks); $report['scenarios'] = $checks;
update_option('pesm_venue_package_' . $phase . '_results', $report, false);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
