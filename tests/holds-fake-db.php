<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

/**
 * In-memory behavioral test double for the hold service.
 * It does NOT implement InnoDB isolation or prove real concurrent safety.
 */
require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Domain/Booking/HoldPolicy.php';
require __DIR__ . '/../src/Support/Audit.php';
require __DIR__ . '/../src/Domain/Booking/HoldService.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Booking\HoldService;

function wp_salt(string $name): string { return 'fake-test-only-secret'; }
function get_option(string $key, mixed $default = false): mixed { return $key === 'pesm_schema_version' ? 2 : $default; }

final class FakeWpdb
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    public int $insert_id = 0;
    public string $now = '2026-11-02 12:00:00';
    public array $holds = [];
    public array $items = [];
    public array $mutations = [];
    public array $audit = [];
    public array $seats = [101 => ['status' => 'available','hold_id'=>null,'expires_at'=>null],
        102 => ['status' => 'available','hold_id'=>null,'expires_at'=>null]];
    private int $nextId = 1;

    public function prepare(string $sql, mixed ...$args): string
    {
        $args = array_map(static fn ($v) => is_int($v) ? $v : "'" . str_replace("'", "''", (string) $v) . "'", $args);
        return vsprintf($sql, $args);
    }
    public function query(string $sql): int|false
    {
        if (in_array($sql, ['START TRANSACTION','COMMIT','ROLLBACK'], true)) return 1;
        if (preg_match('/UPDATE wp_pesm_session_seats SET status = .available.*WHERE session_id = (\d+) AND seat_id = (\d+) AND hold_id = (\d+)/s', $sql, $m)) {
            [$session, $seat, $hold] = array_map('intval', array_slice($m,1));
            if ($session === 1 && isset($this->seats[$seat]) && $this->seats[$seat]['hold_id'] === $hold
                && $this->seats[$seat]['status'] === 'held') {
                $this->seats[$seat] = ['status' => 'available','hold_id' => null, 'expires_at' => null];
                return 1;
            }
            return 0;
        }
        throw new RuntimeException('Fake SQL was not implemented: ' . $sql);
    }
    public function get_var(string $sql): mixed
    {
        if ($sql === 'SELECT UTC_TIMESTAMP()') return $this->now;
        if (preg_match('/SELECT id FROM wp_pesm_sessions WHERE id = (\d+) FOR UPDATE/', $sql, $m)) return (int) $m[1] === 1 ? 1 : null;
        throw new RuntimeException('Fake get_var SQL not implemented: ' . $sql);
    }
    public function get_row(string $sql): ?object
    {
        if (str_contains($sql, 'FROM wp_pesm_sessions s INNER JOIN wp_pesm_events e') || str_contains($sql, 'FROM wp_pesm_sessions s JOIN wp_pesm_events e')) {
            return (object) ['id'=>1,'status'=>'published','event_status'=>'published',
                'starts_at'=>'2026-11-03 12:00:00', 'sale_starts_at'=>'2026-11-02 11:00:00',
                'sale_ends_at'=>'2026-11-02 18:00:00'];
        }
        if (str_contains($sql, 'FROM wp_pesm_hold_mutations')) {
            preg_match('/request_hash=\'([a-f0-9]+)\'/', $sql, $match);
            foreach ($this->mutations as $mutation) {
                if ($mutation['request_hash'] === ($match[1] ?? '')) return (object) $mutation;
            }
            return null;
        }
        if (str_contains($sql, 'FROM wp_pesm_holds WHERE request_hash')) {
            preg_match('/request_hash = \'([a-f0-9]+)\'/',$sql,$match);
            foreach ($this->holds as $hold) if ($hold['request_hash'] === ($match[1] ?? '')) return (object) $hold;
            return null;
        }
        if (str_contains($sql, 'FROM wp_pesm_holds WHERE token_hash') || str_contains($sql, 'FROM wp_pesm_holds WHERE session_id')) {
            preg_match('/token_hash\s*=\s*\'([a-f0-9]+)\'/',$sql,$token);
            preg_match('/session_id\s*=\s*(\d+)/',$sql,$session);
            foreach ($this->holds as $hold) if ($hold['token_hash'] === ($token[1] ?? '')
                && $hold['session_id'] === (int) ($session[1] ?? 0)) return (object) $hold;
            return null;
        }
        throw new RuntimeException('Fake get_row SQL not implemented: ' . $sql);
    }
    public function get_results(string $sql): array
    {
        preg_match('/seat_id IN \(([^)]+)\)/',$sql,$matches);
        $ids = array_map('intval', explode(',', $matches[1] ?? ''));
        $results = [];
        foreach ($ids as $seatId) if (isset($this->seats[$seatId])) {
            $results[] = (object) ['seat_id'=>$seatId, ...$this->seats[$seatId]];
        }
        return $results;
    }
    public function get_col(string $sql): array
    {
        preg_match('/hold_id\s*=\s*(\d+)/', $sql, $match);
        $id = (int) ($match[1] ?? 0);
        $result = $this->items[$id] ?? [];
        sort($result);
        return $result;
    }
    public function insert(string $table, array $data): int
    {
        if ($table === 'wp_pesm_holds') {
            $id = $this->nextId++;
            $data['id'] = $id;
            $data['renewal_count'] = $data['renewal_count'] ?? 0;
            $this->holds[$id] = $data;
            $this->insert_id = $id;
        } elseif ($table === 'wp_pesm_hold_mutations') {
            $this->mutations[] = $data;
        } elseif ($table === 'wp_pesm_hold_items') {
            $this->items[$data['hold_id']][] = $data['seat_id'];
        } elseif ($table === 'wp_pesm_audit_logs') {
            $this->audit[] = $data;
        } else throw new RuntimeException('Fake insert table: ' . $table);
        return 1;
    }
    public function delete(string $table, array $where): int
    {
        if ($table !== 'wp_pesm_hold_items') throw new RuntimeException('Fake delete table: ' . $table);
        $id = $where['hold_id']; $seat = $where['seat_id'];
        $before = count($this->items[$id] ?? []);
        $this->items[$id] = array_values(array_filter($this->items[$id] ?? [], fn($v) => $v !== $seat));
        return $before - count($this->items[$id]);
    }
    public function update(string $table, array $data, array $where): int
    {
        if ($table === 'wp_pesm_session_seats') {
            $this->seats[$where['seat_id']] = array_merge($this->seats[$where['seat_id']], $data);
        } elseif ($table === 'wp_pesm_holds') {
            $this->holds[$where['id']] = array_merge($this->holds[$where['id']], $data);
        } else throw new RuntimeException('Fake update table: ' . $table);
        return 1;
    }
}

