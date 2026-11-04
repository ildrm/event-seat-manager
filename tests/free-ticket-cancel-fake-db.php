<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

/** Simulated transaction rollback and allowed free-ticket cancellation paths only. */
require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Security/Capabilities.php';
require __DIR__ . '/../src/Support/Audit.php';
require __DIR__ . '/../src/Domain/Ticket/FreeTicketCancellationService.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Ticket\FreeTicketCancellationService;

function get_option(string $key, mixed $default = false): mixed { return $key === 'pesm_schema_version' ? 7 : $default; }
function user_can(int $id, string $cap): bool { return $id === 2 && $cap === 'pesm_manage_refunds'; }
class FreeOrderMock {
    public string $status='completed';
    public float $total = 0.0;
    public function get_meta(string $key): string { return $key==='_pesm_is_ticket_order'?'yes':''; }
    public function get_status(): string { return $this->status; }
    public function get_total(): string { return (string) $this->total; }
}
$freeOrder = new FreeOrderMock();
function wc_get_order(int $orderId): ?FreeOrderMock { global $freeOrder; return $orderId === 8 ? $freeOrder : null; }
class FreeFakeDB {
    public string $prefix = 'wp_';
    public int $center = 5;
    public bool $hasCheckin = false;
    public bool $failRemove = false;
    public array $ticket = ['id'=>4, 'seat_id'=>10, 'session_id'=>3, 'hold_id'=>2, 'order_id'=>8, 'status'=>'active'];
    public array $inventory = ['seat_id'=>10, 'status'=>'sold', 'hold_id'=>2];
    public array $holdItems = [10,11];
    public array $audit = [];
    private ?array $snapshot = null;
    public function prepare(string $sql, mixed ...$args): string {
        return vsprintf($sql,array_map(fn($v) => is_int($v) ? $v : "'".$v."'", $args));
    }
    public function get_var(string $sql): mixed {
        if ($sql === 'SELECT UTC_TIMESTAMP()') return '2026-11-02 12:00:00';
        if (str_contains($sql, 'FROM wp_pesm_center_users')) return $this->center === 5 ? 1 : null;
        if (str_contains($sql, 'FROM wp_pesm_checkins')) return $this->hasCheckin ? 1 : null;
        throw new RuntimeException('get_var unknown '. $sql);
    }
    public function get_row(string $sql): ?object {
        if (str_contains($sql, 'FROM wp_pesm_tickets t')) {
            return (object) ['session_id'=>3,'hold_id'=>2,'order_id'=>8,'center_id'=>$this->center];
        }
        if (str_contains($sql, 'FROM wp_pesm_sessions s')) {
            return (object) ['id'=>3,'starts_at'=>'2026-11-03 12:00:00',
                'status'=>'published','event_status'=>'published','center_id'=>$this->center];
        }
        if (str_contains($sql, 'FROM wp_pesm_holds')) return (object) ['id'=>2,'status'=>'sold','order_id'=>8];
        if (str_contains($sql, 'FROM wp_pesm_order_links')) return (object) ['id'=>3,'status'=>'issued'];
        if (str_contains($sql, 'FROM wp_pesm_tickets WHERE id=')) return (object) $this->ticket;
        if (str_contains($sql, 'FROM wp_pesm_session_seats')) return (object) $this->inventory;
        throw new RuntimeException('get_row unknown '. $sql);
    }
    public function update(string $table, array $data, array $where): int {
        $property = match ($table) { 'wp_pesm_tickets'=>'ticket', 'wp_pesm_session_seats'=>'inventory', default=>throw new RuntimeException('update table') };
        foreach ($where as $key=>$value) {
            if ($key==='session_id') continue;
            if (($this->{$property}[$key] ?? null) !== $value) return 0;
        }
        $this->{$property} = array_replace($this->{$property},$data); return 1;
    }
    public function delete(string $table, array $where): int {
        if ($table!=='wp_pesm_hold_items') throw new RuntimeException('delete table');
        if ($this->failRemove || !in_array($where['seat_id'],$this->holdItems,true)) return 0;
        $this->holdItems = array_values(array_diff($this->holdItems,[$where['seat_id']])); return 1;
    }
    public function insert(string $table, array $values): int {
        if ($table!=='wp_pesm_audit_logs') throw new RuntimeException('insert table');
        $this->audit[]=$values; return 1;
    }
    public function query(string $sql): int {
        if ($sql==='START TRANSACTION') {
            $this->snapshot=[$this->ticket,$this->inventory,$this->holdItems,$this->audit]; return 1;
        }
        if ($sql==='COMMIT') { $this->snapshot=null; return 1; }
        if ($sql==='ROLLBACK') {
            if ($this->snapshot) { [$this->ticket,$this->inventory,$this->holdItems,$this->audit]=$this->snapshot; $this->snapshot=null; }
            return 1;
        }
        throw new RuntimeException('query unknown '. $sql);
    }
}
$wpdb = new FreeFakeDB();
$service = new FreeTicketCancellationService();
$n=0;
function check(bool $value, string $label): void { global $n; if (!$value) throw new RuntimeException('FAIL ' . $label); ++$n; }
function fails(callable $fn, string $code): void {
    try { $fn(); } catch (HoldException $e) {
        check($e->errorCode===$code, 'expected '.$code.' got '.$e->errorCode); return;
    }
    throw new RuntimeException('Expected ' . $code);
}
fails(fn()=>$service->cancel(4,3),'permission_denied');
$wpdb->center=7;
fails(fn()=>$service->cancel(4,2),'permission_denied');
$wpdb->center=5;
$freeOrder->total=100;
fails(fn()=>$service->cancel(4,2),'not_free_ticket');
$freeOrder->total=0;
$wpdb->hasCheckin=true;
fails(fn()=>$service->cancel(4,2),'ticket_not_cancelable');
$wpdb->hasCheckin=false;
$wpdb->failRemove=true;
fails(fn()=>$service->cancel(4,2),'cancel_write_failed');
check($wpdb->ticket['status']==='active' && $wpdb->inventory['status']==='sold', 'failed DB delete rollback restores ticket');
$wpdb->failRemove=false;
$result=$service->cancel(4,2);
check($result['cancelled'] && !$result['idempotent_replay'], 'free cancellation succeeded');
check($wpdb->ticket['status']==='cancelled' && $wpdb->inventory['status']==='available' &&
    $wpdb->inventory['hold_id'] === null, 'ticket revoked and stock released');
check($wpdb->holdItems === [11] && count($wpdb->audit) === 1, 'other seat preserved and audited');
$again=$service->cancel(4,2);
check($again['idempotent_replay'] && count($wpdb->audit)===1, 'repeat cancellation idempotent');
echo "PASS: {$n} free-ticket cancellation fake-DB checks; no live DB tested.\n";
