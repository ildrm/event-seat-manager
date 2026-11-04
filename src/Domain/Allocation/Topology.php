<?php
declare(strict_types=1);
namespace PESM\Domain\Allocation;

/** Bounded explicit topology; does not infer adjacency from projected x/y. */
final class Topology
{
    public static function validate(object $session,array $quote,string $now,int $owner=0): void
    {
        $adjacent=(bool)($session->require_adjacent??false);$gap=(bool)($session->avoid_single_gap??false);
        if(!$adjacent&&!$gap){return;}
        global $wpdb;$p=$wpdb->prefix.'pesm_';$ids=[];
        foreach($quote['lines']as$line){if($line['unit_type']==='seat'){array_push($ids,...$line['seat_ids']);}}
        if(!$ids){return;}
        $in=implode(',',array_fill(0,count($ids),'%d'));
        $selected=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}venue_places WHERE layout_id=%d AND seat_id IN ($in)",(int)$session->layout_id,...$ids));
        if(count($selected)!==count($ids)){Policy::fail('invalid_topology',503);}
        $conditions=[];$values=[(int)$session->id,(int)$session->layout_id];
        foreach($selected as$place){
            $conditions[]='(v.section_code=%s AND v.block_code=%s AND v.block_position BETWEEN %d AND %d)';
            array_push($values,$place->section_code,$place->block_code,max(0,(int)$place->block_position-2),(int)$place->block_position+2);
            if((int)$place->closed_loop){
                $edge=$wpdb->get_row($wpdb->prepare("SELECT MIN(block_position) AS first_position,MAX(block_position) AS last_position FROM {$p}venue_places WHERE layout_id=%d AND section_code=%s AND block_code=%s",(int)$session->layout_id,$place->section_code,$place->block_code));
                foreach([(int)$edge->first_position,(int)$edge->last_position]as$position){
                    if(abs((int)$place->block_position-$position)<=2){
                        $other=$position===(int)$edge->first_position?(int)$edge->last_position:(int)$edge->first_position;
                        $conditions[]='(v.section_code=%s AND v.block_code=%s AND v.block_position BETWEEN %d AND %d)';
                        array_push($values,$place->section_code,$place->block_code,max(0,$other-2),$other+2);
                    }
                }
            }
        }
        $rows=$wpdb->get_results($wpdb->prepare("SELECT v.seat_id,v.section_code,v.block_code,v.block_position,v.closed_loop,i.status,i.hold_id,i.expires_at FROM {$p}venue_places v JOIN {$p}session_seats i ON i.seat_id=v.seat_id AND i.session_id=%d WHERE v.layout_id=%d AND (".implode(' OR ',$conditions).') ORDER BY v.seat_id',$values));
        if(!is_array($rows)||count($rows)>2000){Policy::fail('topology_window_exceeded',503);}
        $blocks=[];$graph=[];$state=[];
        foreach($rows as$row){$id=(int)$row->seat_id;$graph[$id]=[];$blocks[$row->section_code.'|'.$row->block_code][(int)$row->block_position]=$id;$state[]=(array)$row;}
        foreach($blocks as$positions){ksort($positions,SORT_NUMERIC);foreach($positions as$pos=>$id){foreach([$pos-1,$pos+1]as$n){if(isset($positions[$n])){$graph[$id][]=$positions[$n];}}}}
        // Cycle wrapping uses known block endpoints, not the edge of the bounded fetch window.
        foreach($selected as$place){if(!(int)$place->closed_loop){continue;}$key=$place->section_code.'|'.$place->block_code;$positions=$blocks[$key]??[];
            $edge=$wpdb->get_row($wpdb->prepare("SELECT MIN(block_position) AS first_position,MAX(block_position) AS last_position FROM {$p}venue_places WHERE layout_id=%d AND section_code=%s AND block_code=%s",(int)$session->layout_id,$place->section_code,$place->block_code));
            $first=$positions[(int)$edge->first_position]??null;$last=$positions[(int)$edge->last_position]??null;
            if($first&&$last&&$first!==$last){$graph[$first][]=$last;$graph[$last][]=$first;}
        }
        \PESM\Domain\Booking\SeatingRules::validate($state,$ids,$now,$adjacent,$gap,$owner,$graph);
    }
}
