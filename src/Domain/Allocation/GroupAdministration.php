<?php
declare(strict_types=1);
namespace PESM\Domain\Allocation;

/** Whole allocation lines are indivisible. Paid refunds require verified Woo refund evidence. */
final class GroupAdministration
{
    public static function page(int $sessionId,int $cursor=0,int $limit=50,int $actorId=0): array
    {
        $actorId=$actorId?:get_current_user_id();$limit=max(1,min(100,$limit));
        if($actorId<1||(!user_can($actorId,'pesm_view_orders')&&!user_can($actorId,'pesm_manage_refunds')&&!user_can($actorId,'pesm_issue_tickets'))){Policy::fail('permission_denied',403);}
        return Database::transaction(function()use($sessionId,$cursor,$limit,$actorId):array{
            global $wpdb;$p=$wpdb->prefix.'pesm_';$session=Database::session($sessionId);
            if(!\PESM\Security\Capabilities::centerAllowed((int)$session->center_id,$actorId)){Policy::fail('permission_denied',403);}
            $rows=$wpdb->get_results($wpdb->prepare("SELECT l.*,h.order_id,h.quote_json,(SELECT COUNT(*) FROM {$p}allocation_tickets t WHERE t.line_id=l.id AND t.status='checked_in') AS used FROM {$p}allocation_lines l JOIN {$p}allocation_holds h ON h.id=l.hold_id WHERE h.session_id=%d AND l.id>%d AND h.order_id>0 ORDER BY l.id LIMIT %d",$sessionId,max(0,$cursor),$limit+1));
            $more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);$items=[];
            foreach($rows as$row){$quote=Database::decode($row->quote_json);$items[]=['id'=>(int)$row->id,'line_id'=>(int)$row->id,'order_id'=>(int)$row->order_id,'unit_type'=>$row->unit_type,'unit_id'=>$row->unit_code,'code'=>$row->unit_code,'section_code'=>$row->section_code,'capacity'=>(int)$row->admission_count,'admissions'=>(int)$row->admission_count,'used'=>(int)$row->used,'remaining'=>(int)$row->admission_count-(int)$row->used,'status'=>$row->status,'ticket_mode'=>$row->ticket_mode,'entry_policy'=>$row->entry_policy,'total_minor'=>(int)$row->total_minor,'currency'=>$quote['currency'],'price_decimals'=>$quote['price_decimals']];}
            return ['items'=>$items,'next_cursor'=>$more?(int)end($rows)->id:null];
        });
    }

    /** Same-price whole-group move; a price difference requires a separate settlement workflow. */
    public static function reallocate(int $lineId,array $destination,string $key,int $actorId): array
    {
        if($actorId<1||(!user_can($actorId,'pesm_issue_tickets')&&!user_can($actorId,'pesm_manage_refunds'))){Policy::fail('permission_denied',403);}
        $units=Policy::units([$destination]);if($units[0]['type']==='seat'){Policy::fail('whole_group_required',422);}
        [$request,$hash]=Database::request('reallocate:'.$lineId,$key,['destination'=>$units,'actor'=>$actorId]);
        return Database::transaction(function()use($lineId,$units,$actorId,$request,$hash):array{
            global $wpdb;$p=$wpdb->prefix.'pesm_';
            $initial=$wpdb->get_row($wpdb->prepare("SELECT l.*,h.session_id FROM {$p}allocation_lines l JOIN {$p}allocation_holds h ON h.id=l.hold_id WHERE l.id=%d",$lineId));if(!$initial){Policy::fail('invalid_line',404);}
            $session=Database::session((int)$initial->session_id);if(!\PESM\Security\Capabilities::centerAllowed((int)$session->center_id,$actorId)){Policy::fail('permission_denied',403);}
            $target=Pricing::quote($session,$units,SessionService::settings((int)$session->id));$newLine=$target['lines'][0];
            Database::sections((int)$session->id,array_merge([$initial->section_code],Pricing::sections($target)));
            $hold=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_holds WHERE id=%d FOR UPDATE",(int)$initial->hold_id));
            $line=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_lines WHERE id=%d FOR UPDATE",$lineId));$now=Database::now();
            if($replay=Database::replay($request,$hash)){return $replay;}
            if(!$line||!$hold||$line->status!=='issued'||$line->unit_type==='seat'||$line->seat_ids_json!==$initial->seat_ids_json){Policy::fail('line_inactive');}
            $originalQuote=Database::decode($hold->quote_json);
            if($newLine['admissions']!==(int)$line->admission_count||$newLine['total_minor']!==(int)$line->total_minor||
                !hash_equals((string)$originalQuote['currency'],(string)$target['currency'])||$originalQuote['price_decimals']!==$target['price_decimals']){Policy::fail('price_difference_unsupported');}
            $slots=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}allocation_tickets WHERE line_id=%d ORDER BY seat_id FOR UPDATE",$lineId));
            if(count($slots)!==(int)$line->admission_count){Policy::fail('admission_snapshot_invalid',503);}foreach($slots as$slot){if($slot->status!=='active'){Policy::fail('group_already_used');}}
            $oldIds=Database::decode($line->seat_ids_json);$newIds=$newLine['seat_ids'];sort($oldIds,SORT_NUMERIC);sort($newIds,SORT_NUMERIC);
            if($oldIds===$newIds){$result=['line_id'=>$lineId,'from_unit'=>$line->unit_code,'unit_id'=>$line->unit_code,'unchanged'=>true];Database::receipt($request,$hash,$result,$now);return $result;}
            if(array_intersect($oldIds,$newIds)){Policy::fail('overlapping_group',422);}
            $rows=ReservationService::inventory((int)$session->id,array_merge($oldIds,$newIds));$old=array_fill_keys($oldIds,true);
            foreach($rows as$row){if(isset($old[(int)$row->seat_id])){if($row->status!=='sold'||(int)$row->hold_id!==(int)$hold->id){Policy::fail('inventory_conflict');}}elseif(!\PESM\Domain\Booking\HoldPolicy::canAcquire($row->status,$row->expires_at,$now)){Policy::fail('seat_unavailable');}}
            ReservationService::free((int)$session->id,(int)$hold->id,$oldIds,$now,['sold']);$marks=implode(',',array_fill(0,count($newIds),'%d'));
            Database::checked($wpdb->query($wpdb->prepare("UPDATE {$p}session_seats SET status='sold',hold_id=%d,expires_at=NULL,updated_at=%s WHERE session_id=%d AND seat_id IN ($marks)",(int)$hold->id,$now,(int)$session->id,...$newIds)));
            foreach($slots as$i=>$slot){Database::checked($wpdb->update($p.'allocation_tickets',['seat_id'=>$newIds[$i],'qr_version'=>(int)$slot->qr_version+1],['id'=>(int)$slot->id]));}
            Database::checked($wpdb->update($p.'allocation_lines',['unit_type'=>$newLine['unit_type'],'unit_code'=>(string)$newLine['unit_id'],'section_code'=>$newLine['section_code'],'seat_ids_json'=>Database::json($newIds),'qr_version'=>(int)$line->qr_version+1],['id'=>$lineId]));
            $quote=Database::decode($hold->quote_json);$quote['lines'][(int)$line->line_no]=$newLine;Database::checked($wpdb->update($p.'allocation_holds',['quote_json'=>Database::json($quote)],['id'=>(int)$hold->id]));Database::revision((int)$session->id,[$initial->section_code,$newLine['section_code']]);
            $result=['line_id'=>$lineId,'from_unit'=>$line->unit_code,'unit_id'=>$newLine['unit_id'],'qr_version'=>(int)$line->qr_version+1,'moved_at_utc'=>ReservationService::iso($now)];Database::receipt($request,$hash,$result,$now);\PESM\Support\Audit::record('allocation_group_reallocated','allocation_line',$lineId,$actorId);return $result;
        });
    }

    public static function cancel(int $lineId,string $key,int $actorId): array
    {
        return self::change($lineId,$key,$actorId,'cancel',function(object $line,object $hold,object $session,string $now):array{
            global $wpdb;$p=$wpdb->prefix.'pesm_';
            if((int)$line->total_minor>0){Database::checked($wpdb->update($p.'allocation_lines',['status'=>'refund_review'],['id'=>(int)$line->id]));return ['line_id'=>(int)$line->id,'status'=>'refund_review','amount_minor'=>(int)$line->total_minor,'order_id'=>(int)$hold->order_id];}
            self::release($line,$hold,$session,$now,'cancelled');return ['line_id'=>(int)$line->id,'status'=>'cancelled'];
        });
    }

    public static function confirmRefund(int $lineId,int $refundId,string $key,int $actorId): array
    {
        if(!function_exists('wc_get_order')){Policy::fail('woocommerce_required',503);}$refund=wc_get_order($refundId);
        if(!$refund||!method_exists($refund,'get_type')||$refund->get_type()!=='shop_order_refund'){Policy::fail('invalid_refund',422);}
        return self::change($lineId,$key,$actorId,'refund:'.$refundId,function(object $line,object $hold,object $session,string $now)use($refund,$refundId):array{
            if($line->status!=='refund_review'||(int)$refund->get_parent_id()!==(int)$hold->order_id){Policy::fail('invalid_refund');}
            $order=wc_get_order((int)$hold->order_id);$quote=Database::decode($hold->quote_json);
            if(!$order||!hash_equals((string)$quote['currency'],(string)$refund->get_currency())){Policy::fail('invalid_refund');}
            $amount=ltrim((string)$refund->get_total(),'-');
            if(\PESM\Domain\Checkout\FinancialSnapshot::minor($amount,(int)$quote['price_decimals'])!==(int)$line->total_minor){Policy::fail('refund_amount_mismatch');}
            $matched=0;
            foreach($refund->get_items('line_item')as$item){$original=$order->get_item((int)$item->get_meta('_refunded_item_id'));if(!$original||(int)$original->get_meta('_pesm_allocation_line_id')!==(int)$line->id){Policy::fail('refund_line_mismatch');}$matched+=\PESM\Domain\Checkout\FinancialSnapshot::minor(ltrim((string)$item->get_total(),'-'),(int)$quote['price_decimals']);}
            if($matched!==(int)$line->total_minor){Policy::fail('refund_line_mismatch');}
            self::release($line,$hold,$session,$now,'refunded');return ['line_id'=>(int)$line->id,'status'=>'refunded','wc_refund_id'=>$refundId];
        });
    }

    public static function denyRefund(int $lineId,string $key,int $actorId): array
    {
        return self::change($lineId,$key,$actorId,'deny_refund',function(object $line):array{
            if($line->status!=='refund_review'){Policy::fail('invalid_refund');}global $wpdb;$p=$wpdb->prefix.'pesm_';Database::checked($wpdb->update($p.'allocation_lines',['status'=>'issued'],['id'=>(int)$line->id]));return ['line_id'=>(int)$line->id,'status'=>'issued'];
        });
    }

    private static function change(int $lineId,string $key,int $actorId,string $action,callable $fn): array
    {
        if($actorId<1||!user_can($actorId,'pesm_manage_refunds')){Policy::fail('permission_denied',403);}[$request,$hash]=Database::request('admin:'.$lineId,$key,['action'=>$action,'actor'=>$actorId]);
        return Database::transaction(function()use($lineId,$actorId,$request,$hash,$fn):array{
            global $wpdb;$p=$wpdb->prefix.'pesm_';
            $initial=$wpdb->get_row($wpdb->prepare("SELECT l.*,h.session_id FROM {$p}allocation_lines l JOIN {$p}allocation_holds h ON h.id=l.hold_id WHERE l.id=%d",$lineId));if(!$initial){Policy::fail('invalid_line',404);}
            $session=Database::session((int)$initial->session_id);if(!\PESM\Security\Capabilities::centerAllowed((int)$session->center_id,$actorId)){Policy::fail('permission_denied',403);}
            Database::sections((int)$session->id,[$initial->section_code]);
            $hold=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_holds WHERE id=%d FOR UPDATE",(int)$initial->hold_id));
            $line=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_lines WHERE id=%d FOR UPDATE",$lineId));$now=Database::now();
            if($replay=Database::replay($request,$hash)){return $replay;}
            if(!$line||!$hold||!in_array($line->status,['issued','refund_review'],true)){Policy::fail('line_inactive');}
            if($line->seat_ids_json!==$initial->seat_ids_json||$line->section_code!==$initial->section_code){Policy::fail('hold_changed_retry');}
            $slots=$wpdb->get_results($wpdb->prepare("SELECT id,status FROM {$p}allocation_tickets WHERE line_id=%d ORDER BY seat_id FOR UPDATE",$lineId));
            if(count($slots)!==(int)$line->admission_count){Policy::fail('admission_snapshot_invalid',503);}
            foreach($slots as$slot){if($slot->status!=='active'){Policy::fail('group_already_used');}}
            $result=$fn($line,$hold,$session,$now);Database::receipt($request,$hash,$result,$now);\PESM\Support\Audit::record('allocation_line_admin','allocation_line',$lineId,$actorId);return $result;
        });
    }

    private static function release(object $line,object $hold,object $session,string $now,string $status): void
    {
        global $wpdb;$p=$wpdb->prefix.'pesm_';$ids=Database::decode($line->seat_ids_json);$rows=ReservationService::inventory((int)$session->id,$ids);
        foreach($rows as$row){if($row->status!=='sold'||(int)$row->hold_id!==(int)$hold->id){Policy::fail('inventory_conflict');}}
        ReservationService::free((int)$session->id,(int)$hold->id,$ids,$now,['sold']);
        Database::checked($wpdb->query($wpdb->prepare("UPDATE {$p}allocation_tickets SET status=%s,qr_version=qr_version+1 WHERE line_id=%d",$status,(int)$line->id)));
        Database::checked($wpdb->update($p.'allocation_lines',['status'=>$status,'qr_version'=>(int)$line->qr_version+1],['id'=>(int)$line->id]));Database::revision((int)$session->id,[$line->section_code]);
    }
}
