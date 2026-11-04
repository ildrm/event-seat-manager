<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

/** Behavioral fake: tests atomic state transitions, NOT real MySQL isolation. */
require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Domain/Booking/CapacityPolicy.php';
require __DIR__ . '/../src/Domain/Checkout/CheckoutPolicy.php';
require __DIR__ . '/../src/Domain/Checkout/AttendeeManifest.php';
require __DIR__ . '/../src/Domain/Ticket/TicketToken.php';
require __DIR__ . '/../src/Domain/GeneralAdmission/AdmissionPolicy.php';
require __DIR__ . '/../src/Support/Audit.php';
require __DIR__ . '/../src/Domain/GeneralAdmission/FreeAdmissionService.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\GeneralAdmission\AdmissionPolicy;
use PESM\Domain\GeneralAdmission\FreeAdmissionService;
use PESM\Domain\Ticket\TicketToken;

function get_option(string $key, mixed $default = false): mixed { return $key === 'pesm_schema_version' ? 10 : $default; }
function wp_salt(string $name): string { return 'test-only-unusable-private-secret'; }
function wc_get_order(int $id): object|false { return $id === 99 ? new StubOrder() : false; }

final class StubOrder {
    public function get_meta(string $key): mixed { return [
        '_pesm_mode'=>'general', '_pesm_is_ticket_order'=>'yes', '_pesm_capacity_hold_id'=>12,
        '_pesm_attendees_v1'=>($GLOBALS['attendee_snapshot'] ?? null),
        '_pesm_session_id'=>7,
        '_pesm_unit_price_minor'=>0,'_pesm_total_minor'=>0,'_pesm_price_decimals'=>0,'_pesm_currency'=>'IRR',
    ][$key] ?? null; }
    public function get_status(): string { return 'pending'; }
    public function get_total(): string { return '0'; }
    public function get_currency(): string { return 'IRR'; }
    public function get_billing_first_name(): string { return 'نرگس'; }
    public function get_billing_last_name(): string { return 'نمونه'; }
}

final class AdmissionFakeDb {
    public string $prefix='wp_';
    public string $now='2026-11-02 12:00:00';
    public array $inventory=['session_id'=>7,'total_capacity'=>900,'blocked_units'=>0,'held_units'=>0,'pending_units'=>3,'sold_units'=>0,'version'=>0];
    public array $hold=['id'=>12,'session_id'=>7,'quantity'=>3,'order_id'=>99,'status'=>'payment_pending','expires_at'=>'2026-11-02 12:10:00'];
    public array $link=['id'=>8,'hold_id'=>12,'order_id'=>99,'status'=>'pending'];
    public array $session=['id'=>7,'mode'=>'general','unit_price_minor'=>0,'status'=>'published','event_status'=>'published'];
    public array $tickets=[];
    public array $audit=[];
    public int $insert_id=0;
    public ?int $failTicketIndex=null;
    public bool $failAudit=false;
    private ?string $snapshot=null;

