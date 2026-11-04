<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

/** Contract/rollback test with an in-memory SQL-shaped fake; NOT an InnoDB concurrency test. */
const ARRAY_A = 'ARRAY_A';
require_once __DIR__ . '/../src/Domain/Booking/HoldException.php';
require_once __DIR__ . '/../src/Domain/Pricing/DiscountPolicy.php';
require_once __DIR__ . '/../src/Domain/Pricing/DiscountService.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Pricing\DiscountService;

function get_option(string $key, mixed $default = null): mixed { return $key === 'pesm_schema_version' ? 16 : ($GLOBALS['discount_enabled'] ?? 'yes'); }
function wc_get_order(int $id): ?FakeOrder { return $GLOBALS['orders'][$id] ?? null; }
final class FakeOrder {
    public function __construct(public int $holdId) {}
    public function get_meta(string $k): mixed {
        return ['_pesm_hold_id'=>$this->holdId, '_pesm_is_ticket_order'=>'yes'][$k] ?? null;
    }
}
final class FakeDB {
    public string $prefix='wp_';
    public array $rules = [];
    public array $claims = [];
    public array $zones = [7=>'VIP',8=>'STD',9=>'VIP'];
    public array $tickets=[];
    public array $holdSeats=[];
    private ?array $before = null;
    public int $insert_id=0;
    public bool $failInsert=false;
    public function prepare(string $sql, ...$values): string {
        foreach ($values as $value) {
            $pos = strpos($sql, '%');
            if ($pos === false) throw new RuntimeException('No placeholder in SQL: '.$sql);
            $format=$sql[$pos+1] ?? '';
            if ($format === 'd') $value=(string)(int)$value;
            elseif ($format === 's') $value="'".str_replace("'","''",(string)$value)."'";
            else throw new RuntimeException('Bad SQL placeholder');
            $sql=substr_replace($sql,$value,$pos,2);
        }
        return $sql;
    }
    public function get_row(string $sql, mixed $output = null): mixed {
        if (str_contains($sql,'FROM wp_pesm_sessions WHERE id=')) {
            return (object)['id'=>5,'event_id'=>22,'mode'=>'seated','unit_price_minor'=>1000];
        }
        if (str_contains($sql,'FROM wp_pesm_holds WHERE session_id=')) {
            if (!str_contains($sql,hash('sha256',str_repeat('a',64)))) return null;
            return (object)['id'=>31];
        }
        if (str_contains($sql,'discount_claims WHERE hold_id=')) {
            preg_match('/hold_id=(\d+)/',$sql,$m); $row=$this->claims[(int)$m[1]] ?? null;
            return $row === null ? null : (object)$row;
        }
        if (str_contains($sql,'discount_rules WHERE event_id=')) {
            preg_match('/event_id=(\d+) AND code=\'([^\']+)\'/',$sql,$m);
            $row = $this->rules[1] ?? null;
            return $row && $row['event_id'] === (int)($m[1] ?? 0) && $row['code'] === ($m[2] ?? '') ? $row : null;
        }
        throw new RuntimeException('Unknown get_row '.$sql);
    }
    public function get_results(string $sql): array {
        if (str_contains($sql,'FROM wp_pesm_hold_items hi')) {
            return [(object)['id'=>7,'zone_label'=>'VIP'],(object)['id'=>8,'zone_label'=>'STD'],(object)['id'=>9,'zone_label'=>'VIP']];
        }
        if (!str_contains($sql,'FROM wp_pesm_seats')) throw new RuntimeException($sql);
        preg_match('/id IN \(([^)]+)\)/',$sql,$m);
        $ids=array_map('intval',explode(',',$m[1] ?? ''));
        $out=[];
        foreach($ids as $id) if(isset($this->zones[$id])) $out[]=(object)['id'=>$id,'zone_label'=>$this->zones[$id]];
        return $out;
    }
    public function insert(string $table,array $data): int|false {
        if($this->failInsert) return false;
        if(!str_contains($table,'discount_claims')) throw new RuntimeException('Unknown insert '.$table);
        $this->insert_id=count($this->claims)+1;
        $this->claims[(int)$data['hold_id']]=$data+['id'=>$this->insert_id];return 1;
    }
    public function update(string $table, array $data, array $where): int|false {
        if(!str_contains($table,'discount_claims')) throw new RuntimeException('Unknown update '.$table);
        foreach($this->claims as &$claim) {
            if($claim['id']===$where['id'] && $claim['status']===$where['status']) {
                $claim=array_replace($claim,$data);return 1;
            }
        }
        return 0;
    }
    public function get_var(string $sql): int|string {
        if ($sql === 'SELECT UTC_TIMESTAMP()') return '2026-11-03 12:00:00';
        if(str_contains($sql,'FROM wp_pesm_tickets')) {
            preg_match('/hold_id=(\d+)/',$sql,$m);return (int)($this->tickets[(int)$m[1]] ?? 0);
        }
        if(str_contains($sql,'FROM wp_pesm_hold_items')) {
            preg_match('/hold_id=(\d+)/',$sql,$m);return (int)($this->holdSeats[(int)$m[1]] ?? 0);
        }
        throw new RuntimeException('Unknown get_var '.$sql);
    }
    public function query(string $sql): int|false {
        if ($sql==='START TRANSACTION') { $this->before=[$this->rules,$this->claims];return 1; }
        if ($sql==='COMMIT') {$this->before=null;return 1;}
        if ($sql==='ROLLBACK') { if($this->before) [$this->rules,$this->claims]=$this->before;$this->before=null;return 1; }
        if (str_contains($sql,'discount_rules SET reserved_uses=reserved_uses+1')) {
            ++$this->rules[1]['reserved_uses'];return 1;
        }
        if (str_contains($sql,'discount_rules SET reserved_uses=reserved_uses-1,redeemed_uses=redeemed_uses+1')) {
            if ($this->rules[1]['reserved_uses']<1) return 0;
            --$this->rules[1]['reserved_uses'];++$this->rules[1]['redeemed_uses'];return 1;
        }
        if (str_contains($sql,'discount_rules SET reserved_uses=reserved_uses-1')) {
            if ($this->rules[1]['reserved_uses']<1) return 0;
            --$this->rules[1]['reserved_uses'];return 1;
        }
        throw new RuntimeException('Unknown query '.$sql);
    }
}
$wpdb=new FakeDB();
$wpdb->rules[1]=['id'=>1,'event_id'=>22,'session_id'=>0,'code'=>'VIP25','zone_label'=>'VIP',
    'kind'=>'percent','value'=>2500,'enabled'=>1,'starts_at'=>'2026-11-01 00:00:00',
    'ends_at'=>'2026-12-01 00:00:00','min_order_minor'=>100,'max_uses'=>1,'reserved_uses'=>0,'redeemed_uses'=>0];
