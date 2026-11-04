<?php
declare(strict_types=1);

/**
 * Actual shared-InnoDB gate races. Use a disposable local WordPress only.
 *
 * PESM_RUN_INTEGRATION=1 PESM_RACE_PHASE=seed wp --user=1 eval-file <this-file>
 * Run workers concurrently (separate wp processes, one database):
 *  - PESM_RACE_PHASE=worker PESM_RACE_CASE=incremental PESM_RACE_WORKER=0..19
 *  - PESM_RACE_PHASE=worker PESM_RACE_CASE=replay      PESM_RACE_WORKER=0..19
 *  - PESM_RACE_PHASE=worker PESM_RACE_CASE=cancel_entry PESM_RACE_WORKER=0..1
 *    Worker 0 cancels; worker 1 scans. Optionally provide the same future Unix
 *    seconds PESM_RACE_START_AT (at most 10 seconds ahead) to each first wave.
 * PESM_RUN_INTEGRATION=1 PESM_RACE_PHASE=verify wp --user=1 eval-file <this-file>
 *
 * No output contains QR payloads, access tokens, request keys or passwords.
 * Fixtures/credentials are stored solely in non-autoloaded private WP options.
 * This is a functional race test, not a throughput or latency acceptance claim.
 */
if (!defined('ABSPATH')) { echo "SKIP: run in disposable local WordPress.\n"; return; }
if (getenv('PESM_RUN_INTEGRATION') !== '1' || wp_get_environment_type() !== 'local' ||
    !defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Requires explicitly enabled disposable local WP-CLI integration.'); }
$phase = getenv('PESM_RACE_PHASE');
if (!in_array($phase, ['seed', 'worker', 'verify'], true)) { throw new RuntimeException('Set PESM_RACE_PHASE=seed, worker or verify.'); }
if ((int) get_option('pesm_schema_version', 0) !== 19 || !function_exists('wc_get_order')) { throw new RuntimeException('Activate schema19 Event Seat Manager and WooCommerce.'); }
if ($phase !== 'worker' && !current_user_can('manage_options')) { throw new RuntimeException('Run seed/verify as an administrator using --user.'); }
if (!defined('PESM_VENUE_ACCEPTANCE_TEST')) { define('PESM_VENUE_ACCEPTANCE_TEST', true); }
add_filter('pre_wp_mail', static fn() => true, 99);
add_filter('pre_http_request', static fn() => new WP_Error('race_remote_disabled', 'Remote requests disabled in race acceptance.'), 99);
add_filter('option_pesm_notifications_enabled', static fn() => 'no');
add_filter('option_woocommerce_calc_taxes', static fn() => 'no');
add_filter('option_pesm_entry_before_seconds', static fn() => 7200);
$_SERVER['REMOTE_ADDR'] = '127.0.0.200'; // Every operator intentionally shares one router address.

global $wpdb;
$p = $wpdb->prefix . 'pesm_'; $option = 'pesm_venue_race_fixture'; $checks = [];
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('Venue race acceptance failed: ' . $label); }
    $checks[] = $label;
};
$output = static function (array $value): void { echo json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"; };
$safeCase = static fn(array $case): array => array_intersect_key($case, array_flip(['session_id', 'order_id', 'hold_id', 'line_id', 'capacity']));

if ($phase === 'seed') {
    $actor = get_current_user_id(); $run = bin2hex(random_bytes(6));
    $runJob = static function (int $id): array {
        for ($batch = 0; $batch < 100; $batch++) {
            \PESM\Domain\Venue\JobService::run($id); $state = \PESM\Domain\Venue\JobService::status($id);
            if ($state['status'] === 'complete') { return $state; }
            if ($state['status'] === 'failed') { throw new RuntimeException('Venue race fixture job failed.'); }
        }
        throw new RuntimeException('Venue race fixture job exceeded its bounded batch budget.');
    };
    $center = \PESM\Domain\Hall\HallService::createCenter('Venue gate race ' . $run);
    $hall = \PESM\Domain\Hall\HallService::createHall($center, 'Venue gate race hall', 1, 1);
    $generation = \PESM\Domain\Venue\JobService::create($center, $hall, 'generate',
        ['preset_id' => 'restaurant_round_banquet', 'parameters' => ['capacity' => 24, 'group_size' => 8]], $actor);
    $generated = $runJob((int) $generation['id']); $layout = (int) $generated['layout_id'];
    $publish = \PESM\Domain\Venue\JobService::create($center, $hall, 'publish', ['layout_id' => $layout], $actor); $runJob((int) $publish['id']);
    $event = \PESM\Domain\Hall\HallService::createEvent($center, 'Venue gate race event');
    \PESM\Domain\Event\LifecycleService::change('event', $event, 'published', $actor);
    $newSession = static function (int $price, string $policy) use ($event, $hall, $actor, $wpdb, $p, $runJob): int {
        $session = \PESM\Domain\Hall\HallService::createSession($event, $hall, gmdate('Y-m-d H:i:s', time() + 3600),
            gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s', time() + 1800), $price, 'allocated');
        \PESM\Domain\Allocation\SessionService::configure($session, ['ticket_mode' => 'group', 'group_entry_policy' => $policy]);
        $job = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}venue_jobs WHERE session_id=%d AND kind='provision' ORDER BY id DESC LIMIT 1", $session));
        $runJob($job); \PESM\Domain\Event\LifecycleService::change('session', $session, 'published', $actor); return $session;
    };
    $free = $newSession(0, 'incremental'); $paid = $newSession(1000, 'together');
    $groups = $wpdb->get_results($wpdb->prepare("SELECT group_code,capacity FROM {$p}venue_groups WHERE layout_id=%d ORDER BY id LIMIT 3", $layout));
    $assert(count($groups) === 3, 'fixture contains three explicit eight-place groups');
    $cases = [];
    foreach (['incremental', 'replay', 'cancel_entry'] as $index => $name) {
        $session = $name === 'cancel_entry' ? $paid : $free;
        $group = $groups[$index]; $assert((int) $group->capacity === 8, 'fixture group capacity is eight: ' . $name);
        $hold = \PESM\Domain\Allocation\ReservationService::acquire($session, [['type' => 'group', 'id' => $group->group_code]], bin2hex(random_bytes(24)));
        $prepared = \PESM\Domain\Allocation\CheckoutService::prepare($session, $hold['hold_token'],
            ['first_name' => 'Race', 'last_name' => 'Fixture', 'phone' => '0912111000' . $index, 'email' => 'race-' . $index . '@example.test']);
        $orderId = (int) $prepared['order_id'];
        if ($name === 'cancel_entry') {
            $order = wc_get_order($orderId); $order->payment_complete('venue-race-local-fixture');
            $issued = \PESM\Domain\Allocation\CheckoutService::confirmOrder($orderId);
            $assert(in_array($issued, ['issued', 'already_issued'], true), 'paid cancellation race has verified paid issuance');
        }
        $tickets = \PESM\Domain\Allocation\CheckoutService::tickets($orderId, $prepared['guest_access_token']); $line = $tickets['lines'][0];
        $assert(count($line['tickets']) === 0 && $line['group_ticket']['remaining'] === 8, 'fixture starts with one unused shared credential: ' . $name);
        $cases[$name] = ['session_id' => $session, 'order_id' => $orderId, 'hold_id' => (int) $hold['hold_id'],
            'line_id' => (int) $line['line_id'], 'capacity' => 8, 'credential' => $line['group_ticket']['qr_payload']];
    }
    $operators = [];
    for ($index = 0; $index < 20; $index++) {
        $id = wp_insert_user(['user_login' => 'pesm_race_' . $run . '_' . $index, 'user_pass' => bin2hex(random_bytes(32)),
            'role' => 'pesm_gate_operator', 'user_email' => 'race-' . $run . '-' . $index . '@example.test', 'display_name' => 'Race gate ' . ($index + 1)]);
        if (is_wp_error($id)) { throw new RuntimeException('Cannot create scoped test gate operator.'); }
        if ($wpdb->insert($p . 'center_users', ['center_id' => $center, 'user_id' => (int) $id]) !== 1) { throw new RuntimeException('Cannot scope test gate operator to its center.'); }
        $assert(user_can((int) $id, 'pesm_scan_tickets') && \PESM\Security\Capabilities::centerAllowed($center, (int) $id), 'gate operator is authorized and center scoped: ' . $index);
        $operators[] = (int) $id;
    }
    $fixture = ['run' => $run, 'actor_id' => $actor, 'center_id' => $center, 'hall_id' => $hall, 'layout_id' => $layout,
        'event_id' => $event, 'operators' => $operators, 'cases' => $cases, 'request_secret' => bin2hex(random_bytes(32))];
    update_option($option, $fixture, false);
    $publicCases = []; foreach ($cases as $name => $case) { $publicCases[$name] = $safeCase($case); }
    $output(['phase' => 'seed', 'passed' => true, 'run' => $run, 'center_id' => $center, 'operator_count' => count($operators),
        'cases' => $publicCases, 'workers' => ['incremental' => 20, 'replay' => 20, 'cancel_entry' => 2], 'checks' => count($checks)]);
    return;
}

$fixture = get_option($option, null);
$assert(is_array($fixture) && preg_match('/^[a-f0-9]{12}$/D', $fixture['run'] ?? '') === 1, 'private race fixture exists');
$resultPrefix = 'pesm_venue_race_' . $fixture['run'] . '_';
if ($phase === 'worker') {
    $name = getenv('PESM_RACE_CASE'); $rawIndex = getenv('PESM_RACE_WORKER');
    if (!isset($fixture['cases'][$name]) || !is_string($rawIndex) || !preg_match('/^(?:0|[1-9][0-9]?)$/D', $rawIndex)) { throw new RuntimeException('Provide valid race case and worker index.'); }
    $index = (int) $rawIndex; $maximum = $name === 'cancel_entry' ? 2 : 20;
    if ($index >= $maximum) { throw new RuntimeException('Worker index exceeds this race case.'); }
    $resultOption = $resultPrefix . $name . '_' . $index;
    $existing = get_option($resultOption, null);
    if (is_array($existing)) { $output($existing + ['worker_replay' => true]); return; }
    $case = $fixture['cases'][$name]; $cancel = $name === 'cancel_entry' && $index === 0;
    $operator = $cancel ? (int) $fixture['actor_id'] : (int) $fixture['operators'][$name === 'replay' ? 0 : $index];
    wp_set_current_user($operator);
    $gate = $name === 'replay' ? 'same-router-retry' : 'same-router-' . $index;
    $key = hash_hmac('sha256', $name . ':' . ($name === 'replay' ? 'same' : (string) $index), $fixture['request_secret']);
    $barrier = getenv('PESM_RACE_START_AT');
    if ($barrier !== false && $barrier !== '') {
        if (!is_numeric($barrier) || (float) $barrier - microtime(true) > 10) { throw new RuntimeException('Race barrier must be numeric and at most ten seconds ahead.'); }
        while (($remaining = (float) $barrier - microtime(true)) > 0) { usleep((int) min(20000, ceil($remaining * 1000000))); }
    }
    $result = ['phase' => 'worker', 'run' => $fixture['run'], 'case' => $name, 'worker' => $index, 'action' => $cancel ? 'cancel' : 'scan',
        'operator_id' => $operator, 'line_id' => $case['line_id'], 'ok' => false, 'started_at' => microtime(true)];
    $start = hrtime(true);
    try {
        if ($cancel) {
            $response = \PESM\Domain\Allocation\GroupAdministration::cancel((int) $case['line_id'], $key, $operator);
            $result += ['status' => $response['status'], 'replay' => !empty($response['idempotent_replay'])];
        } else {
            $response = \PESM\Domain\Allocation\GateService::scan($case['credential'], $operator, $gate, $key, $name === 'replay' ? 2 : 1);
            $result += ['admitted' => $response['admitted'], 'used' => $response['used'], 'remaining' => $response['remaining'], 'replay' => !empty($response['idempotent_replay'])];
        }
        $result['ok'] = true;
    } catch (\PESM\Domain\Booking\HoldException $error) {
        $result['code'] = $error->errorCode;
    }
    $result['elapsed_ms'] = round((hrtime(true) - $start) / 1000000, 3);
    if (!add_option($resultOption, $result, '', false)) { throw new RuntimeException('Worker result already exists; do not reuse worker indices concurrently.'); }
    $output($result); return;
}

$caseState = static function (array $case) use ($wpdb, $p): array {
    $line = $wpdb->get_row($wpdb->prepare("SELECT status,admission_count,seat_ids_json FROM {$p}allocation_lines WHERE id=%d", $case['line_id']));
    if (!$line) { throw new RuntimeException('A seeded race allocation line is missing.'); }
    $slots = $wpdb->get_results($wpdb->prepare("SELECT t.id,t.seat_id,t.status,inv.status AS inventory_status,inv.hold_id FROM {$p}allocation_tickets t JOIN {$p}session_seats inv ON inv.seat_id=t.seat_id AND inv.session_id=t.session_id WHERE t.line_id=%d ORDER BY t.seat_id", $case['line_id']), ARRAY_A);
    $checkins = $wpdb->get_results($wpdb->prepare("SELECT c.ticket_id,c.operator_id FROM {$p}allocation_checkins c JOIN {$p}allocation_tickets t ON t.id=c.ticket_id WHERE t.line_id=%d ORDER BY c.ticket_id", $case['line_id']), ARRAY_A);
    return ['status' => $line->status, 'capacity' => (int) $line->admission_count, 'seat_ids' => json_decode($line->seat_ids_json, true, 32, JSON_THROW_ON_ERROR),
        'slots' => $slots, 'checkins' => $checkins, 'used' => count(array_filter($slots, static fn(array $slot): bool => $slot['status'] === 'checked_in'))];
};
$results = []; $states = [];
foreach (['incremental' => 20, 'replay' => 20, 'cancel_entry' => 2] as $name => $count) {
    $rows = [];
    for ($index = 0; $index < $count; $index++) {
        $row = get_option($resultPrefix . $name . '_' . $index, null);
        $assert(is_array($row) && $row['case'] === $name && $row['worker'] === $index, 'worker result recorded: ' . $name . '/' . $index);
        $rows[] = $row;
    }
    $state = $caseState($fixture['cases'][$name]); $states[$name] = $state; $results[$name] = $rows;
    $assert($state['capacity'] === 8 && count($state['slots']) === 8 && count(array_unique(array_column($state['slots'], 'seat_id'))) === 8,
        'every group retains exactly eight distinct admission slots: ' . $name);
    $lineSeats = array_map('intval', $state['seat_ids']); $slotSeats = array_map('intval', array_column($state['slots'], 'seat_id'));
    sort($lineSeats, SORT_NUMERIC); sort($slotSeats, SORT_NUMERIC);
    $assert($lineSeats === $slotSeats, 'line member identities equal canonical admission-slot identities: ' . $name);
    $assert(count($state['checkins']) === $state['used'] && count(array_unique(array_column($state['checkins'], 'ticket_id'))) === $state['used'],
        'canonical check-ins and checked-in admission slots agree without duplicates: ' . $name);
    foreach ($state['slots'] as $slot) {
        $assert($slot['inventory_status'] === 'sold' && (int) $slot['hold_id'] === $fixture['cases'][$name]['hold_id'], 'paid/sold group inventory ownership never reopens during gate or cancellation: ' . $name . '/' . (int) $slot['seat_id']);
    }
}
$incremental = $results['incremental']; $won = array_filter($incremental, static fn(array $row): bool => $row['ok']);
$lost = array_filter($incremental, static fn(array $row): bool => !$row['ok']);
$assert(count(array_unique(array_column($incremental, 'operator_id'))) === 20, 'incremental contention uses twenty distinct center-scoped gate operators');
$assert(count($won) === 8 && array_sum(array_column($won, 'admitted')) === 8 && $states['incremental']['used'] === 8,
    'twenty shared-group incremental attempts admit exactly eight guests');
$assert(count($lost) === 12 && array_unique(array_column($lost, 'code')) === ['already_checked_in'], 'the twelve excess attempts reject exhausted shared inventory');
$assert(count(array_unique(array_column($states['incremental']['checkins'], 'operator_id'))) === 8, 'each incremental winner owns one distinct canonical check-in');

$replay = $results['replay'];
$assert(count(array_filter($replay, static fn(array $row): bool => $row['ok'])) === 20, 'every concurrent identical scan request succeeds or replays');
$assert(count(array_filter($replay, static fn(array $row): bool => !$row['replay'])) === 1 && $states['replay']['used'] === 2,
    'same-key retries have one committing winner and consume exactly two admissions');
foreach ($replay as $row) { $assert($row['admitted'] === 2 && $row['used'] === 2 && $row['remaining'] === 6, 'same-key response preserves original accepted admission result: ' . $row['worker']); }
[$requestHash] = \PESM\Domain\Allocation\Database::request('scan:' . $fixture['cases']['replay']['line_id'],
    hash_hmac('sha256', 'replay:same', $fixture['request_secret']), []);
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}allocation_requests WHERE request_hash=%s", $requestHash)) === 1,
    'same-key scan has exactly one persisted atomic replay receipt');

