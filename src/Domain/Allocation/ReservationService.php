<?php
declare(strict_types=1);
namespace PESM\Domain\Allocation;

use PESM\Domain\Booking\HoldPolicy;

final class ReservationService
{
    public static function view(int $sessionId,string $token): array
    {
        return Database::transaction(function()use($sessionId,$token):array{
            Database::session($sessionId);$hold=self::hold($sessionId,$token,false);$quote=Database::decode($hold->quote_json);Database::sections($sessionId,Pricing::sections($quote));
            $hold=self::hold($sessionId,$token,true);return self::result($hold,$token,Database::now(),false);
        });
    }
    public static function quote(int $sessionId,array $units): array
    {
        return Database::transaction(function()use($sessionId,$units):array{
            $session=Database::session($sessionId,true);$settings=SessionService::settings($sessionId);
            if($settings['inventory_status']!=='ready'){Policy::fail('inventory_not_ready',503);}
            return Pricing::quote($session,$units,$settings);
        });
    }

    public static function acquire(int $sessionId,array $units,string $key): array
    {
        $units=Policy::units($units);[$request,$payload]=Database::request('acquire:'.$sessionId,$key,$units);
        $token=hash_hmac('sha256','allocated:token:'.$sessionId.':'.$key,wp_salt('auth'));
        return Database::transaction(function()use($sessionId,$units,$request,$payload,$token):array{
            global $wpdb;$p=$wpdb->prefix.'pesm_';$session=Database::session($sessionId,true);$settings=SessionService::settings($sessionId);
            if($settings['inventory_status']!=='ready'){Policy::fail('inventory_not_ready',503);}
            $known=$wpdb->get_row($wpdb->prepare("SELECT quote_json FROM {$p}allocation_holds WHERE request_hash=%s",$request));
            $quote=$known?Database::decode($known->quote_json):Pricing::quote($session,$units,$settings);$sections=Pricing::sections($quote);Database::sections($sessionId,$sections);
            $now=Database::now();Database::sale($session,$now);
            $previous=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_holds WHERE request_hash=%s FOR UPDATE",$request));
            if($previous){
                $currentSelection=hash('sha256',Database::json(Pricing::units(Database::decode($previous->quote_json))));
                if(!hash_equals((string)$previous->selection_hash,$payload)||!hash_equals($currentSelection,$payload)){Policy::fail('idempotency_conflict');}
                if($previous->status!=='active'||$previous->expires_at<=$now){Policy::fail('hold_expired',410);}
                return self::result($previous,$token,$now,true);
            }
            $ids=Pricing::ids($quote);$rows=self::inventory($sessionId,$ids);
            foreach($rows as$row){if(!HoldPolicy::canAcquire((string)$row->status,$row->expires_at,$now)){Policy::fail('seat_unavailable');}}
            Topology::validate($session,$quote,$now);
            $expires=HoldPolicy::expiry($now,min($session->sale_ends_at,$session->starts_at));
            $holdId=Database::insert('allocation_holds',['session_id'=>$sessionId,'request_hash'=>$request,'token_hash'=>hash('sha256',$token),'selection_hash'=>$payload,'quote_json'=>Database::json($quote),'status'=>'active','created_at'=>$now,'expires_at'=>$expires]);
            self::lines($holdId,$quote);self::assign($sessionId,$holdId,$ids,'held',$expires,$now);Database::revision($sessionId,$sections);
            $hold=(object)['id'=>$holdId,'quote_json'=>Database::json($quote),'expires_at'=>$expires,'status'=>'active'];
            return self::result($hold,$token,$now,false);
        });
    }

