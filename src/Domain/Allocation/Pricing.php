<?php
declare(strict_types=1);
namespace PESM\Domain\Allocation;

final class Pricing
{
    /** Resolve logical units to the one underlying seat inventory, never client-supplied members/prices. */
    public static function quote(object $session,array $units,array $settings): array
    {
        global $wpdb;$p=$wpdb->prefix.'pesm_';$lines=[];$seen=[];$total=0;$count=0;
        foreach(Policy::units($units)as$unit){
            $group=null;
            if($unit['type']==='seat'){
                $members=$wpdb->get_results($wpdb->prepare("SELECT v.*,s.seat_code FROM {$p}venue_places v JOIN {$p}seats s ON s.id=v.seat_id WHERE v.layout_id=%d AND v.seat_id=%d",(int)$session->layout_id,(int)$unit['id']));
                if(!$members){Policy::fail('invalid_seat',422);}
                if($members[0]->group_code!==''){
                    $group=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}venue_groups WHERE layout_id=%d AND group_code=%s",(int)$session->layout_id,(string)$members[0]->group_code));
                    if(!$group||($settings['group_modes'][$group->group_code]??$group->sale_mode)==='whole'){Policy::fail('whole_group_required',422);}
                }
                $type='seat';$code=(string)$unit['id'];
            }else{
                $group=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}venue_groups WHERE layout_id=%d AND group_code=%s",(int)$session->layout_id,(string)$unit['id']));
                if(!$group||($settings['group_modes'][$group->group_code]??$group->sale_mode)==='individual'){Policy::fail('invalid_group',422);}
                $members=$wpdb->get_results($wpdb->prepare("SELECT v.*,s.seat_code FROM {$p}venue_places v JOIN {$p}seats s ON s.id=v.seat_id WHERE v.layout_id=%d AND v.group_code=%s ORDER BY v.seat_id LIMIT 201",(int)$session->layout_id,(string)$unit['id']));
                if(count($members)!==(int)$group->capacity||count($members)>Policy::MAX_ADMISSIONS){Policy::fail('invalid_group_capacity',422);}
                $type=(string)$group->group_type;$code=(string)$group->group_code;
            }
            $ids=[];$subtotal=0;
            foreach($members as$member){
                $id=(int)$member->seat_id;if(isset($seen[$id])){Policy::fail('duplicate_resource',422);}$seen[$id]=true;$ids[]=$id;
                $subtotal+=Policy::price((int)$session->unit_price_minor,(string)$member->section_code,(string)$member->category_code,$settings);
            }
            if($type!=='seat'&&array_key_exists($code,$settings['pricing']['groups'])){$subtotal=$settings['pricing']['groups'][$code];}
            $count+=count($ids);$total+=$subtotal;
            if($count>min(Policy::MAX_ADMISSIONS,(int)($settings['purchase_limit']??Policy::MAX_ADMISSIONS))||$total>20000000000000){Policy::fail('selection_limit',422);}
            $lines[]=['unit_type'=>$type,'unit_id'=>$type==='seat'?(int)$code:$code,'group_code'=>$type==='seat'?'':$code,
                'section_code'=>(string)$members[0]->section_code,'category_code'=>(string)$members[0]->category_code,
                'seat_ids'=>$ids,'admissions'=>count($ids),'total_minor'=>$subtotal];
        }
        return ['lines'=>$lines,'total_minor'=>$total,'admissions'=>$count,'currency'=>$settings['currency'],
            'price_decimals'=>(int)$settings['price_decimals'],'pricing_revision'=>(int)$settings['pricing_revision'],
            'ticket_mode'=>$settings['ticket_mode'],'group_entry_policy'=>$settings['group_entry_policy']];
    }

    public static function ids(array $quote): array { $ids=[];foreach($quote['lines']as$l){array_push($ids,...$l['seat_ids']);}sort($ids,SORT_NUMERIC);return $ids; }
    public static function sections(array $quote): array { $a=array_values(array_unique(array_column($quote['lines'],'section_code')));sort($a,SORT_STRING);return $a; }
    public static function units(array $quote): array{return Policy::units(array_map(static fn($l)=>['type'=>$l['unit_type']==='seat'?'seat':'group','id'=>$l['unit_id']],$quote['lines']));}
}
