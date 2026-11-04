<?php

declare(strict_types=1);

/**
 * Destructive fixture creation: disposable LOCAL WordPress + WooCommerce only.
 * PESM_RUN_INTEGRATION=1 wp eval-file tests/integration-wp.php
 * Worker modes use the fixture option created by the default scenario.
 * This script never contacts a payment gateway or sends actual email.
 */
if (!defined('ABSPATH')) {
    echo "SKIP: run inside a disposable local WordPress installation.\n";
    return;
}
if (getenv('PESM_RUN_INTEGRATION') !== '1' || wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Requires PESM_RUN_INTEGRATION=1 and WP_ENVIRONMENT_TYPE=local.');
}
if (!class_exists(\PESM\Domain\Hall\HallService::class) || !function_exists('wc_create_order')) {
    throw new RuntimeException('Activate Event Seat Manager and WooCommerce first.');
}

use PESM\Domain\Booking\CapacityService;
use PESM\Domain\Booking\HoldException;
use PESM\Domain\Booking\HoldService;
use PESM\Domain\Checkout\CheckoutService;
use PESM\Domain\Event\LifecycleService;
use PESM\Domain\Gate\CheckinService;
use PESM\Domain\Hall\HallService;
use PESM\Domain\Reporting\AttendanceReport;
use PESM\Domain\Reporting\SeatStatusReport;
use PESM\Domain\Ticket\TicketService;
use PESM\Domain\Ticket\TicketToken;

add_filter('pre_wp_mail', static fn(): bool => true);
add_filter('pre_http_request', static fn(): WP_Error => new WP_Error('integration_network_disabled', 'No remote requests during integration tests.'), 99);
global $wpdb;
$p = $wpdb->prefix . 'pesm_';
$_SERVER['REMOTE_ADDR'] = '127.0.0.' . random_int(2, 250);
$mode = $args[0] ?? 'all';
$state = get_option('pesm_integration_fixture', []);

if ($mode === 'storage-on' || $mode === 'storage-off') {
    $synchronizer = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class);
    $synchronizer->create_database_tables();
    update_option('woocommerce_custom_orders_table_data_sync_enabled', 'yes');
    for ($batch = 0; $batch < 100; ++$batch) {
        $ids = $synchronizer->get_next_batch_to_process(100);
        if (!$ids) { break; }
        $synchronizer->process_batch($ids);
    }
    update_option('woocommerce_custom_orders_table_enabled', $mode === 'storage-on' ? 'yes' : 'no');
    echo wp_json_encode(['mode' => $mode, 'hpos' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
        'pending_sync' => $synchronizer->has_orders_pending_sync()]) . "\n";
    return;
}

if ($mode === 'prepare-upgrade-race') {
    foreach (['ticket_bundle_items', 'ticket_bundles'] as $suffix) {
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}{$suffix}") !== 0) {
            throw new RuntimeException('Upgrade fixture requires empty bundle tables.');
        }
    }
    $wpdb->query("DROP TABLE {$p}ticket_bundle_items, {$p}ticket_bundles");
    update_option('pesm_schema_version', 17, false);
    echo "{\"downgraded_schema_marker\":17}\n";
    return;
}

if ($mode === 'browser-fixture') {
    wp_set_current_user(1);
    $pages = [];
    foreach (['seat-map' => '[pesm_seat_map session="' . $state['free_session'] . '"]',
        'general' => '[pesm_event id="' . $state['event'] . '"]',
        'tickets' => '[pesm_my_tickets]'] as $slug => $shortcode) {
        $page = get_page_by_path('integration-' . $slug);
        $id = wp_insert_post(['ID' => $page ? $page->ID : 0, 'post_type' => 'page', 'post_status' => 'publish',
            'post_name' => 'integration-' . $slug, 'post_title' => 'Integration ' . $slug, 'post_content' => $shortcode]);
        $pages[$slug] = ['id' => $id, 'url' => get_permalink($id)];
    }
    echo wp_json_encode(['center' => $state['center'], 'session' => $state['free_session'], 'event' => $state['event'], 'pages' => $pages], JSON_PRETTY_PRINT) . "\n";
    return;
}

