<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Domain/Ticket/TicketToken.php';
require __DIR__ . '/../src/Security/Capabilities.php';
require __DIR__ . '/../src/Support/Audit.php';
require __DIR__ . '/../src/Domain/GeneralAdmission/AdmissionGateService.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Ticket\TicketToken;
use PESM\Domain\GeneralAdmission\AdmissionGateService;

function wp_salt(string $kind): string { return 'free-admission-gate-test-only-secret'; }
function user_can(int $id, string $cap): bool { return $id===9 && in_array($cap,['pesm_scan_tickets','pesm_manage_centers'],true); }
function get_option(string $key, mixed $default=false): mixed { return $default; }

final class GateFakeDb {
    public string $prefix='wp_';
    public int $insert_id=0;
    public string $now='2026-11-03 10:00:00';
    public array $ticket=[];
    public array $event=['starts_at'=>'2026-11-03 10:00:00','status'=>'published','center_id'=>13,'event_status'=>'published'];
    public array $checkins=[];
    public array $audit=[];
    public bool $failCheckin=false;
    private ?string $snapshot=null;
    public function __construct() {
        $id=bin2hex(random_bytes(16));
        $qr=TicketToken::create($id,1,wp_salt('secure_auth'));
        $this->ticket=['id'=>2,'public_id'=>$id,'qr_version'=>1,'ticket_number'=>'P-'.strtoupper(substr($id,0,16)),
            'status'=>'active','session_id'=>7,'qr_token_hash'=>hash('sha256',$qr)];
    }
    public function prepare(string $sql,mixed ...$values):string {
        foreach ($values as $value) $sql=preg_replace('/%[sd]/',is_int($value) ? (string)$value : "'".str_replace("'","''",(string)$value)."'",$sql,1);
        return $sql;
    }
    public function query(string $sql):int|false {
        if ($sql==='START TRANSACTION') {$this->snapshot=serialize([$this->ticket,$this->checkins,$this->audit]);return 1;}
        if ($sql==='COMMIT') {$this->snapshot=null;return 1;}
        if ($sql==='ROLLBACK') {if ($this->snapshot) [$this->ticket,$this->checkins,$this->audit]=unserialize($this->snapshot);$this->snapshot=null;return 1;}
        throw new RuntimeException('Unknown query '.$sql);
    }
    public function get_var(string $sql):mixed {
        if ($sql==='SELECT UTC_TIMESTAMP()') return $this->now;
        if (str_contains($sql,'SELECT e.center_id FROM wp_pesm_capacity_tickets')) return 13;
        throw new RuntimeException('Unknown get_var '.$sql);
    }
    public function get_row(string $sql):?object {
        if (str_contains($sql,'FROM wp_pesm_capacity_tickets t') && str_contains($sql,'ticket_number='))
            return (object)array_merge($this->ticket,['center_id'=>13]);
        if (str_contains($sql,'FROM wp_pesm_capacity_tickets WHERE public_id=')) return (object)$this->ticket;
        if (str_contains($sql,'FROM wp_pesm_capacity_tickets WHERE ticket_number=')) return (object)$this->ticket;
        if (str_contains($sql,'FROM wp_pesm_sessions s')) return (object)$this->event;
        throw new RuntimeException('Unknown get_row '.$sql);
    }
    public function insert(string $table,array $data):int|false {
        if ($table==='wp_pesm_capacity_checkins') {
            if ($this->failCheckin || $this->checkins) return false;
            $this->checkins[]=$data;return 1;
        }
        if ($table==='wp_pesm_audit_logs') {$this->audit[]=$data;return 1;}
        throw new RuntimeException('Unknown insert '.$table);
    }
    public function update(string $table,array $data,array $where):int|false {
        if ($table==='wp_pesm_capacity_tickets' && $where['id']===$this->ticket['id'] &&
            $where['status']===$this->ticket['status']) {$this->ticket=array_replace($this->ticket,$data);return 1;}
        return 0;
    }
}

$count=0;
function check(bool $valid,string $msg):void {global $count;++$count;if(!$valid)throw new RuntimeException($msg);}
function checkError(callable $action,string $code):void {
    try {$action();}catch(HoldException $e){check($e->errorCode===$code,'Got '.$e->errorCode.' expected '.$code);return;}
    throw new RuntimeException('Expected '.$code);
}
$wpdb=new GateFakeDb();$gate=new AdmissionGateService();
$payload=TicketToken::create($wpdb->ticket['public_id'],1,wp_salt('secure_auth'));
check($gate->lookupNumber($wpdb->ticket['ticket_number'],9)['admission_type']==='general','Lookup GA');
checkError(fn()=>$gate->lookupNumber($wpdb->ticket['ticket_number'],8),'permission_denied');
checkError(fn()=>$gate->scan($payload,8),'permission_denied');
checkError(fn()=>$gate->scan('invalid',9),'invalid_qr');
checkError(fn()=>$gate->scan(TicketToken::create($wpdb->ticket['public_id'],2,wp_salt('secure_auth')),9),'invalid_qr');
$wpdb->now='2026-11-03 03:00:00';
checkError(fn()=>$gate->scan($payload,9),'invalid_entry_time');
$wpdb->now='2026-11-03 10:00:00';
$wpdb->failCheckin=true;
checkError(fn()=>$gate->scan($payload,9),'checkin_failed');
check($wpdb->ticket['status']==='active' && count($wpdb->checkins)===0,'Failed checkin rolled back');
$wpdb->failCheckin=false;
check($gate->manualCheckin($wpdb->ticket['ticket_number'],9,'درب شمال')['checked_in']===true,'Manual first checkin');
check($wpdb->ticket['status']==='checked_in' && count($wpdb->checkins)===1,'Atomic checked in');
check(count($wpdb->audit)===1,'Audit recorded');
checkError(fn()=>$gate->scan($payload,9),'already_checked_in');
$wpdb=new GateFakeDb();
$wpdb->ticket['status']='cancelled';
checkError(fn()=>$gate->scan(TicketToken::create($wpdb->ticket['public_id'],1,wp_salt('secure_auth')),9),'ticket_inactive');
$wpdb=new GateFakeDb();$wpdb->event['status']='cancelled';
checkError(fn()=>$gate->scan(TicketToken::create($wpdb->ticket['public_id'],1,wp_salt('secure_auth')),9),'event_cancelled');
echo "PASS: {$count} free-general-admission gate assertions (fake DB, NOT concurrent scanners).\n";
