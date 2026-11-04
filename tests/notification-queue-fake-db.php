<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

require __DIR__.'/../src/Domain/Notification/NotificationPolicy.php';
require __DIR__.'/../src/Domain/Notification/PayloadCipher.php';
require __DIR__.'/../src/Domain/Notification/MelipayamakClient.php';
require __DIR__.'/../src/Domain/Notification/NotificationQueue.php';

use PESM\Domain\Notification\NotificationQueue as Queue;
use PESM\Domain\Notification\PayloadCipher as Cipher;

function expectN(bool $condition, string $message): void { if (!$condition) {throw new RuntimeException($message);} $GLOBALS['checks']++; }
function wp_salt(string $purpose): string {return str_repeat('z',64);}
function get_option(string $name,mixed $fallback=false):mixed {return $GLOBALS['options'][$name]??$fallback;}
function is_email(string $email): bool {return filter_var($email,FILTER_VALIDATE_EMAIL)!==false;}
function wc_get_order(int $id): object|false {return $id===77 ? $GLOBALS['order'] : false;}
function is_wp_error(mixed $obj): bool {return false;}
function wp_remote_post(string $url,array $args): array {$GLOBALS['smsCalls'][]=$args['body'];return $GLOBALS['provider'];}
function wp_remote_retrieve_response_code(array $response):int{return $response['code'];}
function wp_remote_retrieve_body(array $response):string{return $response['body'];}
function wp_mail(string $email,string $title,string $message):bool {$GLOBALS['mailCalls'][]=[$email,$title,$message];return $GLOBALS['mailWorks'];}
function do_action(string $action,mixed ...$args):void {$GLOBALS['actions'][]=$action;}
const PESM_SMS_USERNAME='fake'; const PESM_SMS_PASSWORD='not-real';

