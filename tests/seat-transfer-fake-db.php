<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

/** Transactional state-machine test double; NOT an InnoDB concurrency test. */
require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Domain/Booking/HoldPolicy.php';
require __DIR__ . '/../src/Domain/Booking/SeatingRules.php';
require __DIR__ . '/../src/Domain/Ticket/SeatTransferPolicy.php';
require __DIR__ . '/../src/Domain/Ticket/TicketToken.php';
require __DIR__ . '/../src/Security/Capabilities.php';
require __DIR__ . '/../src/Support/Audit.php';
require __DIR__ . '/../src/Domain/Ticket/SeatTransferService.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Ticket\SeatTransferService;
use PESM\Domain\Ticket\TicketToken;
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

function get_option(string $key, mixed $default = false): mixed {
    return $key === 'pesm_schema_version' ? 7 : $default;
}
function user_can(int $id, string $cap): bool {
    return $id === 2 && $cap === 'pesm_issue_tickets';
}
function wp_salt(string $scope): string { return str_repeat('K', 40); }
class TransferOrderStub {
    public string $status = 'completed';
    public bool $paid = true;
    public float $refunded = 0.0;
    public function get_meta(string $key): string { return $key === '_pesm_is_ticket_order' ? 'yes' : ''; }
    public function get_status(): string { return $this->status; }
    public function get_total(): string { return '500'; }
    public function is_paid(): bool { return $this->paid; }
    public function get_total_refunded(): float { return $this->refunded; }
}
$orderStub = new TransferOrderStub();
function wc_get_order(int $id): ?TransferOrderStub {
    global $orderStub;
    return $id === 9 ? $orderStub : null;
}
class TransferFakeDB {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public array $ticket;
    public array $inventory = [];
    public array $items = [1];
    public array $history = [];
    public array $audit = [];
    public array $checkins = [];
    public bool $failLedger = false;
    public int $center = 1;
    private ?array $snapshot = null;
    public function __construct() { $this->reset(); }
    public function reset(): void {
        $public = str_repeat('a', 32);
        $this->ticket = ['id'=>8, 'seat_id'=>1, 'session_id'=>4, 'hold_id'=>3,
            'order_id'=>9, 'public_id'=>$public, 'status'=>'active', 'qr_version'=>1,
            'qr_token_hash'=>hash('sha256', TicketToken::create($public,1,wp_salt('secure_auth')))];
        $this->inventory = [1=>['seat_id'=>1,'status'=>'sold','hold_id'=>3],
            2=>['seat_id'=>2,'status'=>'available','hold_id'=>null],
            3=>['seat_id'=>3,'status'=>'available','hold_id'=>null]];
        $this->items = [1]; $this->history = []; $this->audit = []; $this->checkins = [];
        $this->center = 1; $this->snapshot = null; $this->failLedger = false;
    }
    public function prepare(string $sql, mixed ...$args): string {
        return vsprintf($sql, array_map(static fn($v) => is_int($v) ? $v : "'" . str_replace("'","''",(string) $v) . "'", $args));
    }
    public function get_var(string $sql): mixed {
        if ($sql === 'SELECT UTC_TIMESTAMP()') return '2026-11-02 12:00:00';
        if (str_contains($sql, 'FROM wp_pesm_center_users')) return $this->center === 1 ? 1 : null;
        if (str_contains($sql, 'SELECT e.center_id FROM wp_pesm_tickets')) return $this->center;
        if (str_contains($sql, 'FROM wp_pesm_checkins')) return count($this->checkins) ? 1 : null;
        throw new RuntimeException('Unknown get_var: '. $sql);
    }
    public function get_row(string $sql): ?object {
        if (str_contains($sql, 'FROM wp_pesm_tickets t') && str_contains($sql, 'JOIN wp_pesm_sessions')) {
            return (object) ['session_id'=>4,'hold_id'=>3,'order_id'=>9,'center_id'=>$this->center];
        }
        if (str_contains($sql, 'FROM wp_pesm_sessions s') && str_contains($sql, 'FOR UPDATE')) {
            return (object) ['id'=>4,'starts_at'=>'2026-11-03 12:00:00','status'=>'published',
                'event_status'=>'published','center_id'=>$this->center, 'require_adjacent'=>0,'avoid_single_gap'=>0];
        }
        if (str_contains($sql, 'FROM wp_pesm_holds')) return (object) ['id'=>3,'status'=>'sold','order_id'=>9];
        if (str_contains($sql, 'FROM wp_pesm_order_links')) return (object) ['id'=>6,'status'=>'issued'];
        if (str_contains($sql, 'FROM wp_pesm_tickets WHERE id=')) return (object) $this->ticket;
        if (str_contains($sql, 'FROM wp_pesm_ticket_seat_transfers')) {
            foreach ($this->history as $entry) {
                if (str_contains($sql, "'" . $entry['request_hash'] . "'")) return (object) $entry;
            }
            return null;
        }
        throw new RuntimeException('Unknown get_row: '. $sql);
    }
    public function get_results(string $sql, mixed $output = null): ?array {
        if (str_contains($sql, 'FROM wp_pesm_ticket_seat_transfers tr')) {
            return array_map(static fn($r): array => [
                'id'=>1,'from_seat_id'=>$r['from_seat_id'],'to_seat_id'=>$r['to_seat_id'],
                'old_qr_version'=>$r['old_qr_version'],'new_qr_version'=>$r['new_qr_version'],
                'actor_user_id'=>$r['actor_user_id'],'created_at'=>$r['created_at'],
                'from_seat_code'=>'A-1','to_seat_code'=>'A-2',
            ],$this->history);
        }
        if (str_contains($sql, 'FROM wp_pesm_session_seats')) {
            if (str_contains($sql, 'IN (')) {
                // Transfer tests only use source and destination seats 1 and 2.
                return [(object) $this->inventory[1],(object) $this->inventory[2]];
            }
        }
        throw new RuntimeException('Unknown get_results: '. $sql);
    }
    public function get_col(string $sql): array { return $this->items; }
    public function update(string $table, array $data, array $where): int {
        if ($table === 'wp_pesm_tickets') {
            foreach ($where as $key=>$value) { if ($this->ticket[$key] !== $value) return 0; }
            $this->ticket = array_replace($this->ticket, $data); return 1;
        }
        if ($table === 'wp_pesm_session_seats') {
            $seatId = (int) $where['seat_id'];
            foreach ($where as $key=>$value) {
                if ($key === 'session_id') continue;
                if ($this->inventory[$seatId][$key] !== $value) return 0;
            }
            $this->inventory[$seatId] = array_replace($this->inventory[$seatId], $data);
            return 1;
        }
        throw new RuntimeException('Unknown update: '. $table);
    }
    public function insert(string $table, array $data): int {
        if ($table === 'wp_pesm_hold_items') { $this->items[] = $data['seat_id']; return 1; }
        if ($table === 'wp_pesm_audit_logs') { $this->audit[] = $data; return 1; }
        if ($table === 'wp_pesm_ticket_seat_transfers') {
            if ($this->failLedger) return 0;
            $this->history[] = $data; return 1;
        }
        throw new RuntimeException('Unknown insert: '. $table);
    }
    public function delete(string $table, array $where): int {
        if ($table === 'wp_pesm_hold_items') {
            if (!in_array($where['seat_id'], $this->items, true)) return 0;
            $this->items = array_values(array_diff($this->items, [$where['seat_id']]));
            return 1;
        }
        throw new RuntimeException('Unknown delete: '. $table);
    }
    public function query(string $sql): int {
        if ($sql === 'START TRANSACTION') {
            $this->snapshot = [$this->ticket, $this->inventory, $this->items, $this->history, $this->audit]; return 1;
        }
        if ($sql === 'COMMIT') { $this->snapshot = null; return 1; }
        if ($sql === 'ROLLBACK') {
            if ($this->snapshot !== null) {
                [$this->ticket, $this->inventory, $this->items, $this->history, $this->audit] = $this->snapshot;
                $this->snapshot = null;
            }
            return 1;
        }
        throw new RuntimeException('Unknown query: '. $sql);
    }
}
$wpdb = new TransferFakeDB();
$svc = new SeatTransferService();
$n = 0;
function check(bool $ok, string $note): void {
    global $n;
    if (!$ok) throw new RuntimeException('FAILED: ' . $note);
    ++$n;
}
function fails(callable $run, string $expected): void {
    try { $run(); } catch (HoldException $e) {
        check($e->errorCode === $expected, 'expected ' . $expected . ' got ' . $e->errorCode); return;
    }
    throw new RuntimeException('Expected ' . $expected);
}
$key = str_repeat('R', 32);
fails(fn() => $svc->transfer(8,2,$key,5),'permission_denied');
$wpdb->center = 2;
fails(fn() => $svc->transfer(8,2,$key,2),'permission_denied');
$wpdb->center = 1;
$wpdb->inventory[2]['status'] = 'held';
fails(fn() => $svc->transfer(8,2,$key,2),'seat_unavailable');
check($wpdb->ticket['seat_id'] === 1, 'conflict does not change ticket');
$wpdb->inventory[2]['status'] = 'available';
$wpdb->checkins = [8];
fails(fn() => $svc->transfer(8,2,$key,2),'ticket_not_transferable');
$wpdb->checkins = [];
$oldQr = TicketToken::create($wpdb->ticket['public_id'],1,wp_salt('secure_auth'));
$wpdb->failLedger = true;
fails(fn() => $svc->transfer(8,2,$key,2),'transfer_write_failed');
check($wpdb->ticket['seat_id'] === 1 && $wpdb->inventory[1]['status'] === 'sold' &&
    $wpdb->inventory[2]['status'] === 'available' && $wpdb->history === [], 'failure rolls back ticket and inventory');
