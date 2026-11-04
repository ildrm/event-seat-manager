<?php

declare(strict_types=1);

namespace PESM\Domain\Venue;

use InvalidArgumentException;
use PESM\Security\Capabilities;
use RuntimeException;
use Throwable;

/** Durable job cursor and database lease are independent of the scheduling provider. */
final class JobService
{
    private const HOOK = 'pesm_venue_job';
    private const IMPORT_CHUNK = 1048576;

    public static function register(): void
    {
        add_action(self::HOOK,[self::class,'run'],10,1);
        add_action('pesm_hourly_maintenance',[self::class,'recover']);
        if (defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI')) {
            \WP_CLI::add_command('pesm venue-jobs',static function ($args,$assoc): void {
                $limit=max(1,min(10000,(int)($assoc['limit']??100)));
                if (isset($args[0])) { self::run((int)$args[0]); \WP_CLI::log(self::encode(self::status((int)$args[0]))); return; }
                for ($i=0;$i<$limit;$i++) {
                    global $wpdb; $id=(int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}pesm_venue_jobs WHERE status='pending' OR (status='running' AND lease_until<UTC_TIMESTAMP()) ORDER BY id LIMIT 1");
                    if (!$id) { break; }
                    self::run($id);
                }
                \WP_CLI::success('Venue job batches processed.');
            });
        }
    }

    public static function create(int $centerId,int $hallId,string $kind,array $payload,int $actorId): array
    {
        if (!in_array($kind,['generate','import','publish','provision'],true) || $centerId<1 || $hallId<1 || $actorId<1) { throw new InvalidArgumentException('Invalid venue job.'); }
        $cap=$kind==='provision'?'pesm_manage_sessions':'pesm_edit_layouts';
        if (!user_can($actorId,$cap)||!Capabilities::centerAllowed($centerId,$actorId)) { throw new RuntimeException('Venue job is outside your permissions.'); }
        global $wpdb; $p=$wpdb->prefix.'pesm_';
        $hall=$wpdb->get_row($wpdb->prepare("SELECT id,center_id FROM {$p}halls WHERE id=%d",$hallId));
        if (!$hall || (int)$hall->center_id!==$centerId) { throw new RuntimeException('Hall does not belong to this center.'); }
        $sessionId=0; $layoutId=0;
        if ($kind==='generate') {
            $id=(string)($payload['preset_id']??'');
            $payload=['preset_id'=>$id,'parameters'=>Generator::parameters($id,$payload['parameters']??[])];
        } elseif ($kind==='import') {
            if (isset($payload['jsonl'])) {
                if (!is_string($payload['jsonl'])||strlen($payload['jsonl'])>2097152) { throw new InvalidArgumentException('Use streamed upload for imports larger than 2 MiB.'); }
                $stream=fopen('php://temp','w+b');
                if (!$stream) { throw new RuntimeException('Unable to stage import.'); }
                try { fwrite($stream,$payload['jsonl']); rewind($stream); $payload=['upload_token'=>self::stageImport($stream,$actorId)]; } finally { fclose($stream); }
            }
            $token=$payload['upload_token']??'';
            if (!is_string($token)||!preg_match('/^[a-f0-9]{32}$/D',$token) || !$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$p}venue_import_chunks WHERE token_hash=%s AND actor_id=%d AND part_no=0",hash('sha256',$token),$actorId))) { throw new InvalidArgumentException('Import upload is missing or belongs to another administrator.'); }
            $payload=['upload_token'=>$token,'upload_hash'=>hash('sha256',$token)];
        } else {
            if ($kind==='provision') {
                $sessionId=(int)($payload['session_id']??0);
                $session=$wpdb->get_row($wpdb->prepare("SELECT s.layout_id FROM {$p}sessions s JOIN {$p}events e ON e.id=s.event_id WHERE s.id=%d AND e.center_id=%d AND s.hall_id=%d AND s.mode='allocated' AND s.status='draft'",$sessionId,$centerId,$hallId));
                if (!$session) { throw new RuntimeException('Allocated draft session not found.'); }
                $layoutId=(int)$session->layout_id; $payload=['session_id'=>$sessionId];
            } else { $layoutId=(int)($payload['layout_id']??0); $payload=['layout_id'=>$layoutId]; }
            if (!$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$p}layout_versions l JOIN {$p}venue_layouts v ON v.layout_id=l.id WHERE l.id=%d AND l.hall_id=%d",$layoutId,$hallId))) { throw new RuntimeException('Venue layout does not belong to this hall.'); }
        }
        $now=gmdate('Y-m-d H:i:s');
        if ($wpdb->insert($p.'venue_jobs',['center_id'=>$centerId,'hall_id'=>$hallId,'layout_id'=>$layoutId,
            'session_id'=>$sessionId,'actor_id'=>$actorId,'kind'=>$kind,'status'=>'pending','payload'=>self::encode($payload),
            'cursor_json'=>'{}','result_json'=>'{}','lease_token'=>'','created_at'=>$now,'updated_at'=>$now])!==1) { throw new RuntimeException('Unable to queue venue job.'); }
        $id=(int)$wpdb->insert_id; self::schedule($id); return self::status($id);
    }