if (str_starts_with($mode, 'worker-')) {
    if (!$state) { throw new RuntimeException('Run the main scenario before workers.'); }
    wp_set_current_user(0);
    try {
        if ($mode === 'worker-seat') {
            $result = (new HoldService())->acquire($state['race_session'], [$state['race_seat']], bin2hex(random_bytes(24)));
            echo wp_json_encode(['ok' => true, 'hold_id' => $result['hold_id']]) . "\n";
        } elseif ($mode === 'worker-capacity') {
            $result = (new CapacityService())->acquire($state['race_capacity_session'], 1, bin2hex(random_bytes(24)));
            echo wp_json_encode(['ok' => true, 'hold_id' => $result['hold_id']]) . "\n";
        } elseif ($mode === 'worker-gate') {
            $result = (new CheckinService())->scan($state['race_qr'], $state['operator'], 'integration-race');
            echo wp_json_encode(['ok' => true, 'checked_in' => $result['checked_in']]) . "\n";
        } elseif ($mode === 'worker-upgrade') {
            \PESM\Database\Migrator::run();
            echo wp_json_encode(['ok' => (int) get_option('pesm_schema_version') === 18 && !get_option('pesm_schema_error')]) . "\n";
        } else { throw new RuntimeException('Unknown worker mode.'); }
    } catch (HoldException $error) {
        echo wp_json_encode(['ok' => false, 'error' => $error->errorCode]) . "\n";
    }
    return;
}

$checks = [];
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
    $checks[] = $label;
};
$reject = static function (callable $operation, string $code, string $label) use ($assert): void {
    try { $operation(); }
    catch (HoldException $error) { $assert($error->errorCode === $code, $label . ' [' . $error->errorCode . ']'); return; }
    throw new RuntimeException('FAIL: ' . $label . ' was accepted');
};

