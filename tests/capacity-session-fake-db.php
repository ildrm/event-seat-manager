<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

/** General-admission session creation with fake transactions, not WordPress/MySQL. */
require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Domain/Booking/CapacityPolicy.php';
require __DIR__ . '/../src/Domain/Hall/HallService.php';
require __DIR__ . '/../src/Support/Audit.php';

use PESM\Domain\Hall\HallService;

final class SessionDbDouble
{
    public string $prefix='wp_';
    public int $insert_id=0;
    public array $sessions=[];
    public array $capacity=[];
    public array $audit=[];
    public bool $failCapacity=false;
    private ?string $snapshot=null;

    public function prepare(string $sql, mixed ...$args): string {
        foreach ($args as $arg) {
            $sql=preg_replace('/%[sd]/',is_int($arg)?(string)$arg:"'".$arg."'",$sql,1);
        }
        return $sql;
    }
    public function query(string $sql): int|false {
        if ($sql==='START TRANSACTION') {
            $this->snapshot=serialize([$this->sessions,$this->capacity,$this->audit]);return 1;
        }
        if ($sql==='COMMIT') {$this->snapshot=null;return 1;}
        if ($sql==='ROLLBACK') {
            if ($this->snapshot!==null) [$this->sessions,$this->capacity,$this->audit]=unserialize($this->snapshot);
            $this->snapshot=null;return 1;
        }
        throw new RuntimeException('Unexpected SQL: '.$sql);
    }
    public function get_row(string $sql): ?object {
        if (str_contains($sql,'wp_pesm_events')) {
            preg_match('/id = (\d+)/',$sql,$m);
            return (int)($m[1]??0)===20?(object)['id'=>20,'center_id'=>2]:null;
        }
        if (str_contains($sql,'wp_pesm_halls')) {
            preg_match('/id = (\d+)/',$sql,$m);
            return (int)($m[1]??0)===10?(object)['id'=>10,'center_id'=>2,'current_layout_id'=>5]:null;
        }
        throw new RuntimeException('Unexpected get_row: '.$sql);
    }
    public function insert(string $table, array $row): int {
        if ($table==='wp_pesm_sessions') {
            $this->insert_id=101 + count($this->sessions);
            $this->sessions[$this->insert_id]=$row;
            return 1;
        }
        if ($table==='wp_pesm_session_capacity') {
            if ($this->failCapacity) return 0;
            $this->capacity[$row['session_id']]=$row;
            return 1;
        }
        if ($table==='wp_pesm_audit_logs') { $this->audit[]=$row;return 1; }
        throw new RuntimeException('Unexpected insert: '.$table);
    }
}
$wpdb=new SessionDbDouble();
$checks=0;
function checkSession(bool $condition,string $message): void {
    global $checks;++$checks;
    if (!$condition) throw new RuntimeException('FAILED: '.$message);
}
function failSession(callable $action): void {
    global $checks;
    try {$action();} catch (Throwable $e) { ++$checks;return; }
    throw new RuntimeException('Expected session failure');
}
$start='2028-01-01 15:00:00';$saleStart='2027-12-01 08:00:00';$saleEnd='2027-12-31 14:00:00';
$id=HallService::createSession(20,0,$start,$saleStart,$saleEnd,0,'general',900);
checkSession($id===101,'created first session');
checkSession(($wpdb->sessions[101]['mode']??null)==='general','mode stored as general');
checkSession(($wpdb->sessions[101]['layout_id']??null)===0,'general has no seat layout');
checkSession(($wpdb->capacity[101]['total_capacity']??null)===900,'capacity row created');
checkSession(count($wpdb->audit)===1,'session creation audit in transaction');
$id=HallService::createSession(20,10,$start,$saleStart,$saleEnd,1000,'general',500);
checkSession($wpdb->sessions[$id]['hall_id']===10,'optional hall accepted');
failSession(fn()=>HallService::createSession(20,0,$start,$saleStart,$saleEnd,0,'general',0));
failSession(fn()=>HallService::createSession(20,0,$start,$saleStart,$saleEnd,0,'unknown',900));
failSession(fn()=>HallService::createSession(20,0,$start,$saleStart,$saleEnd,0,'seated',0));
$before=count($wpdb->sessions);
$wpdb->failCapacity=true;
failSession(fn()=>HallService::createSession(20,0,$start,$saleStart,$saleEnd,0,'general',100));
checkSession(count($wpdb->sessions)===$before,'failed capacity insert rolls back session');
checkSession(count($wpdb->audit)===2,'failed capacity insert has no audit record');
echo "PASS: {$checks} general-admission session creation fake-DB assertions.\n";
