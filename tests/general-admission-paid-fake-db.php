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

function get_option(string $key, mixed $default = false): mixed {
    return $key === 'pesm_schema_version' ? 10 : ($key === 'pesm_enable_paid_general_preview' ? $GLOBALS['paid_preview'] : $default);
}
function wc_tax_enabled(): bool { return $GLOBALS['tax_enabled']; }
function wc_get_price_decimals(): int { return $GLOBALS['shop_decimals'] ?? 2; }

function wp_salt(string $name): string { return 'test-only-unusable-private-secret'; }
function wc_get_order(int $id): object|false { return $id === 99 ? new StubOrder() : false; }

final class StubOrder {
    public function is_paid(): bool { return $GLOBALS['paid_verified']; }
    public function get_date_paid(): ?DateTimeImmutable { return $GLOBALS['paid_date'] ? new DateTimeImmutable('2026-11-02T12:00:00Z') : null; }
    public function get_meta(string $key): mixed { return [
        '_pesm_mode'=>'general', '_pesm_is_ticket_order'=>'yes', '_pesm_capacity_hold_id'=>12,
        '_pesm_attendees_v1'=>($GLOBALS['attendee_snapshot'] ?? null),
        '_pesm_session_id'=>7, '_pesm_unit_price_minor'=>($GLOBALS['snapshot_unit'] ?? 100), '_pesm_total_minor'=>($GLOBALS['snapshot_total'] ?? 300),
        '_pesm_currency'=>($GLOBALS['snapshot_currency'] ?? 'USD'), '_pesm_price_decimals'=>($GLOBALS['snapshot_decimals'] ?? 2),
    ][$key] ?? null; }
    public function get_status(): string { return 'processing'; }
    public function get_total(): string { return $GLOBALS['order_total'] ?? '3.00'; }
    public function get_currency(): string { return $GLOBALS['order_currency'] ?? 'USD'; }
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

$GLOBALS['paid_preview'] = 'no';
$GLOBALS['tax_enabled'] = false;
$GLOBALS['paid_verified'] = true;
$GLOBALS['paid_date'] = true;
$GLOBALS['order_total'] = '3.00';
$GLOBALS['snapshot_unit'] = 100;
$GLOBALS['snapshot_total'] = 300;
expected(fn()=>AdmissionPolicy::assertCheckoutAllowed(100,'general'), 'paid_admission_disabled');
$GLOBALS['paid_preview'] = 'yes';
$GLOBALS['tax_enabled'] = true;
expected(fn()=>AdmissionPolicy::assertCheckoutAllowed(100,'general'), 'paid_tax_unsupported');
$GLOBALS['tax_enabled'] = false;
AdmissionPolicy::assertCheckoutAllowed(100,'general');ok(true, 'Opt-in paid GA with tax disabled');
AdmissionPolicy::assertCheckoutAllowed(0,'general');ok(true, 'Free GA unaffected');
expected(fn()=>AdmissionPolicy::assertCheckoutAllowed(-1,'general'),'invalid_session');
expected(fn()=>AdmissionPolicy::assertCheckoutAllowed(100,'seated'),'invalid_session');
$service = new FreeAdmissionService();
$wpdb = new AdmissionFakeDb();
$wpdb->session['unit_price_minor'] = 100;
$GLOBALS['paid_verified'] = false;
expected(fn()=>$service->confirmPaid(99), 'payment_unverified');
ok($wpdb->inventory['pending_units'] === 3 && $wpdb->tickets === [], 'No issuance for unpaid order');
$GLOBALS['paid_verified'] = true;
$GLOBALS['paid_date'] = false;
expected(fn()=>$service->confirmPaid(99), 'payment_unverified');
$GLOBALS['paid_date'] = true;
$GLOBALS['order_total'] = '3.01';
expected(fn()=>$service->confirmPaid(99), 'price_mismatch');
ok($wpdb->tickets === [], 'Tampered total not issued');
$GLOBALS['order_total'] = '3.00';
$GLOBALS['snapshot_total'] = 400;
expected(fn()=>$service->confirmPaid(99), 'price_mismatch');
$GLOBALS['snapshot_total'] = 300;
$GLOBALS['order_currency']='EUR';
expected(fn()=>$service->confirmPaid(99),'price_mismatch');
ok($wpdb->tickets===[],'Currency mismatch never issues paid GA');
$GLOBALS['order_currency']='USD';$GLOBALS['snapshot_currency']='';
expected(fn()=>$service->confirmPaid(99),'price_mismatch');
$GLOBALS['snapshot_currency']='USD';$GLOBALS['snapshot_decimals']='';
expected(fn()=>$service->confirmPaid(99),'price_mismatch');
$GLOBALS['snapshot_decimals']=2;$GLOBALS['shop_decimals']=0;
$wpdb->failTicketIndex = 2;
expected(fn()=>$service->confirmPaid(99), 'ticket_write_failed');
ok($wpdb->tickets === [] && $wpdb->inventory['pending_units'] === 3, 'Partial ticket insert rolled back');
$wpdb->failTicketIndex = null;
ok($service->confirmPaid(99) === 'issued', 'Paid confirmation issues one ticket per admission');
ok(count($wpdb->tickets) === 3 && $wpdb->inventory['sold_units'] === 3 && $wpdb->inventory['pending_units'] === 0, 'Atomic paid issuance');
ok($service->confirmPaid(99) === 'already_issued', 'Replayed callback never duplicates tickets');
ok(count($wpdb->tickets) === 3, 'Replay count remains stable');
$wpdb = new AdmissionFakeDb();
$wpdb->session['unit_price_minor'] = 100;
$wpdb->hold['expires_at'] = '2026-11-02 11:59:00';
ok($service->confirmPaid(99) === 'review_required', 'Late paid callback never steals expired capacity');
ok($wpdb->tickets === [] && $wpdb->link['status'] === 'review_required', 'Late payment flagged for review, not issued');
expected(fn()=>$service->confirmFree(99), 'invalid_order');
$GLOBALS['attendee_snapshot'] = \PESM\Domain\Checkout\AttendeeManifest::general([
 ['first_name'=>'زهرا','last_name'=>'کریمی'],
 ['first_name'=>'مریم','last_name'=>'رضایی'],
 ['first_name'=>'رضا','last_name'=>'صالحی'],
],3,'نرگس','نمونه');
$wpdb=new AdmissionFakeDb();$wpdb->session['unit_price_minor']=100;
ok($service->confirmPaid(99)==='issued','Paid GA names from immutable order snapshot');
ok(array_column($wpdb->tickets,'attendee_name')===['زهرا کریمی','مریم رضایی','رضا صالحی'], 'Paid GA per-person assignment');
unset($GLOBALS['attendee_snapshot']);
echo "PASS: {$n} paid-general-admission fake-DB assertions (NOT a live payment or concurrent InnoDB test).\n";