if ($mode === 'network-activation') {
    $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}tickets");
    $handlerFilter = static fn(): Closure => static function (): never { throw new RuntimeException('network_activation_blocked'); };
    add_filter('wp_die_handler', $handlerFilter);
    try {
        do_action('activate_' . plugin_basename(PESM_FILE), true);
        throw new RuntimeException('Network activation was unexpectedly accepted.');
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'network_activation_blocked', 'Network activation explicitly rejected by actual registered callback');
    } finally { remove_filter('wp_die_handler', $handlerFilter); }
    $assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}tickets") === $before, 'Rejected network activation preserves issued tickets');
    echo wp_json_encode(['mode' => $mode, 'passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
    return;
}

if ($mode === 'verify-upgrade') {
    $assert((int) get_option('pesm_schema_version') === 18 && !get_option('pesm_schema_error'), 'Concurrent upgrade reaches schema18 without error');
    $names = $wpdb->get_col($wpdb->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s', $wpdb->esc_like($p) . '%'));
    $assert(count($names) === 37, 'Concurrent upgrade recreates both new bundle tables');
    $database = (string) $wpdb->get_var('SELECT DATABASE()');
    $lock = 'esm_migrate_' . substr(hash('sha256', $database . '|' . $wpdb->prefix), 0, 48);
    $assert((int) $wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)', $lock)) === 1, 'Migration advisory lock is released after all workers');
    $assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}tickets") >= $state['ticket_count'], 'Concurrent schema upgrade preserves issued tickets');
    echo wp_json_encode(['mode' => $mode, 'passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
    return;
}

if ($mode === 'retention') {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $counts = [];
    $tableNames = $wpdb->get_col($wpdb->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s', $wpdb->esc_like($p) . '%'));
    foreach ($tableNames as $name) { $counts[$name] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$name}`"); }
    $plugin = plugin_basename(PESM_FILE);
    deactivate_plugins($plugin);
    $assert(!is_plugin_active($plugin), 'Actual deactivation changes plugin state');
    $assert(!wp_next_scheduled('pesm_hourly_maintenance') && !wp_next_scheduled('pesm_process_notifications'), 'Deactivation removes maintenance schedules');
    foreach ($counts as $name => $count) { $assert((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$name}`") === $count, 'Deactivation retains ' . $name); }
    $error = activate_plugin($plugin);
    $assert(!is_wp_error($error) && is_plugin_active($plugin), 'Reactivation succeeds after retention check');
    $assert(!get_option('pesm_schema_error'), 'Reactivation validates schema');
    echo wp_json_encode(['mode' => $mode, 'passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
    return;
}

if ($mode === 'representative-storage') {
    wp_set_current_user(1);
    $now = time();
    $ids = [];
    foreach ([0, 750] as $price) {
        $id = HallService::createSession($state['event'], $state['hall'], gmdate('Y-m-d H:i:s', $now + 172800),
            gmdate('Y-m-d H:i:s', $now - 3600), gmdate('Y-m-d H:i:s', $now + 86400), $price);
        LifecycleService::change('session', $id, 'published', 1);
        $seat = (int) $wpdb->get_var($wpdb->prepare("SELECT seat_id FROM {$p}session_seats WHERE session_id=%d ORDER BY seat_id LIMIT 1", $id));
        wp_set_current_user(0);
        $hold = (new HoldService())->acquire($id, [$seat], bin2hex(random_bytes(24)));
        $result = (new CheckoutService())->prepare($id, $hold['token'], ['first_name' => 'Storage', 'last_name' => 'Guest',
            'phone' => '09121112222', 'email' => 'storage@example.invalid']);
        $order = wc_get_order($result['order_id']);
        $assert($order->get_customer_id() === 0, 'Storage-mode order remains a guest order');
        $assert((float) $order->get_total() === (float) ($price / 100), 'Storage-mode order preserves server price');
        if ($price > 0) { $order->payment_complete('integration-storage-not-a-bank-transaction'); }
        $view = (new TicketService())->forGuest($result['order_id'], $result['guest_access_token']);
        $assert(count($view['tickets']) === 1, 'Storage-mode checkout issues one ticket after valid completion');
        $assert((new TicketService())->confirmOrder($result['order_id'], $price === 0) === 'already_issued', 'Storage-mode callback replay is idempotent');
        $ids[] = $result['order_id'];
    }
    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}order_links WHERE order_id IN (%d,%d)", ...$ids)) === 2, 'Both storage-mode orders retain inventory mappings');
    $hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    if ($hpos) {
        $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id IN (%d,%d)", ...$ids)) === 2, 'HPOS writes both orders to authoritative custom order tables');
    }
    echo wp_json_encode(['mode' => $mode, 'passed' => count($checks), 'hpos_enabled' => $hpos,
        'order_data_store' => $order->get_data_store()->get_current_class_name(), 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
    return;
}

if ($mode === 'verify-races') {
    $seatHolds = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}holds WHERE session_id=%d AND status='active'", $state['race_session']));
    $assert($seatHolds === 1, 'Twenty seat contenders yield one active hold');
    $capacity = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}session_capacity WHERE session_id=%d", $state['race_capacity_session']));
    $assert((int) $capacity->held_units === 3, 'Twenty capacity contenders allocate exactly three units');
    $assert((int) $capacity->held_units + (int) $capacity->pending_units + (int) $capacity->sold_units <= (int) $capacity->total_capacity, 'Concurrent general admission never oversells');
    $assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}checkins WHERE ticket_id=%d", $state['race_ticket'])) === 1, 'Concurrent scanners commit one check-in');
    echo wp_json_encode(['mode' => $mode, 'passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
    return;
}

$started = microtime(true);
$assert((int) get_option('pesm_schema_version') === \PESM\Database\Migrator::VERSION, 'Schema version matches migrator');
$assert(!get_option('pesm_schema_error'), 'Migration has no recorded error');
$tables = $wpdb->get_results($wpdb->prepare('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s', $wpdb->esc_like($p) . '%'));
$assert(count($tables) === 37, 'All 37 plugin tables created');
$assert(count(array_filter($tables, static fn(object $table): bool => strtoupper($table->ENGINE) !== 'INNODB')) === 0, 'All plugin tables use InnoDB');

update_option('woocommerce_currency', 'USD');
update_option('woocommerce_price_num_decimals', 2);
update_option('woocommerce_calc_taxes', 'no');
update_option('pesm_phone_event_limit', 100);
update_option('pesm_entry_before_seconds', 259200);
update_option('pesm_enable_paid_general_preview', 'yes');
update_option('pesm_discount_preview_enabled', 'no');
wp_set_current_user(1);
$suffix = bin2hex(random_bytes(5));
$center = HallService::createCenter('Integration A ' . $suffix);
$otherCenter = HallService::createCenter('Integration B ' . $suffix);
$hall = HallService::createHall($center, 'Integration 900 seats ' . $suffix, 15, 60);
$event = HallService::createEvent($center, 'Integration event ' . $suffix);
$otherEvent = HallService::createEvent($otherCenter, 'Other center event ' . $suffix);
LifecycleService::change('event', $event, 'published', 1);
LifecycleService::change('event', $otherEvent, 'published', 1);
$start = gmdate('Y-m-d H:i:s', time() + 172800);
$saleStart = gmdate('Y-m-d H:i:s', time() - 3600);
$saleEnd = gmdate('Y-m-d H:i:s', time() + 86400);
$session = static function (int $price = 0, string $type = 'seated', int $capacity = 0) use ($event, $hall, $start, $saleStart, $saleEnd): int {
    $id = HallService::createSession($event, $type === 'seated' ? $hall : 0, $start, $saleStart, $saleEnd, $price, $type, $capacity);
    LifecycleService::change('session', $id, 'published', 1);
    return $id;
};
$free = $session();
$paid = $session(1000);
$general = $session(0, 'general', 900);
$paidGeneral = $session(500, 'general', 10);
$raceSession = $session();
$raceCapacity = $session(0, 'general', 3);
$seatIds = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT seat_id FROM {$p}session_seats WHERE session_id=%d ORDER BY seat_id LIMIT 12", $free)));
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}session_seats WHERE session_id=%d", $free)) === 900, 'Real 15x60 session has 900 inventory rows');

$operator = wp_insert_user(['user_login' => 'esm_gate_' . $suffix, 'user_pass' => wp_generate_password(30), 'role' => 'pesm_gate_operator']);
$assert(!is_wp_error($operator), 'Gate role user created');
$wpdb->insert($p . 'center_users', ['center_id' => $center, 'user_id' => $operator]);
$wrongOperator = wp_insert_user(['user_login' => 'esm_wrong_' . $suffix, 'user_pass' => wp_generate_password(30), 'role' => 'pesm_gate_operator']);
$wpdb->insert($p . 'center_users', ['center_id' => $otherCenter, 'user_id' => $wrongOperator]);
$analyst = wp_insert_user(['user_login' => 'esm_analyst_' . $suffix, 'user_pass' => wp_generate_password(30), 'role' => 'pesm_analyst']);
$wpdb->insert($p . 'center_users', ['center_id' => $otherCenter, 'user_id' => $analyst]);

wp_set_current_user(0);
$buyer = ['first_name' => 'Integration', 'last_name' => 'Guest', 'phone' => '09123456789', 'email' => 'guest@example.invalid'];
$holds = new HoldService();
$checkout = new CheckoutService();
$tickets = new TicketService();
$key = bin2hex(random_bytes(24));
$hold = $holds->acquire($free, [$seatIds[0], $seatIds[1]], $key);
$replay = $holds->acquire($free, [$seatIds[0], $seatIds[1]], $key);
$assert($hold['hold_id'] === $replay['hold_id'] && $replay['idempotent_replay'], 'Real hold replay reuses the same reservation');
$reject(fn() => $holds->acquire($free, [$seatIds[0]], bin2hex(random_bytes(24))), 'seat_unavailable', 'Second holder cannot acquire occupied seat');
$freeOrder = $checkout->prepare($free, $hold['token'], $buyer);
$order = wc_get_order($freeOrder['order_id']);
$assert($order->get_customer_id() === 0 && $order->get_status() === 'completed' && (float) $order->get_total() === 0.0, 'Guest free order completes without account or gateway');
$view = $tickets->forGuest($freeOrder['order_id'], $freeOrder['guest_access_token']);
$assert(count($view['tickets']) === 2, 'Free seated order issues one ticket per selected seat');
$assert(count(array_unique(array_column($view['tickets'], 'qr_payload'))) === 2, 'Each seated ticket has distinct QR');
$first = $view['tickets'][0];
$assert(TicketToken::valid($first['qr_payload'], $first['public_id'], (int) $first['qr_version'], wp_salt('secure_auth')), 'Real issued QR validates');
$reject(fn() => $tickets->forGuest($freeOrder['order_id'], str_repeat('a', 64)), 'permission_denied', 'Wrong bearer token cannot view order or invoice');
$same = $checkout->prepare($free, $hold['token'], $buyer);
$assert($same['order_id'] === $freeOrder['order_id'], 'Free checkout replay does not duplicate WooCommerce order');
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}tickets WHERE order_id=%d", $freeOrder['order_id'])) === 2, 'Free checkout replay does not duplicate tickets');

$gate = new CheckinService();
$reject(fn() => $gate->scan($first['qr_payload'], (int) $wrongOperator), 'permission_denied', 'Gate operator from another center is denied');
$assert($gate->scan($first['qr_payload'], (int) $operator, 'integration')['checked_in'], 'Authorized scanner accepts valid ticket');
$reject(fn() => $gate->scan($first['qr_payload'], (int) $operator), 'already_checked_in', 'Duplicate check-in rejected');

$paidHold = $holds->acquire($paid, [$seatIds[2], $seatIds[3]], bin2hex(random_bytes(24)));
$paidOrder = $checkout->prepare($paid, $paidHold['token'], $buyer);
$order = wc_get_order($paidOrder['order_id']);
$assert($order->get_status() === 'pending' && $paidOrder['payment_required'] && (float) $order->get_total() === 20.0, 'Paid checkout uses server total and awaits payment');
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}tickets WHERE order_id=%d", $paidOrder['order_id'])) === 0, 'Unpaid order has no tickets');
$reject(fn() => $tickets->confirmOrder($paidOrder['order_id']), 'payment_unverified', 'Unpaid direct confirmation rejected');
$order->set_total('0.01'); $order->save(); $order->payment_complete('integration-not-a-bank-transaction');
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}tickets WHERE order_id=%d", $paidOrder['order_id'])) === 0, 'Tampered paid total rejected by actual payment hook');
$reject(fn() => $tickets->confirmOrder($paidOrder['order_id']), 'price_mismatch', 'Explicit confirmation rejects mismatched paid total');
$order = wc_get_order($paidOrder['order_id']); $order->set_total('20.00'); $order->save();
$assert($tickets->confirmOrder($paidOrder['order_id']) === 'issued', 'Verified payment with correct total issues tickets');
$assert($tickets->confirmOrder($paidOrder['order_id']) === 'already_issued', 'Paid callback replay remains idempotent');
$order->update_status('refunded');
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}tickets WHERE order_id=%d AND status='cancelled'", $paidOrder['order_id'])) === 2, 'Actual refunded-status hook revokes both tickets');
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}session_seats WHERE session_id=%d AND hold_id=%d AND status='sold'", $paid, $paidHold['hold_id'])) === 2, 'Refund does not silently resell sold inventory');

