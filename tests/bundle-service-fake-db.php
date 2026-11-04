<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

const ARRAY_A = 'ARRAY_A';
require_once __DIR__ . '/../src/Domain/Booking/HoldException.php';
require_once __DIR__ . '/../src/Domain/Booking/HoldPolicy.php';
require_once __DIR__ . '/../src/Domain/Ticket/BundlePolicy.php';
require_once __DIR__ . '/../src/Security/Capabilities.php';
require_once __DIR__ . '/../src/Support/Audit.php';
require_once __DIR__ . '/../src/Domain/Ticket/BundleService.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Ticket\BundleService;

function get_option(string $key, mixed $default=false): mixed {
    return $key === 'pesm_schema_version' ? 18 : ($key === 'pesm_ticket_bundles_preview_enabled' ? 'yes' : $default);
}
function user_can(int $actor, string $cap): bool { return $actor === 7 && $cap === 'pesm_issue_tickets'; }
function wp_salt(string $scope): string { return str_repeat('p', 48); }
function is_email(string $email): bool { return filter_var($email, FILTER_VALIDATE_EMAIL) !== false; }
class BundleOrderStub {
    public string $email='bundle@example.com'; public string $currency='IRR'; public string $status='completed';
    public bool $paid=true; public float $refunded=0;
    public function get_meta(string $k): string { return $k === '_pesm_is_ticket_order' ? 'yes' : ''; }
    public function get_billing_email(): string { return $this->email; }
    public function get_currency(): string { return $this->currency; }
    public function get_status(): string { return $this->status; }
    public function get_total(): string { return '500'; }
    public function is_paid(): bool { return $this->paid; }
    public function get_total_refunded(): float { return $this->refunded; }
}
$orders=[20=>new BundleOrderStub(),21=>new BundleOrderStub(),22=>new BundleOrderStub()];
function wc_get_order(int $id): ?BundleOrderStub { return $GLOBALS['orders'][$id] ?? null; }
class BundleFakeDB {
    public string $prefix='wp_'; public int $insert_id=0; public int $centerScope=4;
    public array $bundles=[]; public array $items=[]; public array $audit=[];
    public array $checkins=[]; public array $transferred=[]; public array $refunds=[];
    public bool $failSecondItem=false; private ?array $snap=null;
    public array $tickets=[
       10=>['id'=>10,'session_id'=>101,'order_id'=>20,'attendee_name'=>'مینا','ticket_status'=>'active',
            'session_status'=>'published','event_status'=>'published','event_id'=>50,'center_id'=>4,'starts_at'=>'2027-01-20 12:00:00'],
       11=>['id'=>11,'session_id'=>102,'order_id'=>21,'attendee_name'=>'مینا','ticket_status'=>'active',
            'session_status'=>'published','event_status'=>'published','event_id'=>50,'center_id'=>4,'starts_at'=>'2027-01-21 12:00:00'],
       12=>['id'=>12,'session_id'=>103,'order_id'=>22,'attendee_name'=>'مینا','ticket_status'=>'active',
            'session_status'=>'published','event_status'=>'published','event_id'=>50,'center_id'=>4,'starts_at'=>'2027-01-22 12:00:00'],
    ];
    public function prepare(string $sql, mixed ...$args): string {
        foreach ($args as $arg) {
            $pos=strpos($sql,'%'); if ($pos===false) throw new RuntimeException('bad placeholder');
            $v=$sql[$pos+1]==='d'?strval(intval($arg)):"'".str_replace("'","''",(string)$arg)."'";
            $sql=substr_replace($sql,$v,$pos,2);
        } return $sql;
    }
    public function query(string $sql): int {
        if ($sql==='START TRANSACTION') { $this->snap=[$this->bundles,$this->items,$this->audit]; return 1; }
        if ($sql==='COMMIT') {$this->snap=null;return 1;}
        if ($sql==='ROLLBACK') {
            if ($this->snap !== null) [$this->bundles,$this->items,$this->audit]=$this->snap;
            $this->snap=null;return 1;
        }
        throw new RuntimeException('unmocked query '.$sql);
    }
    public function get_row(string $sql): ?object {
        if (str_contains($sql,'FROM wp_pesm_ticket_bundles WHERE request_hash=')) {
            foreach ($this->bundles as $b) if (str_contains($sql,$b['request_hash'])) return (object)$b;
            return null;
        }
        if (str_contains($sql,'FROM wp_pesm_ticket_bundles WHERE id=')) {
            preg_match('/WHERE id=(\d+)/',$sql,$m);
            return isset($this->bundles[(int)($m[1]??0)])?(object)$this->bundles[(int)$m[1]]:null;
        }
        throw new RuntimeException('unmocked row '.$sql);
    }
    public function get_results(string $sql, mixed $output=null): array {
        if (str_contains($sql,'FROM wp_pesm_tickets t JOIN wp_pesm_sessions')) {
            preg_match('/t.id IN \(([^)]+)\)/',$sql,$m);
            $ids=array_map('intval',explode(',', $m[1]??''));
            $out=[]; foreach($ids as $id) if(isset($this->tickets[$id]))$out[]=$this->tickets[$id];
            usort($out,static fn($a,$b)=>$a['id']<=>$b['id']);return $out;
        }
        if (str_contains($sql,'FROM wp_pesm_ticket_bundle_items i')) {
            preg_match('/bundle_id=(\d+)/',$sql,$m);$id=(int)($m[1]??0);
            return array_values(array_map(static fn($r)=>['ticket_id'=>$r['ticket_id'],
                'session_id'=>$r['session_id'],'ticket_number'=>'P-AABBCCDDEEFF0011',
                'ticket_status'=>'active','starts_at'=>'2027-01-20 12:00:00','seat_code'=>'A-3'],
                array_filter($this->items,static fn($r)=>$r['bundle_id']===$id)));
        }
        throw new RuntimeException('unmocked results '.$sql);
    }
    public function get_var(string $sql): mixed {
        if ($sql==='SELECT UTC_TIMESTAMP()') return '2026-11-03 12:00:00';
        if (str_contains($sql,'FROM wp_pesm_center_users'))return $this->centerScope===4?1:null;
        if (str_contains($sql,'FROM wp_pesm_ticket_bundle_items')) {
            preg_match('/bundle_id=(\d+)/',$sql,$m);$id=(int)($m[1]??0);
            foreach ($this->items as $i) if ($i['bundle_id']===$id) return $i['ticket_id'];
            return null;
        }
        if (str_contains($sql,'FROM wp_pesm_checkins')) {
            preg_match('/ticket_id=(\d+)/',$sql,$m);return !empty($this->checkins[(int)($m[1]??0)])?1:null;
        }
        if (str_contains($sql,'FROM wp_pesm_ticket_owners')) {
            preg_match('/ticket_id=(\d+)/',$sql,$m);return !empty($this->transferred[(int)($m[1]??0)])?1:null;
        }
        if (str_contains($sql,'FROM wp_pesm_refund_cases')) {
            preg_match('/ticket_id=(\d+)/',$sql,$m);return !empty($this->refunds[(int)($m[1]??0)])?1:null;
        }
        throw new RuntimeException('unmocked var '.$sql);
    }
    public function get_col(string $sql): array {
        preg_match('/bundle_id=(\d+)/',$sql,$m);$id=(int)($m[1]??0);
        $out=[];foreach($this->items as $item) if ($item['bundle_id']===$id) $out[]=$item['ticket_id'];
        sort($out);return $out;
    }
    public function insert(string $table,array $data): int {
        if ($table==='wp_pesm_ticket_bundles') {
            foreach($this->bundles as $b) if($b['request_hash']===$data['request_hash'])return 0;
            $this->insert_id=count($this->bundles)+1;$this->bundles[$this->insert_id]=$data+['id'=>$this->insert_id];return 1;
        }
        if ($table==='wp_pesm_ticket_bundle_items') {
            if($this->failSecondItem && count($this->items)%2===1)return 0;
            foreach($this->items as $i) if($i['ticket_id']===$data['ticket_id']||
                ($i['bundle_id']===$data['bundle_id']&&$i['session_id']===$data['session_id']))return 0;
            $this->items[]=$data;return 1;
        }
        if($table==='wp_pesm_audit_logs'){$this->audit[]=$data;return 1;}
        throw new RuntimeException('unmocked insert '.$table);
    }
}
$wpdb=new BundleFakeDB();
$n=0;
function ok(bool $value,string $note): void {global $n; if(!$value)throw new RuntimeException('FAIL '.$note);++$n;}
function reject(callable $fn,string $code): void {global $n;try{$fn();}catch(HoldException $e){
    if($e->errorCode!==$code)throw new RuntimeException('wrong: '.$e->errorCode.' expected '.$code);
    ++$n;return; } throw new RuntimeException('expected '.$code);}
