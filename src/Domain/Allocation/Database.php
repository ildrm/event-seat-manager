<?php
declare(strict_types=1);
namespace PESM\Domain\Allocation;

use PESM\Domain\Booking\HoldException;

/** The common allocated writer lock protocol: lifecycle -> sections -> hold/line -> seats. */
final class Database
{
    public static function transaction(callable $fn): mixed
    {
        global $wpdb;
        for ($attempt=0; $attempt<3; ++$attempt) {
            // Unique idempotency probes for new keys must not take range/gap locks
            // across unrelated buyers. Resource correctness uses explicit row locks.
            if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false) { Policy::fail('db_unavailable',503); }
            if ($wpdb->query('START TRANSACTION') === false) { Policy::fail('db_unavailable',503); }
            try {
                $result=$fn();
                if ($wpdb->query('COMMIT') === false) { Policy::fail('db_unavailable',503); }
                return $result;
            } catch (\Throwable $e) {
                $error=(string) $wpdb->last_error;
                $wpdb->query('ROLLBACK');
                if ($attempt<2 && (preg_match('/1213|1205|deadlock|lock wait timeout/i',$error) ||
                    ($e instanceof HoldException && $e->errorCode==='hold_changed_retry'))) { usleep(20000*($attempt+1)); continue; }
                if ($e instanceof HoldException) { throw $e; }
                throw new HoldException('allocation_failed',Messages::text('allocation_failed'),503);
            }
        }
        Policy::fail('db_busy',503);
    }

    public static function session(int $sessionId, bool $sale = false): object
    {
        global $wpdb; $p=$wpdb->prefix.'pesm_';
        $eventId=(int)$wpdb->get_var($wpdb->prepare("SELECT event_id FROM {$p}sessions WHERE id=%d",$sessionId));
        if (!$eventId) { Policy::fail('invalid_session',404); }
        $event=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}events WHERE id=%d LOCK IN SHARE MODE",$eventId));
        $session=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}sessions WHERE id=%d LOCK IN SHARE MODE",$sessionId));
        if (!$event || !$session || (int)$session->event_id!==$eventId || ($session->mode??'')!=='allocated') { Policy::fail('invalid_session',404); }
        $session->center_id=(int)$event->center_id; $session->event_status=(string)$event->status;
        if ($sale) { self::sale($session,self::now()); }
        return $session;
    }

    public static function sale(object $session,string $now): void
    {
        if ($session->status!=='published' || $session->event_status!=='published' || $session->sale_starts_at>$now || $session->sale_ends_at<=$now || $session->starts_at<=$now) { Policy::fail('event_closed'); }
    }

    public static function sections(int $sessionId,array $sections): void
    {
        global $wpdb; $p=$wpdb->prefix.'pesm_'; $sections=array_values(array_unique($sections)); sort($sections,SORT_STRING);
        foreach ($sections as $section) {
            Policy::code($section);
            if (!$wpdb->get_row($wpdb->prepare("SELECT section_code FROM {$p}allocation_sections WHERE session_id=%d AND section_code=%s FOR UPDATE",$sessionId,$section))) { Policy::fail((string)$wpdb->last_error!==''?'db_busy':'inventory_not_ready',503); }
        }
    }

    public static function revision(int $sessionId,array $sections): void
    {
        global $wpdb; $p=$wpdb->prefix.'pesm_';
        foreach (array_unique($sections) as $section) {
            if ($wpdb->query($wpdb->prepare("UPDATE {$p}allocation_sections SET revision=revision+1 WHERE session_id=%d AND section_code=%s",$sessionId,$section))!==1) { Policy::fail('write_failed',503); }
        }
    }

    public static function now(): string
    {
        global $wpdb; $now=$wpdb->get_var('SELECT UTC_TIMESTAMP()');
        if (!is_string($now) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$now)) { Policy::fail('db_clock_unavailable',503); }
        return $now;
    }

    public static function json(mixed $value): string { return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    public static function decode(string $value): array { $v=json_decode($value,true,32,JSON_THROW_ON_ERROR); if(!is_array($v)){Policy::fail('invalid_snapshot',503);}return $v; }
    public static function checked(int|false $result): void { if($result===false){Policy::fail('write_failed',503);} }
    public static function insert(string $table,array $values): int { global $wpdb; if($wpdb->insert($wpdb->prefix.'pesm_'.$table,$values)!==1){if($table==='allocation_holds'&&preg_match('/duplicate entry|1062/i',(string)$wpdb->last_error)){Policy::fail('idempotency_conflict');}Policy::fail('write_failed',503);}return (int)$wpdb->insert_id; }

    public static function request(string $scope,string $key,array $payload): array
    {
        \PESM\Domain\Booking\HoldPolicy::validateKey($key);
        return [hash_hmac('sha256','allocated:'.$scope.':'.$key,wp_salt('auth')),hash('sha256',self::json($payload))];
    }
    public static function replay(string $request,string $payload): ?array
    {
        global $wpdb;$p=$wpdb->prefix.'pesm_';
        $row=$wpdb->get_row($wpdb->prepare("SELECT payload_hash,result_json FROM {$p}allocation_requests WHERE request_hash=%s FOR UPDATE",$request));
        if(!$row){return null;}if(!hash_equals((string)$row->payload_hash,$payload)){Policy::fail('idempotency_conflict');}
        return self::decode((string)$row->result_json)+['idempotent_replay'=>true];
    }
    public static function receipt(string $request,string $payload,array $result,string $now): void
    {
        self::insert('allocation_requests',['request_hash'=>$request,'payload_hash'=>$payload,'result_json'=>self::json($result),'created_at'=>$now]);
    }
}
