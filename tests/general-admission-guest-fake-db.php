<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

/** Validates guest authorization before exposing unnumbered tickets/invoice. */
require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Domain/Ticket/TicketToken.php';
require __DIR__ . '/../src/Domain/Ticket/TicketService.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Ticket\TicketService;

function wp_salt(string $kind):string { return 'test-secret-only-with-at-least-32-bytes!'; }
function get_option(string $key, mixed $default=false):mixed { return $key==='pesm_schema_version' ? 10 : $default; }
function wc_get_order(int $orderId):object|false { return $orderId===99 ? new GuestOrder():false; }
final class GuestOrder {
    public function get_meta(string $key):mixed {return ['_pesm_mode'=>'general','_pesm_is_ticket_order'=>'yes'][$key]??null;}
    public function get_status():string {return 'completed';}
    public function get_currency():string {return 'IRR';}
    public function get_total():string {return '0';}
    public function get_discount_total():string {return '0';}
    public function get_total_tax():string {return '0';}
    public function get_total_refunded():string {return '0';}
    public function get_payment_method_title():string {return '';}
    public function get_transaction_id():string {return '';}
    public function get_date_created():mixed {return null;}
}
final class GuestFakeDb {
    public string $prefix='wp_';
    public string $normal;
    public string $recovered;
    public int $ticketsQueried=0;
    public function __construct(){ $this->normal=str_repeat('a',64);$this->recovered=str_repeat('b',64); }
    public function prepare(string $sql,mixed ...$args):string{
        foreach($args as $a)$sql=preg_replace('/%[sd]/',is_int($a)?(string)$a:"'".str_replace("'","''",(string)$a)."'",$sql,1);
        return $sql;
    }
    public function get_row(string $sql):?object {
        if(str_contains($sql,'FROM wp_pesm_capacity_order_links')) {
            if(str_contains($sql,'guest_token_hash=')) {
                return str_contains($sql,hash('sha256',$this->normal))?(object)['id'=>1,'status'=>'issued']:null;
            }
            return (object)['id'=>1,'status'=>'issued'];
        }
        throw new RuntimeException('Unexpected get_row '.$sql);
    }
    public function get_var(string $sql):mixed {
        if(str_contains($sql,'FROM wp_pesm_guest_access_tokens'))return str_contains($sql,hash('sha256',$this->recovered))?1:null;
        throw new RuntimeException('Unexpected get_var '.$sql);
    }
    public function get_results(string $sql,mixed $format=null):array {
        if(!str_contains($sql,'FROM wp_pesm_capacity_tickets'))throw new RuntimeException('Unexpected get_results '.$sql);
        ++$this->ticketsQueried;
        return [['public_id'=>str_repeat('c',32),'ticket_number'=>'P-CCCCCCCCCCCCCCCC',
            'attendee_name'=>'مهمان نمونه','status'=>'active','qr_version'=>1,'session_id'=>7,
            'seat_code'=>'ورود آزاد','row_label'=>'','seat_number'=>1,'zone_label'=>'ظرفیت آزاد']];
    }
}
const ARRAY_A='ARRAY_A';
$wpdb=new GuestFakeDb();$service=new TicketService();
$info=$service->forGuest(99,$wpdb->normal);
if(count($info['tickets'])!==1 || $info['tickets'][0]['seat_code']!=='ورود آزاد' ||
    !str_contains($info['tickets'][0]['qr_payload'],'.') || $info['fulfillment_status']!=='issued' ||
    $info['invoice']['total']!=='0')throw new RuntimeException('Bad free admission ticket or invoice payload');
$info=$service->forGuest(99,$wpdb->recovered);
if(count($info['tickets'])!==1)throw new RuntimeException('Recovered guest token failed');
try {$service->forGuest(99,str_repeat('d',64));throw new RuntimeException('Unauthenticated guest saw tickets');}
catch(HoldException $e) {if($e->errorCode!=='permission_denied')throw $e;}
if($wpdb->ticketsQueried!==2)throw new RuntimeException('Tickets queried before validating access token');
echo "PASS: 4 general-admission guest authorization and invoice assertions (fake DB).\n";