class FakeOrder {
    public string $status='completed';
    public string $email='customer@example.org';
    public string $phone='+989123456789';
    public function get_meta(string $k):string{return ['_pesm_is_ticket_order'=>'yes','_pesm_mode'=>'seated'][$k]??'';}
    public function get_status():string{return $this->status;}
    public function get_billing_email():string{return $this->email;}
    public function get_billing_phone():string{return $this->phone;}
}
class QueueFakeDb {
    public string $prefix='wp_';
    public array $rows=[];
    public int $insertCount=0;
    public array $queries=[];
    public function prepare(string $sql,mixed ...$args):string {
        foreach($args as $arg) {$replacement=is_int($arg)?(string)$arg:"'".str_replace("'","''",(string)$arg)."'"; $sql=preg_replace('/%[ds]/',$replacement,$sql,1);}
        return $sql;
    }
    public function get_var(string $sql): mixed {
        if (str_contains($sql,'SELECT COUNT(*) FROM wp_pesm_tickets')) return $GLOBALS['activeTickets'];
        if (str_contains($sql,'SELECT COUNT(*) FROM wp_pesm_capacity_tickets')) return $GLOBALS['activeTickets'];
        throw new RuntimeException('Unknown get_var '.$sql);
    }
    public function query(string $sql):int|false {
        $this->queries[]=$sql;
        if (str_contains($sql,'INSERT IGNORE INTO wp_pesm_notification_jobs')) {
            $pattern="/VALUES \\('([^']+)',(\\d+),'([^']+)','([^']+)','([^']+)','queued',0,'([^']+)','([^']+)','([^']+)'\\)/";
            if (!preg_match($pattern,$sql,$m)) throw new RuntimeException('Unexpected insert: '.$sql);
            foreach($this->rows as $row) if ($row['dedupe_hash']===$m[4]) return 0;
            $id=count($this->rows)+1;
            $this->rows[$id]=['id'=>$id,'event_key'=>$m[1],'order_id'=>(int)$m[2],'channel'=>$m[3],
                'dedupe_hash'=>$m[4],'payload'=>$m[5],'status'=>'queued','attempts'=>0,'locked_until'=>null];
            $this->insertCount++;return 1;
        }
        if (str_contains($sql,"SET status='sending'")) {
            preg_match('/WHERE id=(\\d+)/',$sql,$m);$id=(int)($m[1]??0);
            if (!isset($this->rows[$id]) || $this->rows[$id]['status']!=='queued')return 0;
            $this->rows[$id]['status']='sending';$this->rows[$id]['attempts']++;return 1;
        }
        if (str_contains($sql,"SET status='queued',locked_until=NULL")) {
            preg_match("/last_error_code='([^']+)'.*WHERE id=(\\d+)/s",$sql,$m);$id=(int)($m[2]??0);
            if (!$id) throw new RuntimeException('Unexpected retry query '.$sql);
            $this->rows[$id]['status']='queued';$this->rows[$id]['last_error_code']=$m[1];return 1;
        }
        if (str_contains($sql,"SET status='review',last_error_code='abandoned_send'")) return 0;
        if (str_contains($sql,'DELETE FROM wp_pesm_notification_jobs')) return 0;
        throw new RuntimeException('Unexpected query: '.$sql);
    }
    public function get_col(string $sql):array {
        if (!str_contains($sql,"status='queued'")) throw new RuntimeException('Unexpected get_col: '.$sql);
        return array_keys(array_filter($this->rows,fn($row)=>$row['status']==='queued'));
    }
    public function get_row(string $sql):?object {
        preg_match('/WHERE id=(\\d+)/',$sql,$m);$id=(int)($m[1]??0);
        return isset($this->rows[$id])?(object)$this->rows[$id]:null;
    }
    public function update(string $table,array $data,array $where):int {
        $id=(int)$where['id'];
        if (!isset($this->rows[$id]) || $this->rows[$id]['status']!==$where['status'])return 0;
        $this->rows[$id]=array_merge($this->rows[$id],$data);return 1;
    }
    public function get_results(string $sql):array {
        if (!str_contains($sql,'GROUP BY status')) throw new RuntimeException('Unexpected summary');
        $counts=[];foreach($this->rows as $row){$counts[$row['status']]=($counts[$row['status']]??0)+1;}
        return array_map(fn($status,$total)=>(object)['status'=>$status,'total'=>$total],array_keys($counts),array_values($counts));
    }
}
$GLOBALS['checks']=0;$GLOBALS['options']=['pesm_schema_version'=>11,'pesm_notifications_enabled'=>'yes','pesm_sms_ticket_confirmed_pattern'=>412];
$GLOBALS['order']=new FakeOrder();$GLOBALS['wpdb']=new QueueFakeDb();$GLOBALS['activeTickets']=2;
$GLOBALS['smsCalls']=[];$GLOBALS['mailCalls']=[];$GLOBALS['actions']=[];
$GLOBALS['mailWorks']=true;$GLOBALS['provider']=['code'=>200,'body'=>'{"Value":"500001","RetStatus":1}'];
Queue::onTicketsIssued(77);Queue::onTicketsIssued(77);
expectN(count($GLOBALS['wpdb']->rows)===2,'Only one per channel should enqueue');
expectN($GLOBALS['wpdb']->insertCount===2,'Dedupe insert should not duplicate');
foreach ($GLOBALS['wpdb']->rows as $row) {
    expectN(!str_contains($row['payload'],'customer@example.org') && !str_contains($row['payload'],'09123456789'),'PII in DB');
    expectN(Cipher::open($row['payload'])['order_id']===77,'Cannot recover valid payload');
}
expectN(Queue::process(10)===2,'Two jobs should process');
expectN(count($GLOBALS['mailCalls'])===1 && count($GLOBALS['smsCalls'])===1,'One email one SMS');
expectN(Queue::summary()['sent']===2,'Both channels accepted');
expectN(Queue::process(10)===0,'No repeat sends');
foreach ($GLOBALS['wpdb']->rows as $row) expectN($row['payload']==='','Delivered personal data must be destroyed');
// If cancellation follows enqueue but precedes dispatch, no confirmation may be sent.
$GLOBALS['wpdb']=new QueueFakeDb();Queue::onTicketsIssued(77);$GLOBALS['order']->status='cancelled';
$mailBefore=count($GLOBALS['mailCalls']);$smsBefore=count($GLOBALS['smsCalls']);
expectN(Queue::process(10)===2,'Cancelled order jobs should be evaluated');
expectN(count($GLOBALS['mailCalls'])===$mailBefore && count($GLOBALS['smsCalls'])===$smsBefore,'Cancelled order must not notify');
expectN(Queue::summary()['failed']===2,'Cancellation jobs should be marked failed');
$GLOBALS['order']->status='completed';
// Email failure requeues without duplicating the SMS provider call.
$GLOBALS['wpdb']=new QueueFakeDb();$GLOBALS['options']['pesm_sms_ticket_confirmed_pattern']=0;$GLOBALS['mailWorks']=false;
Queue::onTicketsIssued(77);expectN(count($GLOBALS['wpdb']->rows)===1,'SMS should be disabled without approved pattern');
expectN(Queue::process(10)===1,'Email first attempt');
expectN(Queue::summary()['queued']===1 && $GLOBALS['wpdb']->rows[1]['attempts']===1,'Email retry with backoff');
// Broken plaintext cannot be used to send: failed/review but no accidental delivery.
$GLOBALS['wpdb']=new QueueFakeDb();$GLOBALS['mailWorks']=true;
Queue::onTicketsIssued(77);$GLOBALS['wpdb']->rows[1]['payload']='not-base64';
expectN(Queue::process(10)===1,'Tampered job claim');
expectN(Queue::summary()['review']===1,'Tampered job must be reviewed');
// No ticket or unverified account cannot enqueue anything.
$GLOBALS['wpdb']=new QueueFakeDb();$GLOBALS['activeTickets']=0;Queue::onTicketsIssued(77);
expectN(count($GLOBALS['wpdb']->rows)===0,'No ticket must never notify');
echo 'notification-queue-fake-db: '.$GLOBALS['checks']." checks passed (not a real InnoDB test)\n";
