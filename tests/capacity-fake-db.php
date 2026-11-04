<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

/** Behavioral fake. Does NOT emulate InnoDB lock isolation, deadlocks, or concurrency. */
require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Domain/Booking/HoldPolicy.php';
require __DIR__ . '/../src/Domain/Booking/CapacityPolicy.php';
require __DIR__ . '/../src/Support/Audit.php';
require __DIR__ . '/../src/Domain/Booking/CapacityService.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Booking\CapacityService;

function wp_salt(string $kind): string { return 'capacity-fake-db-only-secret'; }

final class CapacityFakeDb
{
    public string $prefix='wp_';
    public int $insert_id=0;
    public string $now='2026-11-02 12:00:00';
    public array $inventory=[
        1=>['session_id'=>1,'total_capacity'=>3,'blocked_units'=>0,'held_units'=>0,'pending_units'=>0,'sold_units'=>0,'version'=>0],
    ];
    public array $holds=[];
    public array $audit=[];
    public bool $failInsert=false;
    private ?string $snapshot=null;
    private int $nextId=1;

    public function prepare(string $sql, mixed ...$values): string {
        foreach ($values as $value) {
            $str=is_int($value) ? (string)$value : "'" . str_replace("'", "''", (string)$value) . "'";
            $sql=preg_replace('/%[sd]/',$str,$sql,1);
        }
        return $sql;
    }
    public function query(string $sql): int|false {
        if ($sql==='START TRANSACTION') {
            $this->snapshot=serialize([$this->inventory,$this->holds,$this->audit,$this->nextId]);return 1;
        }
        if ($sql==='COMMIT') { $this->snapshot=null;return 1; }
        if ($sql==='ROLLBACK') {
            if ($this->snapshot!==null) [$this->inventory,$this->holds,$this->audit,$this->nextId]=unserialize($this->snapshot);
            $this->snapshot=null;return 1;
        }
        if (str_contains($sql,'UPDATE wp_pesm_session_capacity SET held_units=held_units+')) {
            preg_match('/held_units=held_units\+(\d+)/',$sql,$inc);
            preg_match('/WHERE session_id=(\d+)/',$sql,$id);
            $id=(int)($id[1]??0);$quantity=(int)($inc[1]??0);$inv=&$this->inventory[$id];
            if (!$inv || $quantity>$inv['total_capacity']-$inv['held_units']-$inv['pending_units']-$inv['sold_units']-$inv['blocked_units']) return 0;
            $inv['held_units']+=$quantity;$inv['version']++;return 1;
        }
        if (str_contains($sql,'UPDATE wp_pesm_session_capacity SET held_units=held_units-')) {
            preg_match('/held_units=held_units-(\d+)/',$sql,$dec);
            preg_match('/WHERE id=(\d+)\)/',$sql,$hold);
            $id=(int)($hold[1]??0);$qty=(int)($dec[1]??0);
            $session=$this->holds[$id]['session_id']??0;
            if (!$session || $this->inventory[$session]['held_units']<$qty) return 0;
            $this->inventory[$session]['held_units']-=$qty;$this->inventory[$session]['version']++;return 1;
        }
        throw new RuntimeException('Unknown fake query '.$sql);
    }
    public function get_var(string $sql): mixed {
        if ($sql==='SELECT UTC_TIMESTAMP()') return $this->now;
        if (str_contains($sql,'COALESCE(SUM(quantity)')) {
            preg_match('/session_id=(\d+)/',$sql,$m);$id=(int)($m[1]??0);
            return array_sum(array_map(fn($h)=>($h['session_id']===$id && $h['status']==='active' && $h['expires_at']<=$this->now) ? $h['quantity']:0,$this->holds));
        }
        throw new RuntimeException('Unknown fake get_var '.$sql);
    }
    public function get_row(string $sql): ?object {
        if (str_contains($sql,'FROM wp_pesm_sessions s JOIN wp_pesm_events e')) {
            preg_match('/s\.id=(\d+)/',$sql,$m);
            if ((int)($m[1]??0)!==1) return null;
            return (object)['id'=>1,'mode'=>'general','status'=>'published','event_status'=>'published',
                'sale_starts_at'=>'2026-11-02 10:00:00','sale_ends_at'=>'2026-11-02 18:00:00',
                'starts_at'=>'2026-11-03 10:00:00'];
        }
        if (str_contains($sql,'FROM wp_pesm_session_capacity c JOIN')) {
            preg_match('/c\.session_id=(\d+)/',$sql,$m);
            $row=$this->inventory[(int)($m[1]??0)]??null;
            $expired = 0;
            foreach ($this->holds as $h) {
                if ($h['session_id']===(int)($m[1]??0) && $h['status']==='active' && $h['expires_at']<=$this->now) {
                    $expired += $h['quantity'];
                }
            }
            return $row ? (object)($row+['expired_units'=>$expired,'mode'=>'general','status'=>'published','event_status'=>'published']) : null;
        }
        if (str_contains($sql,'FROM wp_pesm_session_capacity WHERE')) {
            preg_match('/session_id=(\d+)/',$sql,$m);
            $row=$this->inventory[(int)($m[1]??0)]??null;
            return $row ? (object)$row:null;
        }
        if (str_contains($sql,'FROM wp_pesm_capacity_holds WHERE request_hash=')) {
            preg_match("/request_hash='([0-9a-f]{64})'/",$sql,$m);
            foreach ($this->holds as $row) if ($row['request_hash']===($m[1]??'')) return (object)$row;
            return null;
        }
        if (str_contains($sql,'FROM wp_pesm_capacity_holds')) {
            preg_match('/session_id=(\d+)/',$sql,$id);preg_match("/token_hash='([0-9a-f]{64})'/",$sql,$hash);
            foreach ($this->holds as $row) if ($row['session_id']===(int)($id[1]??0) && $row['token_hash']===($hash[1]??'')) return (object)$row;
            return null;
        }
        throw new RuntimeException('Unknown fake get_row '.$sql);
    }
    public function get_results(string $sql): array {
        if (!str_contains($sql,'FROM wp_pesm_capacity_holds')) throw new RuntimeException('Unknown fake get_results '.$sql);
        preg_match('/session_id=(\d+)/',$sql,$id);preg_match('/LIMIT (\d+)/',$sql,$max);
        $list=[];
        foreach ($this->holds as $h) {
            if ($h['session_id']===(int)($id[1]??0) && $h['status']==='active' && $h['expires_at']<=$this->now) {
                $list[]=(object)['id'=>$h['id'],'quantity'=>$h['quantity']];
            }
        }
        return array_slice($list,0,(int)($max[1]??100));
    }
    public function insert(string $table, array $data): int {
        if ($table==='wp_pesm_capacity_holds') {
            if ($this->failInsert) return 0;
            foreach ($this->holds as $row) if ($row['request_hash']===$data['request_hash']) return 0;
            $this->insert_id=$this->nextId++;
            $this->holds[$this->insert_id]=['id'=>$this->insert_id]+$data;
            return 1;
        }
        if ($table==='wp_pesm_audit_logs') { $this->audit[]=$data;return 1; }
        throw new RuntimeException('Unknown fake insert '.$table);
    }
    public function update(string $table, array $data, array $where): int {
        if ($table!=='wp_pesm_capacity_holds') throw new RuntimeException('Unknown fake update '.$table);
        $id=$where['id']; $row=$this->holds[$id]??null;
        if (!$row) return 0;
        foreach ($where as $column=>$value) if ($row[$column]!==$value) return 0;
        $this->holds[$id]=array_replace($row,$data);return 1;
    }
}

