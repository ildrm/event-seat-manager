<?php
declare(strict_types=1);

/** Real WooCommerce/InnoDB acceptance. Creates disposable fixtures, never calls a gateway. */
if(!defined('ABSPATH')){echo "SKIP: run in disposable local WordPress.\n";return;}
if(getenv('PESM_RUN_INTEGRATION')!=='1'||wp_get_environment_type()!=='local'){throw new RuntimeException('Requires explicitly enabled disposable local integration.');}
if(!defined('PESM_VENUE_ACCEPTANCE_TEST')){define('PESM_VENUE_ACCEPTANCE_TEST',true);}
wp_set_current_user(1);
add_filter('pre_wp_mail',static function($pre,$attributes){$GLOBALS['allocation_last_mail']=$attributes;return true;},10,2);
add_filter('pre_http_request',static fn()=>new WP_Error('test_remote_disabled','No remote requests during allocation acceptance.'),99);

use PESM\Domain\Hall\HallService;
use PESM\Domain\Venue\JobService;
use PESM\Domain\Venue\LayoutRepository;
use PESM\Domain\Allocation\SessionService;
use PESM\Domain\Allocation\ReservationService;
use PESM\Domain\Allocation\CheckoutService;
use PESM\Domain\Allocation\GroupAdministration;
use PESM\Domain\Allocation\GateService;
use PESM\Domain\Allocation\Credential;
use PESM\Domain\Allocation\UnitBrowser;
use PESM\Domain\Event\LifecycleService;
use PESM\Domain\Booking\HoldException;

