<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

/** In-memory transactional test double. Not an InnoDB concurrency or WooCommerce test. */
require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Domain/Event/EventChangePolicy.php';
require __DIR__ . '/../src/Domain/Event/EventChangeService.php';
require __DIR__ . '/../src/Security/Capabilities.php';
require __DIR__ . '/../src/Support/Audit.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Event\EventChangeService;

function get_option(string $key, mixed $fallback = null): mixed { return $key === 'pesm_schema_version' ? 14 : $fallback; }
function user_can(int $id, string $cap): bool { return $id === 2 && in_array($cap, ['pesm_manage_events','pesm_manage_sessions','pesm_manage_refunds'], true); }
function wp_salt(string $type): string { return str_repeat('S', 44); }

class ChangeFakeDB
{
    public string $prefix='wp_';
    public array $event=['id'=>1,'center_id'=>10,'status'=>'published'];
    public array $sessions=[4=>['id'=>4,'event_id'=>1,'status'=>'published','starts_at'=>'2026-12-01 18:00:00','sale_ends_at'=>'2026-11-30 20:00:00']];
    public array $orders=[34,35];
    public array $changes=[];
    public array $cases=[];
    public array $audit=[];
    public int $pending=0;
    public int $admitted=0;
    public bool $failCase=false;
    public int $insert_id=0;
    private ?array $snapshot=null;
    public function prepare(string $sql, mixed ...$args): string
    {
        return vsprintf($sql,array_map(static fn($a)=>is_int($a)?$a:"'".str_replace("'","''",(string)$a)."'",$args));
    }
    public function query(string $sql): int|false
    {
        if ($sql === 'START TRANSACTION') { $this->snapshot=[$this->event,$this->sessions,$this->changes,$this->cases,$this->audit,$this->insert_id]; return 1; }
        if ($sql === 'COMMIT') { $this->snapshot=null; return 1; }
        if ($sql === 'ROLLBACK') { if($this->snapshot){[$this->event,$this->sessions,$this->changes,$this->cases,$this->audit,$this->insert_id]=$this->snapshot;} $this->snapshot=null;return 1; }
        throw new RuntimeException('unhandled query '.$sql);
    }
    public function get_var(string $sql): mixed
    {
        if ($sql === 'SELECT UTC_TIMESTAMP()') return '2026-11-02 12:00:00';
        if (str_contains($sql,'FROM wp_pesm_center_users')) return 1;
        if (str_contains($sql,'SELECT center_id FROM wp_pesm_events')) return $this->event['center_id'];
        if (str_contains($sql,'SELECT e.center_id FROM wp_pesm_sessions')) return $this->event['center_id'];
        if (str_contains($sql,'SELECT event_id FROM wp_pesm_sessions')) return 1;
        if (str_contains($sql,'FROM wp_pesm_holds') || str_contains($sql,'FROM wp_pesm_capacity_holds')) return $this->pending;
        if (str_contains($sql,'FROM wp_pesm_checkins') || str_contains($sql,'FROM wp_pesm_capacity_checkins')) return $this->admitted;
        throw new RuntimeException('unhandled get_var '.$sql);
    }
    public function get_row(string $sql): ?object
    {
        if (str_contains($sql,'FROM wp_pesm_events WHERE id=')) return (object)$this->event;
        if (str_contains($sql,'FROM wp_pesm_event_changes WHERE request_hash=')) {
            foreach ($this->changes as $change) if (str_contains($sql,"'".$change['request_hash']."'")) return (object)$change;
            return null;
        }
        if (str_contains($sql,'FROM wp_pesm_event_changes WHERE id=')) return isset($this->changes[1])?(object)$this->changes[1]:null;
        return null;
    }
    public function get_results(string $sql): array
    {
        if (str_contains($sql,'FROM wp_pesm_sessions WHERE event_id')) return array_map(static fn($r)=>(object)$r, $this->sessions);
        if (str_contains($sql,'FROM wp_pesm_event_change_cases')) return array_map(static fn($c)=>(object)$c,$this->cases);
        throw new RuntimeException('unhandled get_results '.$sql);
    }
    public function get_col(string $sql): array
    {
        if (str_contains($sql,'SELECT DISTINCT order_id')) return $this->orders;
        throw new RuntimeException('unhandled get_col '.$sql);
    }
    public function update(string $table,array $data,array $where): int
    {
        if ($table === 'wp_pesm_events') {
            foreach ($where as $key=>$value) if($this->event[$key]!==$value) return 0;
            $this->event=array_replace($this->event,$data);return 1;
        }
        if ($table === 'wp_pesm_sessions') {
            $id=(int)$where['id'];
            foreach($where as $key=>$value) if($this->sessions[$id][$key]!==$value) return 0;
            $this->sessions[$id]=array_replace($this->sessions[$id],$data);return 1;
        }
        throw new RuntimeException('unhandled update '.$table);
    }
    public function insert(string $table,array $data): int
    {
        if($table==='wp_pesm_event_changes') {
            $data['id']=count($this->changes)+1;$this->insert_id=$data['id'];$this->changes[$data['id']]=$data;return 1;
        }
        if($table==='wp_pesm_event_change_cases') { if($this->failCase) return 0; $this->cases[]=$data;return 1; }
        if($table==='wp_pesm_audit_logs') {$this->audit[]=$data;return 1;}
        throw new RuntimeException('unhandled insert '.$table);
    }
    public function reset(): void {
        $this->event['status']='published';$this->sessions[4]['status']='published';
        $this->sessions[4]['starts_at']='2026-12-01 18:00:00';
        $this->changes=[];$this->cases=[];$this->audit=[];$this->pending=0;$this->admitted=0;
        $this->failCase=false;$this->insert_id=0;$this->orders=[34,35];
    }
}
$wpdb=new ChangeFakeDB();
function ck(bool $condition,string $message): void {if(!$condition) throw new RuntimeException('FAIL: '.$message);}
function failCode(callable $work,string $code): void {
    try { $work(); throw new RuntimeException('Expected '.$code); }
    catch (HoldException $e) {ck($e->errorCode===$code,'expected '.$code.' got '.$e->errorCode);}
}
$service=new EventChangeService();
$key=str_repeat('f',40);
$reason='تغییر برنامه به علت اختلال در سالن اصلی';
$r=$service->cancel('event',1,$reason,$key,2);
ck($r['affected_orders']===2 && !$r['replayed'],'records 2 distinct orders');
ck($wpdb->event['status']==='cancelled' && $wpdb->sessions[4]['status']==='cancelled','both statuses cancelled');
ck(count($wpdb->cases)===2 && count($wpdb->audit)===1,'ledger and audit created');
$again=$service->cancel('event',1,$reason,$key,2);
ck($again['replayed'] && count($wpdb->cases)===2,'replay without duplicate');
failCode(fn()=>$service->cancel('event',1,$reason.' اضافی',$key,2),'idempotency_conflict');
$cases=$service->cases(1,2); ck(count($cases['orders'])===2 && $cases['orders'][0]['status']==='review_required','scoped finance case read');
failCode(fn()=>$service->cases(1,3),'permission_denied');
$wpdb->reset();$wpdb->failCase=true;
failCode(fn()=>$service->cancel('session',4,$reason,str_repeat('a',40),2),'write_failed');
ck($wpdb->sessions[4]['status']==='published' && $wpdb->changes===[],'atomic rollback when ledger insert fails');
$wpdb->reset();$wpdb->pending=1;
failCode(fn()=>$service->postponeSession(4,'2026-12-04 18:00:00',$reason,str_repeat('b',40),2),'change_conflict');
ck($wpdb->sessions[4]['starts_at']==='2026-12-01 18:00:00','no date mutation on active lease');
$wpdb->pending=0;$wpdb->admitted=1;
failCode(fn()=>$service->postponeSession(4,'2026-12-04 18:00:00',$reason,str_repeat('c',40),2),'change_conflict');
$wpdb->admitted=0;
failCode(fn()=>$service->postponeSession(4,'2026-11-30 12:00:00',$reason,str_repeat('d',40),2),'invalid_new_date');
$r=$service->postponeSession(4,'2026-12-04 18:00:00',$reason,str_repeat('e',40),2);
ck($wpdb->sessions[4]['starts_at']==='2026-12-04 18:00:00' && $r['affected_orders']===2,'new date and case ledger');
ck($wpdb->sessions[4]['status']==='paused','rescheduled session paused until manual customer notification');
$wpdb->reset();$wpdb->orders=range(1,2001);
failCode(fn()=>$service->cancel('event',1,$reason,str_repeat('a',42),2),'too_many_orders');
ck($wpdb->event['status']==='published','oversized batch refused safely');
$wpdb->reset();
failCode(fn()=>$service->cancel('event',1,$reason,str_repeat('a',10),2),'invalid_request_key');
failCode(fn()=>$service->cancel('event',1,'کوتاه',str_repeat('a',40),2),'invalid_reason');
echo "event-change simulated DB tests: PASS (14 checks and negative cases)\n";