    /** Private shared storage: no local-file dependency, executable upload or public URL. */
    public static function stageImport($stream,int $actorId=0): string
    {
        if (!is_resource($stream)||$actorId<1||!user_can($actorId,'pesm_edit_layouts')) { throw new InvalidArgumentException('An authorized administrator and readable upload are required.'); }
        global $wpdb; $table=$wpdb->prefix.'pesm_venue_import_chunks';
        $token=bin2hex(random_bytes(16)); $hash=hash('sha256',$token); $bytes=0; $part=0; $buffer='';
        try {
            while (!feof($stream)) {
                $read=fread($stream,self::IMPORT_CHUNK-strlen($buffer));
                if ($read===false) { throw new RuntimeException('Unable to read upload.'); }
                if ($read===''&&!feof($stream)) { throw new RuntimeException('Upload stream stopped before its end.'); }
                $buffer.=$read;
                if (strlen($buffer)===self::IMPORT_CHUNK || (feof($stream)&&$buffer!=='')) {
                    $bytes+=strlen($buffer);
                    if ($bytes>Document::MAX_BYTES) { throw new InvalidArgumentException('Layout upload exceeds 128 MiB.'); }
                    if ($wpdb->insert($table,['token_hash'=>$hash,'actor_id'=>$actorId,'part_no'=>$part++,'data'=>$buffer,'created_at'=>gmdate('Y-m-d H:i:s')])!==1) { throw new RuntimeException('Unable to store upload chunk.'); }
                    $buffer='';
                }
            }
            if ($bytes===0) { throw new InvalidArgumentException('Layout file is empty.'); }
            return $token;
        } catch (Throwable $error) { $wpdb->delete($table,['token_hash'=>$hash]); throw $error; }
    }

    public static function status(int $id): array
    {
        global $wpdb;
        $job=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pesm_venue_jobs WHERE id=%d",$id));
        if (!$job) { throw new RuntimeException('Venue job not found.'); }
        $cursor=self::json($job->cursor_json); $payload=self::json($job->payload);
        return ['id'=>(int)$job->id,'job_id'=>(int)$job->id,'center_id'=>(int)$job->center_id,'hall_id'=>(int)$job->hall_id,
            'layout_id'=>(int)$job->layout_id,'session_id'=>(int)$job->session_id,'kind'=>$job->kind,'status'=>$job->status,
            'processed'=>(int)($cursor['offset']??$cursor['records']??$cursor['count']??0),
            'total'=>(int)($cursor['total']??$payload['parameters']['capacity']??0),'attempts'=>(int)$job->attempts,
            'result'=>self::json($job->result_json),'error'=>$job->error_message,'updated_at'=>$job->updated_at];
    }

    public static function retry(int $id): array
    {
        global $wpdb; $p=$wpdb->prefix.'pesm_';
        $job=$wpdb->get_row($wpdb->prepare("SELECT status FROM {$p}venue_jobs WHERE id=%d",$id));
        if (!$job||$job->status!=='failed') { throw new RuntimeException('Only a failed venue job can be retried.'); }
        if ($wpdb->update($p.'venue_jobs',['status'=>'pending','lease_token'=>'','lease_until'=>null,'error_message'=>''],['id'=>$id,'status'=>'failed'])!==1) { throw new RuntimeException('Job retry conflicted with another request.'); }
        self::schedule($id); return self::status($id);
    }