global $wpdb;$p=$wpdb->prefix.'pesm_';$checks=[];
$ok=static function(bool $condition,string $label)use(&$checks):void{if(!$condition){throw new RuntimeException($label);} $checks[]=$label;};
$reject=static function(callable $fn,string $code)use($ok):void{try{$fn();}catch(HoldException$e){$ok($e->errorCode===$code,'reject '.$code.' (got '.$e->errorCode.')');return;}throw new RuntimeException('Expected '.$code);};
$run=static function(int $id):array{for($i=0;$i<1000;$i++){JobService::run($id);$s=JobService::status($id);if($s['status']==='complete'){return $s;}if($s['status']==='failed'){throw new RuntimeException($s['error']);}}throw new RuntimeException('Job did not finish.');};
$key=static fn():string=>bin2hex(random_bytes(24));
$center=HallService::createCenter('Allocation acceptance '.gmdate('His').'-'.random_int(100,999));
$hall=HallService::createHall($center,'Allocation real database acceptance',1,1);
$generation=JobService::create($center,$hall,'generate',['preset_id'=>'restaurant_round_banquet','parameters'=>['capacity'=>64,'sections'=>2,'group_size'=>8]],1);
$generated=$run($generation['id']);$layout=(int)$generated['layout_id'];$run(JobService::create($center,$hall,'publish',['layout_id'=>$layout],1)['id']);
$ok((int)$wpdb->get_var($wpdb->prepare("SELECT seat_count FROM {$p}layout_versions WHERE id=%d",$layout))===64,'generated/published exact venue capacity');
$event=HallService::createEvent($center,'Allocation acceptance event');LifecycleService::change('event',$event,'published',1);
$create=static function(array $settings,int $price=10000)use($event,$hall,$run,$wpdb,$p):int{
    $id=HallService::createSession($event,$hall,gmdate('Y-m-d H:i:s',time()+3600),gmdate('Y-m-d H:i:s',time()-3600),gmdate('Y-m-d H:i:s',time()+1800),$price,'allocated');
    SessionService::configure($id,$settings);
    $job=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}venue_jobs WHERE session_id=%d AND kind='provision' ORDER BY id DESC LIMIT 1",$id));$run($job);LifecycleService::change('session',$id,'published',1);return $id;
};
$groups=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}venue_groups WHERE layout_id=%d ORDER BY id",$layout));$group=$groups[0];$second=$groups[1];
$members=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT seat_id FROM {$p}venue_places WHERE layout_id=%d AND group_code=%s ORDER BY seat_id",$layout,$group->group_code)));
$settings=['ticket_mode'=>'buyer_choice','group_entry_policy'=>'incremental','pricing'=>['sections'=>[$group->section_code=>11000],'categories'=>['STANDARD'=>12000],'section_categories'=>[$group->section_code=>['STANDARD'=>13000]],'groups'=>[$group->group_code=>8001]]];
$session=$create($settings);
$quote=ReservationService::quote($session,[['type'=>'seat','id'=>$members[0]]]);$ok($quote['total_minor']===13000,'section/category precedence in real quote');
$q=ReservationService::quote($session,[['type'=>'group','id'=>$group->group_code]]);$ok($q['total_minor']===8001&&$q['admissions']===8,'whole group flat total and capacity');
$firstKey=$key();$hold=ReservationService::acquire($session,[['type'=>'group','id'=>$group->group_code]],$firstKey);
$same=ReservationService::acquire($session,[['type'=>'group','id'=>$group->group_code]],$firstKey);$ok($same['hold_id']===$hold['hold_id']&&$same['idempotent_replay'],'acquire replay uses original inventory/quote');
$reject(fn()=>ReservationService::acquire($session,[['type'=>'seat','id'=>$members[0]]],$key()),'seat_unavailable');
ReservationService::mutate($session,$hold['hold_token'],'release',[],$key());
$individual=ReservationService::acquire($session,[['type'=>'seat','id'=>$members[0]]],$key());$reject(fn()=>ReservationService::acquire($session,[['type'=>'group','id'=>$group->group_code]],$key()),'seat_unavailable');ReservationService::mutate($session,$individual['hold_token'],'release',[],$key());
$reject(fn()=>ReservationService::quote($session,[['type'=>'group','id'=>$group->group_code],['type'=>'seat','id'=>$members[0]]]),'duplicate_resource');
$hold=ReservationService::acquire($session,[['type'=>'group','id'=>$group->group_code]],$key());$view=ReservationService::view($session,$hold['hold_token']);$ok($view['expires_at_utc']===$hold['expires_at_utc'],'read-only revalidation preserves lease');
$page=UnitBrowser::page($session,['type'=>'group','available_only'=>true,'limit'=>100]);$ok(!in_array($group->group_code,array_column($page['items'],'id'),true),'available list excludes held whole group before pagination');
$buyer=['first_name'=>'Integration','last_name'=>'Guest','phone'=>'09123456789','email'=>'allocation@example.test','ticket_modes'=>[$group->group_code=>'group']];
$nextBuyer=static fn():array=>array_replace($buyer,['phone'=>'0912'.str_pad((string)random_int(0,9999999),7,'0',STR_PAD_LEFT)]);
$order=CheckoutService::prepare($session,$hold['hold_token'],$nextBuyer());$wc=wc_get_order($order['order_id']);$ok($wc&&$order['payment_required'],'real Woo dedicated group order created');
$ok(count($wc->get_items())===1,'whole group is one Woo order line');
$wc->set_currency('EUR');$wc->save();$wc->set_date_paid(time());$wc->set_status('processing');$wc->save();$reject(fn()=>CheckoutService::confirmOrder($order['order_id']),'price_mismatch');
$wc->set_currency($q['currency']);$wc->save();$wc->payment_complete('allocation-local-fixture');$issued=CheckoutService::confirmOrder($order['order_id']);$ok(in_array($issued,['issued','already_issued'],true),'verified local payment issues admissions');
$ok(CheckoutService::confirmOrder($order['order_id'])==='already_issued','issuance replay creates no duplicate slots');
$tickets=CheckoutService::tickets($order['order_id'],$order['guest_access_token']);$line=$tickets['lines'][0];$ok(count($line['tickets'])===0&&$line['group_ticket']['capacity']===8,'group regime exposes one group QR and no member QR');
$slot=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_tickets WHERE line_id=%d ORDER BY seat_id LIMIT 1",$line['line_id']));
$reject(fn()=>GateService::scan(Credential::create('P',$slot->public_id),1,'test',$key(),1),'invalid_qr');
$scanKey=$key();$scan=GateService::scan($line['group_ticket']['qr_payload'],1,'test',$scanKey,2);$ok($scan['admitted']===2&&$scan['remaining']===6,'incremental group entry consumes exact quantity');
$again=GateService::scan($line['group_ticket']['qr_payload'],1,'test',$scanKey,2);$ok($again['remaining']===6&&$again['idempotent_replay'],'scan retry never consumes more guests');
$reject(fn()=>GateService::scan($line['group_ticket']['qr_payload'],1,'test',$key(),7),'admission_quantity');
$reject(fn()=>GroupAdministration::cancel($line['line_id'],$key(),1),'group_already_used');
$all=GateService::scan($line['group_ticket']['qr_payload'],1,'test',$key(),6);$ok($all['remaining']===0,'remaining group admissions consumed');$reject(fn()=>GateService::scan($line['group_ticket']['qr_payload'],1,'test',$key(),1),'already_checked_in');
$_SERVER['REMOTE_ADDR']='127.0.0.181';CheckoutService::requestRecovery($order['order_id'],'allocation@example.test');$mail=$GLOBALS['allocation_last_mail']??[];preg_match('/\b([0-9]{8})\b/',$mail['message']??'',$otp);
$ok(isset($otp[1]),'recovery email intercepted locally');$recovery=CheckoutService::verifyRecovery($order['order_id'],$otp[1]);$ok(CheckoutService::tickets($order['order_id'],$recovery['guest_access_token'])['order_id']===$order['order_id'],'existing bounded guest recovery grants allocated ticket access');

