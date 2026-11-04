<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';
require __DIR__.'/../src/Domain/Booking/HoldException.php';
require __DIR__.'/../src/Domain/Ticket/AttendeeChangePolicy.php';
require __DIR__.'/../src/Domain/Ticket/AttendeeChangeService.php';
require __DIR__.'/../src/Security/Capabilities.php';
require __DIR__.'/../src/Support/Audit.php';
use PESM\Domain\Ticket\AttendeeChangeService;
use PESM\Domain\Booking\HoldException;
function user_can(int $actor,string $permission):bool {return $actor===2 && $permission==='pesm_issue_tickets';}
function get_option(string $key,mixed $default=false):mixed {return $key==='pesm_schema_version'?12:$default;}
function wp_salt(string $name):string {return str_repeat('S',36);}
class Order17 {
 public string $status='completed';
 public function get_meta(string $key):string {return $key==='_pesm_is_ticket_order'?'yes':'';}
 public function get_status():string {return $this->status;}
}
$order17=new Order17();
function wc_get_order(int $id):?Order17 {global $order17;return $id===9?$order17:null;}
class Fake17 {
 public string $prefix='wp_'; public string $name='فاطمه احمدی'; public string $status='active';
 public array $history=[];public array $audit=[];public bool $failed=false;public bool $checked=false;
 public int $center=1; public string $start='2026-11-04 10:00:00';private ?array $snap=null;
 public function prepare(string $sql,mixed ...$params):string {
  return vsprintf($sql,array_map(static fn($x)=>is_int($x)?$x:"'".str_replace("'","''",(string)$x)."'",$params));
 }
 public function get_row(string $sql):?object {
  if (str_contains($sql,'FROM wp_pesm_tickets t'))return (object)['session_id'=>5,'order_id'=>9,'center_id'=>$this->center];
  if (str_contains($sql,'FROM wp_pesm_capacity_tickets t'))return (object)['session_id'=>5,'order_id'=>9,'center_id'=>$this->center];
  if (str_contains($sql,'FROM wp_pesm_tickets WHERE id=')||str_contains($sql,'FROM wp_pesm_capacity_tickets WHERE id='))
   return (object)['id'=>7,'session_id'=>5,'order_id'=>9,'attendee_name'=>$this->name,'status'=>$this->status];
  if (str_contains($sql,'FROM wp_pesm_ticket_attendee_changes')) {
   foreach($this->history as $change) if(str_contains($sql,"'".$change['request_hash']."'"))return (object)$change;
   return null;
  }
  if (str_contains($sql,'FROM wp_pesm_sessions s'))return (object)['center_id'=>$this->center,'starts_at'=>$this->start,'status'=>'published','event_status'=>'published'];
  throw new RuntimeException('unknown get_row: '.$sql);
 }
 public function get_var(string $sql):mixed {
  if ($sql==='SELECT UTC_TIMESTAMP()') return '2026-11-02 12:00:00';
  if (str_contains($sql,'FROM wp_pesm_checkins')||str_contains($sql,'FROM wp_pesm_capacity_checkins'))return $this->checked?1:null;
  if (str_contains($sql,'FROM wp_pesm_center_users'))return $this->center===1?1:null;
  throw new RuntimeException('unknown get_var: '.$sql);
 }
 public function query(string $sql):int {
  if ($sql==='START TRANSACTION'){$this->snap=[$this->name,$this->history,$this->audit];return 1;}
  if ($sql==='COMMIT'){$this->snap=null;return 1;}
  if ($sql==='ROLLBACK'){if($this->snap!==null)[$this->name,$this->history,$this->audit]=$this->snap;$this->snap=null;return 1;}
  throw new RuntimeException('unknown query: '.$sql);
 }
 public function update(string $table,array $data,array $where):int {
  if ($this->name!==$where['attendee_name']||$this->status!==$where['status'])return 0;
  $this->name=$data['attendee_name'];return 1;
 }
 public function insert(string $table,array $data):int {
  if ($table==='wp_pesm_ticket_attendee_changes'){if($this->failed)return 0;$this->history[]=$data;return 1;}
  if ($table==='wp_pesm_audit_logs'){$this->audit[]=$data;return 1;}
  throw new RuntimeException('unknown insert: '.$table);
 }
}
$wpdb=new Fake17();$svc=new AttendeeChangeService();$n=0;
function ok17(bool $condition,string $description):void {global $n;if(!$condition)throw new RuntimeException('FAILED '.$description);$n++;}
function reject17(callable $f,string $code):void {try{$f();throw new RuntimeException('Expected rejection '.$code);}catch(HoldException $e){ok17($e->errorCode===$code,'rejected '.$code);}}
$key=str_repeat('b',32);
reject17(fn()=>$svc->change('seated',7,'نام جدید',$key,3),'permission_denied');
$wpdb->center=2;reject17(fn()=>$svc->change('seated',7,'نام جدید',$key,2),'ticket_not_found');$wpdb->center=1;
$wpdb->checked=true;reject17(fn()=>$svc->change('seated',7,'نام جدید',$key,2),'ticket_locked');$wpdb->checked=false;
$wpdb->start='2026-10-10 00:00:00';reject17(fn()=>$svc->change('seated',7,'نام جدید',$key,2),'change_window_closed');$wpdb->start='2026-11-04 10:00:00';
$wpdb->failed=true;reject17(fn()=>$svc->change('seated',7,'نام جدید',$key,2),'write_failed');ok17($wpdb->name==='فاطمه احمدی'&&$wpdb->history===[],'rollback on journal error');$wpdb->failed=false;
$result=$svc->change('seated',7,'نام جدید',$key,2);ok17($result['replayed']===false&&$wpdb->name==='نام جدید','update');ok17(count($wpdb->history)===1&&count($wpdb->audit)===1,'journal and audit');
$result=$svc->change('seated',7,'نام جدید',$key,2);ok17($result['replayed']===true&&count($wpdb->history)===1,'idempotent');
reject17(fn()=>$svc->change('seated',7,'تغییر دیگر',$key,2),'idempotency_conflict');
$wpdb->name='علی رضا';$wpdb->history=[];$wpdb->audit=[];
$result=$svc->change('general',7,'حمید کاظمی',str_repeat('c',32),2);ok17($result['type']==='general'&&$wpdb->name==='حمید کاظمی','general admission');
$order17->status='refunded';reject17(fn()=>$svc->change('general',7,'رضا قنبری',str_repeat('d',32),2),'order_unavailable');
$order17->status='completed';
$noOpKey=str_repeat('e',32);
$result=$svc->change('general',7,'حمید کاظمی',$noOpKey,2);
ok17(($result['unchanged']??false)===true, 'noop request recorded');
$result=$svc->change('general',7,'حمید کاظمی',$noOpKey,2);
ok17($result['replayed']===true,'noop replay idempotent');
reject17(fn()=>$svc->change('general',7,'نام متفاوت',$noOpKey,2),'idempotency_conflict');
echo "PASS: {$n} simulated attendee changes (not real InnoDB).\n";