    public static function mutate(int $sessionId,string $token,string $action,array $units,string $key): array
    {
        self::token($token);if(!in_array($action,['replace','renew','release'],true)){Policy::fail('invalid_action',422);}
        $units=$action==='replace'?Policy::units($units):[];[$request,$payload]=Database::request($action.':'.$sessionId.':'.hash('sha256',$token),$key,$units);
        return Database::transaction(function()use($sessionId,$token,$action,$units,$request,$payload):array{
            global $wpdb;$p=$wpdb->prefix.'pesm_';$session=Database::session($sessionId,$action!=='release');
            $initial=self::hold($sessionId,$token,false);$before=Database::decode($initial->quote_json);
            $target=$action==='replace'?Pricing::quote($session,$units,SessionService::settings($sessionId)):$before;
            $sections=array_merge(Pricing::sections($before),Pricing::sections($target));Database::sections($sessionId,$sections);
            $hold=self::hold($sessionId,$token,true);$now=Database::now();
            if($replay=Database::replay($request,$payload)){
                if($action!=='release'&&($hold->status!=='active'||$hold->expires_at<=$now||
                    ($replay['expires_at_utc']??'')!==self::iso($hold->expires_at)||
                    !hash_equals(hash('sha256',$hold->quote_json),hash('sha256',Database::json($replay['quote']??[]))))){Policy::fail('idempotency_conflict');}
                return $action==='release'?$replay:$replay+['hold_token'=>$token,'token'=>$token];
            }
            if($hold->quote_json!==$initial->quote_json){Policy::fail('hold_changed_retry');}
            if($action==='release'&&in_array($hold->status,['released','expired'],true)){return ['released'=>false];}
            if($hold->status!=='active'||($action!=='release'&&$hold->expires_at<=$now)){Policy::fail('hold_expired',410);}
            $oldIds=Pricing::ids($before);$newIds=$action==='release'?[]:Pricing::ids($target);$union=array_values(array_unique(array_merge($oldIds,$newIds)));sort($union,SORT_NUMERIC);
            $rows=self::inventory($sessionId,$union);$old=array_fill_keys($oldIds,true);$wanted=array_fill_keys($newIds,true);
            foreach($rows as$row){$id=(int)$row->seat_id;
                if(isset($old[$id])&&($row->status!=='held'||(int)$row->hold_id!==(int)$hold->id)){Policy::fail('hold_conflict');}
                if(!isset($old[$id])&&isset($wanted[$id])&&!HoldPolicy::canAcquire($row->status,$row->expires_at,$now)){Policy::fail('seat_unavailable');}
            }
            $expiry=$hold->expires_at;
            if($action==='renew'){$expiry=HoldPolicy::renewalExpiry($now,$hold->created_at,$expiry,$session->sale_ends_at,$session->starts_at,(int)$hold->renewal_count);}
            if($action==='replace'){Topology::validate($session,$target,$now,(int)$hold->id);}
            $released=array_values(array_diff($oldIds,$newIds));self::free($sessionId,(int)$hold->id,$released,$now,['held']);
            if($newIds){self::assign($sessionId,(int)$hold->id,$newIds,'held',$expiry,$now);}
            $values=['quote_json'=>Database::json($target),'expires_at'=>$expiry];
            if($action==='replace'){
                Database::checked($wpdb->delete($p.'allocation_lines',['hold_id'=>(int)$hold->id]));self::lines((int)$hold->id,$target);
            }elseif($action==='renew'){$values['renewal_count']=(int)$hold->renewal_count+1;}else{$values['status']=$expiry<=$now?'expired':'released';}
            Database::checked($wpdb->update($p.'allocation_holds',$values,['id'=>(int)$hold->id]));Database::revision($sessionId,$sections);
            $hold=(object)array_merge((array)$hold,$values);$result=$action==='release'?['released'=>true]:self::result($hold,$token,$now,false);
            $receipt=$result;unset($receipt['hold_token'],$receipt['token']);Database::receipt($request,$payload,$receipt,$now);return $result;
        });
    }