$lateHold = $holds->acquire($paid, [$seatIds[6]], bin2hex(random_bytes(24)));
$lateOrder = $checkout->prepare($paid, $lateHold['token'], $buyer);
$wpdb->query($wpdb->prepare("UPDATE {$p}holds SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=%d", $lateHold['hold_id']));
$wpdb->query($wpdb->prepare("UPDATE {$p}session_seats SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE session_id=%d AND seat_id=%d", $paid, $seatIds[6]));
$replacement = $holds->acquire($paid, [$seatIds[6]], bin2hex(random_bytes(24)));
wc_get_order($lateOrder['order_id'])->payment_complete('integration-late-not-a-bank-transaction');
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}tickets WHERE order_id=%d", $lateOrder['order_id'])) === 0, 'Late actual payment hook cannot issue a reassigned seat');
$assert($wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}order_links WHERE order_id=%d", $lateOrder['order_id'])) === 'review_required', 'Late payment is recorded for supervised reconciliation');
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT hold_id FROM {$p}session_seats WHERE session_id=%d AND seat_id=%d", $paid, $seatIds[6])) === $replacement['hold_id'], 'Late callback preserves the replacement owner');

$outbox = [];
add_filter('pre_wp_mail', static function (mixed $result, array $attributes) use (&$outbox): bool { $outbox[] = $attributes; return true; }, 5, 2);
$recovery = new \PESM\Domain\Guest\GuestAccessService();
$recovery->requestCode($freeOrder['order_id'], $buyer['email']);
$message = end($outbox);
preg_match('/[0-9]{8}/', $message['message'], $matches);
$code = $matches[0] ?? '';
$assert(strlen($code) === 8, 'Guest recovery code is sent only to the captured mailbox');
$reject(fn() => $recovery->verifyCode($freeOrder['order_id'], $code === '00000000' ? '11111111' : '00000000'), 'invalid_code', 'Wrong recovery OTP rejected');
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT attempts FROM {$p}guest_recovery_requests WHERE order_id=%d", $freeOrder['order_id'])) === 1, 'Failed OTP attempt commits its counter');
$recovered = $recovery->verifyCode($freeOrder['order_id'], $code);
$assert(count($tickets->forGuest($freeOrder['order_id'], $recovered['guest_access_token'])['tickets']) === 2, 'Verified mailbox recovery retrieves existing tickets');
$reject(fn() => $recovery->verifyCode($freeOrder['order_id'], $code), 'invalid_code', 'Recovery code is consumed exactly once');