    public static function run(int $id): void
    {
        global $wpdb; $p=$wpdb->prefix.'pesm_'; $token=bin2hex(random_bytes(16)); $claimed=false;
        try {
            self::begin();
            $job=$wpdb->get_row($wpdb->prepare("SELECT *,UTC_TIMESTAMP() AS db_now FROM {$p}venue_jobs WHERE id=%d FOR UPDATE",$id));
            if (!$job || in_array($job->status,['complete','failed'],true) || ($job->status==='running'&&$job->lease_until>$job->db_now)) { self::commit(); return; }
            self::write($wpdb->query($wpdb->prepare("UPDATE {$p}venue_jobs SET status='running',lease_token=%s,lease_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 180 SECOND),attempts=attempts+1,updated_at=UTC_TIMESTAMP() WHERE id=%d",$token,$id)));
            self::commit(); $claimed=true;
            $cap=$job->kind==='provision'?'pesm_manage_sessions':'pesm_edit_layouts';
            if (!user_can((int)$job->actor_id,$cap)||!Capabilities::centerAllowed((int)$job->center_id,(int)$job->actor_id)) { throw new RuntimeException('Job administrator no longer has permission.'); }
            $payload=self::json($job->payload); $cursor=self::json($job->cursor_json);
            if ($job->kind==='publish') {
                // Publication has its own transaction and is idempotent if a worker dies before its receipt.
                $result=LayoutRepository::publish((int)$job->layout_id);
                self::finish($id,$token,$cursor,$result,true); return;
            }
            if ($job->kind==='provision') {
                $result=\PESM\Domain\Allocation\SessionService::provision((int)$job->session_id,(int)($cursor['cursor']??0),2000);
                $cursor=['cursor'=>(int)$result['cursor'],'count'=>(int)$result['count'],'total'=>(int)($result['total']??0)];
                self::finish($id,$token,$cursor,$result,(bool)$result['complete']); return;
            }
            if ($job->kind==='generate') {
                $plan=$payload['plan']??Generator::plan($payload['preset_id'],$payload['parameters']);
                $payload['plan']=$plan; $offset=(int)($cursor['offset']??0); $total=Generator::recordCount($plan);
                $records=Generator::batch($plan,$offset,2000); $done=$offset+count($records)>=$total;
                $next=['offset'=>$offset+count($records),'total'=>$total]; $manifest=$plan['manifest'];
            } else {
                $batch=self::importBatch($payload['upload_token'],(int)($cursor['bytes']??0));
                $records=$batch['records']; $done=$batch['complete'];
                $oldCount=(int)($cursor['records']??0);
                foreach ($records as $n=>$record) {
                    if (($oldCount+$n===0)!==($record['type']==='manifest')) { throw new InvalidArgumentException('A single manifest must be the first import record.'); }
                }
                if ($oldCount===0 && (!$records||$records[0]['type']!=='manifest')) { throw new InvalidArgumentException('Import manifest is missing.'); }
                $manifest=$payload['manifest']??$records[0]['data']; $payload['manifest']=$manifest;
                $next=['bytes'=>$batch['bytes'],'records'=>$oldCount+count($records),'total'=>1+$manifest['section_count']+$manifest['group_count']+$manifest['capacity']+$manifest['object_count']];
                if ($next['records']>$next['total']) { throw new InvalidArgumentException('Import contains too many records.'); }
            }
            self::begin();
            $locked=self::lockLease($id,$token);
            $layoutId=(int)$locked->layout_id;
            if (!$layoutId) {
                $layoutId=LayoutRepository::createDraft((int)$locked->hall_id,$manifest['preset_id'],$manifest['parameters'],$manifest);
                self::write($wpdb->update($p.'venue_jobs',['layout_id'=>$layoutId],['id'=>$id,'lease_token'=>$token]));
            }
            LayoutRepository::storeRecords($layoutId,$records);
            if ($done) { LayoutRepository::finishBuild($layoutId); }
            $result=['layout_id'=>$layoutId,'capacity'=>$manifest['capacity'],'revision'=>0,'status'=>$done?'draft':'building'];
            self::saveProgress($id,$token,$next,$result,$done,$payload);
            if ($done&&$job->kind==='import') { self::write($wpdb->delete($p.'venue_import_chunks',['token_hash'=>hash('sha256',$payload['upload_token'])])); }
            self::commit();
            if (!$done) { self::schedule($id); }
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            if ($claimed) {
                $message=preg_replace('/[\x00-\x1F\x7F]/',' ',substr($error->getMessage(),0,490));
                $wpdb->update($p.'venue_jobs',['status'=>'failed','error_message'=>$message,'lease_token'=>'','lease_until'=>null,'updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id,'lease_token'=>$token]);
            }
        }
    }

    public static function recover(): void
    {
        global $wpdb; $p=$wpdb->prefix.'pesm_';
        $rows=$wpdb->get_col("SELECT id FROM {$p}venue_jobs WHERE status='pending' OR (status='running' AND lease_until<UTC_TIMESTAMP()) ORDER BY id LIMIT 100");
        foreach ($rows?:[] as $id) { self::schedule((int)$id); }
        // Upload tokens are private, short-lived import staging rather than retained user data.
        $wpdb->query("DELETE c FROM {$p}venue_import_chunks c WHERE c.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY) AND NOT EXISTS (SELECT 1 FROM {$p}venue_jobs j WHERE j.kind='import' AND j.status IN ('pending','running','failed') AND j.payload LIKE CONCAT('%',c.token_hash,'%'))");
    }

    public static function schedule(int $id): void
    {
        if (function_exists('as_enqueue_async_action')&&function_exists('did_action')&&did_action('action_scheduler_init')) {
            // Duplicate scheduler deliveries are harmless; the DB lease is authoritative.
            // An in-progress unique action must not suppress its own next batch.
            as_enqueue_async_action(self::HOOK,[$id],'pesm-venue',false);
        } elseif (function_exists('wp_next_scheduled')&&!wp_next_scheduled(self::HOOK,[$id])) {
            wp_schedule_single_event(time()+1,self::HOOK,[$id]);
        }
    }

    private static function importBatch(string $token,int $offset): array
    {
        global $wpdb; $p=$wpdb->prefix.'pesm_'; $hash=hash('sha256',$token);
        $records=[]; $buffer=''; $start=$offset; $part=-1; $chunk=null; $complete=false;
        while (count($records)<2000 && $offset-$start<2097152) {
            $wanted=intdiv($offset,self::IMPORT_CHUNK);
            if ($wanted!==$part) {
                $chunk=$wpdb->get_var($wpdb->prepare("SELECT data FROM {$p}venue_import_chunks WHERE token_hash=%s AND part_no=%d",$hash,$wanted));
                $part=$wanted;
            }
            if ($chunk===null) { $complete=true; break; }
            $inside=$offset%self::IMPORT_CHUNK;
            if ($inside>=strlen($chunk)) { $complete=true; break; }
            $newline=strpos($chunk,"\n",$inside);
            $take=$newline===false?strlen($chunk)-$inside:$newline-$inside+1;
            $buffer.=substr($chunk,$inside,$take); $offset+=$take;
            if (strlen($buffer)>Document::MAX_RECORD_BYTES) { throw new InvalidArgumentException('Layout record exceeds 1 MiB.'); }
            if ($newline!==false) {
                if (trim($buffer)!=='') { $decoded=json_decode($buffer,true,32,JSON_THROW_ON_ERROR); if (!is_array($decoded)) { throw new InvalidArgumentException('Invalid import record.'); } $records[]=Document::record($decoded); }
                $buffer='';
            }
            if ($offset>Document::MAX_BYTES) { throw new InvalidArgumentException('Layout import exceeds 128 MiB.'); }
            // A short final chunk has no next part; the next iteration observes its end.
        }
        if ($buffer!=='') {
            if ($complete) {
                if (trim($buffer)!=='') { $decoded=json_decode($buffer,true,32,JSON_THROW_ON_ERROR); if (!is_array($decoded)) { throw new InvalidArgumentException('Invalid import record.'); } $records[]=Document::record($decoded); }
            } else { $offset-=strlen($buffer); }
        }
        return ['records'=>$records,'bytes'=>$offset,'complete'=>$complete];
    }

    private static function finish(int $id,string $token,array $cursor,array $result,bool $done): void
    {
        global $wpdb;
        self::begin();
        try { self::lockLease($id,$token); self::saveProgress($id,$token,$cursor,$result,$done); self::commit(); }
        catch (Throwable $error) { $wpdb->query('ROLLBACK'); throw $error; }
        if (!$done) { self::schedule($id); }
    }

    private static function lockLease(int $id,string $token): object
    {
        global $wpdb;
        $job=$wpdb->get_row($wpdb->prepare("SELECT *,UTC_TIMESTAMP() AS db_now FROM {$wpdb->prefix}pesm_venue_jobs WHERE id=%d FOR UPDATE",$id));
        if (!$job||$job->status!=='running'||!hash_equals($job->lease_token,$token)||$job->lease_until<$job->db_now) { throw new RuntimeException('Venue job lease expired.'); }
        return $job;
    }

    private static function saveProgress(int $id,string $token,array $cursor,array $result,bool $done,?array $payload=null): void
    {
        global $wpdb;
        $data=['status'=>$done?'complete':'pending','cursor_json'=>self::encode($cursor),'result_json'=>self::encode($result),
            'lease_token'=>'','lease_until'=>null,'error_message'=>'','updated_at'=>gmdate('Y-m-d H:i:s')];
        if ($payload!==null) { $data['payload']=self::encode($payload); }
        if ($wpdb->update($wpdb->prefix.'pesm_venue_jobs',$data,['id'=>$id,'lease_token'=>$token])!==1) { throw new RuntimeException('Unable to save venue job progress.'); }
    }
    private static function encode(mixed $v): string { return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    private static function json(string $v): array { $d=json_decode($v,true,64,JSON_THROW_ON_ERROR); if (!is_array($d)) { throw new RuntimeException('Invalid stored job data.'); } return $d; }
    private static function write(mixed $result): void { if ($result===false) { throw new RuntimeException('Venue job database write failed.'); } }
    private static function begin(): void { global $wpdb; self::write($wpdb->query('START TRANSACTION')); }
    private static function commit(): void { global $wpdb; self::write($wpdb->query('COMMIT')); }
}