    public static function availability(int $sessionId,array $scope,array $since=[]): array
    {
        if(!$scope||count($scope)>10){Policy::fail('invalid_availability_scope',422);}
        return Database::transaction(function()use($sessionId,$scope,$since):array{
            global $wpdb;$p=$wpdb->prefix.'pesm_';$session=Database::session($sessionId);$now=Database::now();
            foreach($scope as$code){if(!is_string($code)||strlen($code)>100||!preg_match('/^[A-Za-z0-9._:-]+$/D',$code)){Policy::fail('invalid_availability_scope',422);}}
            $in=implode(',',array_fill(0,count($scope),'%s'));
            $tileCodes=array_fill_keys($wpdb->get_col($wpdb->prepare("SELECT tile_code FROM {$p}venue_tiles WHERE layout_id=%d AND tile_code IN ($in)",(int)$session->layout_id,...$scope)),true);
            $byId=[];
            foreach(array_unique($scope)as$code){
                $tile=isset($tileCodes[$code]);$column=$tile?'tile_code':'section_code';$index=$tile?'layout_tile':'layout_section';
                if(!$tile&&!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}venue_sections WHERE layout_id=%d AND section_code=%s",(int)$session->layout_id,$code))){Policy::fail('invalid_availability_scope',422);}
                // Separate equality ranges avoid OR/filesort plans over all 150,000 places.
                $part=$wpdb->get_results($wpdb->prepare("SELECT v.seat_id,v.section_code,v.group_code,i.status,i.expires_at FROM {$p}venue_places v FORCE INDEX ($index) STRAIGHT_JOIN {$p}session_seats i ON i.seat_id=v.seat_id AND i.session_id=%d WHERE v.layout_id=%d AND v.$column=%s ORDER BY v.seat_id LIMIT 5001",$sessionId,(int)$session->layout_id,$code));
                if(!is_array($part)){Policy::fail('db_unavailable',503);}foreach($part as$row){$byId[(int)$row->seat_id]=$row;}
                if(count($byId)>5000){Policy::fail('availability_scope_too_large',422);}
            }
            ksort($byId,SORT_NUMERIC);$rows=array_values($byId);
            $items=[];$sections=[];$next=null;$groupCodes=[];
            foreach($rows as$row){$sections[$row->section_code]=true;if($row->group_code!==''){$groupCodes[$row->group_code]=true;}$expired=HoldPolicy::canAcquire($row->status,$row->expires_at,$now);
                $items[]=['seat_id'=>(int)$row->seat_id,'status'=>$expired?'available':(string)$row->status];
                if(in_array($row->status,['held','payment_pending'],true)&&$row->expires_at>$now){$next=$next===null?$row->expires_at:min($next,$row->expires_at);}
            }
            $revisions=[];
            foreach(array_keys($sections)as$section){$revisions[$section]=(int)$wpdb->get_var($wpdb->prepare("SELECT revision FROM {$p}allocation_sections WHERE session_id=%d AND section_code=%s",$sessionId,$section));}
            $groups=[];
            if($groupCodes){$marks=implode(',',array_fill(0,count($groupCodes),'%s'));
                $groupRows=$wpdb->get_results($wpdb->prepare("SELECT g.group_code,g.capacity,COUNT(v.seat_id) AS member_count,SUM(CASE WHEN i.seat_id IS NULL OR NOT (i.status='available' OR (i.status IN ('held','payment_pending') AND i.expires_at<=%s)) THEN 1 ELSE 0 END) AS unavailable_count,MAX(CASE WHEN i.status='blocked' THEN 4 WHEN i.status='sold' THEN 3 WHEN i.status='payment_pending' AND i.expires_at>%s THEN 2 WHEN i.status='held' AND i.expires_at>%s THEN 1 ELSE 0 END) AS state_rank,MIN(CASE WHEN i.status IN ('held','payment_pending') AND i.expires_at>%s THEN i.expires_at ELSE NULL END) AS next_expiry FROM {$p}venue_groups g JOIN {$p}venue_places v ON v.layout_id=g.layout_id AND v.group_code=g.group_code LEFT JOIN {$p}session_seats i ON i.session_id=%d AND i.seat_id=v.seat_id WHERE g.layout_id=%d AND g.group_code IN ($marks) GROUP BY g.group_code,g.capacity",$now,$now,$now,$now,$sessionId,(int)$session->layout_id,...array_keys($groupCodes)));
                foreach($groupRows as$group){$free=(int)$group->unavailable_count===0&&(int)$group->member_count===(int)$group->capacity;$status=$free?'available':([1=>'held',2=>'payment_pending',3=>'sold',4=>'blocked'][(int)$group->state_rank]??'unavailable');$groups[]=['group_code'=>$group->group_code,'status'=>$status];if($group->next_expiry){$next=$next===null?$group->next_expiry:min($next,$group->next_expiry);}}
            }
            return ['items'=>$items,'availability'=>$items,'groups'=>$groups,'revisions'=>$revisions,'snapshot'=>true,'next_expiry_utc'=>$next?self::iso($next):null,'server_time_utc'=>self::iso($now)];
        });
    }

