<?php
declare(strict_types=1);
namespace PESM\Domain\Allocation;

/** SQL selects unresolved allocations before pagination; healthy recent sales cannot hide old cases. */
final class Reconciliation
{
    public static function page(int $centerId,int $cursor=0,int $limit=100,int $actorId=0): array
    {
        $actorId=$actorId?:get_current_user_id();self::authorized($centerId,$actorId,false);$limit=max(1,min(200,$limit));global $wpdb;$p=$wpdb->prefix.'pesm_';$now=Database::now();
        $rows=$wpdb->get_results($wpdb->prepare("SELECT h.id AS hold_id,h.order_id,h.session_id,s.event_id,h.status AS hold_status,h.expires_at,o.status AS link_status,o.order_id AS linked_order_id,
            (SELECT COALESCE(SUM(l.admission_count),0) FROM {$p}allocation_lines l WHERE l.hold_id=h.id) AS seat_count,
            (SELECT COUNT(*) FROM {$p}allocation_tickets t JOIN {$p}allocation_lines l ON l.id=t.line_id WHERE l.hold_id=h.id) AS ticket_count,
            (SELECT COUNT(*) FROM {$p}allocation_lines l WHERE l.hold_id=h.id AND l.status IN ('refund_review','review_required','refund_creating')) AS line_reviews
            FROM {$p}allocation_holds h JOIN {$p}sessions s ON s.id=h.session_id JOIN {$p}events e ON e.id=s.event_id LEFT JOIN {$p}allocation_orders o ON o.hold_id=h.id
            WHERE e.center_id=%d AND h.id>%d AND (h.status IN ('checkout_creating','payment_pending','sold','review_required') OR o.status='review_required')
            HAVING link_status='review_required' OR hold_status='review_required' OR line_reviews>0
              OR (hold_status='sold' AND (seat_count<>ticket_count OR COALESCE(link_status,'')<>'issued'))
              OR (hold_status IN ('checkout_creating','payment_pending') AND expires_at<=%s)
              OR (hold_status='payment_pending' AND COALESCE(linked_order_id,0)=0)
            ORDER BY hold_id ASC LIMIT %d",$centerId,max(0,$cursor),$now,$limit+1),ARRAY_A);
        if(!is_array($rows)){Policy::fail('db_unavailable',503);}$more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);$items=[];
        foreach($rows as$row){
            $problem=($row['link_status']==='review_required'||$row['hold_status']==='review_required')?'manual_review':((int)$row['line_reviews']>0?'refund_review':($row['hold_status']==='sold'?'ticket_inventory_mismatch':($row['hold_status']==='checkout_creating'?'abandoned_checkout':(empty($row['linked_order_id'])?'missing_order_link':'expired_payment'))));
            $items[]=['inventory_type'=>'allocated','hold_id'=>(int)$row['hold_id'],'order_id'=>(int)$row['order_id'],'session_id'=>(int)$row['session_id'],'event_id'=>(int)$row['event_id'],'hold_status'=>$row['hold_status'],'link_status'=>$row['link_status']??'','seat_count'=>(int)$row['seat_count'],'ticket_count'=>(int)$row['ticket_count'],'problem'=>$problem];
        }
        return ['items'=>$items,'count'=>count($items),'next_cursor'=>$more?(int)end($rows)['hold_id']:null];
    }

    public static function retryOrder(int $orderId,int $actorId=0): array
    {
        $actorId=$actorId?:get_current_user_id();global $wpdb;$p=$wpdb->prefix.'pesm_';
        $row=$wpdb->get_row($wpdb->prepare("SELECT e.center_id,o.financial_json FROM {$p}allocation_orders o JOIN {$p}allocation_holds h ON h.id=o.hold_id JOIN {$p}sessions s ON s.id=h.session_id JOIN {$p}events e ON e.id=s.event_id WHERE o.order_id=%d",$orderId));
        if(!$row){Policy::fail('invalid_order',404);}self::authorized((int)$row->center_id,$actorId,true);$snapshot=Database::decode($row->financial_json);
        $result=CheckoutService::confirmOrder($orderId,(int)$snapshot['total_minor']===0);\PESM\Support\Audit::record('allocation_reconciliation_retry','order',$orderId,$actorId);return ['order_id'=>$orderId,'status'=>$result];
    }

    public static function markReview(int $orderId): void
    {
        global $wpdb;$p=$wpdb->prefix.'pesm_';$initial=$wpdb->get_row($wpdb->prepare("SELECT h.* FROM {$p}allocation_holds h JOIN {$p}allocation_orders o ON o.hold_id=h.id WHERE o.order_id=%d",$orderId));if(!$initial){return;}
        Database::transaction(function()use($initial,$orderId):void{
            global $wpdb;$p=$wpdb->prefix.'pesm_';Database::session((int)$initial->session_id);Database::sections((int)$initial->session_id,Pricing::sections(Database::decode($initial->quote_json)));
            $hold=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_holds WHERE id=%d FOR UPDATE",(int)$initial->id));
            if(!$hold){return;}if($hold->quote_json!==$initial->quote_json){Policy::fail('hold_changed_retry');}
            Database::checked($wpdb->query($wpdb->prepare("UPDATE {$p}allocation_orders SET status='review_required' WHERE order_id=%d AND status NOT IN ('issued','revoked')",$orderId)));
        });
    }

    private static function authorized(int $centerId,int $actorId,bool $write): void
    {
        if($actorId<1||!\PESM\Security\Capabilities::centerAllowed($centerId,$actorId)||
            ($write?(!user_can($actorId,'pesm_manage_refunds')&&!user_can($actorId,'pesm_issue_tickets')):!user_can($actorId,'pesm_view_orders'))){Policy::fail('permission_denied',403);}
    }
}