$freeSession=$create(['ticket_mode'=>'group','group_entry_policy'=>'together'],0);$freeHold=ReservationService::acquire($freeSession,[['type'=>'group','id'=>$group->group_code]],$key());$freeOrder=CheckoutService::prepare($freeSession,$freeHold['hold_token'],$nextBuyer());$freeTickets=CheckoutService::tickets($freeOrder['order_id'],$freeOrder['guest_access_token']);
$together=GateService::scan($freeTickets['lines'][0]['group_ticket']['qr_payload'],1,'test',$key(),1);$ok($together['admitted']===8&&$together['remaining']===0,'together QR admits entire party once');
$cancelHold=ReservationService::acquire($freeSession,[['type'=>'group','id'=>$second->group_code]],$key());$cancelOrder=CheckoutService::prepare($freeSession,$cancelHold['hold_token'],$nextBuyer());$cancelTickets=CheckoutService::tickets($cancelOrder['order_id'],$cancelOrder['guest_access_token']);$cancel=GroupAdministration::cancel($cancelTickets['lines'][0]['line_id'],$key(),1);$ok($cancel['status']==='cancelled','free unused whole group cancels atomically');
$cancelQr=$cancelTickets['lines'][0]['group_ticket']['qr_payload'];$reject(fn()=>GateService::scan($cancelQr,1,'test',$key(),1),'ticket_inactive');$reacquired=ReservationService::acquire($freeSession,[['type'=>'group','id'=>$second->group_code]],$key());$ok($reacquired['quote']['admissions']===8,'cancel releases full group for another buyer');ReservationService::mutate($freeSession,$reacquired['hold_token'],'release',[],$key());