    public function prepare(string $sql, mixed ...$values): string {
        foreach ($values as $value) {
            $replacement=is_int($value) ? (string)$value : "'".str_replace("'","''",(string)$value)."'";
            $sql=preg_replace('/%[sd]/',$replacement,$sql,1);
        }
        return $sql;
    }
    public function query(string $sql): int|false {
        if ($sql==='START TRANSACTION') { $this->snapshot=serialize([$this->inventory,$this->hold,$this->link,$this->tickets,$this->audit]);return 1; }
        if ($sql==='COMMIT') { $this->snapshot=null;return 1; }
        if ($sql==='ROLLBACK') {
            if ($this->snapshot!==null) [$this->inventory,$this->hold,$this->link,$this->tickets,$this->audit]=unserialize($this->snapshot);
            $this->snapshot=null;return 1;
        }
        if (str_contains($sql,'UPDATE wp_pesm_session_capacity SET pending_units=pending_units-')) {
            preg_match('/pending_units=pending_units-(\d+)/',$sql,$m);$n=(int)($m[1]??0);
            if ($this->inventory['pending_units'] < $n) return 0;
            $this->inventory['pending_units']-=$n;
            if (str_contains($sql,'sold_units=sold_units+')) $this->inventory['sold_units']+=$n;
            ++$this->inventory['version'];return 1;
        }
        throw new RuntimeException('Unexpected SQL query: '.$sql);
    }
    public function get_var(string $sql): mixed {
        if ($sql==='SELECT UTC_TIMESTAMP()') return $this->now;
        if (str_contains($sql,'SELECT id FROM wp_pesm_sessions')) return 7;
        if (str_contains($sql,'SELECT session_id FROM wp_pesm_session_capacity')) return 7;
        throw new RuntimeException('Unexpected get_var: '.$sql);
    }
    public function get_row(string $sql): ?object {
        if (str_contains($sql,'FROM wp_pesm_sessions s')) return (object)$this->session;
        if (str_contains($sql,'FROM wp_pesm_session_capacity')) return (object)$this->inventory;
        if (str_contains($sql,'FROM wp_pesm_capacity_holds')) return (object)$this->hold;
        if (str_contains($sql,'FROM wp_pesm_capacity_order_links')) return (object)$this->link;
        throw new RuntimeException('Unexpected get_row: '.$sql);
    }
    public function get_results(string $sql): array {
        if (str_contains($sql,'FROM wp_pesm_capacity_tickets')) return array_map(fn($t)=>(object)$t,$this->tickets);
        throw new RuntimeException('Unexpected get_results: '.$sql);
    }
    public function insert(string $table, array $data): int|false {
        if ($table==='wp_pesm_capacity_tickets') {
            if ($this->failTicketIndex === $data['admission_index']) return false;
            foreach ($this->tickets as $ticket) if ($ticket['hold_id']===$data['hold_id'] && $ticket['admission_index']===$data['admission_index']) return false;
            $this->tickets[]=['id'=>count($this->tickets)+1]+$data;
            return 1;
        }
        if ($table==='wp_pesm_audit_logs') { if ($this->failAudit) return false; $this->audit[]=$data;return 1; }
        throw new RuntimeException('Unexpected insert: '.$table);
    }
    public function update(string $table,array $data,array $where): int|false {
        if ($table==='wp_pesm_capacity_holds') {
            if (isset($where['status']) && $this->hold['status']!==$where['status']) return 0;
            $this->hold=array_replace($this->hold,$data);return 1;
        }
        if ($table==='wp_pesm_capacity_order_links') { $this->link=array_replace($this->link,$data);return 1; }
        if ($table==='wp_pesm_capacity_tickets') {
            foreach ($this->tickets as &$t) {
                if ($t['id']!==$where['id'] || (isset($where['status']) && $t['status']!==$where['status'])) continue;
                $t=array_replace($t,$data);return 1;
            }
            return 0;
        }
        throw new RuntimeException('Unexpected update: '.$table);
    }
}

$n=0;
function ok(bool $condition,string $msg):void { global $n;++$n;if(!$condition)throw new RuntimeException($msg); }
function expected(callable $fn,string $error):void {
    try {$fn();} catch(HoldException $e){ok($e->errorCode===$error,'Incorrect error: '.$e->errorCode);return;}
    throw new RuntimeException('Expected '.$error);
}
expected(fn()=>AdmissionPolicy::assertFree(100,'general'),'paid_admission_disabled');
expected(fn()=>AdmissionPolicy::assertFree(0,'seated'),'invalid_session');
AdmissionPolicy::assertFree(0,'general');ok(true,'Free mode');
expected(fn()=>AdmissionPolicy::assertPending((object)['status'=>'expired','expires_at'=>'2026-11-02 13:00:00'],'2026-11-02 12:00:00'),'reconciliation_required');
expected(fn()=>AdmissionPolicy::assertPending((object)['status'=>'payment_pending','expires_at'=>'2026-11-02 12:00:00'],'2026-11-02 12:00:00'),'reconciliation_required');
AdmissionPolicy::assertPending((object)['status'=>'payment_pending','expires_at'=>'2026-11-02 13:00:00'],'2026-11-02 12:00:00');ok(true,'Pending status');
foreach ([1,20] as $q) {AdmissionPolicy::assertHold((object)['status'=>'active','expires_at'=>'2026-11-02 13:00:00','quantity'=>$q],'2026-11-02 12:00:00');ok(true,'Quantity');}
expected(fn()=>AdmissionPolicy::assertHold((object)['status'=>'active','expires_at'=>'2026-11-02 13:00:00','quantity'=>21],'2026-11-02 12:00:00'),'invalid_quantity');
expected(fn()=>AdmissionPolicy::assertHold((object)['status'=>'active','expires_at'=>'2026-11-02 12:00:00','quantity'=>1],'2026-11-02 12:00:00'),'hold_expired');