update_option('pesm_discount_preview_enabled', 'yes');
$coupon = (new \PESM\Domain\Pricing\DiscountService())->create(['event_id' => $event, 'session_id' => $paid,
    'code' => 'ALLFREE' . strtoupper($suffix), 'kind' => 'percent', 'value' => 10000,
    'starts_at' => $saleStart, 'ends_at' => $saleEnd, 'max_uses' => 1], 1);
$discountHold = $holds->acquire($paid, [$seatIds[7]], bin2hex(random_bytes(24)));
$discountOrder = $checkout->prepare($paid, $discountHold['token'], $buyer + ['coupon_code' => $coupon['code']]);
$assert(!$discountOrder['payment_required'] && count($tickets->forGuest($discountOrder['order_id'], $discountOrder['guest_access_token'])['tickets']) === 1, 'Actual fully discounted paid session issues a free ticket');
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT redeemed_uses FROM {$p}discount_rules WHERE id=%d", $coupon['id'])) === 1, 'Discount quota is redeemed once after issuance');
update_option('pesm_discount_preview_enabled', 'no');

$changeHold = $holds->acquire($free, [$seatIds[8], $seatIds[9]], bin2hex(random_bytes(24)));
$changeOrder = $checkout->prepare($free, $changeHold['token'], $buyer);
$changeTicket = $tickets->forGuest($changeOrder['order_id'], $changeOrder['guest_access_token'])['tickets'][0];
$moved = (new \PESM\Domain\Ticket\SeatTransferService())->transfer((int) $changeTicket['id'], $seatIds[10], bin2hex(random_bytes(24)), 1);
$assert((int) $moved['new_seat_id'] === $seatIds[10], 'Real same-price ticket transfer changes seat atomically');
$assert($wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}session_seats WHERE session_id=%d AND seat_id=%d", $free, $seatIds[8])) === 'available', 'Transfer releases source seat');
$reject(fn() => $gate->scan($changeTicket['qr_payload'], (int) $operator), 'invalid_qr', 'Transfer rotates QR and rejects the previous QR');
$cancel = new \PESM\Domain\Ticket\FreeTicketCancellationService();
$assert($cancel->cancel((int) $changeTicket['id'], 1)['cancelled'], 'Real free cancellation revokes the transferred ticket');
$assert($cancel->cancel((int) $changeTicket['id'], 1)['idempotent_replay'], 'Free cancellation retry is idempotent');
$reject(fn() => $cancel->cancel((int) $first['id'], 1), 'ticket_not_cancelable', 'Used ticket cannot be cancelled and resold');

