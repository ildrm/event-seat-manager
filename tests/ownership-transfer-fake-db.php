<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';
/** This models database responses. It DOES NOT prove MySQL concurrency safety. */
require __DIR__.'/../src/Domain/Booking/HoldException.php';
require __DIR__.'/../src/Domain/Ticket/OwnershipTransferPolicy.php';
require __DIR__.'/../src/Domain/Ticket/AttendeeChangePolicy.php';
require __DIR__.'/../src/Domain/Ticket/TicketToken.php';
require __DIR__.'/../src/Domain/Notification/PayloadCipher.php';
require __DIR__.'/../src/Support/Audit.php';
require __DIR__.'/../src/Domain/Ticket/OwnershipTransferService.php';
use PESM\Domain\Ticket\OwnershipTransferService;
use PESM\Domain\Booking\HoldException;
if (!defined('ARRAY_A')) define('ARRAY_A','ARRAY_A');
function wp_salt(string $purpose):string{return str_repeat('S',48);}
function is_email(string $s):bool{return filter_var($s,FILTER_VALIDATE_EMAIL)!==false;}
$transferFeature024=true;$ssl024=true;
function get_option(string $k,mixed $d=false):mixed{global $transferFeature024;return $k==='pesm_schema_version'?17:($k==='pesm_ownership_transfer_preview_enabled'?($transferFeature024?'yes':'no'):$d);}
function is_ssl():bool{global $ssl024;return $ssl024;}
function wp_mail(string $to,string $subject,string $body):bool {global $messages024,$mailFails024;$messages024[]=[$to,$subject,$body];return !$mailFails024;}
class Order024 {
 public string $status='completed';public float $refund=0.0;
 public function get_meta(string $key):string{return $key==='_pesm_is_ticket_order'?'yes':'';}
 public function get_status():string{return $this->status;}
 public function get_total_refunded():float{return $this->refund;}
}
$order024=new Order024();$messages024=[];$mailFails024=false;
function wc_get_order(int $id):?Order024 {global $order024;return $id===9?$order024:null;}
class FakeDB024 {
 public string $prefix='wp_'; public int $insert_id=0;public string $last_error='';
 public array $ticket=[];public array $invites=[];public array $owners=[];public array $audit=[];
 public bool $checked=false;public bool $failTicket=false;public string $mode='seated';private ?array $snapshot=null;
 public function __construct(){ $this->reset(); }
 public function reset():void {
  $public=str_repeat('a',32);$this->ticket=['id'=>7,'order_id'=>9,'session_id'=>4,'public_id'=>$public,
   'ticket_number'=>'P-AAAAAAAAAAAAAAAA','status'=>'active','qr_version'=>1,
   'attendee_name'=>'مالک اصلی','qr_token_hash'=>hash('sha256',\PESM\Domain\Ticket\TicketToken::create($public,1,wp_salt('secure_auth')))];
  $this->invites=[];$this->owners=[];$this->audit=[];$this->checked=false;$this->failTicket=false;$this->insert_id=0;$this->snapshot=null;$this->mode='seated';
 }
 public function prepare(string $sql,mixed ...$values):string{
  return vsprintf($sql,array_map(static fn($x)=>is_int($x)?(string)$x:"'".str_replace("'","''",(string)$x)."'",$values));
 }
 public function query(string $sql):int|false {
  if ($sql==='START TRANSACTION'){ $this->snapshot=[$this->ticket,$this->invites,$this->owners,$this->audit];return 1; }
  if ($sql==='COMMIT'){ $this->snapshot=null;return 1; }
  if ($sql==='ROLLBACK'){if($this->snapshot!==null)[$this->ticket,$this->invites,$this->owners,$this->audit]=$this->snapshot;$this->snapshot=null;return 1;}
  if (str_contains($sql,'UPDATE wp_pesm_ticket_ownership_transfers SET attempts=attempts+1')){ $id=(int) filter_number024($sql,'id');$this->invites[$id]['attempts']++;return 1; }
  throw new RuntimeException('Unknown query: '.$sql);
 }
 public function get_row(string $sql,mixed $format=null):object|array|null {
  if (str_contains($sql,'FROM wp_pesm_tickets t') && str_contains($sql,'JOIN wp_pesm_seats s')) {
   return $format===ARRAY_A?array_merge($this->ticket,['seat_code'=>'A-1','row_label'=>'A','zone_label'=>'VIP']):(object)array_merge($this->ticket,['seat_code'=>'A-1','row_label'=>'A','zone_label'=>'VIP']);
  }
  if (str_contains($sql,'FROM wp_pesm_capacity_tickets WHERE id=')) {
   if (preg_match('/id=(\d+)/',$sql,$m)&&(int)$m[1]===7)return $format===ARRAY_A?array_merge($this->ticket,['seat_code'=>'ورود آزاد']):(object)$this->ticket;
   return null;
  }
  if (str_contains($sql,'FROM wp_pesm_tickets WHERE id=')) {
   if (preg_match('/id=(\d+)/',$sql,$m)&&(int)$m[1]===7)return $format===ARRAY_A?$this->ticket:(object)$this->ticket;
   return null;
  }
  if (str_contains($sql,'FROM wp_pesm_ticket_owners WHERE owner_token_hash=')){
   foreach($this->owners as $row)if(str_contains($sql,"'".$row['owner_token_hash']."'"))return (object)$row;return null;
  }
  if (str_contains($sql,'FROM wp_pesm_ticket_owners WHERE ticket_type=')){
   return isset($this->owners[$this->mode.':7'])?(object)$this->owners[$this->mode.':7']:null;
  }
  if (str_contains($sql,'FROM wp_pesm_ticket_ownership_transfers WHERE request_hash=')){
   foreach($this->invites as $row)if(str_contains($sql,"'".$row['request_hash']."'"))return (object)$row;return null;
  }
  if (str_contains($sql,'FROM wp_pesm_ticket_ownership_transfers WHERE id=')){
   $id=(int) filter_number024($sql,'id');return isset($this->invites[$id])?(object)$this->invites[$id]:null;
  }
  if (str_contains($sql,'FROM wp_pesm_sessions s'))return (object)['starts_at'=>'2026-11-10 10:00:00','status'=>'published','event_status'=>'published'];
  throw new RuntimeException('Unknown get_row '.$sql);
 }
 public function get_var(string $sql):mixed {
  if ($sql==='SELECT UTC_TIMESTAMP()')return '2026-11-03 12:00:00';
  if (str_contains($sql,'FROM wp_pesm_checkins')||str_contains($sql,'FROM wp_pesm_capacity_checkins'))return $this->checked?1:null;
  if (str_contains($sql,'FROM wp_pesm_order_links')||str_contains($sql,'FROM wp_pesm_capacity_order_links')) {
   return str_contains($sql,"'".hash('sha256',str_repeat('b',64))."'")?1:null;
  }
  if (str_contains($sql,'FROM wp_pesm_guest_access_tokens'))return null;
  if (str_contains($sql,'FROM wp_pesm_ticket_ownership_transfers')&&str_contains($sql,"status='pending'")){
   foreach($this->invites as $row)if($row['status']==='pending'&&$row['expires_at']>'2026-11-03 12:00:00')return $row['id'];return null;
  }
  throw new RuntimeException('Unknown get_var '.$sql);
 }
 public function insert(string $table,array $data):int {
  if($table==='wp_pesm_ticket_ownership_transfers'){$this->insert_id=count($this->invites)+1;$this->invites[$this->insert_id]=['id'=>$this->insert_id,...$data];return 1;}
  if($table==='wp_pesm_ticket_owners'){$this->owners[$data['ticket_type'].':'.$data['ticket_id']]=$data;return 1;}
  if($table==='wp_pesm_audit_logs'){$this->audit[]=$data;return 1;}
  throw new RuntimeException('Unknown insert '.$table);
 }
 public function update(string $table,array $data,array $where):int {
  if($table==='wp_pesm_ticket_ownership_transfers'){
   $id=(int)$where['id'];if(!isset($this->invites[$id]))return 0;
   if(isset($where['status'])&&$this->invites[$id]['status']!==$where['status'])return 0;
   $this->invites[$id]=array_merge($this->invites[$id],$data);return 1;
  }
  if($table==='wp_pesm_ticket_owners'){$this->owners[$where['ticket_type'].':'.$where['ticket_id']]=array_merge($this->owners[$where['ticket_type'].':'.$where['ticket_id']],$data);return 1;}
  if($table==='wp_pesm_tickets'||$table==='wp_pesm_capacity_tickets'){
   if($this->failTicket||$this->ticket['status']!==$where['status']||$this->ticket['qr_version']!==$where['qr_version'])return 0;
   $this->ticket=array_merge($this->ticket,$data);return 1;
  }
  throw new RuntimeException('Unknown update '.$table);
 }
}
function filter_number024(string $s,string $key):string {if(!preg_match('/'.preg_quote($key,'/').'=(\d+)/',$s,$m))throw new RuntimeException($s);return $m[1];}
$wpdb=new FakeDB024();$svc=new OwnershipTransferService();$n024=0;
function assert024(bool $v,string $desc):void {global $n024;if(!$v)throw new RuntimeException('FAILED '.$desc);$n024++;}
function deny024(callable $f,string $code):void {try{$f();}catch(HoldException $e){assert024($e->errorCode===$code,"Rejected $code; got {$e->errorCode}");return;}throw new RuntimeException("Should reject $code");}
$key=str_repeat('d',32);$buyerToken=str_repeat('b',64);
// No invite on wrong owner's bearer.
deny024(fn()=>$svc->request('seated',7,str_repeat('c',64),'new@example.com','گیرنده جدید',$key),'permission_denied');
$result=$svc->request('seated',7,$buyerToken,'new@example.com','گیرنده جدید',$key);
assert024($result['status']==='pending'&&$result['transfer_id']===1,'new invite');
assert024(count($messages024)===1&&!str_contains(json_encode($result),'کد'),'OTP never returned in JSON');
assert024(!str_contains(json_encode($wpdb->invites),'new@example.com'),'email encrypted');
$result2=$svc->request('seated',7,$buyerToken,'new@example.com','گیرنده جدید',$key);
assert024($result2['replayed']&&count($messages024)===1,'idempotent invitation');
deny024(fn()=>$svc->request('seated',7,$buyerToken,'other@example.com','گیرنده دیگر',$key),'idempotency_conflict');
deny024(fn()=>$svc->request('seated',7,$buyerToken,'other@example.com','گیرنده دیگر',str_repeat('e',32)),'transfer_pending');
$before=$wpdb->ticket['qr_token_hash'];
deny024(fn()=>$svc->accept(1,'00000000'),'invalid_code');
assert024($wpdb->invites[1]['attempts']===1&&$wpdb->ticket['qr_version']===1,'wrong OTP counted');
$code='';preg_match('/(\d{8})/',$messages024[0][2],$matches);$code=$matches[1]??'';
assert024(strlen($code)===8,'OTP sent via email mock');
$wpdb->failTicket=true;
deny024(fn()=>$svc->accept(1,$code),'write_failed');
assert024($wpdb->ticket['qr_version']===1&&$wpdb->owners===[]&&$wpdb->invites[1]['status']==='pending','rollback on ticket write fail');
$wpdb->failTicket=false;
$accepted=$svc->accept(1,$code);
assert024($accepted['accepted']===true&&$wpdb->ticket['qr_version']===2&&$wpdb->ticket['attendee_name']==='گیرنده جدید','accepted, name and QR rotated');
assert024($before!==$wpdb->ticket['qr_token_hash'],'previous QR invalid');
assert024($wpdb->invites[1]['status']==='accepted'&&count($wpdb->owners)===1,'owner table and journal');
$owned=$svc->view($accepted['ownership_token']);
assert024($owned['ticket']['attendee_name']==='گیرنده جدید'&&$owned['ticket']['qr_payload']!==null,'recipient token can view');
deny024(fn()=>$svc->view($buyerToken),'permission_denied');
deny024(fn()=>$svc->accept(1,$code),'transfer_unavailable');
deny024(fn()=>$svc->request('seated',7,$buyerToken,'third@example.com','شخص جدید',str_repeat('f',32)),'permission_denied');
$next=$svc->request('seated',7,$accepted['ownership_token'],'third@example.com','شخص جدید',str_repeat('f',32));
assert024($next['status']==='pending','new owner can initiate another transfer');
preg_match('/(\d{8})/',$messages024[count($messages024)-1][2],$m2);
$second=$svc->accept($next['transfer_id'], $m2[1]);
assert024($second['accepted']===true&&$wpdb->ticket['qr_version']===3,'second transfer rotates QR again');
deny024(fn()=>$svc->view($accepted['ownership_token']),'permission_denied');
assert024($svc->view($second['ownership_token'])['ticket']['attendee_name']==='شخص جدید','new recipient sees own ticket');
$transferFeature024=false;
deny024(fn()=>$svc->request('seated',7,$second['ownership_token'],'x@example.com','شخص دیگر',str_repeat('a',32)),'feature_disabled');
assert024($svc->view($second['ownership_token'])['ticket']['status']==='active','view stays active after disabling new transfers');
$transferFeature024=true;$ssl024=false;
deny024(fn()=>$svc->view($second['ownership_token']),'https_required');
$ssl024=true;