$refundHold=ReservationService::acquire($session,[['type'=>'group','id'=>$second->group_code]],$key());$refundOrder=CheckoutService::prepare($session,$refundHold['hold_token'],$nextBuyer());$refundWc=wc_get_order($refundOrder['order_id']);$refundWc->payment_complete('allocation-refund-fixture');CheckoutService::confirmOrder($refundOrder['order_id']);$refundTickets=CheckoutService::tickets($refundOrder['order_id'],$refundOrder['guest_access_token']);$refundLine=$refundTickets['lines'][0]['line_id'];$review=GroupAdministration::cancel($refundLine,$key(),1);$ok($review['status']==='refund_review','paid group refund starts supervised review without releasing stock');
$refundItemId=(int)array_key_first($refundWc->get_items());$refundAmount=$refundWc->get_total();$refund=wc_create_refund(['order_id'=>$refundOrder['order_id'],'amount'=>$refundAmount,'refund_payment'=>false,'restock_items'=>false,'line_items'=>[$refundItemId=>['qty'=>1,'refund_total'=>$refundAmount]]]);
$ok(!is_wp_error($refund),'real Woo refund record created without contacting a payment gateway');
// Woo may already emit the full-order refunded hook and revoke the allocation.
$lineStatus=(string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}allocation_lines WHERE id=%d",$refundLine));
if($lineStatus==='refund_review'){$confirmed=GroupAdministration::confirmRefund($refundLine,(int)$refund->get_id(),$key(),1);$ok($confirmed['status']==='refunded','verified whole-line Woo refund releases allocation');}else{$ok($lineStatus==='refunded','verified full-order refund callback released unused group');}

$third=$groups[2];$fourth=$groups[3];$fifth=$groups[4];
$frozen=ReservationService::acquire($session,[['type'=>'group','id'=>$third->group_code]],$key());$oldPrice=$frozen['quote']['total_minor'];$changed=$settings;$changed['pricing']['groups'][$third->group_code]=222;
SessionService::configure($session,$changed);$ok(ReservationService::view($session,$frozen['hold_token'])['quote']['total_minor']===$oldPrice,'published pricing update preserves held immutable quote');
$repriced=ReservationService::mutate($session,$frozen['hold_token'],'replace',[['type'=>'group','id'=>$third->group_code]],$key());$ok($repriced['quote']['total_minor']===222,'replacement reprices entire desired selection');ReservationService::mutate($session,$frozen['hold_token'],'release',[],$key());
$firstThird=(int)$wpdb->get_var($wpdb->prepare("SELECT seat_id FROM {$p}venue_places WHERE layout_id=%d AND group_code=%s ORDER BY seat_id LIMIT 1",$layout,$third->group_code));
SessionService::block($session,$firstThird,true,1);$reject(fn()=>ReservationService::acquire($session,[['type'=>'group','id'=>$third->group_code]],$key()),'seat_unavailable');SessionService::block($session,$firstThird,false,1);
$reject(fn()=>(new \PESM\Domain\Booking\HoldService())->acquire($session,[$firstThird],$key()),'layout_requires_v2');
$availability=ReservationService::availability($session,[$third->section_code]);$ok(isset($availability['groups'])&&count($availability['items'])<=5000,'bounded availability includes complete group states');

$moveHold=ReservationService::acquire($freeSession,[['type'=>'group','id'=>$groups[6]->group_code]],$key());$moveOrder=CheckoutService::prepare($freeSession,$moveHold['hold_token'],$nextBuyer());$moveTickets=CheckoutService::tickets($moveOrder['order_id'],$moveOrder['guest_access_token']);$moveLine=$moveTickets['lines'][0];$moveKey=$key();
$move=GroupAdministration::reallocate($moveLine['line_id'],['type'=>'group','id'=>$groups[7]->group_code],$moveKey,1);$ok($move['unit_id']===$groups[7]->group_code,'whole unused group moves atomically at equal price and capacity');
$moveReplay=GroupAdministration::reallocate($moveLine['line_id'],['type'=>'group','id'=>$groups[7]->group_code],$moveKey,1);$ok($moveReplay['idempotent_replay'],'whole-group reallocation retry is idempotent');
$reject(fn()=>GateService::scan($moveLine['group_ticket']['qr_payload'],1,'test',$key(),1),'invalid_qr');
$moved=CheckoutService::tickets($moveOrder['order_id'],$moveOrder['guest_access_token']);$ok($moved['lines'][0]['unit_id']===$groups[7]->group_code&&$moved['lines'][0]['group_ticket']['qr_payload']!==$moveLine['group_ticket']['qr_payload'],'moved group credential rotates and receipt points to destination');
$adminPage=GroupAdministration::page($freeSession,0,2,1);$ok(count($adminPage['items'])===2&&$adminPage['next_cursor']!==null,'admin allocation listing is bounded and cursor based');