$gaHold = (new CapacityService())->acquire($general, 3, bin2hex(random_bytes(24)));
$gaOrder = $checkout->prepare($general, $gaHold['token'], $buyer);
$gaView = $tickets->forGuest($gaOrder['order_id'], $gaOrder['guest_access_token']);
$assert(count($gaView['tickets']) === 3 && !$gaOrder['payment_required'], 'Free general admission issues three independent tickets');
$assert($gate->scan($gaView['tickets'][0]['qr_payload'], (int) $operator)['checked_in'], 'General-admission QR uses authorized gate pipeline');
$reject(fn() => $gate->scan($gaView['tickets'][0]['qr_payload'], (int) $operator), 'already_checked_in', 'General-admission duplicate check-in rejected');
$gaPaidHold = (new CapacityService())->acquire($paidGeneral, 2, bin2hex(random_bytes(24)));
$gaPaidOrder = $checkout->prepare($paidGeneral, $gaPaidHold['token'], $buyer);
wc_get_order($gaPaidOrder['order_id'])->payment_complete('integration-ga-not-a-bank-transaction');
$assert(count($tickets->forGuest($gaPaidOrder['order_id'], $gaPaidOrder['guest_access_token'])['tickets']) === 2, 'Opt-in paid general admission issues after Woo payment completion');