$wpdb = new FakeWpdb();
$service = new HoldService();
$checks = 0;
function assertThat(bool $test, string $message): void
{
    global $checks;
    if (!$test) throw new RuntimeException('FAIL: ' . $message);
    ++$checks;
}
function mustFail(callable $fn, string $code): void
{
    try { $fn(); } catch (HoldException $e) {
        assertThat($e->errorCode === $code, 'expected error: ' . $code); return;
    }
    throw new RuntimeException('Expected error: ' . $code);
}
$keyA = str_repeat('a', 40);
$keyB = str_repeat('b', 40);
$keyC = str_repeat('c', 40);
$a = $service->acquire(1,[101],$keyA);
assertThat($a['hold_id'] === 1 && $wpdb->seats[101]['status'] === 'held', 'first hold assigns seat');
assertThat(count($wpdb->audit) === 1, 'first hold has audit trail');
$replay = $service->acquire(1,[101],$keyA);
assertThat($replay['idempotent_replay'] && $replay['token'] === $a['token'], 'idempotent replay');
assertThat(count($wpdb->holds) === 1, 'no duplicate hold on replay');
mustFail(fn() => $service->acquire(1,[102],$keyA), 'idempotency_conflict');
mustFail(fn() => $service->acquire(1,[101],$keyB), 'seat_unavailable');
$b = $service->acquire(1,[102],$keyB);
assertThat($b['hold_id'] === 2, 'independent seat hold');
mustFail(fn() => $service->release(1,str_repeat('f',64)), 'permission_denied');
$released = $service->release(1,$a['token']);
assertThat($released['released'] && $wpdb->seats[101]['status'] === 'available', 'release own seat');
assertThat(!$service->release(1,$a['token'])['released'], 'release idempotency');
$c = $service->acquire(1,[101],$keyC);
assertThat($c['hold_id'] === 3, 'reassigned after release');
// An already-released old token cannot unlock another owner's new hold.
$service->release(1,$a['token']);
assertThat($wpdb->seats[101]['hold_id'] === 3, 'old hold token cannot release new hold');
// Claim an expired hold lazily, leaving old hold's record intact.
$wpdb->now = '2026-11-02 12:11:00';
$d = $service->acquire(1,[102],str_repeat('d',40));
assertThat($d['hold_id'] === 4, 'expired hold can be acquired without cron');
$service->release(1,$b['token']);
assertThat($wpdb->seats[102]['hold_id'] === 4, 'late release cannot release replacement');
assertThat(count($wpdb->audit) >= 5, 'hold changes audited');
// New transaction-based replacement/renewal behavior: all operations use the same token.
$e = $service->acquire(1,[101],str_repeat('e',40));
mustFail(fn() => $service->modify(1, $e['token'], str_repeat('h',40), [101,102]), 'seat_unavailable');
assertThat($wpdb->items[$e['hold_id']] === [101], 'failed replacement retains original selection');
$service->release(1, $d['token']);
$replaced = $service->modify(1, $e['token'], str_repeat('h',40), [101,102]);
assertThat($replaced['seat_ids'] === [101,102], 'replace adds seat without token change');
assertThat($wpdb->seats[102]['hold_id'] === $e['hold_id'], 'added seat belongs to same hold');
$replaced = $service->modify(1, $e['token'], str_repeat('i',40), [102]);
assertThat($replaced['seat_ids'] === [102], 'replace removes seat');
assertThat($wpdb->seats[101]['status'] === 'available', 'released seat is available');
$wpdb->now = '2026-11-02 12:16:00';
$renewed = $service->modify(1, $e['token'], str_repeat('j',40));
assertThat($renewed['expires_at_utc'] === '2026-11-02T12:26:00Z', 'renewal extends with DB time');
assertThat($wpdb->seats[102]['expires_at'] === '2026-11-02 12:26:00', 'seat expiry matches hold');
$replayRenew = $service->modify(1, $e['token'], str_repeat('j',40));
assertThat($replayRenew['idempotent_replay'] === true && $wpdb->holds[$e['hold_id']]['renewal_count'] === 1, 'renewal network replay not counted twice');
$wpdb->now = '2026-11-02 12:22:00';
$renewed = $service->modify(1, $e['token'], str_repeat('k',40));
assertThat($renewed['expires_at_utc'] === '2026-11-02T12:32:00Z', 'second renewal extends');
mustFail(fn() => $service->modify(1, $e['token'], str_repeat('l',40)), 'hold_not_renewable');
mustFail(fn() => $service->modify(1, str_repeat('0',64), str_repeat('m',40), [102]), 'permission_denied');
$wpdb->now = '2026-11-02 12:33:00';
mustFail(fn() => $service->modify(1, $e['token'], str_repeat('n',40), [102]), 'hold_expired');
echo "PASS: $checks fake-db behavioral assertions. No real isolation/transaction/concurrency tested.\n";
