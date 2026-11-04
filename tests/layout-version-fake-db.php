<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

// Simulated database tests verify call sequencing, transactional rollback and
// immutability rules; they do NOT establish InnoDB correctness.
require __DIR__ . '/../src/Domain/Hall/LayoutDocument.php';
require __DIR__ . '/../src/Domain/Hall/LayoutVersionService.php';
require __DIR__ . '/../src/Domain/Hall/LayoutObjectStorage.php';
require __DIR__ . '/../src/Security/Capabilities.php';
require __DIR__ . '/../src/Support/Audit.php';
use PESM\Domain\Hall\LayoutDocument;
use PESM\Domain\Hall\LayoutVersionService;

const ARRAY_A = 'ARRAY_A';
function user_can(int $id, string $cap): bool { return $id === 7; }
function qa09(bool $success, string $description): void {
    global $checks;
    $checks++;
    if (!$success) { throw new RuntimeException($description); }
}
$checks=0;
final class FakeDb09 {
    public string $prefix='wp_';
    public int $insert_id=0;
    public bool $failBulk=false;
    public array $halls=[1=>['id'=>1,'center_id'=>1,'current_layout_id'=>5]];
    public array $layouts=[5=>['id'=>5,'hall_id'=>1,'version_no'=>1,'status'=>'published','seat_count'=>2]];
    public array $seats=[5=>[
        ['code'=>'A-1','row_label'=>'A','number'=>1,'zone'=>'عمومی','x'=>1,'y'=>2],
        ['code'=>'A-2','row_label'=>'A','number'=>2,'zone'=>'عمومی','x'=>3,'y'=>2],
    ]];
    public array $objects=[];
    public array $sessions=[100=>['layout_id'=>5]];
    public array $audit=[];
    private ?string $snapshot=null;
    public function prepare(string $sql, mixed ...$values): string {
        foreach($values as $v) {
            $formatted=is_int($v) ? (string)$v : "'" . str_replace("'", "''", (string)$v) . "'";
            $sql=preg_replace('/%[ds]/', $formatted, $sql, 1);
        }
        return $sql;
    }
    public function get_row(string $sql): ?object {
        if (str_contains($sql, 'wp_pesm_halls')) {
            preg_match('/id\s*=\s*(\d+)/',$sql,$m);
            return isset($this->halls[(int)($m[1]??0)]) ? (object)$this->halls[(int)$m[1]] : null;
        }
        if (str_contains($sql, 'wp_pesm_layout_versions')) {
            preg_match('/hall_id\s*=\s*(\d+)/',$sql,$h);
            preg_match('/\bid\s*=\s*(\d+)/',$sql,$m);
            $record=$this->layouts[(int)($m[1]??0)]??null;
            return $record && $record['hall_id']===(int)($h[1]??0) ? (object)$record : null;
        }
        return null;
    }
    public function get_results(string $sql, string $format): array {
        preg_match('/layout_id\s*=\s*(\d+)/', $sql, $m);
        if (str_contains($sql,'wp_pesm_layout_objects')) return $this->objects[(int)($m[1]??0)]??[];
        return $this->seats[(int)($m[1]??0)]??[];
    }
    public function get_var(string $sql): int {
        if (str_contains($sql, 'MAX(version_no)')) {
            preg_match('/hall_id\s*=\s*(\d+)/',$sql,$m);
            return max(array_map(static fn($r)=>$r['hall_id']===(int)$m[1] ? $r['version_no'] : 0, $this->layouts));
        }
        if (str_contains($sql, 'COUNT(*)')) {
            preg_match('/layout_id\s*=\s*(\d+)/',$sql,$m);
            return count($this->seats[(int)($m[1]??0)]??[]);
        }
        return 0;
    }
    public function insert(string $table, array $data): int {
        if ($table==='wp_pesm_layout_versions') {
            $this->insert_id=max(array_keys($this->layouts))+1;
            $this->layouts[$this->insert_id]=['id'=>$this->insert_id]+$data;
            $this->seats[$this->insert_id]=[];
        } elseif ($table==='wp_pesm_layout_objects') {
            $id=$data['layout_id'];$this->objects[$id][]=['id'=>$data['object_code'], 'type'=>$data['object_type'],
                'x'=>$data['pos_x'],'y'=>$data['pos_y'],'width'=>$data['width'],'height'=>$data['height'],'label'=>$data['label']];
        } elseif($table==='wp_pesm_audit_logs') { $this->audit[]=$data; }
        else { throw new RuntimeException('Unexpected insert: '. $table); }
        return 1;
    }
    public function update(string $table, array $data, array $where): int {
        if ($table==='wp_pesm_layout_versions') {
            $id=$where['id'];
            if (($this->layouts[$id]['status']??null)!==($where['status']??null)) return 0;
            $this->layouts[$id]=array_replace($this->layouts[$id],$data);
            return 1;
        }
        if ($table==='wp_pesm_halls') {
            $this->halls[$where['id']]=array_replace($this->halls[$where['id']],$data);
            return 1;
        }
        throw new RuntimeException('Unexpected update: '. $table);
    }
    public function query(string $sql): int|false {
        if ($sql==='START TRANSACTION') {
            $this->snapshot=serialize([$this->halls,$this->layouts,$this->seats,$this->objects,$this->audit]); return 1;
        }
        if ($sql==='COMMIT') { $this->snapshot=null; return 1; }
        if ($sql==='ROLLBACK') {
            if ($this->snapshot) [$this->halls,$this->layouts,$this->seats,$this->objects,$this->audit]=unserialize($this->snapshot);
            $this->snapshot=null; return 1;
        }
        if (str_starts_with($sql, 'DELETE FROM wp_pesm_layout_objects')) {
            preg_match('/layout_id\s*=\s*(\d+)/',$sql,$m);$id=(int)($m[1]??0);$this->objects[$id]=[];return 1;
        }
        if (str_starts_with($sql, 'INSERT INTO wp_pesm_seats')) {
            if ($this->failBulk) return false;
            preg_match_all("/\\((\\d+),'([^']+)','([^']+)',(\\d+),'([^']+)',(-?\\d+),(-?\\d+)\\)/u",$sql,$hits,PREG_SET_ORDER);
            foreach ($hits as $r) {
                $this->seats[(int)$r[1]][]=['code'=>$r[2],'row_label'=>$r[3],
                    'number'=>(int)$r[4], 'zone'=>$r[5], 'x'=>(int)$r[6], 'y'=>(int)$r[7]];
            }
            return count($hits);
        }
        throw new RuntimeException('Unexpected SQL: ' . $sql);
    }
}
$wpdb = new FakeDb09();
$service = new LayoutVersionService();
$json = LayoutDocument::encode([
    ['code'=>'B-1','row_label'=>'B','number'=>1,'zone'=>'بالکن','x'=>10,'y'=>40],
    ['code'=>'B-2','row_label'=>'B','number'=>2,'zone'=>'بالکن','x'=>35,'y'=>40],
]);
qa09(str_contains($service->export(1,5,7),'A-1'),'export original');
qa09(count(LayoutDocument::parse($service->export(1,5,7)))===2,'original round trip');
try { $service->export(1,5,8); throw new RuntimeException('Unauthorized read accepted'); }
catch (RuntimeException $e) { qa09(str_contains($e->getMessage(),'مجوز'),'capability denial'); }
$id=$service->importDraft(1,7,$json);
qa09($id===6,'draft has next version');
qa09($wpdb->layouts[6]['status']==='draft','draft not published');
qa09($wpdb->halls[1]['current_layout_id']===5,'current layout unchanged after import');
qa09($wpdb->sessions[100]['layout_id']===5,'existing session unchanged');
qa09(count(LayoutDocument::parse($service->export(1,6,7)))===2,'export draft');
qa09(str_contains($service->export(1,6,7),'بالکن'),'Persian export');
qa09($wpdb->audit[0]['actor_user_id']===7,'audit actor recorded');
$service->publish(1,6,7);
qa09($wpdb->layouts[6]['status']==='published','draft published');
qa09($wpdb->halls[1]['current_layout_id']===6,'future layout changed');
qa09($wpdb->sessions[100]['layout_id']===5,'existing session still immutable');
try { $service->publish(1,6,7); throw new RuntimeException('Double publish accepted'); }
catch(RuntimeException $e) { qa09(str_contains($e->getMessage(),'پیش‌نویس'),'re-publishing rejected'); }
try { $service->importDraft(1,7,'invalid'); throw new RuntimeException('Broken data accepted'); }
catch(InvalidArgumentException $e) { qa09(!isset($wpdb->layouts[7]),'bad JSON never modifies DB'); }
$wpdb->failBulk=true;
try { $service->importDraft(1,7,$json); throw new RuntimeException('Bulk failed but accepted'); }
catch(RuntimeException $e) { qa09(!isset($wpdb->layouts[7]),'bulk failure rolls back new layout'); }
qa09($wpdb->halls[1]['current_layout_id']===6,'bulk failure preserves current layout');
qa09($wpdb->layouts[5]['status']==='published','historical layout preserved');
$wpdb->failBulk=false;
$objects = [['id'=>'STAGE-1','type'=>'stage','x'=>30,'y'=>30,'width'=>90,'height'=>40,'label'=>'سن']];
$objectDoc = LayoutDocument::encodeFull(LayoutDocument::parse($json),$objects);
$newId=$service->importDraft(1,7,$objectDoc);
qa09($newId===7,'objects imported into new draft');
qa09(count($wpdb->objects[7]??[])===1,'objects stored separately from seats');
qa09(LayoutDocument::parseFull($service->export(1,7,7))['objects']===$objects,'export has object metadata');
qa09($wpdb->halls[1]['current_layout_id']===6,'object draft does not modify published layout');
qa09($wpdb->sessions[100]['layout_id']===5,'historic session immutable');
echo "PASS: {$checks} layout version fake DB assertions (not MySQL integration).\n";