$expiryHold = $holds->acquire($free, [$seatIds[4]], bin2hex(random_bytes(24)));
$wpdb->query($wpdb->prepare("UPDATE {$p}session_seats SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE session_id=%d AND seat_id=%d", $free, $seatIds[4]));
$wpdb->query($wpdb->prepare("UPDATE {$p}holds SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=%d", $expiryHold['hold_id']));
$report = (new AttendanceReport())->summary(1, $center, $free);
$assert($report['sessions'][0]['available'] === 897 && $report['sessions'][0]['held'] === 0, 'Attendance report treats expired seat lease as available without cron');
$snapshot = (new SeatStatusReport())->snapshot(1, $center, $free);
$assert($snapshot['totals']['available'] === 897 && $snapshot['totals']['held'] === 0, 'Seat-status report agrees with booking expiry rules');
$reject(fn() => (new AttendanceReport())->summary((int) $analyst, $center), 'permission_denied', 'Analyst cannot access another center report');
$holds->acquire($free, [$seatIds[4]], bin2hex(random_bytes(24)));
$assert(true, 'Expired inventory can be acquired without cron');

$beforeCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}tickets");
update_option('pesm_schema_version', 17);
\PESM\Database\Migrator::run();
$assert((int) get_option('pesm_schema_version') === 18 && !get_option('pesm_schema_error'), 'Upgrade from prior schema completes');
$assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}tickets") === $beforeCount, 'Schema upgrade preserves tickets');

wp_set_current_user(0);
$request = new WP_REST_Request('GET', '/pesm/v1/tickets/me');
$request->set_param('order_id', $freeOrder['order_id']);
$request->set_header('X-PESM-Guest-Token', $freeOrder['guest_access_token']);
$response = rest_do_request($request);
$assert($response->get_status() === 200 && count($response->get_data()['tickets']) === 2, 'Public guest REST flow returns only authorized order tickets');
$request = new WP_REST_Request('GET', '/pesm/v1/admin/reports/attendance');
$request->set_param('center_id', $center);
$assert(rest_do_request($request)->get_status() === 401, 'Anonymous admin report REST access is denied');

$state = ['center' => $center, 'other_center' => $otherCenter, 'hall' => $hall, 'event' => $event,
    'free_session' => $free, 'race_session' => $raceSession, 'race_seat' => $seatIds[5],
    'race_capacity_session' => $raceCapacity, 'operator' => (int) $operator,
    'race_qr' => $view['tickets'][1]['qr_payload'], 'race_ticket' => (int) $view['tickets'][1]['id'],
    'free_order' => $freeOrder['order_id'], 'ticket_count' => $beforeCount];
update_option('pesm_integration_fixture', $state, false);
echo wp_json_encode(['mode' => 'all', 'passed' => count($checks), 'seconds' => round(microtime(true) - $started, 3),
    'wordpress' => get_bloginfo('version'), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION,
    'database' => $wpdb->get_var('SELECT VERSION()'), 'schema' => get_option('pesm_schema_version'),
    'hpos_enabled' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
    'order_data_store' => wc_get_order($freeOrder['order_id'])->get_data_store()->get_current_class_name(),
    'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