$wpdb=new AdmissionFakeDb();
$service=new FreeAdmissionService();
$wpdb->failTicketIndex=2;
expected(fn()=>$service->confirmFree(99),'ticket_write_failed');
ok(count($wpdb->tickets)===0,'Failed issuance rolled back all tickets');
ok($wpdb->inventory['pending_units']===3 && $wpdb->inventory['sold_units']===0,'Failed issuance preserves counters');
ok($wpdb->hold['status']==='payment_pending','Failed issuance preserves hold');
$wpdb->failTicketIndex=null;
ok($service->confirmFree(99)==='issued','Ticket issuance');
ok(count($wpdb->tickets)===3,'Individual ticket per admission');
ok(count(array_unique(array_column($wpdb->tickets,'ticket_number')))===3,'Unique ticket numbers');
ok($wpdb->inventory['pending_units']===0 && $wpdb->inventory['sold_units']===3,'Atomic capacity transition');
ok($wpdb->hold['status']==='issued' && $wpdb->link['status']==='issued','Both states issued');
ok(count($wpdb->audit)===1,'Audit inserted');
ok($service->confirmFree(99)==='already_issued','Idempotent callback');
ok(count($wpdb->tickets)===3 && count($wpdb->audit)===1,'No double issuance on replay');
$wpdb->now='2026-11-02 12:11:00';
ok($service->confirmFree(99)==='already_issued','Already issued remains available after hold deadline');
$wpdb->now='2026-11-02 12:00:00';
ok($service->revokeOrder(99)==='cancelled','Refund/cancel revoke succeeds');
ok(count(array_filter($wpdb->tickets,fn($t)=>$t['status']==='cancelled'))===3,'Every ticket revoked');
ok($wpdb->inventory['sold_units']===3,'Sold capacity not silently resold');
ok($service->revokeOrder(99)==='already_cancelled','Cancellation idempotent');
$wpdb=new AdmissionFakeDb();
$wpdb->hold['expires_at']='2026-11-02 11:59:00';
ok($service->confirmFree(99)==='review_required','Expired pending not issued');
ok(count($wpdb->tickets)===0,'Expired pending does not issue ticket');
ok($wpdb->link['status']==='review_required','Expired pending marked for supervisor');
$wpdb=new AdmissionFakeDb();$wpdb->failAudit=true;
expected(fn()=>$service->confirmFree(99),'ticket_write_failed');
ok(count($wpdb->tickets)===0 && $wpdb->inventory['pending_units']===3,'Audit failure rolls back issuance');
$wpdb=new AdmissionFakeDb();$wpdb->session['unit_price_minor']=1;
expected(fn()=>$service->confirmFree(99),'paid_admission_disabled');
ok(count($wpdb->tickets)===0,'Paid session cannot be issued as free');
// Each issued GA ticket must use its own attendee, rather than billing details.
$GLOBALS['attendee_snapshot'] = \PESM\Domain\Checkout\AttendeeManifest::general([
 ['first_name'=>'زهرا','last_name'=>'کریمی'],
 ['first_name'=>'مریم','last_name'=>'رضایی'],
 ['first_name'=>'رضا','last_name'=>'صالحی'],
],3,'نرگس','نمونه');
$wpdb=new AdmissionFakeDb();
ok($service->confirmFree(99)==='issued','Per-person GA issue from Woo order snapshot');
ok(array_column($wpdb->tickets,'attendee_name')===['زهرا کریمی','مریم رضایی','رضا صالحی'], 'Each GA person maps to own ticket');
unset($GLOBALS['attendee_snapshot']);
echo "PASS: {$n} free-general-admission policy/transaction assertions (fake DB, NOT InnoDB).\n";