    public static function cleanup(int $limit=50): int
    {
        global $wpdb;$p=$wpdb->prefix.'pesm_';$limit=max(1,min(100,$limit));$cleaned=0;
        $holds=$wpdb->get_results($wpdb->prepare("SELECT id,session_id FROM {$p}allocation_holds WHERE status IN ('active','payment_pending','checkout_creating') AND expires_at<=UTC_TIMESTAMP() ORDER BY expires_at,id LIMIT %d",$limit));
        foreach($holds as$candidate){
            try{Database::transaction(function()use($candidate,&$cleaned):void{
                global $wpdb;$p=$wpdb->prefix.'pesm_';Database::session((int)$candidate->session_id);
                $initial=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_holds WHERE id=%d",(int)$candidate->id));if(!$initial){return;}$quote=Database::decode($initial->quote_json);$sections=Pricing::sections($quote);Database::sections((int)$candidate->session_id,$sections);
                $hold=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_holds WHERE id=%d FOR UPDATE",(int)$candidate->id));$now=Database::now();
                if(!$hold||$hold->expires_at>$now||!in_array($hold->status,['active','payment_pending','checkout_creating'],true)||$hold->quote_json!==$initial->quote_json){return;}
                self::inventory((int)$hold->session_id,Pricing::ids($quote));self::free((int)$hold->session_id,(int)$hold->id,Pricing::ids($quote),$now,['held','payment_pending']);
                Database::checked($wpdb->update($p.'allocation_holds',['status'=>'expired'],['id'=>(int)$hold->id]));
                if((int)$hold->order_id){Database::checked($wpdb->update($p.'allocation_orders',['status'=>'review_required'],['hold_id'=>(int)$hold->id]));}
                Database::revision((int)$hold->session_id,$sections);++$cleaned;
            });}catch(\Throwable){/* bounded best effort; request-time expiry stays authoritative */}
        }
        return $cleaned;
    }

    public static function hold(int $sessionId,string $token,bool $lock): object
    {
        self::token($token);global $wpdb;$p=$wpdb->prefix.'pesm_';$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_holds WHERE session_id=%d AND token_hash=%s".($lock?' FOR UPDATE':''),$sessionId,hash('sha256',$token)));
        if(!$row){Policy::fail('permission_denied',403);}return $row;
    }
    public static function inventory(int $sessionId,array $ids): array
    {
        if(!$ids||count($ids)>400){Policy::fail('invalid_selection',422);}sort($ids,SORT_NUMERIC);global $wpdb;$p=$wpdb->prefix.'pesm_';$in=implode(',',array_fill(0,count($ids),'%d'));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT seat_id,status,hold_id,expires_at FROM {$p}session_seats WHERE session_id=%d AND seat_id IN ($in) ORDER BY seat_id FOR UPDATE",$sessionId,...$ids));
        if(!is_array($rows)||count($rows)!==count($ids)){Policy::fail('invalid_seat',422);}return $rows;
    }
    public static function assign(int $sessionId,int $holdId,array $ids,string $status,string $expiry,string $now): void
    {
        if(!$ids){return;}global $wpdb;$p=$wpdb->prefix.'pesm_';$in=implode(',',array_fill(0,count($ids),'%d'));
        Database::checked($wpdb->query($wpdb->prepare("UPDATE {$p}session_seats SET status=%s,hold_id=%d,expires_at=%s,updated_at=%s WHERE session_id=%d AND seat_id IN ($in)",$status,$holdId,$expiry,$now,$sessionId,...$ids)));
    }
    public static function free(int $sessionId,int $holdId,array $ids,string $now,array $statuses): void
    {
        if(!$ids){return;}global $wpdb;$p=$wpdb->prefix.'pesm_';$in=implode(',',array_fill(0,count($statuses),'%s'));$places=implode(',',array_fill(0,count($ids),'%d'));
        Database::checked($wpdb->query($wpdb->prepare("UPDATE {$p}session_seats SET status='available',hold_id=NULL,expires_at=NULL,updated_at=%s WHERE session_id=%d AND hold_id=%d AND status IN ($in) AND seat_id IN ($places)",$now,$sessionId,$holdId,...$statuses,...$ids)));
    }
    private static function lines(int $holdId,array $quote): void
    {
        foreach($quote['lines']as$i=>$line){Database::insert('allocation_lines',['hold_id'=>$holdId,'line_no'=>$i,'unit_type'=>$line['unit_type'],'unit_code'=>(string)$line['unit_id'],'section_code'=>$line['section_code'],'seat_ids_json'=>Database::json($line['seat_ids']),'admission_count'=>$line['admissions'],'total_minor'=>$line['total_minor']]);}
    }
    private static function result(object $hold,string $token,string $now,bool $replay): array {return ['hold_id'=>(int)$hold->id,'hold_token'=>$token,'token'=>$token,'status'=>$hold->status,'quote'=>Database::decode($hold->quote_json),'expires_at_utc'=>self::iso($hold->expires_at),'server_time_utc'=>self::iso($now),'idempotent_replay'=>$replay];}
    public static function iso(string $time): string {return str_replace(' ','T',$time).'Z';}
    private static function token(string $token): void {if(!preg_match('/^[a-f0-9]{64}$/D',$token)){Policy::fail('invalid_token',403);}}
}
