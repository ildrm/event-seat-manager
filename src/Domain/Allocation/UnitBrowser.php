<?php
declare(strict_types=1);
namespace PESM\Domain\Allocation;

/** Bounded accessible unit search; availability filtering happens before cursor pagination. */
final class UnitBrowser
{
    public static function page(int $sessionId,array $filters=[]): array
    {
        return Database::transaction(function()use($sessionId,$filters):array{
            global $wpdb;$p=$wpdb->prefix.'pesm_';$session=Database::session($sessionId);$settings=SessionService::settings($sessionId);$now=Database::now();
            $limit=max(1,min(100,(int)($filters['limit']??50)));$type=(string)($filters['type']??'all');
            $type=match($type){'seats'=>'seat','tables'=>'table','boxes'=>'box',default=>$type};if(!in_array($type,['all','seat','group','table','box'],true)){Policy::fail('invalid_unit_filter',422);}
            $cursor=self::cursor((string)($filters['cursor']??''));$items=[];$last=$cursor;$more=false;
            $available=filter_var($filters['available_only']??$filters['available']??false,FILTER_VALIDATE_BOOLEAN);
            $search=trim((string)($filters['search']??$filters['q']??''));if(strlen($search)>120){Policy::fail('invalid_unit_filter',422);}
            $section=(string)($filters['section']??$filters['section_code']??'');$category=(string)($filters['category']??$filters['category_code']??'');
            $rowFilter = (string) ($filters['row'] ?? '');
            if ($rowFilter !== '') { if (strlen($rowFilter) > 20) { Policy::fail('invalid_unit_filter', 422); } Policy::code($rowFilter); }
            if($section!==''){Policy::code($section);}if($category!==''){Policy::code($category);}
            if($type!=='seat'&&$cursor['kind']!=='seat'){
                $where=['g.layout_id=%d','g.group_code>%s'];$values=[(int)$session->layout_id,$cursor['after']];
                if(in_array($type,['table','box'],true)){$where[]='g.group_type=%s';$values[]=$type;}
                if($section!==''){$where[]='g.section_code=%s';$values[]=$section;}if($category!==''){$where[]='g.category_code=%s';$values[]=$category;}
                if($search!==''){$where[]='g.group_code LIKE %s';$values[]='%'.$wpdb->esc_like($search).'%';}
                if ($rowFilter !== '') { $where[]="EXISTS (SELECT 1 FROM {$p}venue_places rv JOIN {$p}seats rs ON rs.id=rv.seat_id WHERE rv.layout_id=g.layout_id AND rv.group_code=g.group_code AND rs.row_label=%s)"; $values[]=$rowFilter; }
                $mode=self::saleExpression('g',$settings,$values);$where[]="$mode<>'individual'";
                if($available){$where[]="NOT EXISTS (SELECT 1 FROM {$p}venue_places vm LEFT JOIN {$p}session_seats im ON im.seat_id=vm.seat_id AND im.session_id=%d WHERE vm.layout_id=g.layout_id AND vm.group_code=g.group_code AND (im.seat_id IS NULL OR NOT (im.status='available' OR (im.status IN ('held','payment_pending') AND im.expires_at<=%s))))";array_push($values,$sessionId,$now);}
                $values[]=$limit+1;
                $groups=$wpdb->get_results($wpdb->prepare("SELECT g.* FROM {$p}venue_groups g WHERE ".implode(' AND ',$where).' ORDER BY g.group_code LIMIT %d',$values));
                if (!is_array($groups)) { Policy::fail('db_unavailable', 503); }
                foreach($groups as$group){if(count($items)>=$limit){$more=true;break;}
                    $item=self::group($session,$group,$settings,$now);$items[]=$item;$last=['kind'=>'group','after'=>$group->group_code];
                }
            }
            if(count($items)<$limit&&in_array($type,['all','seat'],true)){
                $after=$cursor['kind']==='seat'?(int)$cursor['after']:0;$where=['v.layout_id=%d','v.seat_id>%d'];$values=[$sessionId,(int)$session->layout_id,$after];
                if($section!==''){$where[]='v.section_code=%s';$values[]=$section;}if($category!==''){$where[]='v.category_code=%s';$values[]=$category;}
                if($search!==''){$where[]='(s.seat_code LIKE %s OR s.row_label LIKE %s)';array_push($values,'%'.$wpdb->esc_like($search).'%','%'.$wpdb->esc_like($search).'%');}
                if ($rowFilter !== '') { $where[]='s.row_label=%s'; $values[]=$rowFilter; }
                $mode=self::saleExpression('g',$settings,$values);$where[]="(v.group_code='' OR $mode<>'whole')";
                if($available){$where[]="(i.status='available' OR (i.status IN ('held','payment_pending') AND i.expires_at<=%s))";$values[]=$now;}
                $values[]=$limit-count($items)+1;
                $rows=$wpdb->get_results($wpdb->prepare("SELECT v.*,s.seat_code,s.row_label,s.seat_number,i.status,i.expires_at FROM {$p}venue_places v JOIN {$p}seats s ON s.id=v.seat_id JOIN {$p}session_seats i ON i.seat_id=v.seat_id AND i.session_id=%d LEFT JOIN {$p}venue_groups g ON g.layout_id=v.layout_id AND g.group_code=v.group_code WHERE ".implode(' AND ',$where).' ORDER BY v.seat_id LIMIT %d',$values));
                if (!is_array($rows)) { Policy::fail('db_unavailable', 503); }
                foreach($rows as$row){if(count($items)>=$limit){$more=true;break;}$free=\PESM\Domain\Booking\HoldPolicy::canAcquire($row->status,$row->expires_at,$now);
                    $items[]=['type'=>'seat','unit_type'=>'seat','id'=>(int)$row->seat_id,'unit_id'=>(int)$row->seat_id,'code'=>$row->seat_code,'seat_code'=>$row->seat_code,'row_label'=>$row->row_label,'number'=>(int)$row->seat_number,'section_code'=>$row->section_code,'category_code'=>$row->category_code,'group_code'=>$row->group_code,'tile_code'=>$row->tile_code,'capacity'=>1,'admissions'=>1,'available'=>$free,'status'=>$free?'available':$row->status,'total_minor'=>Policy::price((int)$session->unit_price_minor,$row->section_code,$row->category_code,$settings),'price_minor'=>Policy::price((int)$session->unit_price_minor,$row->section_code,$row->category_code,$settings),'accessible'=>(bool)$row->accessible];
                    $last=['kind'=>'seat','after'=>(string)$row->seat_id];
                }
            }elseif(count($items)===$limit&&$type==='all'&&$last['kind']==='group'){$more=true;}
            return ['items'=>$items,'next_cursor'=>$more?self::encode($last):null,'currency'=>$settings['currency'],'price_decimals'=>(int)$settings['price_decimals'],'server_time_utc'=>ReservationService::iso($now)];
        });
    }