$svc=new BundleService();$key=str_repeat('b',32);
reject(fn()=> $svc->create([10,11],$key,3),'permission_denied');
$wpdb->centerScope=3;reject(fn()=> $svc->create([10,11],$key,7),'permission_denied');$wpdb->centerScope=4;
$orders[21]->email='different@example.com';reject(fn()=> $svc->create([10,11],$key,7),'bundle_order_invalid');$orders[21]->email='bundle@example.com';
$wpdb->checkins[11]=true;reject(fn()=> $svc->create([10,11],$key,7),'bundle_ticket_ineligible');$wpdb->checkins=[];
$wpdb->transferred[11]=true;reject(fn()=> $svc->create([10,11],$key,7),'bundle_owner_unverified');$wpdb->transferred=[];
$wpdb->refunds[11]=true;reject(fn()=> $svc->create([10,11],$key,7),'bundle_refund_conflict');$wpdb->refunds=[];
$wpdb->failSecondItem=true;reject(fn()=> $svc->create([10,11],$key,7),'bundle_conflict');
ok(count($wpdb->bundles)===0 && $wpdb->items===[] && count($wpdb->audit)===0,'atomic rollback');
$wpdb->failSecondItem=false;
$result=$svc->create([11,10],$key,7);
ok($result['replayed']===false&&$result['ticket_ids']===[10,11]&&count($wpdb->items)===2,'create');
$replayed=$svc->create([10,11],$key,7);
ok($replayed['replayed']===true&&count($wpdb->bundles)===1,'replay');
reject(fn()=> $svc->create([10,12],$key,7),'idempotency_conflict');
reject(fn()=> $svc->create([10,12],str_repeat('c',32),7),'bundle_conflict');
$view=$svc->view($result['bundle_id'],7);
ok($view['all_tickets_active']===true&&count($view['tickets'])===2,'view');
$wpdb->centerScope=3;reject(fn()=> $svc->view($result['bundle_id'],7),'permission_denied');
print "bundle fake-db: $n assertions OK\n";