// Cart credentials remain in the private WC session; cart metadata contains no bearer.
\PESM\Domain\Allocation\CartService::register();
$cartHold=ReservationService::acquire($freeSession,[['type'=>'group','id'=>$third->group_code]],$key());$attached=\PESM\Domain\Allocation\CartService::attach($freeSession,$cartHold['hold_token']);$cartNonce=$attached['cart_nonce'];$cartKey=$attached['cart_key'];
$ok(isset(WC()->cart->get_cart()[$cartKey]),'allocated hold attaches to real Woo cart');
$ok(!str_contains(wp_json_encode(WC()->cart->get_cart_item($cartKey)),$cartHold['hold_token']),'cart item metadata does not contain raw hold bearer');
$preview=\PESM\Domain\Allocation\CartService::preview($cartKey,$cartNonce);$ok(!isset($preview['hold_token'])&&!isset($preview['token'])&&$preview['session_id']===$freeSession,'cart preview uses private session ownership without exposing token');
$reject(fn()=>\PESM\Domain\Allocation\CartService::preview($cartKey,str_repeat('0',64)),'csrf_denied');
$ok(!\PESM\Domain\Allocation\CartService::validateQuantity(true,$cartKey,WC()->cart->get_cart_item($cartKey),2),'normal Woo quantity mutation is rejected');
$replaced=\PESM\Domain\Allocation\CartService::replace($cartKey,[['type'=>'group','id'=>$fourth->group_code]],$key(),$cartNonce);$ok($replaced['quote']['lines'][0]['unit_id']===$fourth->group_code,'cart replacement commits authoritative allocation');
$cartCompleted=\PESM\Domain\Allocation\CartService::complete($cartKey,$buyer,$cartNonce);$ok(!isset(WC()->cart->get_cart()[$cartKey])&&!$cartCompleted['payment_required'],'dedicated cart checkout issues real free order and removes review line');
$cartReplayed=\PESM\Domain\Allocation\CartService::complete($cartKey,$buyer,$cartNonce);$ok($cartReplayed['order_id']===$cartCompleted['order_id']&&$cartReplayed['idempotent_replay'],'cart checkout replay survives removal after lost response');

$lateHold=ReservationService::acquire($session,[['type'=>'group','id'=>$fifth->group_code]],$key());$lateOrder=CheckoutService::prepare($session,$lateHold['hold_token'],$nextBuyer());
$wpdb->query($wpdb->prepare("UPDATE {$p}allocation_holds SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=%d",$lateHold['hold_id']));$wpdb->query($wpdb->prepare("UPDATE {$p}session_seats SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE session_id=%d AND hold_id=%d",$session,$lateHold['hold_id']));
$nextHold=ReservationService::acquire($session,[['type'=>'group','id'=>$fifth->group_code]],$key());$lateWc=wc_get_order($lateOrder['order_id']);$lateWc->payment_complete('allocation-late-fixture');$ok(CheckoutService::confirmOrder($lateOrder['order_id'])==='review_required','late paid callback cannot seize reallocated places');ReservationService::cleanup();
$ownerCount=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}session_seats WHERE session_id=%d AND hold_id=%d AND status='held'",$session,$nextHold['hold_id']));$ok($ownerCount===8,'cleanup of expired payment lease does not release new buyer inventory');ReservationService::mutate($session,$nextHold['hold_token'],'release',[],$key());

