<?php
declare(strict_types=1);
namespace PESM\Domain\Allocation;

/** Line lock serializes every individual/shared credential for one purchased group. */
final class GateService
{
    public static function scan(string $payload,int $operatorId,string $gate,string $key,int $quantity=1): array
    {
        if($operatorId<1||!user_can($operatorId,'pesm_scan_tickets')){Policy::fail('permission_denied',403);}
        [$kind,$id]=Credential::parse($payload);global $wpdb;$p=$wpdb->prefix.'pesm_';
        $lookup=$kind==='G'?$wpdb->get_row($wpdb->prepare("SELECT l.id AS line_id,h.session_id FROM {$p}allocation_lines l JOIN {$p}allocation_holds h ON h.id=l.hold_id WHERE l.public_id=%s",$id)):$wpdb->get_row($wpdb->prepare("SELECT line_id,session_id FROM {$p}allocation_tickets WHERE public_id=%s",$id));
        if(!$lookup){Policy::fail('ticket_not_found',404);}
        $gate=function_exists('mb_substr')?mb_substr(trim($gate),0,100,'UTF-8'):substr(trim($gate),0,100);
        [$request,$hash]=Database::request('scan:'.(int)$lookup->line_id,$key,['credential'=>hash('sha256',$payload),'operator'=>$operatorId,'gate'=>$gate,'quantity'=>$quantity]);
        return Database::transaction(function()use($lookup,$kind,$id,$payload,$operatorId,$gate,$quantity,$request,$hash):array{
            global $wpdb;$p=$wpdb->prefix.'pesm_';$session=Database::session((int)$lookup->session_id);
            if(!\PESM\Security\Capabilities::centerAllowed((int)$session->center_id,$operatorId)){Policy::fail('permission_denied',403);}
            $line=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_lines WHERE id=%d FOR UPDATE",(int)$lookup->line_id));$now=Database::now();
            if($replay=Database::replay($request,$hash)){return $replay;}
            if(!$line||$line->status!=='issued'||$session->status==='cancelled'||$session->event_status==='cancelled'){Policy::fail('ticket_inactive');}
            $start=strtotime($session->starts_at.' UTC');$when=strtotime($now.' UTC');
            $before=max(0,min(86400,(int)get_option('pesm_entry_before_seconds',7200)));$after=max(0,min(172800,(int)get_option('pesm_entry_after_seconds',14400)));
            if($when<$start-$before||$when>$start+$after){Policy::fail('invalid_entry_time');}
            if($kind==='G'){
                if($line->ticket_mode!=='group'||!$line->public_id||!Credential::valid($payload,'G',$line->public_id,(int)$line->qr_version)){Policy::fail('invalid_qr',422);}
                $slots=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}allocation_tickets WHERE line_id=%d ORDER BY seat_id FOR UPDATE",(int)$line->id));$used=0;
                foreach($slots as$slot){if($slot->status==='checked_in'){++$used;}elseif($slot->status!=='active'){Policy::fail('ticket_inactive');}}
                if(count($slots)!==(int)$line->admission_count){Policy::fail('admission_snapshot_invalid',503);}
                $take=Policy::entry($line->entry_policy,(int)$line->admission_count,$used,$quantity);$selected=[];
                foreach($slots as$slot){if($slot->status==='active'&&count($selected)<$take){$selected[]=$slot;}}
            }else{
                if($line->ticket_mode!=='individual'){Policy::fail('invalid_qr',422);}
                $slot=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}allocation_tickets WHERE line_id=%d AND public_id=%s FOR UPDATE",(int)$line->id,$id));
                if(!$slot||!Credential::valid($payload,'P',$slot->public_id,(int)$slot->qr_version)){Policy::fail('invalid_qr',422);}
                if($slot->status==='checked_in'){Policy::fail('already_checked_in');}if($slot->status!=='active'){Policy::fail('ticket_inactive');}
                if($quantity!==1){Policy::fail('admission_quantity',422);}$take=1;$selected=[$slot];
                $used=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}allocation_tickets WHERE line_id=%d AND status='checked_in'",(int)$line->id));
            }
            foreach($selected as$slot){
                Database::insert('allocation_checkins',['ticket_id'=>(int)$slot->id,'operator_id'=>$operatorId,'gate_label'=>$gate,'checked_in_at'=>$now]);
                if($wpdb->update($p.'allocation_tickets',['status'=>'checked_in'],['id'=>(int)$slot->id,'status'=>'active'])!==1){Policy::fail('checkin_failed',503);}
            }
            $result=['checked_in'=>true,'ticket_number'=>Credential::number($kind,$id),'admitted'=>$take,'used'=>$used+$take,'remaining'=>(int)$line->admission_count-$used-$take,'checked_in_at_utc'=>ReservationService::iso($now)];
            Database::receipt($request,$hash,$result,$now);\PESM\Support\Audit::record('allocated_checkin','allocation_line',(int)$line->id,$operatorId);return $result;
        });
    }

    public static function lookupNumber(string $number,int $operatorId): array
    {
        if($operatorId<1||!user_can($operatorId,'pesm_scan_tickets')){Policy::fail('permission_denied',403);}
        if(!preg_match('/^A-([PG])-([A-F0-9]{32})$/D',$number,$m)){Policy::fail('invalid_ticket_number',422);}$kind=$m[1];$id=strtolower($m[2]);global $wpdb;$p=$wpdb->prefix.'pesm_';
        $row=$kind==='G'?$wpdb->get_row($wpdb->prepare("SELECT l.id AS line_id,l.qr_version,l.status,l.status AS line_status,l.ticket_mode,h.session_id FROM {$p}allocation_lines l JOIN {$p}allocation_holds h ON h.id=l.hold_id WHERE l.public_id=%s",$id)):$wpdb->get_row($wpdb->prepare("SELECT t.line_id,t.qr_version,t.status,l.status AS line_status,l.ticket_mode,t.session_id FROM {$p}allocation_tickets t JOIN {$p}allocation_lines l ON l.id=t.line_id WHERE t.public_id=%s",$id));
        if(!$row||($kind==='G'?$row->ticket_mode!=='group':$row->ticket_mode!=='individual')){Policy::fail('ticket_not_found',404);}
        $center=(int)$wpdb->get_var($wpdb->prepare("SELECT e.center_id FROM {$p}sessions s JOIN {$p}events e ON e.id=s.event_id WHERE s.id=%d",(int)$row->session_id));
        if(!$center||!\PESM\Security\Capabilities::centerAllowed($center,$operatorId)){Policy::fail('ticket_not_found',404);}
        $capacity=(int)$wpdb->get_var($wpdb->prepare("SELECT admission_count FROM {$p}allocation_lines WHERE id=%d",(int)$row->line_id));$used=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}allocation_tickets WHERE line_id=%d AND status='checked_in'",(int)$row->line_id));
        return ['ticket_number'=>$number,'session_id'=>(int)$row->session_id,'status'=>$row->line_status==='issued'?$row->status:$row->line_status,'kind'=>$kind==='G'?'group':'individual','capacity'=>$kind==='G'?$capacity:1,'remaining'=>$row->line_status!=='issued'?0:($kind==='G'?$capacity-$used:($row->status==='active'?1:0))];
    }

    public static function manualCheckin(string $number,int $operatorId,string $gate,string $key,int $quantity=1): array
    {
        self::lookupNumber($number,$operatorId);preg_match('/^A-([PG])-([A-F0-9]{32})$/D',$number,$m);$kind=$m[1];$id=strtolower($m[2]);global $wpdb;$p=$wpdb->prefix.'pesm_';$table=$kind==='G'?'allocation_lines':'allocation_tickets';
        $version=(int)$wpdb->get_var($wpdb->prepare("SELECT qr_version FROM {$p}{$table} WHERE public_id=%s",$id));
        return self::scan(Credential::create($kind,$id,$version),$operatorId,$gate,$key,$quantity);
    }
}
