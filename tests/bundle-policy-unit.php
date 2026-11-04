<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

require_once __DIR__ . '/../src/Domain/Booking/HoldException.php';
require_once __DIR__ . '/../src/Domain/Booking/HoldPolicy.php';
require_once __DIR__ . '/../src/Domain/Ticket/BundlePolicy.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Ticket\BundlePolicy;

$n=0;
function eq(mixed $left, mixed $right): void { global $n; if ($left !== $right) throw new RuntimeException(var_export([$left,$right],true)); ++$n; }
function rejects(callable $cb,string $code): void { global $n; try{$cb();}catch(HoldException $e){if($e->errorCode===$code){++$n;return;}throw $e;} throw new RuntimeException('missing '.$code); }

eq(BundlePolicy::ticketIds([10,3,2]),[2,3,10]);
rejects(fn()=>BundlePolicy::ticketIds([1]),'invalid_bundle');
rejects(fn()=>BundlePolicy::ticketIds([1,1]),'duplicate_ticket');
rejects(fn()=>BundlePolicy::ticketIds([1,'2']),'invalid_bundle');
rejects(fn()=>BundlePolicy::ticketIds(range(1,9)),'invalid_bundle');
BundlePolicy::key(str_repeat('a', 32)); ++$n;
rejects(fn()=>BundlePolicy::key('bad'),'invalid_request_key');
$ticket = ['id'=>1,'event_id'=>20,'center_id'=>3,'attendee_name'=>'مینا', 'session_id'=>4,
    'ticket_status'=>'active','session_status'=>'published','event_status'=>'published',
    'starts_at'=>'2027-01-01 15:00:00','checked_in'=>0];
$t2=$ticket; $t2['id']=2; $t2['session_id']=5;
$m=BundlePolicy::validateManifest([$ticket,$t2],'2026-11-03 12:00:00');
eq($m['session_ids'],[4,5]);eq($m['event_id'],20);
$x=$t2;$x['session_id']=4;rejects(fn()=>BundlePolicy::validateManifest([$ticket,$x],'2026-11-03 12:00:00'),'duplicate_session');
$x=$t2;$x['attendee_name']='علی';rejects(fn()=>BundlePolicy::validateManifest([$ticket,$x],'2026-11-03 12:00:00'),'bundle_owner_mismatch');
$x=$t2;$x['center_id']=7;rejects(fn()=>BundlePolicy::validateManifest([$ticket,$x],'2026-11-03 12:00:00'),'bundle_owner_mismatch');
$x=$t2;$x['event_id']=10;rejects(fn()=>BundlePolicy::validateManifest([$ticket,$x],'2026-11-03 12:00:00'),'bundle_owner_mismatch');
$x=$t2;$x['checked_in']=1;rejects(fn()=>BundlePolicy::validateManifest([$ticket,$x],'2026-11-03 12:00:00'),'bundle_ticket_ineligible');
$x=$t2;$x['starts_at']='2026-11-03 11:59:59';rejects(fn()=>BundlePolicy::validateManifest([$ticket,$x],'2026-11-03 12:00:00'),'bundle_ticket_ineligible');
$x=$t2;$x['ticket_status']='refunded';rejects(fn()=>BundlePolicy::validateManifest([$ticket,$x],'2026-11-03 12:00:00'),'bundle_ticket_ineligible');
class StubOrder {
    public string $email='test@example.com'; public string $currency='IRR'; public string $status='completed';
    public bool $paid=true; public float $refunded=0;
    public function get_meta($k): string {return $k==='_pesm_is_ticket_order'?'yes':'';}
    public function get_billing_email(): string{return $this->email;}
    public function get_currency(): string{return $this->currency;}
    public function get_status(): string{return $this->status;}
    public function get_total(): float{return 100.0;}
    public function is_paid(): bool{return $this->paid;}
    public function get_total_refunded(): float{return $this->refunded;}
}
$o=new StubOrder();
eq(BundlePolicy::verifyPaidOrder($o,'','')['email'],'test@example.com');
rejects(fn()=>BundlePolicy::verifyPaidOrder($o,'other@example.com','IRR'),'bundle_order_invalid');
rejects(fn()=>BundlePolicy::verifyPaidOrder($o,'test@example.com','USD'),'bundle_order_invalid');
$o->refunded=1;rejects(fn()=>BundlePolicy::verifyPaidOrder($o,'',''),'bundle_order_invalid');
$o->refunded=0;$o->paid=false;rejects(fn()=>BundlePolicy::verifyPaidOrder($o,'',''),'bundle_order_invalid');
print "bundle policy: $n assertions OK\n";