$anomalies=\PESM\Domain\Allocation\Reconciliation::page($center,0,100,1);$ok(in_array($lateOrder['order_id'],array_column($anomalies['items'],'order_id'),true),'allocated reconciliation exposes unresolved late payment');
$ok(\PESM\Domain\Allocation\Reconciliation::retryOrder($lateOrder['order_id'],1)['status']==='review_required','supervisor retry never steals expired inventory');
$personSession=$create(['ticket_mode'=>'individual'],0);$personHold=ReservationService::acquire($personSession,[['type'=>'group','id'=>$group->group_code]],$key());SessionService::configure($personSession,['ticket_mode'=>'group']);
$personOrder=CheckoutService::prepare($personSession,$personHold['hold_token'],$nextBuyer());$personTickets=CheckoutService::tickets($personOrder['order_id'],$personOrder['guest_access_token']);$personLine=$personTickets['lines'][0];
$ok(count($personLine['tickets'])===8&&$personLine['group_ticket']===null,'held individual ticket policy survives later session policy change');
$personScan=GateService::scan($personLine['tickets'][0]['qr_payload'],1,'test',$key(),1);$ok($personScan['admitted']===1&&$personScan['remaining']===7,'separate member QR admits exactly one guest');
$secondPerson=GateService::scan($personLine['tickets'][1]['qr_payload'],1,'test',$key(),1);$ok($secondPerson['remaining']===6,'other members arrive independently');$reject(fn()=>GateService::scan($personLine['tickets'][0]['qr_payload'],1,'test',$key(),1),'already_checked_in');
$reject(fn()=>GroupAdministration::cancel($personLine['line_id'],$key(),1),'group_already_used');

// Scope the configured cap to this PHP test process; do not alter shared staging options.
add_filter('option_pesm_phone_event_limit',static fn()=>20);
$sameBuyer=$nextBuyer();$capOrderIds=[];
foreach([$groups[5],$groups[6]]as$capGroup){$capHold=ReservationService::acquire($session,[['type'=>'group','id'=>$capGroup->group_code]],$key());$capOrderIds[]=CheckoutService::prepare($session,$capHold['hold_token'],$sameBuyer)['order_id'];}
$capHold=ReservationService::acquire($session,[['type'=>'group','id'=>$groups[7]->group_code]],$key());$reject(fn()=>CheckoutService::prepare($session,$capHold['hold_token'],$sameBuyer),'phone_limit');ReservationService::mutate($session,$capHold['hold_token'],'release',[],$key());
$generalSession=HallService::createSession($event,0,gmdate('Y-m-d H:i:s',time()+3600),gmdate('Y-m-d H:i:s',time()-3600),gmdate('Y-m-d H:i:s',time()+1800),0,'general',20);LifecycleService::change('session',$generalSession,'published',1);
$generalHold=(new \PESM\Domain\Booking\CapacityService())->acquire($generalSession,5,$key());$reject(fn()=>(new \PESM\Domain\GeneralAdmission\FreeAdmissionService())->prepare($generalSession,$generalHold['token'],$sameBuyer),'phone_limit');(new \PESM\Domain\Booking\CapacityService())->release($generalSession,$generalHold['token']);
$legacyHall=HallService::createHall($center,'Cross-mode cap legacy hall',1,6);$legacySession=HallService::createSession($event,$legacyHall,gmdate('Y-m-d H:i:s',time()+3600),gmdate('Y-m-d H:i:s',time()-3600),gmdate('Y-m-d H:i:s',time()+1800),0,'seated');LifecycleService::change('session',$legacySession,'published',1);$legacyIds=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT seat_id FROM {$p}session_seats WHERE session_id=%d ORDER BY seat_id LIMIT 5",$legacySession)));$legacyHold=(new \PESM\Domain\Booking\HoldService())->acquire($legacySession,$legacyIds,$key());$reject(fn()=>(new \PESM\Domain\Checkout\CheckoutService())->prepare($legacySession,$legacyHold['token'],$sameBuyer),'phone_limit');(new \PESM\Domain\Booking\HoldService())->release($legacySession,$legacyHold['token']);
$preserved=SessionService::settings($session);SessionService::configure($session,['ticket_mode'=>'individual']);$afterPatch=SessionService::settings($session);$ok($afterPatch['pricing']===$preserved['pricing'],'admission-only PATCH preserves every price override');

