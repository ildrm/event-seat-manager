<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

require __DIR__ . '/../src/Domain/Hall/LayoutDocument.php';
require __DIR__ . '/../src/Domain/Hall/DraftSaveService.php';
require __DIR__ . '/../src/Domain/Hall/LayoutObjectStorage.php';
require __DIR__ . '/../src/Security/Capabilities.php';
require __DIR__ . '/../src/Support/Audit.php';

use PESM\Domain\Hall\LayoutDocument;
use PESM\Domain\Hall\DraftSaveService;
use PESM\Domain\Hall\DraftConflictException;

function user_can(int $userId, string $cap): bool { return $userId === 7; }
function wp_salt(string $context): string { return 'test-salt-not-production'; }
function qa10db(bool $value, string $reason): void {
    global $checks;
    ++$checks;
    if (!$value) { throw new RuntimeException('FAILED: ' . $reason); }
}
$checks = 0;
final class DraftDbFake
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public bool $failBulk = false;
    public bool $failObjects = false;
    public array $halls = [1 => ['id'=>1,'center_id'=>4]];
    public array $versions = [5=>['id'=>5,'hall_id'=>1,'status'=>'published','version_no'=>1,'revision'=>0,'seat_count'=>2,'editor_request_hash'=>null]];
    public array $seats = [5=>[['code'=>'A-1','row_label'=>'A','number'=>1,'zone'=>'عمومی','x'=>1,'y'=>2],['code'=>'A-2','row_label'=>'A','number'=>2,'zone'=>'عمومی','x'=>4,'y'=>2]]];
    public array $objects=[];
    public array $sessions = [50=>['layout_id'=>5]];
    public array $audit=[];
    private ?string $snapshot=null;
    public function prepare(string $sql, mixed ...$values): string {
        foreach ($values as $value) {
            $replacement = is_int($value) ? (string)$value : "'" . str_replace("'", "''", (string)$value) . "'";
            $sql = preg_replace('/%[ds]/', $replacement, $sql, 1);
        }
        return $sql;
    }
    public function get_row(string $sql): ?object {
        if (str_contains($sql, 'wp_pesm_halls')) {
            preg_match('/\bid\s*=\s*(\d+)/',$sql,$m);
            return isset($this->halls[(int)($m[1]??0)]) ? (object)$this->halls[(int)$m[1]] : null;
        }
        if (str_contains($sql, 'wp_pesm_layout_versions')) {
            preg_match('/hall_id\s*=\s*(\d+)/',$sql,$h);
            if (str_contains($sql, 'editor_request_hash=')) {
                preg_match("/editor_request_hash='([a-f0-9]{64})'/",$sql,$m);
                foreach ($this->versions as $row) if ($row['hall_id']===(int)($h[1]??0) && ($row['editor_request_hash']??null)===($m[1]??null)) return (object)$row;
                return null;
            }
            preg_match('/\bid\s*=\s*(\d+)/',$sql,$m);
            $row=$this->versions[(int)($m[1]??0)]??null;
            return $row && $row['hall_id']===(int)($h[1]??0) ? (object)$row : null;
        }
        throw new RuntimeException('Unexpected get_row SQL: '.$sql);
    }
    public function get_var(string $sql): int {
        if (str_contains($sql, 'MAX(version_no)')) return max(array_column($this->versions,'version_no'));
        if (str_contains($sql, 'wp_pesm_sessions')) {
            preg_match('/layout_id\s*=\s*(\d+)/',$sql,$m);
            return count(array_filter($this->sessions,static fn($row)=>$row['layout_id']===(int)($m[1]??0)));
        }
        throw new RuntimeException('Unexpected get_var SQL: '.$sql);
    }
    public function insert(string $table, array $data): int {
        if ($table==='wp_pesm_layout_versions') {
            $this->insert_id=max(array_keys($this->versions))+1;
            $this->versions[$this->insert_id]=['id'=>$this->insert_id]+$data;
            $this->seats[$this->insert_id]=[];
        } elseif ($table==='wp_pesm_layout_objects') {
            if ($this->failObjects) return 0;
            $this->objects[$data['layout_id']][]=['id'=>$data['object_code'],'type'=>$data['object_type'],
               'x'=>$data['pos_x'],'y'=>$data['pos_y'],'width'=>$data['width'],
               'height'=>$data['height'],'label'=>$data['label']];
        } elseif ($table==='wp_pesm_audit_logs') { $this->audit[]=$data; }
        else throw new RuntimeException('Unexpected insert table: '.$table);
        return 1;
    }
    public function update(string $table, array $data, array $where): int {
        if ($table!=='wp_pesm_layout_versions') throw new RuntimeException('Unexpected update table');
        $id=$where['id']; $row=$this->versions[$id]??null;
        if (!$row) return 0;
        foreach ($where as $key=>$expected) if (($row[$key]??null)!==$expected) return 0;
        $this->versions[$id]=array_replace($row,$data);
        return 1;
    }
    public function query(string $sql): int|false {
        if ($sql==='START TRANSACTION') { $this->snapshot=serialize([$this->versions,$this->seats,$this->objects,$this->audit]); return 1; }
        if ($sql==='COMMIT') { $this->snapshot=null; return 1; }
        if ($sql==='ROLLBACK') {
            if ($this->snapshot!==null) [$this->versions,$this->seats,$this->objects,$this->audit]=unserialize($this->snapshot);
            $this->snapshot=null;return 1;
        }
        if (str_starts_with($sql,'DELETE FROM wp_pesm_layout_objects')) {
            preg_match('/layout_id\s*=\s*(\d+)/',$sql,$m);$this->objects[(int)($m[1]??0)]=[];return 1;
        }
        if (str_starts_with($sql,'DELETE FROM wp_pesm_seats')) {
            preg_match('/layout_id\s*=\s*(\d+)/',$sql,$m);
            $id=(int)($m[1]??0); $old=count($this->seats[$id]??[]);$this->seats[$id]=[];return $old;
        }
        if (str_starts_with($sql,'INSERT INTO wp_pesm_seats')) {
            if ($this->failBulk) return false;
            preg_match_all("/\((\d+),'([^']+)','([^']+)',(\d+),'([^']+)',(-?\d+),(-?\d+)\)/u",$sql,$matches,PREG_SET_ORDER);
            foreach ($matches as $hit) $this->seats[(int)$hit[1]][] = ['code'=>$hit[2],'row_label'=>$hit[3],'number'=>(int)$hit[4], 'zone'=>$hit[5],'x'=>(int)$hit[6],'y'=>(int)$hit[7]];
            return count($matches);
        }
        throw new RuntimeException('Unexpected query SQL: '.$sql);
    }
}
$wpdb = new DraftDbFake();
$service = new DraftSaveService();
$first = LayoutDocument::encode([
    ['code'=>'B-1','row_label'=>'B','number'=>1,'zone'=>'بالکن','x'=>1,'y'=>10],
    ['code'=>'B-2','row_label'=>'B','number'=>2,'zone'=>'بالکن','x'=>4,'y'=>10],
]);
$second = LayoutDocument::encode([
    ['code'=>'C-1','row_label'=>'C','number'=>1,'zone'=>'بالکن','x'=>20,'y'=>30],
]);
$key=str_repeat('a',48);
$created=$service->save(1,5,7,0,$key,$first);
qa10db($created['layout_id']===6 && $created['revision']===1 && $created['created'], 'published creates draft');
qa10db($wpdb->versions[5]['status']==='published' && count($wpdb->seats[5])===2,'published unchanged');
qa10db($wpdb->versions[6]['status']==='draft' && count($wpdb->seats[6])===2,'draft populated');
qa10db($wpdb->sessions[50]['layout_id']===5,'old session untouched');
$replayed=$service->save(1,5,7,0,$key,$first);
qa10db($replayed['layout_id']===6 && $replayed['revision']===1,'duplicate create returns same draft');
qa10db(count($wpdb->seats[6])===2 && count($wpdb->audit)===1,'duplicate does not overwrite or audit');
try { $service->save(1,5,7,0,$key,$second);throw new RuntimeException('Changed replay accepted'); }
catch (DraftConflictException $e) { qa10db(count($wpdb->seats[6])===2,'changed replay rejected'); }
$updated=$service->save(1,6,7,1,str_repeat('b',48),$second);
qa10db($updated['layout_id']===6 && $updated['revision']===2 && !$updated['created'],'revision increased');
qa10db(count($wpdb->seats[6])===1 && $wpdb->seats[6][0]['code']==='C-1','replacement atomic');
try { $service->save(1,6,7,1,str_repeat('c',48),$first);throw new RuntimeException('Stale revision accepted'); }
catch (DraftConflictException $e) { qa10db($wpdb->versions[6]['revision']===2 && count($wpdb->seats[6])===1,'stale revision rejected'); }
try { $service->save(1,5,8,0,str_repeat('d',48),$first);throw new RuntimeException('Unauth allowed'); }
catch (RuntimeException $e) { qa10db(count($wpdb->versions)===2,'permission checked'); }
try { $service->save(1,6,7,2,str_repeat('e',48),'not json');throw new RuntimeException('Invalid accepted'); }
catch (InvalidArgumentException $e) { qa10db($wpdb->versions[6]['revision']===2,'invalid payload rejected before changes'); }
$wpdb->sessions[51]=['layout_id'=>6];
try { $service->save(1,6,7,2,str_repeat('f',48),$first);throw new RuntimeException('In-use draft changed'); }
catch (DraftConflictException $e) { qa10db(count($wpdb->seats[6])===1,'in-use draft immutable'); }
unset($wpdb->sessions[51]);
$wpdb->failBulk=true;
try { $service->save(1,6,7,2,str_repeat('1',48),$first);throw new RuntimeException('Bulk failure accepted'); }
catch (RuntimeException $e) {
    qa10db($wpdb->versions[6]['revision']===2,'write failure revision rolled back');
    qa10db(count($wpdb->seats[6])===1,'write failure seat data rolled back');
}
qa10db(count($wpdb->audit)===2,'only successful mutations audited');
$wpdb->failBulk=false;
$ob=[['id'=>'DOOR-1','type'=>'door','x'=>10,'y'=>10,'width'=>60,'height'=>20,'label'=>'درب اصلی']];
$doc=LayoutDocument::encodeFull(LayoutDocument::parse($second),$ob);
$next=$service->save(1,6,7,2,str_repeat('2',48),$doc);
qa10db($next['revision']===3,'objects draft revision');
qa10db($wpdb->objects[6]===$ob,'objects saved atomically');
$wpdb->failObjects=true;
$invalid=LayoutDocument::encodeFull(LayoutDocument::parse($first),$ob);
try {$service->save(1,6,7,3,str_repeat('3',48),$invalid);throw new RuntimeException('object insert failure accepted');}
catch (RuntimeException $e) {
    qa10db($wpdb->versions[6]['revision']===3,'object write failure revision rollback');
    qa10db($wpdb->objects[6]===$ob,'object write failure preserves objects');
    qa10db($wpdb->seats[6][0]['code']==='C-1','object write failure preserves seat data');
}
echo "PASS: {$checks} draft autosave fake-DB assertions (not InnoDB integration).\n";