$wpdb=new CapacityFakeDb();
$service=new CapacityService();
$checks=0;
function checkGA(bool $condition,string $name): void {
    global $checks;++$checks;
    if (!$condition) throw new RuntimeException('FAILED: '.$name);
}
function failsGA(callable $action,string $code): void {
    try { $action(); } catch(HoldException $e) { checkGA($e->errorCode===$code,$code);return; }
    throw new RuntimeException('Expected '.$code);
}
$keyA=str_repeat('a',40);$keyB=str_repeat('b',40);$keyC=str_repeat('c',40);
$a=$service->acquire(1,2,$keyA);
checkGA($a['quantity']===2 && $wpdb->inventory[1]['held_units']===2,'first hold');
checkGA($a['expires_at_utc']==='2026-11-02 12:10:00','TTL is database-based');
checkGA($service->availability(1)['available']===1,'availability after first hold');
checkGA($service->acquire(1,2,$keyA)['replayed']===true,'replay returns existing');
checkGA(count($wpdb->holds)===1,'idempotency not double-counted');
failsGA(fn()=> $service->acquire(1,1,$keyA),'idempotency_conflict');
failsGA(fn()=> $service->acquire(1,2,$keyB),'capacity_unavailable');
checkGA($wpdb->inventory[1]['held_units']===2,'failed acquire did not alter counters');
$b=$service->acquire(1,1,$keyB);
checkGA($wpdb->inventory[1]['held_units']===3,'exact sell-out hold');
failsGA(fn()=> $service->acquire(1,1,$keyC),'capacity_unavailable');
failsGA(fn()=> $service->release(1,str_repeat('f',64)),'permission_denied');
checkGA($service->release(1,$a['token'])['released'],'release owned capacity');
checkGA($wpdb->inventory[1]['held_units']===1,'release decremented counter once');
checkGA($service->release(1,$a['token'])['already_released'],'repeat release is idempotent');
checkGA($wpdb->inventory[1]['held_units']===1,'repeat release leaves inventory');
$c=$service->acquire(1,2,$keyC);
checkGA($wpdb->inventory[1]['held_units']===3 && $c['hold_id']!==$a['hold_id'],'reassigned capacity');
$service->release(1,$a['token']);
checkGA($wpdb->inventory[1]['held_units']===3,'old token cannot release new owner');
$wpdb->now='2026-11-02 12:11:00';
checkGA($service->availability(1)['available']===3,'read-only availability ignores expired holds without cron');
$d=$service->acquire(1,3,str_repeat('d',40));
checkGA($wpdb->inventory[1]['held_units']===3 && $d['quantity']===3,'lazy reaping + reallocation');
checkGA(count(array_filter($wpdb->holds,fn($h)=>$h['status']==='expired'))===2,'both expired holds marked');
$service->release(1,$b['token']);
checkGA($wpdb->inventory[1]['held_units']===3,'late release cannot free new owner');
$wpdb->failInsert=true;
$service->release(1,$d['token']);
failsGA(fn()=> $service->acquire(1,1,str_repeat('e',40)),'write_failed');
checkGA($wpdb->inventory[1]['held_units']===0,'failed insert rolls back reserved counters');
checkGA(count($wpdb->audit) >= 5,'audit records without bearer tokens');
echo "PASS: {$checks} capacity-hold fake-DB behavioral checks (not InnoDB concurrency).\n";