// A partial order refund exercises the explicit whole-line confirmation path.
$partialSession=$create(['ticket_mode'=>'group'],1000);$partialHold=ReservationService::acquire($partialSession,[['type'=>'group','id'=>$group->group_code],['type'=>'group','id'=>$second->group_code]],$key());$partialOrder=CheckoutService::prepare($partialSession,$partialHold['hold_token'],$nextBuyer());$partialWc=wc_get_order($partialOrder['order_id']);$partialWc->payment_complete('allocation-partial-refund');CheckoutService::confirmOrder($partialOrder['order_id']);$partialTickets=CheckoutService::tickets($partialOrder['order_id'],$partialOrder['guest_access_token']);$firstLine=$partialTickets['lines'][0]['line_id'];$otherLine=$partialTickets['lines'][1]['line_id'];
GroupAdministration::cancel($firstLine,$key(),1);$itemByLine=[];foreach($partialWc->get_items()as$itemId=>$item){$itemByLine[(int)$item->get_meta('_pesm_allocation_line_id')]=[$itemId,$item];}$firstItem=$itemByLine[$firstLine];$lineRefund=wc_create_refund(['order_id'=>$partialOrder['order_id'],'amount'=>$firstItem[1]->get_total(),'refund_payment'=>false,'restock_items'=>false,'line_items'=>[$firstItem[0]=>['qty'=>1,'refund_total'=>$firstItem[1]->get_total()]]]);
$ok(!is_wp_error($lineRefund),'partial order refund records one whole purchased group');
$confirmed=GroupAdministration::confirmRefund($firstLine,(int)$lineRefund->get_id(),$key(),1);$ok($confirmed['status']==='refunded','explicit whole-line refund verifies Woo line ownership and amount');
$otherStatus=(string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}allocation_lines WHERE id=%d",$otherLine));$ok($otherStatus==='issued','refunding one whole group preserves the other purchased group');
GroupAdministration::cancel($otherLine,$key(),1);$reject(fn()=>GroupAdministration::confirmRefund($otherLine,(int)$lineRefund->get_id(),$key(),1),'refund_line_mismatch');GroupAdministration::denyRefund($otherLine,$key(),1);

$fixture=['center_id'=>$center,'hall_id'=>$hall,'layout_id'=>$layout,'event_id'=>$event,'session_id'=>$session,'free_session_id'=>$freeSession,'order_id'=>$order['order_id'],'group_code'=>$group->group_code,'member_ids'=>$members,'checks'=>count($checks)];
update_option('pesm_allocation_integration_fixture',$fixture,false);
if(is_dir('/private/tmp/esm-integration')){file_put_contents('/private/tmp/esm-integration/allocation-v2-fixture.json',wp_json_encode($fixture));chmod('/private/tmp/esm-integration/allocation-v2-fixture.json',0600);}
if(is_dir('/private/tmp/esm-integration')){file_put_contents('/private/tmp/esm-integration/allocation-v2-results.json',wp_json_encode(['passed'=>true,'checks'=>count($checks),'scenarios'=>$checks,'fixture'=>$fixture],JSON_PRETTY_PRINT));}
echo wp_json_encode(['passed'=>true,'checks'=>count($checks),'scenarios'=>$checks,'fixture'=>$fixture],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