$GLOBALS['orders']=[101=>new FakeOrder(31),102=>new FakeOrder(32)];
$service=new DiscountService();
$count=0;
function check(bool $condition,string $label): void {
    global $count;
    if(!$condition) throw new RuntimeException('assertion failed: '.$label);
    ++$count;
}
function denied(callable $callback,string $code): void {
    global $count;
    try {$callback();} catch(HoldException $e) {
        if($e->errorCode!==$code)throw new RuntimeException('Wrong rejection '.$e->errorCode);
        ++$count;return;
    }
    throw new RuntimeException('Expected '.$code);
}
$now='2026-11-03 12:00:00';
$wpdb->query('START TRANSACTION');
$a=$service->reserve(22,5,31,[7,8,9],1000,'vip25',$now);
$wpdb->query('COMMIT');
check($a['discount_minor']===500,'correct amount');
check($a['payable_minor']===2500,'correct final');
check($wpdb->rules[1]['reserved_uses']===1,'quota reserved');
check(count($wpdb->claims)===1,'claim persisted');
$wpdb->query('START TRANSACTION');
$b=$service->reserve(22,5,31,[7,8,9],1000,'VIP25',$now);
$wpdb->query('COMMIT');
check($a===$b,'idempotent replay');
check($wpdb->rules[1]['reserved_uses']===1,'no additional quota');
$wpdb->query('START TRANSACTION');
denied(fn()=>$service->reserve(22,5,32,[7,8,9],1000,'VIP25',$now),'coupon_exhausted');
$wpdb->query('ROLLBACK');
check(count($wpdb->claims)===1,'failed claim leaves ledger unchanged');
$wpdb->tickets[31]=3;$wpdb->holdSeats[31]=3;
$service->redeemOrder(101);
check($wpdb->rules[1]['reserved_uses']===0,'reserved decreases on issuance');
check($wpdb->rules[1]['redeemed_uses']===1,'redeemed increments');
check($wpdb->claims[31]['status']==='redeemed','claim redeemed');
$service->redeemOrder(101);
check($wpdb->rules[1]['redeemed_uses']===1,'replay cannot redeem twice');
$service->releaseUnredeemed(101);
check($wpdb->rules[1]['redeemed_uses']===1,'cannot release redeemed claim');
$wpdb->rules[1]['max_uses']=2;
$wpdb->query('START TRANSACTION');
$service->reserve(22,5,32,[7,8,9],1000,'VIP25',$now);
$wpdb->query('COMMIT');
$wpdb->tickets[32]=1;$wpdb->holdSeats[32]=3;
try { $service->redeemOrder(102); throw new RuntimeException('Expected issuance mismatch'); }
catch(RuntimeException $e) {check(str_contains($e->getMessage(),'tickets'), 'invalid issuance blocked');}
check($wpdb->claims[32]['status']==='reserved','failed redeem is rolled back');
$service->releaseUnredeemed(102);
check($wpdb->claims[32]['status']==='released','unredeemed release');
check($wpdb->rules[1]['reserved_uses']===0,'reservation quota returned');
$service->releaseUnredeemed(102);
check($wpdb->rules[1]['reserved_uses']===0,'release idempotent');
$wpdb->query('START TRANSACTION');
denied(fn()=>$service->reserve(22,5,33,[7,8,9],1000,'BADCODE',$now),'coupon_unavailable');
$wpdb->query('ROLLBACK');
$wpdb->rules[1]['max_uses']=3;
$wpdb->failInsert=true;
$wpdb->query('START TRANSACTION');
denied(fn()=>$service->reserve(22,5,34,[7,8,9],1000,'VIP25',$now),'coupon_write_failed');
$wpdb->query('ROLLBACK');
check(!isset($wpdb->claims[34]),'insert failure rolls back claim');
check($wpdb->rules[1]['reserved_uses']===0,'insert failure does not consume quota');
$wpdb->failInsert=false;
// Price preview never claims quota; it is explicitly non-binding.
$preview=$service->preview(5,str_repeat('a',64),'vip25');
check($preview['base_minor']===3000,'preview base');
check($preview['discount_minor']===500,'preview discount');
check($preview['payable_minor']===2500,'preview payable');
check($preview['indicative']===true,'preview non-binding marker');
check($wpdb->rules[1]['reserved_uses']===0,'preview does not consume quota');
denied(fn()=>$service->preview(5,str_repeat('b',64),'vip25'),'hold_expired');
$GLOBALS['discount_enabled']='no';
$wpdb->query('START TRANSACTION');
denied(fn()=>$service->reserve(22,5,35,[7,8,9],1000,'VIP25',$now),'coupon_disabled');
$wpdb->query('ROLLBACK');
echo "PASS: {$count} discount reservation fake-DB assertions; no actual SQL transactions exercised.\n";