    private static function group(object $session,object $group,array $settings,string $now): array
    {
        global $wpdb;$p=$wpdb->prefix.'pesm_';
        $members=$wpdb->get_results($wpdb->prepare("SELECT v.category_code,v.section_code,v.tile_code,i.status,i.expires_at FROM {$p}venue_places v LEFT JOIN {$p}session_seats i ON i.seat_id=v.seat_id AND i.session_id=%d WHERE v.layout_id=%d AND v.group_code=%s ORDER BY v.seat_id LIMIT 201",(int)$session->id,(int)$session->layout_id,$group->group_code));
        if (!is_array($members)) { Policy::fail('db_unavailable', 503); }
        $free=count($members)===(int)$group->capacity&&count($members)<=200;$total=0;
        foreach($members as$member){if(!$member->status||!\PESM\Domain\Booking\HoldPolicy::canAcquire($member->status,$member->expires_at,$now)){$free=false;}$total+=Policy::price((int)$session->unit_price_minor,$member->section_code,$member->category_code,$settings);}
        $total=$settings['pricing']['groups'][$group->group_code]??$total;
        return ['type'=>'group','unit_type'=>$group->group_type,'id'=>$group->group_code,'unit_id'=>$group->group_code,'code'=>$group->group_code,'group_code'=>$group->group_code,'section_code'=>$group->section_code,'category_code'=>$group->category_code,'tile_code'=>$members[0]->tile_code ?? null,'capacity'=>(int)$group->capacity,'admissions'=>(int)$group->capacity,'available'=>$free,'status'=>$free?'available':'unavailable','total_minor'=>$total,'price_minor'=>$total,'sale_mode'=>$settings['group_modes'][$group->group_code]??$group->sale_mode];
    }
    /** CASE constants remain parameters; group keys are never interpolated into SQL. */
    private static function saleExpression(string $alias,array $settings,array &$values): string
    {
        if(empty($settings['group_modes'])){return $alias.'.sale_mode';}
        $sql='CASE '.$alias.'.group_code';foreach($settings['group_modes']??[]as$code=>$mode){$sql.=' WHEN %s THEN %s';array_push($values,$code,$mode);}return $sql.' ELSE '.$alias.'.sale_mode END';
    }
    private static function cursor(string $cursor): array
    {
        if($cursor===''){return ['kind'=>'group','after'=>''];}
        if(strlen($cursor)>250){Policy::fail('invalid_cursor',422);}$raw=base64_decode(strtr($cursor,'-_','+/'),true);$value=$raw===false?null:json_decode($raw,true);
        if(!is_array($value)||!in_array($value['kind']??'',['group','seat'],true)||!is_string($value['after']??null)){Policy::fail('invalid_cursor',422);}
        if($value['kind']==='group'){Policy::code($value['after']);}elseif(!preg_match('/^[1-9][0-9]{0,18}$/D',$value['after'])){Policy::fail('invalid_cursor',422);}return $value;
    }
    private static function encode(array $cursor): string{return rtrim(strtr(base64_encode(Database::json($cursor)),'+/','-_'),'=');}
}