$wpdb->reset();$messages024=[];$mailFails024=true;
deny024(fn()=>$svc->request('seated',7,$buyerToken,'new@example.com','گیرنده جدید',$key),'delivery_failed');
assert024($wpdb->invites[1]['status']==='delivery_failed','undelivered invitation invalidated');
$mailFails024=false;
$wpdb->reset();$order024->status='cancelled';
deny024(fn()=>$svc->request('seated',7,$buyerToken,'new@example.com','گیرنده جدید',$key),'order_unavailable');
$order024->status='completed';$order024->refund=20;
deny024(fn()=>$svc->request('seated',7,$buyerToken,'new@example.com','گیرنده جدید',$key),'order_unavailable');
$order024->refund=0;
$wpdb->checked=true;
deny024(fn()=>$svc->request('seated',7,$buyerToken,'new@example.com','گیرنده جدید',$key),'ticket_locked');
$wpdb->reset();$wpdb->mode='general';$messages024=[];
$inv=$svc->request('general',7,$buyerToken,'general@example.com','مهمان جدید',str_repeat('a',32));
assert024($inv['transfer_id']===1,'general admission invitation');
preg_match('/(\d{8})/',$messages024[0][2],$mg);
$ga=$svc->accept(1,$mg[1]);
assert024($ga['ticket_type']==='general'&&$wpdb->ticket['attendee_name']==='مهمان جدید','general admission ownership confirmed');
assert024($svc->view($ga['ownership_token'])['ticket']['seat_code']==='ورود آزاد','general admission view');
// Regression: original order access must exclude transferred tickets, incl. recovered order tokens.
$source=file_get_contents(__DIR__.'/../src/Domain/Ticket/TicketService.php');
assert024(substr_count($source,'NOT EXISTS (SELECT 1 FROM {$p}ticket_owners o')===2,'both ticket modes filtered from original guest order');
echo "PASS: {$n024} ownership transfer assertions with DB mock; no InnoDB/SMTP tested.\n";