[$cancel, $scan] = $results['cancel_entry']; $state = $states['cancel_entry'];
if ($cancel['ok']) {
    $assert($cancel['status'] === 'refund_review' && !$scan['ok'] && $scan['code'] === 'ticket_inactive' && $state['status'] === 'refund_review' && $state['used'] === 0,
        'cancellation winner prevents entry, preserves unused group and requires supervised paid refund');
    $assert(count(array_filter($state['slots'], static fn(array $slot): bool => $slot['status'] === 'active')) === 8,
        'paid cancellation review retains all eight unused admissions until a verified whole-group refund');
    $outcome = 'cancellation_won';
} else {
    $assert($cancel['code'] === 'group_already_used' && $scan['ok'] && $scan['admitted'] === 8 && $state['status'] === 'issued' && $state['used'] === 8,
        'entry winner admits the whole group and prevents cancellation or seat reopening');
    $outcome = 'entry_won';
}
$safeStates = [];
foreach ($states as $name => $state) { $safeStates[$name] = ['line_id' => $fixture['cases'][$name]['line_id'], 'status' => $state['status'], 'capacity' => $state['capacity'],
    'used' => $state['used'], 'checkins' => count($state['checkins']), 'sold_member_places' => count($state['slots'])]; }
$report = ['phase' => 'verify', 'passed' => true, 'run' => $fixture['run'], 'schema' => 19, 'operator_count' => 20,
    'attempts' => 42, 'cancellation_outcome' => $outcome, 'states' => $safeStates, 'results' => $results, 'checks' => count($checks), 'scenarios' => $checks];
update_option('pesm_venue_race_results', $report, false); $output($report);
