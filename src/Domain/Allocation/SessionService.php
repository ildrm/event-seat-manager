<?php
declare(strict_types=1);
namespace PESM\Domain\Allocation;

final class SessionService
{
    public static function configure(int $sessionId,array $settings): array
    {
        return Database::transaction(function()use($sessionId,$settings):array{
            global $wpdb;$p=$wpdb->prefix.'pesm_';
            // Serialize policy changes with quotes, preserving every existing hold snapshot.
            $eventId=(int)$wpdb->get_var($wpdb->prepare("SELECT event_id FROM {$p}sessions WHERE id=%d",$sessionId));
            if(!$eventId){Policy::fail('invalid_session',404);}
            $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}events WHERE id=%d LOCK IN SHARE MODE",$eventId));
            $session=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}sessions WHERE id=%d FOR UPDATE",$sessionId));
            if(!$session||$session->mode!=='allocated'||!in_array($session->status,['draft','published','paused'],true)){Policy::fail('configuration_locked');}
            $layout=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}venue_layouts WHERE layout_id=%d",(int)$session->layout_id));
            if(!$layout||$layout->status!=='published'){Policy::fail('layout_not_published');}
            $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_sessions WHERE session_id=%d FOR UPDATE",$sessionId));
            $old=$existing?Database::decode((string)$existing->settings_json):[];$input=$settings;
            $settings=array_replace($old,$input);
            // PATCH preserves omitted maps; a supplied map replaces that map, while
            // pricing:[] explicitly clears all overrides. Never infer omission as zero.
            if(isset($input['pricing'])&&is_array($input['pricing'])&&$input['pricing']!==[]){$settings['pricing']=array_replace($old['pricing']??[],$input['pricing']);}
            $settings=Policy::settings($settings);
            $settings['currency']=function_exists('get_woocommerce_currency')?(string)get_woocommerce_currency():'IRR';
            $settings['price_decimals']=function_exists('wc_get_price_decimals')?(int)wc_get_price_decimals():0;
            if(!preg_match('/^[A-Z0-9_-]{2,12}$/D',$settings['currency'])||$settings['price_decimals']<0||$settings['price_decimals']>6){Policy::fail('invalid_settings',422);}
            if($session->status!=='draft'){self::validateGroupLimit((int)$session->layout_id,$settings);}
            if($existing){
                Database::checked($wpdb->update($p.'allocation_sessions',['settings_json'=>Database::json($settings),'price_revision'=>(int)$existing->price_revision+1],['session_id'=>$sessionId]));
            }else{
                Database::insert('allocation_sessions',['session_id'=>$sessionId,'settings_json'=>Database::json($settings),'price_revision'=>1,'status'=>'provisioning']);
            }
            Database::checked($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$p}allocation_sections (session_id,section_code,revision) SELECT %d,section_code,0 FROM {$p}venue_sections WHERE layout_id=%d",$sessionId,(int)$session->layout_id)));
            return self::settings($sessionId);
        });
    }

    public static function settings(int $sessionId): array
    {
        global $wpdb;$p=$wpdb->prefix.'pesm_';
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_sessions WHERE session_id=%d LOCK IN SHARE MODE",$sessionId));
        if(!$row){Policy::fail('inventory_not_ready',503);}
        return Database::decode((string)$row->settings_json)+['pricing_revision'=>(int)$row->price_revision,'inventory_status'=>(string)$row->status,'purchase_limit'=>max(1,min(200,(int)get_option('pesm_phone_event_limit',20)))];
    }

    public static function assertPublishable(int $sessionId): void
    {
        global $wpdb;$p=$wpdb->prefix.'pesm_';$settings=self::settings($sessionId);
        if($settings['inventory_status']!=='ready'){Policy::fail('inventory_not_ready',503);}
        $layout=(int)$wpdb->get_var($wpdb->prepare("SELECT layout_id FROM {$p}sessions WHERE id=%d AND mode='allocated'",$sessionId));
        if(!$layout){Policy::fail('invalid_session',404);}self::validateGroupLimit($layout,$settings);
    }

    private static function validateGroupLimit(int $layoutId,array $settings): void
    {
        global $wpdb;$p=$wpdb->prefix.'pesm_';$limit=max(1,min(200,(int)get_option('pesm_phone_event_limit',20)));
        $groups=$wpdb->get_results($wpdb->prepare("SELECT group_code,sale_mode,capacity FROM {$p}venue_groups WHERE layout_id=%d AND capacity>%d",$layoutId,$limit));
        foreach($groups as$group){if(($settings['group_modes'][$group->group_code]??$group->sale_mode)!=='individual'){Policy::fail('group_capacity_exceeds_limit',422);}}
    }

    public static function provision(int $sessionId,int $afterSeatId=0,int $limit=2000): array
    {
        $limit=max(1,min(2000,$limit));
        return Database::transaction(function()use($sessionId,$afterSeatId,$limit):array{
            global $wpdb;$p=$wpdb->prefix.'pesm_';
            $session=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}sessions WHERE id=%d FOR UPDATE",$sessionId));
            if(!$session||$session->mode!=='allocated'||$session->status!=='draft'){Policy::fail('configuration_locked');}
            $config=self::settings($sessionId);
            $expected=(int)$wpdb->get_var($wpdb->prepare("SELECT seat_count FROM {$p}layout_versions WHERE id=%d",(int)$session->layout_id));
            if($config['inventory_status']==='ready'){return ['cursor'=>$afterSeatId,'complete'=>true,'total'=>$expected,'count'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}session_seats WHERE session_id=%d",$sessionId))];}
            $ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT seat_id FROM {$p}venue_places WHERE layout_id=%d AND seat_id>%d ORDER BY seat_id LIMIT %d",(int)$session->layout_id,$afterSeatId,$limit)));
            if($ids){
                $in=implode(',',array_fill(0,count($ids),'%d'));
                Database::checked($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$p}session_seats (session_id,seat_id,status,updated_at) SELECT %d,seat_id,'available',UTC_TIMESTAMP() FROM {$p}venue_places WHERE layout_id=%d AND seat_id IN ($in)",$sessionId,(int)$session->layout_id,...$ids)));
            }
            $cursor=$ids?max($ids):$afterSeatId;
            $more=(bool)$wpdb->get_var($wpdb->prepare("SELECT seat_id FROM {$p}venue_places WHERE layout_id=%d AND seat_id>%d LIMIT 1",(int)$session->layout_id,$cursor));
            $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}session_seats WHERE session_id=%d",$sessionId));
            if(!$more){
                if($expected<1||$count!==$expected){Policy::fail('inventory_count_mismatch',503);}
                Database::checked($wpdb->update($p.'allocation_sessions',['status'=>'ready'],['session_id'=>$sessionId]));
            }
            return ['cursor'=>$cursor,'complete'=>!$more,'count'=>$count,'total'=>$expected];
        });
    }

    public static function block(int $sessionId,int $seatId,bool $blocked,int $actorId): array
    {
        return Database::transaction(function()use($sessionId,$seatId,$blocked,$actorId):array{
            global $wpdb;$p=$wpdb->prefix.'pesm_';$session=Database::session($sessionId);
            if(!\PESM\Security\Capabilities::centerAllowed((int)$session->center_id,$actorId)||!user_can($actorId,'pesm_edit_layouts')){Policy::fail('permission_denied',403);}
            $place=$wpdb->get_row($wpdb->prepare("SELECT section_code FROM {$p}venue_places WHERE layout_id=%d AND seat_id=%d",(int)$session->layout_id,$seatId));
            if(!$place){Policy::fail('invalid_seat',404);}Database::sections($sessionId,[$place->section_code]);$now=Database::now();
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}session_seats WHERE session_id=%d AND seat_id=%d FOR UPDATE",$sessionId,$seatId));
            if(!$row){Policy::fail('invalid_seat',404);}
            $current=(string)$row->status;
            if($blocked&&($current!=='available'&&!\PESM\Domain\Booking\HoldPolicy::canAcquire($current,$row->expires_at,$now))){if($current==='blocked'){return ['blocked'=>true];}Policy::fail('seat_unavailable');}
            if(!$blocked&&$current!=='blocked'){return ['blocked'=>false];}
            Database::checked($wpdb->update($p.'session_seats',['status'=>$blocked?'blocked':'available','hold_id'=>null,'expires_at'=>null,'updated_at'=>$now],['session_id'=>$sessionId,'seat_id'=>$seatId]));
            Database::revision($sessionId,[$place->section_code]);
            \PESM\Support\Audit::record($blocked?'allocated_seat_blocked':'allocated_seat_unblocked','seat',$seatId,$actorId);
            return ['blocked'=>$blocked];
        });
    }
}
