<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

/** Behavioral fake DB: no MySQL isolation, WooCommerce checkout or bank/gateway involved. */
require_once __DIR__ . '/../src/Domain/Booking/HoldException.php';
require_once __DIR__ . '/../src/Domain/Ticket/RefundPolicy.php';
require_once __DIR__ . '/../src/Domain/Ticket/RefundReviewService.php';
require_once __DIR__ . '/../src/Security/Capabilities.php';
require_once __DIR__ . '/../src/Support/Audit.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Ticket\RefundReviewService;

function get_option(string $key, mixed $default = false): mixed {
    if ($key==='pesm_schema_version') return 15;
    return $default;
}
function user_can(int $actor, string $permission): bool { return $actor === 2 && $permission === 'pesm_manage_refunds'; }
function wp_salt(string $context): string { return str_repeat('Z', 40); }
function wc_get_price_decimals(): int { return 2; }
class Item21 {
    public function __construct(private int $id, private int $quantity, private string $total, private int $session, private int $seat) {}
    public function get_meta(string $key): int {
        return match ($key) {
            '_pesm_session_id'=>$this->session, '_pesm_seat_id'=>$this->seat, '_refunded_item_id'=>$this->id, default=>0
        };
    }
    public function get_quantity(): int { return $this->quantity; }
    public function get_total(): string { return $this->total; }
    public function get_total_tax(): string { return '0.00'; }
}
class Refund21 {
    public function __construct(private array $items) {}
    public function get_items(string $type): array { return $this->items; }
    public function get_id(): int { return 200; }
}
class Order21 {
    public bool $hasRefund = false;
    public string $refundAmount = '-42.00';
    public function get_meta(string $name): string { return $name==='_pesm_is_ticket_order'?'yes':''; }
    public function is_paid(): bool { return true; }
    public function get_items(string $type): array {return [90=>new Item21(90,1,'42.00',5,11)];}
    public function get_total_refunded_for_item(int $id): string { return $this->hasRefund?'42.00':'0'; }
    public function get_refunds(): array {
        return $this->hasRefund ? [new Refund21([new Item21(90,-1,$this->refundAmount,5,11)])] : [];
    }
    public function get_currency(): string { return 'USD'; }
}
$order21 = new Order21();
function wc_get_order(int $id): ?Order21 {global $order21;return $id===99?$order21:null;}
class FakeRefundDB {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public array $ticket = ['id'=>7,'session_id'=>5,'hold_id'=>4,'order_id'=>99,'seat_id'=>11,'status'=>'active'];
    public array $inventory = ['status'=>'sold','hold_id'=>4,'seat_id'=>11,'session_id'=>5];
    public array $holdItems = [11,12];
    public ?array $case = null;
    public array $audit = [];
    public bool $failCase = false;
    public bool $failRelease = false;
    public bool $failClose = false;
    public bool $checked = false;
    public int $center = 3;
    public string $start = '2026-11-05 10:00:00';
    private ?array $snapshot = null;
    public function prepare(string $sql, mixed ...$args): string {
        return vsprintf($sql, array_map(static fn($v)=>is_int($v)?$v:"'".str_replace("'","''",(string)$v)."'", $args));
    }
    public function get_var(string $sql): mixed {
        if ($sql === 'SELECT UTC_TIMESTAMP()') return '2026-11-03 12:00:00';
        if (str_contains($sql, 'FROM wp_pesm_center_users')) return $this->center===3?1:null;
        if (str_contains($sql, 'FROM wp_pesm_checkins')) return $this->checked?1:null;
        throw new RuntimeException('Unknown get_var '.$sql);
    }
    public function get_row(string $sql): ?object {
        if (str_contains($sql, 'FROM wp_pesm_tickets t')) return (object)['session_id'=>5,'hold_id'=>4,'order_id'=>99,'center_id'=>$this->center];
        if (str_contains($sql,'FROM wp_pesm_sessions WHERE')) return (object)['id'=>5,'starts_at'=>$this->start];
        if (str_contains($sql,'FROM wp_pesm_holds')) return (object)['id'=>4,'status'=>'sold','order_id'=>99];
        if (str_contains($sql,'FROM wp_pesm_order_links')) return (object)['id'=>8,'status'=>'issued'];
        if (str_contains($sql,'FROM wp_pesm_refund_cases')) return $this->case?(object)$this->case:null;
        if (str_contains($sql,'FROM wp_pesm_tickets WHERE')) return (object)$this->ticket;
        if (str_contains($sql,'FROM wp_pesm_session_seats')) return (object)$this->inventory;
        throw new RuntimeException('Unknown get_row '.$sql);
    }
    public function insert(string $table, array $values): int {
        if ($table==='wp_pesm_refund_cases') {
            if ($this->failCase || $this->case) return 0;
            $this->case = ['id'=>25,'wc_refund_id'=>0, ...$values]; $this->insert_id=25; return 1;
        }
        if ($table==='wp_pesm_audit_logs') {$this->audit[]=$values;return 1;}
        throw new RuntimeException('Unknown insert '.$table);
    }
    public function update(string $table, array $data, array $where): int {
        $ref = match($table) {
            'wp_pesm_tickets'=>'ticket', 'wp_pesm_session_seats'=>'inventory', 'wp_pesm_refund_cases'=>'case',
            default=>throw new RuntimeException('Unknown update '.$table),
        };
        if ($ref==='case' && $this->case===null) return 0;
        foreach ($where as $key=>$value) {
            if (($this->{$ref}[$key] ?? null) !== $value) return 0;
        }
        if ($table==='wp_pesm_session_seats' && $this->failRelease) return 0;
        if ($table==='wp_pesm_refund_cases' && $this->failClose) return 0;
        $this->{$ref}=array_replace($this->{$ref},$data); return 1;
    }
    public function delete(string $table, array $where): int {
        if ($table!=='wp_pesm_hold_items')throw new RuntimeException('Unknown delete '.$table);
        if (!in_array($where['seat_id'],$this->holdItems,true))return 0;
        $this->holdItems=array_values(array_diff($this->holdItems,[$where['seat_id']]));return 1;
    }
    public function query(string $sql): int {
        if ($sql==='START TRANSACTION') { $this->snapshot=[$this->ticket,$this->inventory,$this->case,$this->holdItems,$this->audit];return 1; }
        if ($sql==='COMMIT'){$this->snapshot=null;return 1;}
        if ($sql==='ROLLBACK'){
            if ($this->snapshot!==null)[$this->ticket,$this->inventory,$this->case,$this->holdItems,$this->audit]=$this->snapshot;
            $this->snapshot=null;return 1;
        }
        throw new RuntimeException('Unknown query '.$sql);
    }
}
$wpdb = new FakeRefundDB();
$service = new RefundReviewService();
$checks=0;
function rcheck(bool $value,string $label):void {global $checks;if(!$value)throw new RuntimeException('FAIL '.$label);$checks++;}
function rreject(callable $fn,string $code):void {
    try {$fn();}
    catch(HoldException $e){rcheck($e->errorCode===$code,'expected '.$code.' got '.$e->errorCode);return;}
    throw new RuntimeException('Expected failure '.$code);
}
$key=str_repeat('c',32);
rreject(fn()=>$service->request(7,'علت ثبت استرداد',$key,3),'permission_denied');
$wpdb->center=4;
rreject(fn()=>$service->request(7,'علت ثبت استرداد',$key,2),'permission_denied');
$wpdb->center=3;
$wpdb->checked=true;
rreject(fn()=>$service->request(7,'علت ثبت استرداد',$key,2),'ticket_not_refundable');
$wpdb->checked=false;
$wpdb->failCase=true;
rreject(fn()=>$service->request(7,'علت ثبت استرداد',$key,2),'refund_write_failed');
rcheck($wpdb->case===null&&$wpdb->ticket['status']==='active','request rollback');
$wpdb->failCase=false;
$result=$service->request(7,'علت ثبت استرداد',$key,2);
rcheck($result['status']==='awaiting_woocommerce'&&$result['amount_minor']===4200,'request holds ticket before refund');
rcheck($wpdb->ticket['status']==='refund_pending' && $wpdb->inventory['status']==='sold','seat stays occupied');
rcheck(count($wpdb->audit)===1,'request audited');
$again=$service->request(7,'علت ثبت استرداد',$key,2);
rcheck($again['replayed'] && count($wpdb->audit)===1,'request replay');
rreject(fn()=>$service->request(7,'علت استرداد متفاوت', $key,2),'idempotency_conflict');
rreject(fn()=>$service->request(7,'علت ثبت استرداد',str_repeat('d',32),2),'refund_case_exists');
rreject(fn()=>$service->reconcile(7,2),'refund_not_recorded');
rcheck($wpdb->ticket['status']==='refund_pending','unpaid remains blocked');
$order21->hasRefund=true;
$order21->refundAmount='-40.00';
rreject(fn()=>$service->reconcile(7,2),'refund_not_recorded');
rcheck($wpdb->ticket['status']==='refund_pending','wrong refund cannot release stock');
$order21->refundAmount='-42.00';
$wpdb->failClose=true;
rreject(fn()=>$service->reconcile(7,2),'refund_write_failed');
rcheck($wpdb->ticket['status']==='refund_pending'&&$wpdb->inventory['status']==='sold','failed case close rolls back release');
$wpdb->failClose=false;
$wpdb->failRelease=true;
rreject(fn()=>$service->reconcile(7,2),'refund_write_failed');
rcheck($wpdb->ticket['status']==='refund_pending'&&$wpdb->case['status']==='awaiting_woocommerce','finalize rollback');
$wpdb->failRelease=false;
$final=$service->reconcile(7,2);
rcheck($final['status']==='finalized'&&$final['seat_released'],'refund reconciled');
rcheck($wpdb->ticket['status']==='refunded'&&$wpdb->inventory['status']==='available','ticket revoked and seat freed');
rcheck($wpdb->holdItems===[12] && count($wpdb->audit)===2,'other seats unaffected');
$replay=$service->reconcile(7,2);
rcheck($replay['replayed'] && count($wpdb->audit)===2,'idempotent finalize');
// A finance reconciliation after session start may invalidate entry but cannot re-list an old seat.
$wpdb = new FakeRefundDB(); $order21->hasRefund = false;
$service->request(7,'علت ثبت استرداد',str_repeat('e',32),2);
$order21->hasRefund = true;
$wpdb->start = '2026-11-02 10:00:00';
$late = $service->reconcile(7,2);
rcheck($late['status']==='finalized'&&!$late['seat_released'],'late reconciliation does not release sold inventory');
rcheck($wpdb->inventory['status']==='sold'&&$wpdb->ticket['status']==='refunded','late refund revokes entry but keeps seat blocked');
echo "PASS: {$checks} refund request/reconcile fake-DB checks (not real InnoDB).\n";