$wpdb->failLedger = false;
$result = $svc->transfer(8,2,$key,2);
check($result['new_seat_id'] === 2 && $result['qr_version'] === 2 && !$result['idempotent_replay'], 'transfer success');
check($wpdb->inventory[2]['status'] === 'sold' && $wpdb->inventory[1]['status'] === 'available', 'atomic ownership exchanged');
check($wpdb->items === [2] && count($wpdb->history) === 1 && count($wpdb->audit) === 1, 'hold items ledger and audit updated');
check(!TicketToken::valid($oldQr, $wpdb->ticket['public_id'], 2, wp_salt('secure_auth')), 'old QR rejected');
$again = $svc->transfer(8,2,$key,2);
check($again['idempotent_replay'] && $again['qr_version'] === 2 && count($wpdb->history) === 1, 'request is idempotent');
fails(fn() => $svc->transfer(8,3,$key,2),'idempotency_conflict');
$history = $svc->history(8, 2);
check(count($history['transfers']) === 1 && $history['transfers'][0]['to_seat_code'] === 'A-2',
    'history read shows destination seat label');
check(!isset($history['transfers'][0]['request_hash']), 'history hides request secret hash');
$wpdb->center = 2;
fails(fn() => $svc->history(8,2), 'permission_denied');
$wpdb->center = 1;

$orderStub->refunded = 20.0;
fails(fn() => $svc->transfer(8,3,str_repeat('T',32),2),'order_unsettled');
$orderStub->refunded = 0.0;
$orderStub->status = 'refunded';
fails(fn() => $svc->transfer(8,3,str_repeat('S',32),2),'order_unsettled');
echo "PASS: {$n} transactional fake-DB seat-transfer checks; NOT MySQL concurrency.\n";
