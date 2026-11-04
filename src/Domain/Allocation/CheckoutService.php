<?php
declare(strict_types=1);
namespace PESM\Domain\Allocation;

use PESM\Domain\Checkout\CheckoutPolicy;
use PESM\Domain\Checkout\FinancialSnapshot;

/** Dedicated one-session Woo order boundary. No external calls under inventory locks. */
final class CheckoutService
{
    public static function prepare(int $sessionId,string $holdToken,array $buyer): array
    {
        if(!function_exists('wc_create_order')||!function_exists('wc_get_order')){Policy::fail('woocommerce_required',503);}
        if((isset($buyer['ticket_modes'])&&!is_array($buyer['ticket_modes']))||(isset($buyer['attendees'])&&!is_array($buyer['attendees']))){Policy::fail('invalid_attendee',422);}
        $first=CheckoutPolicy::normalizeName((string)($buyer['first_name']??''));
        $last=CheckoutPolicy::normalizeName((string)($buyer['last_name']??''));
        $phone=CheckoutPolicy::normalizePhone((string)($buyer['phone']??''));$email=trim((string)($buyer['email']??''));
        if($email!==''&&!is_email($email)){Policy::fail('invalid_email',422);}
        $stage=Database::transaction(function()use($sessionId,$holdToken,$buyer,$first,$last,$phone):array{
            global $wpdb;$p=$wpdb->prefix.'pesm_';$session=Database::session($sessionId);
            $initial=ReservationService::hold($sessionId,$holdToken,false);$quote=Database::decode($initial->quote_json);$sections=Pricing::sections($quote);Database::sections($sessionId,$sections);
            $hold=ReservationService::hold($sessionId,$holdToken,true);$now=Database::now();
            if($hold->quote_json!==$initial->quote_json){Policy::fail('hold_changed_retry');}
            if((int)$hold->order_id>0&&in_array($hold->status,['payment_pending','sold'],true)){return ['existing'=>(int)$hold->order_id,'hold_id'=>(int)$hold->id];}
            if($hold->status!=='active'||$hold->expires_at<=$now){Policy::fail($hold->status==='checkout_creating'?'checkout_reconciliation_required':'hold_expired',409);}
            Database::sale($session,$now);
            if($quote['total_minor']>0&&function_exists('wc_tax_enabled')&&wc_tax_enabled()){Policy::fail('paid_tax_unsupported',409);}
            self::phoneLimit($session,$phone,(int)$quote['admissions']);
            $ids=Pricing::ids($quote);$rows=ReservationService::inventory($sessionId,$ids);
            foreach($rows as$row){if($row->status!=='held'||(int)$row->hold_id!==(int)$hold->id||$row->expires_at<=$now){Policy::fail('hold_conflict');}}
            Topology::validate($session,$quote,$now,(int)$hold->id);
            $lines=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}allocation_lines WHERE hold_id=%d ORDER BY line_no FOR UPDATE",(int)$hold->id));
            $attendees=[];
            foreach($lines as$line){
                $mode=$line->unit_type==='seat'?'individual':$quote['ticket_mode'];
                if($mode==='buyer_choice'){$mode=$buyer['ticket_modes'][$line->unit_code]??'individual';}
                if(!in_array($mode,['individual','group'],true)){Policy::fail('invalid_ticket_mode',422);}
                Database::checked($wpdb->update($p.'allocation_lines',['ticket_mode'=>$mode,'entry_policy'=>$quote['group_entry_policy']],['id'=>(int)$line->id]));
                $line->ticket_mode=$mode;$line->entry_policy=$quote['group_entry_policy'];
                foreach(Database::decode($line->seat_ids_json)as$seatId){
                    $person=$buyer['attendees'][$seatId]??null;
                    if($person!==null&&!is_array($person)){Policy::fail('invalid_attendee',422);}
                    $given=$person?CheckoutPolicy::normalizeName((string)($person['first_name']??'')):$first;
                    $family=$person?CheckoutPolicy::normalizeName((string)($person['last_name']??'')):$last;
                    $attendees[(string)$seatId]=$given.' '.$family;
                }
            }
            $deadline=gmdate('Y-m-d H:i:s',min(strtotime($now.' UTC')+1800,strtotime($session->starts_at.' UTC')));
            if($deadline<=$now){Policy::fail('event_closed');}
            ReservationService::assign($sessionId,(int)$hold->id,$ids,'payment_pending',$deadline,$now);
            Database::checked($wpdb->update($p.'allocation_holds',['status'=>'checkout_creating','expires_at'=>$deadline,'phone_hash'=>hash_hmac('sha256',$phone,wp_salt('auth'))],['id'=>(int)$hold->id]));Database::revision($sessionId,$sections);
            return ['hold_id'=>(int)$hold->id,'quote'=>$quote,'lines'=>$lines,'attendees'=>$attendees];
        });
        if(isset($stage['existing'])){return self::response((int)$stage['existing'],(int)$stage['hold_id'],$holdToken);}
        try{
            $quote=$stage['quote'];$product=self::product();$order=wc_create_order(['created_via'=>'pesm']);
            if(is_wp_error($order)){throw new \RuntimeException('Order creation failed.');}
            $order->set_address(['first_name'=>$first,'last_name'=>$last,'phone'=>$phone,'email'=>$email],'billing');$order->set_customer_id(get_current_user_id());$order->set_currency($quote['currency']);
            $order->update_meta_data('_pesm_is_ticket_order','yes');$order->update_meta_data('_pesm_mode','allocated');$order->update_meta_data('_pesm_session_id',$sessionId);$order->update_meta_data('_pesm_allocation_hold_id',$stage['hold_id']);
            $order->update_meta_data('_pesm_total_minor',$quote['total_minor']);$order->update_meta_data('_pesm_currency',$quote['currency']);$order->update_meta_data('_pesm_price_decimals',$quote['price_decimals']);
            $order->update_meta_data('_pesm_allocation_attendees',$stage['attendees']);
            $snapshot=[];
            foreach($stage['lines']as$line){
                $item=new \WC_Order_Item_Product();$item->set_product($product);$item->set_name(Messages::text('unit_'.$line->unit_type).' '.$line->unit_code);$item->set_quantity(1);
                $amount=CheckoutPolicy::amount((int)$line->total_minor,(int)$quote['price_decimals']);$item->set_subtotal($amount);$item->set_total($amount);
                $item->add_meta_data('_pesm_allocation_line_id',(int)$line->id,true);$item->add_meta_data('_pesm_admission_count',(int)$line->admission_count,true);$item->add_meta_data('_pesm_ticket_mode',$line->ticket_mode,true);$item->add_meta_data('_pesm_entry_policy',$line->entry_policy,true);
                $order->add_item($item);$snapshot[]=['line_id'=>(int)$line->id,'total_minor'=>(int)$line->total_minor,'admissions'=>(int)$line->admission_count,'ticket_mode'=>$line->ticket_mode,'entry_policy'=>$line->entry_policy];
            }
            $financial=['total_minor'=>(int)$quote['total_minor'],'currency'=>$quote['currency'],'price_decimals'=>(int)$quote['price_decimals'],'pricing_revision'=>(int)$quote['pricing_revision'],'lines'=>$snapshot];
            $order->update_meta_data('_pesm_allocation_financial_v1',$financial);$order->calculate_totals(false);
            FinancialSnapshot::validate($order,$financial['total_minor'],$financial['currency'],$financial['price_decimals'],$financial['total_minor']===0);
            $order->set_status('pending');$order->save();$orderId=(int)$order->get_id();$guest=self::guest((int)$stage['hold_id'],$holdToken);
            Database::transaction(function()use($sessionId,$stage,$orderId,$guest,$financial):void{
                global $wpdb;$p=$wpdb->prefix.'pesm_';Database::session($sessionId);Database::sections($sessionId,Pricing::sections($stage['quote']));
                $hold=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_holds WHERE id=%d FOR UPDATE",(int)$stage['hold_id']));$now=Database::now();
                if(!$hold||$hold->status!=='checkout_creating'||$hold->expires_at<=$now){Policy::fail('checkout_reconciliation_required',503);}
                Database::insert('allocation_orders',['hold_id'=>(int)$hold->id,'order_id'=>$orderId,'guest_token_hash'=>hash('sha256',$guest),'financial_json'=>Database::json($financial),'status'=>'pending','created_at'=>$now]);
                Database::checked($wpdb->update($p.'allocation_holds',['order_id'=>$orderId,'status'=>'payment_pending'],['id'=>(int)$hold->id]));
            });
            if($financial['total_minor']===0){
                $result=self::confirmOrder($orderId,true);if(!in_array($result,['issued','already_issued'],true)){Policy::fail('checkout_reconciliation_required',503);}
                $order->update_status('completed','Free allocated admission issued.',true);
            }
            return self::response($orderId,(int)$stage['hold_id'],$holdToken);
        }catch(\Throwable $e){throw new \PESM\Domain\Booking\HoldException('checkout_reconciliation_required',Messages::text('checkout_reconciliation_required'),503);}
    }

    public static function confirmOrder(int $orderId,bool $free=false): string
    {
        if(!function_exists('wc_get_order')){Policy::fail('woocommerce_required',503);}$order=wc_get_order($orderId);
        if(!$order||$order->get_meta('_pesm_mode')!=='allocated'||$order->get_meta('_pesm_is_ticket_order')!=='yes'){Policy::fail('invalid_order',404);}
        if(!$free&&(!method_exists($order,'is_paid')||!$order->is_paid()||!$order->get_date_paid())){Policy::fail('payment_unverified');}
        if(in_array((string)$order->get_status(),['cancelled','failed','refunded','trash'],true)){Policy::fail('order_inactive');}
        $sessionId=(int)$order->get_meta('_pesm_session_id');$holdId=(int)$order->get_meta('_pesm_allocation_hold_id');
        return Database::transaction(function()use($order,$orderId,$sessionId,$holdId,$free):string{
            global $wpdb;$p=$wpdb->prefix.'pesm_';$session=Database::session($sessionId);
            $initial=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_holds WHERE id=%d AND session_id=%d",$holdId,$sessionId));if(!$initial){Policy::fail('invalid_order');}
            $quote=Database::decode($initial->quote_json);Database::sections($sessionId,Pricing::sections($quote));
            $hold=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_holds WHERE id=%d AND session_id=%d FOR UPDATE",$holdId,$sessionId));
            $link=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_orders WHERE hold_id=%d AND order_id=%d FOR UPDATE",$holdId,$orderId));$now=Database::now();
            if(!$hold||!$link||(int)$hold->order_id!==$orderId||$hold->quote_json!==$initial->quote_json){Policy::fail('invalid_order');}
            if($link->status==='issued'&&$hold->status==='sold'){return 'already_issued';}
            $financial=Database::decode($link->financial_json);$stored=$order->get_meta('_pesm_allocation_financial_v1');
            if(!is_array($stored)||!hash_equals(hash('sha256',Database::json($financial)),hash('sha256',Database::json($stored)))||$financial['total_minor']!==$quote['total_minor']){Policy::fail('price_mismatch');}
            FinancialSnapshot::validate($order,(int)$financial['total_minor'],(string)$financial['currency'],(int)$financial['price_decimals'],$free);
            $ids=Pricing::ids($quote);$rows=ReservationService::inventory($sessionId,$ids);$conflict=$hold->status!=='payment_pending'||$hold->expires_at<=$now||$session->status==='cancelled'||$session->event_status==='cancelled';
            foreach($rows as$row){if($row->status!=='payment_pending'||(int)$row->hold_id!==$holdId||$row->expires_at<=$now){$conflict=true;}}
            if($conflict){Database::checked($wpdb->update($p.'allocation_orders',['status'=>'review_required'],['id'=>(int)$link->id]));return 'review_required';}
            $lines=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}allocation_lines WHERE hold_id=%d ORDER BY line_no FOR UPDATE",$holdId));$attendees=$order->get_meta('_pesm_allocation_attendees');
            if(!is_array($attendees)){Policy::fail('invalid_attendee_snapshot');}
            $expected=[];foreach($financial['lines']as$f){$expected[(int)$f['line_id']]=$f;}
            foreach($lines as$line){
                $f=$expected[(int)$line->id]??null;
                if(!$f||(int)$f['total_minor']!==(int)$line->total_minor||(int)$f['admissions']!==(int)$line->admission_count||$f['ticket_mode']!==$line->ticket_mode||$f['entry_policy']!==$line->entry_policy){Policy::fail('price_mismatch');}
                $memberIds=Database::decode($line->seat_ids_json);$amounts=Policy::allocate((int)$line->total_minor,$memberIds);
                foreach($amounts as$seatId=>$amount){
                    $name=$attendees[(string)$seatId]??null;if(!is_string($name)||$name===''){Policy::fail('invalid_attendee_snapshot');}
                    Database::insert('allocation_tickets',['line_id'=>(int)$line->id,'session_id'=>$sessionId,'order_id'=>$orderId,'seat_id'=>$seatId,'public_id'=>bin2hex(random_bytes(16)),'attendee_name'=>$name,'amount_minor'=>$amount,'status'=>'active','created_at'=>$now]);
                }
                Database::checked($wpdb->update($p.'allocation_lines',['status'=>'issued','public_id'=>$line->ticket_mode==='group'?bin2hex(random_bytes(16)):null],['id'=>(int)$line->id]));
            }
            foreach($ids as$id){Database::checked($wpdb->update($p.'session_seats',['status'=>'sold','expires_at'=>null,'updated_at'=>$now],['session_id'=>$sessionId,'seat_id'=>$id,'hold_id'=>$holdId,'status'=>'payment_pending']));}
            Database::checked($wpdb->update($p.'allocation_holds',['status'=>'sold'],['id'=>$holdId]));Database::checked($wpdb->update($p.'allocation_orders',['status'=>'issued'],['id'=>(int)$link->id]));Database::revision($sessionId,Pricing::sections($quote));
            \PESM\Support\Audit::record('allocation_tickets_issued','order',$orderId);return 'issued';
        });
    }

    public static function tickets(int $orderId,string $guestToken): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$guestToken)){Policy::fail('permission_denied',403);}global $wpdb;$p=$wpdb->prefix.'pesm_';
        $link=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_orders WHERE order_id=%d AND guest_token_hash=%s",$orderId,hash('sha256',$guestToken)));
        if(!$link){
            $link=$wpdb->get_row($wpdb->prepare("SELECT a.* FROM {$p}allocation_orders a WHERE a.order_id=%d AND EXISTS (SELECT 1 FROM {$p}guest_access_tokens g WHERE g.order_id=a.order_id AND g.token_hash=%s AND g.expires_at>UTC_TIMESTAMP() AND g.revoked_at IS NULL)",$orderId,hash('sha256',$guestToken)));
        }
        if(!$link){Policy::fail('permission_denied',403);}
        $lines=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}allocation_lines WHERE hold_id=%d ORDER BY line_no",(int)$link->hold_id));$result=[];
        foreach($lines as$line){
            $rows=$wpdb->get_results($wpdb->prepare("SELECT t.*,s.seat_code FROM {$p}allocation_tickets t JOIN {$p}seats s ON s.id=t.seat_id WHERE t.line_id=%d ORDER BY t.seat_id",(int)$line->id));$used=0;$tickets=[];
            foreach($rows as$row){if($row->status==='checked_in'){++$used;}
                if($line->ticket_mode==='individual'){$tickets[]=['ticket_number'=>Credential::number('P',$row->public_id),'seat_id'=>(int)$row->seat_id,'seat_code'=>$row->seat_code,'attendee_name'=>$row->attendee_name,'status'=>$row->status==='checked_in'||$line->status==='issued'?$row->status:$line->status,'qr_payload'=>$line->status==='issued'&&$row->status==='active'?Credential::create('P',$row->public_id,(int)$row->qr_version):null];}
            }
            $group=null;if($line->ticket_mode==='group'&&$line->public_id){$group=['ticket_number'=>Credential::number('G',$line->public_id),'capacity'=>(int)$line->admission_count,'used'=>$used,'remaining'=>$line->status==='issued'?(int)$line->admission_count-$used:0,'status'=>$line->status==='issued'&&$used===(int)$line->admission_count?'checked_in':$line->status,'qr_payload'=>$line->status==='issued'&&$used<(int)$line->admission_count?Credential::create('G',$line->public_id,(int)$line->qr_version):null];}
            $result[]=['line_id'=>(int)$line->id,'unit_type'=>$line->unit_type,'unit_id'=>$line->unit_code,'admissions'=>(int)$line->admission_count,'ticket_mode'=>$line->ticket_mode,'entry_policy'=>$line->entry_policy,'status'=>$line->status,'tickets'=>$tickets,'group_ticket'=>$group];
        }
        return ['order_id'=>$orderId,'status'=>$link->status,'lines'=>$result];
    }

    public static function requestRecovery(int $orderId,string $email): array
    {
        // The existing service validates the immutable order billing mailbox, sends an
        // eight-digit code, and applies IP/resend/attempt limits without enumeration.
        return (new \PESM\Domain\Guest\GuestAccessService())->requestCode($orderId,$email);
    }

    public static function verifyRecovery(int $orderId,string $code): array
    {
        return (new \PESM\Domain\Guest\GuestAccessService())->verifyCode($orderId,$code);
    }

    /** Woo cancellation/refund callback: revoke credentials without splitting a group. */
    public static function revokeOrder(int $orderId): array
    {
        if(!function_exists('wc_get_order')){Policy::fail('woocommerce_required',503);}$order=wc_get_order($orderId);
        if(!$order||$order->get_meta('_pesm_mode')!=='allocated'||!in_array($order->get_status(),['cancelled','failed','refunded'],true)){Policy::fail('invalid_order');}
        $sessionId=(int)$order->get_meta('_pesm_session_id');$holdId=(int)$order->get_meta('_pesm_allocation_hold_id');
        return Database::transaction(function()use($order,$orderId,$sessionId,$holdId):array{
            global $wpdb;$p=$wpdb->prefix.'pesm_';$session=Database::session($sessionId);
            $initial=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_holds WHERE id=%d AND session_id=%d",$holdId,$sessionId));if(!$initial){Policy::fail('invalid_order');}
            $quote=Database::decode($initial->quote_json);$sections=Pricing::sections($quote);Database::sections($sessionId,$sections);
            $hold=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_holds WHERE id=%d AND session_id=%d FOR UPDATE",$holdId,$sessionId));
            $link=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_orders WHERE hold_id=%d AND order_id=%d FOR UPDATE",$holdId,$orderId));$now=Database::now();
            if(!$hold||!$link||(int)$hold->order_id!==$orderId){Policy::fail('invalid_order');}
            if($hold->quote_json!==$initial->quote_json){Policy::fail('hold_changed_retry');}
            if(in_array($link->status,['revoked','cancelled'],true)){return ['revoked'=>true,'idempotent_replay'=>true];}
            $financial=Database::decode($link->financial_json);$paid=(int)$financial['total_minor']>0;$fullyRefunded=false;
            if($order->get_status()==='refunded'&&method_exists($order,'get_total_refunded')&&hash_equals((string)$financial['currency'],(string)$order->get_currency())){
                $fullyRefunded=FinancialSnapshot::minor((string)$order->get_total_refunded(),(int)$financial['price_decimals'])===(int)$financial['total_minor'];
            }
            $lines=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}allocation_lines WHERE hold_id=%d ORDER BY id FOR UPDATE",$holdId));$review=false;
            foreach($lines as$line){
                if(in_array($line->status,['cancelled','refunded'],true)){continue;}
                $slots=$wpdb->get_results($wpdb->prepare("SELECT id,status FROM {$p}allocation_tickets WHERE line_id=%d ORDER BY seat_id FOR UPDATE",(int)$line->id));$used=false;foreach($slots as$slot){if($slot->status==='checked_in'){$used=true;}}
                $canRelease=!$used&&($hold->status!=='sold'||!$paid||$fullyRefunded);
                if($canRelease){
                    $ids=Database::decode($line->seat_ids_json);ReservationService::inventory($sessionId,$ids);ReservationService::free($sessionId,$holdId,$ids,$now,['held','payment_pending','sold']);
                }else{$review=true;}
                $status=$canRelease?($fullyRefunded?'refunded':'cancelled'):'review_required';
                Database::checked($wpdb->update($p.'allocation_lines',['status'=>$status,'qr_version'=>(int)$line->qr_version+1],['id'=>(int)$line->id]));
                Database::checked($wpdb->query($wpdb->prepare("UPDATE {$p}allocation_tickets SET status='revoked',qr_version=qr_version+1 WHERE line_id=%d AND status='active'",(int)$line->id)));
            }
            Database::checked($wpdb->update($p.'allocation_orders',['status'=>$review?'review_required':'revoked'],['id'=>(int)$link->id]));
            Database::checked($wpdb->update($p.'allocation_holds',['status'=>$review?'review_required':'released'],['id'=>$holdId]));Database::revision($sessionId,$sections);
            \PESM\Support\Audit::record('allocation_order_revoked','order',$orderId);return ['revoked'=>true,'review_required'=>$review];
        });
    }

    private static function phoneLimit(object $session,string $phone,int $requested): void
    {
        global $wpdb;$p=$wpdb->prefix.'pesm_';$hash=hash_hmac('sha256',$phone,wp_salt('auth'));
        Database::checked($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$p}phone_locks (event_id,phone_hash) VALUES (%d,%s)",(int)$session->event_id,$hash)));
        $wpdb->get_var($wpdb->prepare("SELECT event_id FROM {$p}phone_locks WHERE event_id=%d AND phone_hash=%s FOR UPDATE",(int)$session->event_id,$hash));
        $used=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(l.admission_count),0) FROM {$p}allocation_lines l JOIN {$p}allocation_holds h ON h.id=l.hold_id JOIN {$p}sessions s ON s.id=h.session_id WHERE s.event_id=%d AND h.phone_hash=%s AND (h.status='sold' OR (h.status IN ('checkout_creating','payment_pending') AND h.expires_at>UTC_TIMESTAMP()))",(int)$session->event_id,$hash));
        $used+=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}hold_items hi JOIN {$p}holds h ON h.id=hi.hold_id JOIN {$p}sessions s ON s.id=h.session_id WHERE s.event_id=%d AND h.phone_hash=%s AND (h.status='sold' OR (h.status IN ('checkout_creating','payment_pending') AND h.expires_at>UTC_TIMESTAMP()))",(int)$session->event_id,$hash));
        $used+=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(h.quantity),0) FROM {$p}capacity_holds h JOIN {$p}sessions s ON s.id=h.session_id WHERE s.event_id=%d AND h.phone_hash=%s AND (h.status='issued' OR (h.status IN ('checkout_creating','payment_pending') AND h.expires_at>UTC_TIMESTAMP()))",(int)$session->event_id,$hash));
        $limit=max(1,min(200,(int)get_option('pesm_phone_event_limit',20)));
        if($used+$requested>$limit){Policy::fail('phone_limit');}
    }

    private static function guest(int $holdId,string $token): string {return hash_hmac('sha256','pesm:allocated:guest:'.$holdId.':'.$token,wp_salt('secure_auth'));}
    private static function response(int $orderId,int $holdId,string $token): array
    {
        $order=wc_get_order($orderId);if(!$order||(int)$order->get_meta('_pesm_allocation_hold_id')!==$holdId){Policy::fail('checkout_reconciliation_required',503);}
        return ['order_id'=>$orderId,'hold_id'=>$holdId,'guest_access_token'=>self::guest($holdId,$token),'payment_required'=>(float)$order->get_total()>0,'payment_url'=>(float)$order->get_total()>0?$order->get_checkout_payment_url():null,'order_status'=>(string)$order->get_status()];
    }
    private static function product(): object
    {
        $id=(int)get_option('pesm_wc_product_id',0);$product=$id?wc_get_product($id):false;if($product&&$product->is_type('simple')){return $product;}
        $product=new \WC_Product_Simple();$product->set_name(\PESM\Support\I18n::text('بلیت رویداد (محصول فنی)'));$product->set_status('publish');$product->set_catalog_visibility('hidden');$product->set_virtual(true);$product->set_regular_price('0');$product->set_tax_status('none');$product->save();update_option('pesm_wc_product_id',$product->get_id(),false);return $product;
    }
}
