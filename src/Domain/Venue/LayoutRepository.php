<?php

declare(strict_types=1);

namespace PESM\Domain\Venue;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Persistence adapter; geometry is immutable after publication, inventory lives elsewhere. */
final class LayoutRepository
{
    public static function overview(int $layoutId): array
    {
        global $wpdb; $p=$wpdb->prefix.'pesm_';
        $layout=self::layout($layoutId);
        $manifest=self::json($layout->manifest);
        $sections=[]; $tiles=[];
        foreach (self::rows($wpdb->prepare("SELECT * FROM {$p}venue_sections WHERE layout_id=%d ORDER BY id",$layoutId)) as $row) {
            $s=self::json($row['geometry_json']);
            $s['code']=$row['section_code']; $s['section_code']=$row['section_code'];
            $sections[]=$s;
        }
        foreach (self::rows($wpdb->prepare("SELECT tile_code,section_code,content_hash,payload FROM {$p}venue_tiles WHERE layout_id=%d ORDER BY id",$layoutId)) as $row) {
            $summary=self::json($row['payload']);
            $tiles[]=['code'=>$row['tile_code'],'tile_code'=>$row['tile_code'],'section_code'=>$row['section_code'],
                'count'=>(int)($summary['count']??0),'bounds'=>$summary['bounds']??null,'content_hash'=>$row['content_hash']];
        }
        return ['format'=>Document::FORMAT,'layout_id'=>$layoutId,'revision'=>(int)$layout->revision,
            'status'=>$layout->status,'preset_id'=>$layout->preset_id,'capacity'=>(int)($manifest['capacity']??0),
            'units'=>'mm','sections'=>$sections,'tiles'=>$tiles,'parameters'=>self::json($layout->parameters),
            'categories'=>array_map(static fn($code)=>['code'=>$code,'label'=>$code],$manifest['category_codes']??[]),
            'objects'=>self::objects($layoutId,''),'content_hash'=>$layout->content_hash];
    }

    public static function tile(int $layoutId,string $tileCode): array
    {
        if (!Document::code($tileCode,80)) { throw new InvalidArgumentException('Invalid tile code.'); }
        global $wpdb; $p=$wpdb->prefix.'pesm_'; $layout=self::layout($layoutId);
        $tile=$wpdb->get_row($wpdb->prepare("SELECT section_code FROM {$p}venue_tiles WHERE layout_id=%d AND tile_code=%s",$layoutId,$tileCode));
        if (!$tile) { throw new RuntimeException('Geometry tile not found.'); }
        $seats=self::seats($layoutId,'v.tile_code=%s',[$tileCode],2001);
        if (count($seats)>2000) { throw new RuntimeException('Geometry tile exceeds its record limit.'); }
        $groups=[]; $codes=[];
        foreach ($seats as $seat) { if ($seat['group_code']!=='') { $codes[$seat['group_code']]=true; } }
        if ($codes) {
            $marks=implode(',',array_fill(0,count($codes),'%s'));
            foreach (self::rows($wpdb->prepare("SELECT * FROM {$p}venue_groups WHERE layout_id=%d AND group_code IN ({$marks}) ORDER BY id",$layoutId,...array_keys($codes))) as $row) {
                $g=self::json($row['geometry_json']); $g['id']=$row['group_code']; $g['group_code']=$row['group_code'];
                $g['sale_mode']=$row['sale_mode']; $g['category_code']=$row['category_code']; $groups[]=$g;
            }
        }
        return ['layout_id'=>$layoutId,'revision'=>(int)$layout->revision,'tile_code'=>$tileCode,
            'section_code'=>$tile->section_code,'seats'=>$seats,'groups'=>$groups,'objects'=>self::objects($layoutId,$tile->section_code)];
    }

    /** Search/list uses keysets, never OFFSET over a complete venue. */
    public static function units(int $layoutId,array $filters=[]): array
    {
        self::layout($layoutId);
        global $wpdb; $p=$wpdb->prefix.'pesm_';
        $limit=$filters['limit']??50;
        if (!is_int($limit) && !(is_string($limit)&&ctype_digit($limit))) { throw new InvalidArgumentException('Invalid list size.'); }
        $limit=(int)$limit;
        if ($limit<1 || $limit>100) { throw new InvalidArgumentException('List size must be between 1 and 100.'); }
        $type=$filters['type']??'seat';
        if (!in_array($type,['seat','table','box','all'],true)) { throw new InvalidArgumentException('Invalid purchase-unit type.'); }
        $cursor=(string)($filters['cursor']??'0:0');
        if (!preg_match('/^([012]):([0-9]{1,20})$/D',$cursor,$match)) { throw new InvalidArgumentException('Invalid inventory cursor.'); }
        $cursorKind=(int)$match[1]; $cursorId=(int)$match[2];
        $section=(string)($filters['section_code']??$filters['section']??'');
        $category=(string)($filters['category_code']??$filters['category']??'');
        $row=(string)($filters['row']??''); $q=trim((string)($filters['q']??$filters['search']??''));
        foreach ([$section,$category,$row] as $code) { if ($code!==''&&!Document::code($code)) { throw new InvalidArgumentException('Invalid inventory filter.'); } }
        if (strlen($q)>240 || preg_match('/[\x00-\x1F\x7F]/',$q)) { throw new InvalidArgumentException('Invalid search text.'); }
        $items=[];
        if ($type!=='seat' && $cursorKind<=1) {
            $where=['g.layout_id=%d','g.id>%d','g.sale_mode<>\'individual\'']; $args=[$layoutId,$cursorKind===1?$cursorId:0];
            if ($type!=='all') { $where[]='g.group_type=%s'; $args[]=$type; }
            foreach (['section_code'=>$section,'category_code'=>$category] as $key=>$value) { if ($value!=='') { $where[]='g.'.$key.'=%s'; $args[]=$value; } }
            if ($q!=='') { $where[]='g.group_code LIKE %s'; $args[]='%'.$wpdb->esc_like($q).'%'; }
            $sql="SELECT g.*, (SELECT MIN(v.tile_code) FROM {$p}venue_places v WHERE v.layout_id=g.layout_id AND v.group_code=g.group_code) AS tile_code FROM {$p}venue_groups g WHERE ".implode(' AND ',$where).' ORDER BY g.id LIMIT '.($limit+1);
            foreach (self::rows($wpdb->prepare($sql,...$args)) as $g) {
                $geometry=self::json($g['geometry_json']);
                $items[]=['id'=>$g['group_code'],'unit_id'=>$g['group_code'],'code'=>$g['group_code'],'type'=>$g['group_type'],
                    'section_code'=>$g['section_code'],'category_code'=>$g['category_code'],'capacity'=>(int)$g['capacity'],
                    'group_code'=>$g['group_code'],'sale_mode'=>$g['sale_mode'],'tile_code'=>$g['tile_code'],'row_label'=>'','number'=>0,
                    'x'=>$geometry['x'],'y'=>$geometry['y'],'_cursor'=>'1:'.$g['id']];
            }
        }
        if (($type==='seat'||$type==='all') && count($items)<=$limit) {
            $where=['v.layout_id=%d','v.seat_id>%d',"(v.group_code='' OR g.sale_mode<>'whole')"];
            $args=[$layoutId,$cursorKind===2?$cursorId:0];
            foreach (['section_code'=>$section,'category_code'=>$category] as $key=>$value) { if ($value!=='') { $where[]='v.'.$key.'=%s'; $args[]=$value; } }
            if ($row!=='') { $where[]='s.row_label=%s'; $args[]=$row; }
            if ($q!=='') { $where[]='s.seat_code LIKE %s'; $args[]='%'.$wpdb->esc_like($q).'%'; }
            $sql="SELECT s.id,s.seat_code,s.row_label,s.seat_number,s.pos_x,s.pos_y,v.section_code,v.category_code,v.group_code,v.tile_code FROM {$p}venue_places v JOIN {$p}seats s ON s.id=v.seat_id LEFT JOIN {$p}venue_groups g ON g.layout_id=v.layout_id AND g.group_code=v.group_code WHERE ".implode(' AND ',$where).' ORDER BY v.seat_id LIMIT '.($limit+1-count($items));
            foreach (self::rows($wpdb->prepare($sql,...$args)) as $s) {
                $items[]=['id'=>(int)$s['id'],'unit_id'=>(int)$s['id'],'code'=>$s['seat_code'],'type'=>'seat',
                    'section_code'=>$s['section_code'],'category_code'=>$s['category_code'],'capacity'=>1,
                    'group_code'=>$s['group_code'],'row_label'=>$s['row_label'],'number'=>(int)$s['seat_number'],
                    'x'=>(int)$s['pos_x'],'y'=>(int)$s['pos_y'],'tile_code'=>$s['tile_code'],'_cursor'=>'2:'.$s['id']];
            }
        }
        $hasMore=count($items)>$limit; $items=array_slice($items,0,$limit);
        $next=$hasMore?end($items)['_cursor']:null;
        foreach ($items as &$item) { unset($item['_cursor']); } unset($item);
        return ['items'=>$items,'next_cursor'=>$next];
    }

    public static function locate(int $layoutId,string $code): array
    {
        if (!Document::code($code)) { throw new InvalidArgumentException('Invalid seat or group code.'); }
        self::layout($layoutId);
        global $wpdb; $p=$wpdb->prefix.'pesm_';
        $row=$wpdb->get_row($wpdb->prepare("SELECT s.id,s.seat_code AS code,v.section_code,v.tile_code FROM {$p}seats s JOIN {$p}venue_places v ON v.seat_id=s.id WHERE v.layout_id=%d AND (s.seat_code=%s OR v.group_code=%s) ORDER BY s.id LIMIT 1",$layoutId,$code,$code),ARRAY_A);
        if (!$row) { throw new RuntimeException('Seat or group not found.'); }
        $row['id']=(int)$row['id']; return $row;
    }

    public static function commands(int $layoutId,int $revision,string $requestKey,array $operations): array
    {
        if ($revision<0 || !preg_match('/^[A-Za-z0-9_-]{32,128}$/D',$requestKey) || !array_is_list($operations) || !$operations || count($operations)>500) { throw new InvalidArgumentException('Invalid revisioned edit request.'); }
        global $wpdb; $p=$wpdb->prefix.'pesm_';
        $hash=hash('sha256',$requestKey); $payloadHash=hash('sha256',self::encode([$revision,$operations]));
        self::begin();
        try {
            $layout=self::layout($layoutId,true);
            $previous=$wpdb->get_row($wpdb->prepare("SELECT payload_hash,result_json FROM {$p}venue_commands WHERE layout_id=%d AND request_hash=%s",$layoutId,$hash));
            if ($previous) {
                if (!hash_equals($previous->payload_hash,$payloadHash)) { throw new RuntimeException('Edit request key was already used for different content.'); }
                $result=self::json($previous->result_json); self::commit(); return $result;
            }
            if ($layout->status!=='draft' || (int)$layout->revision!==$revision || (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}sessions WHERE layout_id=%d",$layoutId))>0) { throw new RuntimeException('Layout conflict: reload the current editable draft.'); }
            $changed=[]; $total=0;
            foreach ($operations as $op) {
                if (!is_array($op) || !isset($op['op'])) { throw new InvalidArgumentException('Invalid layout operation.'); }
                if ($op['op']==='object') { self::objectCommand($layoutId,$op); continue; }
                if ($op['op']==='group_mode') {
                    if (!Document::code($op['group_code']??null)||!in_array($op['sale_mode']??null,['individual','whole','both'],true)) { throw new InvalidArgumentException('Invalid group sales mode.'); }
                    if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}venue_groups WHERE layout_id=%d AND group_code=%s",$layoutId,$op['group_code']))) { throw new InvalidArgumentException('Table or box does not belong to this layout.'); }
                    self::write($wpdb->update($p.'venue_groups',['sale_mode'=>$op['sale_mode']],['layout_id'=>$layoutId,'group_code'=>$op['group_code']])); continue;
                }
                if (in_array($op['op'],['move_group','group_category'],true)) {
                    if (!Document::code($op['group_code']??null)) { throw new InvalidArgumentException('Invalid group identity.'); }
                    $group=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}venue_groups WHERE layout_id=%d AND group_code=%s FOR UPDATE",$layoutId,$op['group_code']));
                    if (!$group) { throw new InvalidArgumentException('Table or box does not belong to this layout.'); }
                    $members=self::seats($layoutId,'v.group_code=%s',[$op['group_code']],201);
                    $total+=count($members);
                    if (!$members||count($members)>200||count($members)!==(int)$group->capacity||$total>5000) { throw new InvalidArgumentException('Invalid group capacity or edit batch size.'); }
                    $geometry=self::json($group->geometry_json);
                    $geometry['category_code']=$group->category_code; $geometry['sale_mode']=$group->sale_mode;
                    if ($op['op']==='move_group') {
                        $dx=$op['dx']??0; $dy=$op['dy']??0;
                        if (!is_int($dx)||!is_int($dy)) { throw new InvalidArgumentException('Group movement uses integer millimetres.'); }
                        $geometry['x']+=$dx; $geometry['y']+=$dy;
                        Document::record(['type'=>'group','data'=>$geometry]);
                        foreach ($members as $member) {
                            $member['x']+=$dx; $member['y']+=$dy;
                            Document::record(['type'=>'seat','data'=>$member]);
                        }
                        self::write($wpdb->query($wpdb->prepare("UPDATE {$p}seats s JOIN {$p}venue_places v ON v.seat_id=s.id SET s.pos_x=s.pos_x+%d,s.pos_y=s.pos_y+%d WHERE v.layout_id=%d AND v.group_code=%s",$dx,$dy,$layoutId,$op['group_code'])));
                    } else {
                        if (!Document::code($op['category_code']??null)) { throw new InvalidArgumentException('Invalid group category.'); }
                        $geometry['category_code']=$op['category_code'];
                        self::write($wpdb->query($wpdb->prepare("UPDATE {$p}venue_places SET category_code=%s WHERE layout_id=%d AND group_code=%s",$op['category_code'],$layoutId,$op['group_code'])));
                        self::write($wpdb->query($wpdb->prepare("UPDATE {$p}seats s JOIN {$p}venue_places v ON v.seat_id=s.id SET s.zone_label=%s WHERE v.layout_id=%d AND v.group_code=%s",$op['category_code'],$layoutId,$op['group_code'])));
                    }
                    self::write($wpdb->update($p.'venue_groups',['geometry_json'=>self::encode($geometry),'category_code'=>$geometry['category_code']],['id'=>(int)$group->id]));
                    $changed[$group->section_code]=true; continue;
                }
                if (!in_array($op['op'],['move','category','accessibility'],true)) { throw new InvalidArgumentException('Unsupported layout operation.'); }
                $ids=$op['seat_ids']??[];
                if (!is_array($ids)||!array_is_list($ids)||!$ids||count($ids)>2000) { throw new InvalidArgumentException('An edit must identify 1–2,000 places.'); }
                foreach ($ids as $id) { if (!is_int($id)||$id<1) { throw new InvalidArgumentException('Invalid place identity.'); } }
                $ids=array_values(array_unique($ids)); $total+=count($ids);
                if ($total>5000) { throw new InvalidArgumentException('Edit affects too many places; split it into batches.'); }
                $marks=implode(',',array_fill(0,count($ids),'%d'));
                $seats=self::seats($layoutId,"v.seat_id IN ({$marks})",$ids,2000);
                if (count($seats)!==count($ids)) { throw new InvalidArgumentException('A place does not belong to this layout.'); }
                foreach ($seats as $seat) {
                    $data=[]; $meta=[];
                    if ($op['op']==='move') {
                        foreach (['x','y'] as $axis) {
                            $delta=$op['d'.$axis]??0;
                            if (!is_int($delta) || (isset($op[$axis])&&!is_int($op[$axis]))) { throw new InvalidArgumentException('Move coordinates must be integer millimetres.'); }
                            $position=$op[$axis]??($seat[$axis]+$delta);
                            if ($position < -10000000 || $position > 10000000 || (count($ids)>1&&isset($op[$axis]))) { throw new InvalidArgumentException('Use a relative move for multiple places.'); }
                            $data['pos_'.$axis]=$position;
                        }
                    } elseif ($op['op']==='category') {
                        if (!Document::code($op['category_code']??null)) { throw new InvalidArgumentException('Invalid category.'); }
                        // A whole group's price class must never disagree with its member places.
                        if ($seat['group_code']!=='') {
                            $members=self::rows($wpdb->prepare("SELECT seat_id FROM {$p}venue_places WHERE layout_id=%d AND group_code=%s LIMIT 201",$layoutId,$seat['group_code']));
                            if (count($members)>200) { throw new RuntimeException('Invalid group capacity.'); }
                            foreach ($members as $member) {
                                self::write($wpdb->update($p.'venue_places',['category_code'=>$op['category_code']],['seat_id'=>(int)$member['seat_id']]));
                                self::write($wpdb->update($p.'seats',['zone_label'=>$op['category_code']],['id'=>(int)$member['seat_id'],'layout_id'=>$layoutId]));
                            }
                            self::write($wpdb->update($p.'venue_groups',['category_code'=>$op['category_code']],['layout_id'=>$layoutId,'group_code'=>$seat['group_code']]));
                        }
                        $meta['category_code']=$op['category_code']; $data['zone_label']=$op['category_code'];
                    } else {
                        if (!is_bool($op['accessible']??null)) { throw new InvalidArgumentException('Accessibility must be a boolean.'); }
                        $meta['accessible']=(int)$op['accessible'];
                    }
                    if ($data) { self::write($wpdb->update($p.'seats',$data,['id'=>$seat['id'],'layout_id'=>$layoutId])); }
                    if ($meta) { self::write($wpdb->update($p.'venue_places',$meta,['seat_id'=>$seat['id'],'layout_id'=>$layoutId])); }
                    $changed[$seat['section_code']]=true;
                }
            }
            foreach (array_keys($changed) as $section) { self::refreshSectionBounds($layoutId,$section); }
            $newRevision=$revision+1;
            self::write($wpdb->update($p.'venue_layouts',['revision'=>$newRevision,'content_hash'=>''],['layout_id'=>$layoutId,'revision'=>$revision]));
            self::write($wpdb->update($p.'layout_versions',['revision'=>$newRevision],['id'=>$layoutId]));
            $result=['layout_id'=>$layoutId,'revision'=>$newRevision,'changed_sections'=>array_keys($changed)];
            if ($wpdb->insert($p.'venue_commands',['layout_id'=>$layoutId,'request_hash'=>$hash,'payload_hash'=>$payloadHash,'result_json'=>self::encode($result)])!==1) { throw new RuntimeException('Unable to save edit receipt.'); }
            self::commit(); return $result;
        } catch (Throwable $error) { $wpdb->query('ROLLBACK'); throw $error; }
    }

    public static function createDraft(int $hallId,string $presetId,array $parameters,array $manifest): int
    {
        global $wpdb; $p=$wpdb->prefix.'pesm_';
        $hall=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$p}halls WHERE id=%d FOR UPDATE",$hallId));
        if (!$hall) { throw new RuntimeException('Hall not found.'); }
        $version=1+(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(version_no),0) FROM {$p}layout_versions WHERE hall_id=%d",$hallId));
        if ($wpdb->insert($p.'layout_versions',['hall_id'=>$hallId,'version_no'=>$version,'format'=>Document::FORMAT,'status'=>'draft','revision'=>0,'seat_count'=>0,'created_at'=>gmdate('Y-m-d H:i:s')])!==1) { throw new RuntimeException('Unable to create venue draft.'); }
        $id=(int)$wpdb->insert_id;
        if ($wpdb->insert($p.'venue_layouts',['layout_id'=>$id,'preset_id'=>$presetId,'preset_version'=>(int)$manifest['preset_version'],
            'parameters'=>self::encode($parameters),'manifest'=>self::encode($manifest),'content_hash'=>'','status'=>'building','revision'=>0])!==1) { throw new RuntimeException('Unable to create venue manifest.'); }
        return $id;
    }

    /** Called inside the durable job transaction, alongside its cursor update. */
    public static function storeRecords(int $layoutId,array $records): void
    {
        global $wpdb; $p=$wpdb->prefix.'pesm_';
        if (count($records)>2000) { throw new InvalidArgumentException('Generation batch exceeds 2,000 records.'); }
        $seatRecords=[];
        foreach ($records as $record) {
            $record=Document::record($record); $d=$record['data'];
            if ($record['type']==='seat') { $seatRecords[]=$d; continue; }
            if ($record['type']==='manifest') { continue; }
            if ($record['type']==='section') {
                $data=['layout_id'=>$layoutId,'section_code'=>$d['code'],'label'=>$d['label'],'level_number'=>$d['level'],
                    'elevation'=>$d['elevation'],'bounds_json'=>self::encode($d['bounds']),'geometry_json'=>self::encode($d)];
                $table='venue_sections';
            } elseif ($record['type']==='group') {
                $data=['layout_id'=>$layoutId,'group_code'=>$d['code'],'section_code'=>$d['section_code'],'group_type'=>$d['type'],
                    'category_code'=>$d['category_code'],'capacity'=>$d['capacity'],'sale_mode'=>$d['sale_mode'],'geometry_json'=>self::encode($d)];
                $table='venue_groups';
            } else {
                $data=['layout_id'=>$layoutId,'object_code'=>$d['code'],'section_code'=>$d['section_code'],'payload'=>self::encode($d)]; $table='venue_objects';
            }
            if ($wpdb->insert($p.$table,$data)!==1) { throw new RuntimeException('Unable to persist venue record.'); }
        }
        foreach (array_chunk($seatRecords,400) as $chunk) {
            $rows=[];
            foreach ($chunk as $s) { $rows[]=[$layoutId,$s['code'],$s['row_label'],$s['number'],$s['zone'],$s['x'],$s['y']]; }
            self::insertMany($p.'seats',['layout_id','seat_code','row_label','seat_number','zone_label','pos_x','pos_y'],['%d','%s','%s','%d','%s','%d','%d'],$rows);
            $codes=array_column($chunk,'code'); $marks=implode(',',array_fill(0,count($codes),'%s'));
            $ids=[];
            foreach (self::rows($wpdb->prepare("SELECT id,seat_code FROM {$p}seats WHERE layout_id=%d AND seat_code IN ({$marks})",$layoutId,...$codes)) as $s) { $ids[$s['seat_code']]=(int)$s['id']; }
            $rows=[];
            foreach ($chunk as $s) {
                if (!isset($ids[$s['code']])) { throw new RuntimeException('Seat identity could not be recovered.'); }
                $rows[]=[$ids[$s['code']],$layoutId,$s['section_code'],$s['block_code'],$s['block_position'],(int)$s['closed_loop'],
                    $s['group_code'],$s['category_code'],$s['rotation'],$s['elevation'],(int)$s['accessible'],$s['tile_code']];
            }
            self::insertMany($p.'venue_places',['seat_id','layout_id','section_code','block_code','block_position','closed_loop','group_code','category_code','rotation','elevation','accessible','tile_code'],
                ['%d','%d','%s','%s','%d','%d','%s','%s','%d','%d','%d','%s'],$rows);
        }
    }

    public static function finishBuild(int $layoutId): void
    {
        global $wpdb; $p=$wpdb->prefix.'pesm_';
        $result=self::validateStored($layoutId);
        // Tile manifests contain summaries only. Detailed tiles are projected from canonical records.
        $tiles=self::rows($wpdb->prepare("SELECT v.tile_code,v.section_code,COUNT(*) AS n,MIN(s.pos_x) AS x,MIN(s.pos_y) AS y,MAX(s.pos_x)-MIN(s.pos_x)+800 AS width,MAX(s.pos_y)-MIN(s.pos_y)+800 AS height FROM {$p}venue_places v JOIN {$p}seats s ON s.id=v.seat_id WHERE v.layout_id=%d GROUP BY v.tile_code,v.section_code ORDER BY v.tile_code",$layoutId));
        $groupBounds=self::groupBounds($layoutId,null,true);
        foreach ($tiles as $tile) {
            if ((int)$tile['n']>2000) { throw new RuntimeException('A tile contains too many places.'); }
            $summary=['count'=>(int)$tile['n'],'bounds'=>['x'=>(int)$tile['x']-400,'y'=>(int)$tile['y']-400,'width'=>(int)$tile['width'],'height'=>(int)$tile['height']]];
            $summary['bounds']=self::mergeBounds($summary['bounds'],$groupBounds[$tile['tile_code']]??null);
            if ($wpdb->insert($p.'venue_tiles',['layout_id'=>$layoutId,'section_code'=>$tile['section_code'],'tile_code'=>$tile['tile_code'],
                'payload'=>self::encode($summary),'content_hash'=>hash('sha256',self::encode([$layoutId,$tile['tile_code'],$result['content_hash']]))])!==1) { throw new RuntimeException('Unable to build geometry tile manifest.'); }
        }
        self::write($wpdb->update($p.'venue_layouts',['status'=>'draft','content_hash'=>$result['content_hash']],['layout_id'=>$layoutId,'status'=>'building']));
        self::write($wpdb->update($p.'layout_versions',['seat_count'=>$result['seat_count']],['id'=>$layoutId,'status'=>'draft']));
    }

    public static function publish(int $layoutId): array
    {
        global $wpdb; $p=$wpdb->prefix.'pesm_';
        $info=$wpdb->get_row($wpdb->prepare("SELECT hall_id FROM {$p}layout_versions WHERE id=%d",$layoutId));
        if (!$info) { throw new RuntimeException('Venue layout not found.'); }
        self::begin();
        try {
            $hall=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$p}halls WHERE id=%d FOR UPDATE",(int)$info->hall_id));
            $layout=self::layout($layoutId,true);
            if (!$hall || !in_array($layout->status,['draft','published'],true)) { throw new RuntimeException('Only a completed draft can be published.'); }
            if ($layout->status==='published') { self::commit(); return ['layout_id'=>$layoutId,'status'=>'published']; }
            $result=self::validateStored($layoutId);
            self::write($wpdb->update($p.'venue_layouts',['status'=>'published','content_hash'=>$result['content_hash']],['layout_id'=>$layoutId]));
            self::write($wpdb->update($p.'layout_versions',['status'=>'published'],['id'=>$layoutId,'status'=>'draft']));
            self::write($wpdb->update($p.'halls',['current_layout_id'=>$layoutId],['id'=>(int)$info->hall_id]));
            if (class_exists(\PESM\Support\Audit::class)) { \PESM\Support\Audit::record('venue_published','layout',$layoutId); }
            self::commit(); return ['layout_id'=>$layoutId,'status'=>'published','content_hash'=>$result['content_hash']];
        } catch (Throwable $error) { $wpdb->query('ROLLBACK'); throw $error; }
    }

    public static function export(int $layoutId,$stream): void { Document::writeJsonLines($stream,self::records($layoutId)); }

    public static function records(int $layoutId): iterable
    {
        global $wpdb; $p=$wpdb->prefix.'pesm_'; $layout=self::layout($layoutId);
        $manifest=self::json($layout->manifest);
        // Edits can introduce categories or remove/add objects; counts are persisted on every edit.
        yield ['type'=>'manifest','data'=>$manifest];
        foreach (self::rows($wpdb->prepare("SELECT geometry_json FROM {$p}venue_sections WHERE layout_id=%d ORDER BY id",$layoutId)) as $s) { yield ['type'=>'section','data'=>self::json($s['geometry_json'])]; }
        $cursor=0;
        do {
            $rows=self::rows($wpdb->prepare("SELECT * FROM {$p}venue_groups WHERE layout_id=%d AND id>%d ORDER BY id LIMIT 2000",$layoutId,$cursor));
            foreach ($rows as $g) { $d=self::json($g['geometry_json']); $d['category_code']=$g['category_code']; $d['sale_mode']=$g['sale_mode']; $cursor=(int)$g['id']; yield ['type'=>'group','data'=>$d]; }
        } while (count($rows)===2000);
        $cursor=0;
        do {
            $rows=self::seats($layoutId,'v.seat_id>%d',[$cursor],2000);
            foreach ($rows as $s) { $cursor=$s['id']; unset($s['id'],$s['seat_id']); yield ['type'=>'seat','data'=>$s]; }
        } while (count($rows)===2000);
        $cursor=0;
        do {
            $rows=self::rows($wpdb->prepare("SELECT id,payload FROM {$p}venue_objects WHERE layout_id=%d AND id>%d ORDER BY id LIMIT 1000",$layoutId,$cursor));
            foreach ($rows as $o) { $cursor=(int)$o['id']; yield ['type'=>'object','data'=>self::json($o['payload'])]; }
        } while (count($rows)===1000);
    }

    public static function validateStored(int $layoutId): array
    {
        $hash=hash_init('sha256');
        $records=(static function () use ($layoutId,$hash): iterable {
            foreach (self::records($layoutId) as $record) { hash_update($hash,self::encode($record)."\n"); yield $record; }
        })();
        $result=Document::validateRecords($records); $result['content_hash']=hash_final($hash); return $result;
    }

    private static function seats(int $layoutId,string $where,array $args,int $limit): array
    {
        global $wpdb; $p=$wpdb->prefix.'pesm_';
        $rows=self::rows($wpdb->prepare("SELECT s.id,s.seat_code,s.row_label,s.seat_number,s.zone_label,s.pos_x,s.pos_y,v.* FROM {$p}venue_places v JOIN {$p}seats s ON s.id=v.seat_id WHERE v.layout_id=%d AND {$where} ORDER BY v.seat_id LIMIT ".max(1,min(2001,$limit)),$layoutId,...$args));
        return array_map(static function ($s): array {
            return ['id'=>(int)$s['seat_id'],'seat_id'=>(int)$s['seat_id'],'code'=>$s['seat_code'],'row_label'=>$s['row_label'],'number'=>(int)$s['seat_number'],
                'zone'=>$s['zone_label'],'x'=>(int)$s['pos_x'],'y'=>(int)$s['pos_y'],'section_code'=>$s['section_code'],
                'block_code'=>$s['block_code'],'block_position'=>(int)$s['block_position'],'closed_loop'=>(bool)$s['closed_loop'],
                'group_code'=>$s['group_code'],'category_code'=>$s['category_code'],'rotation'=>(int)$s['rotation'],
                'elevation'=>(int)$s['elevation'],'accessible'=>(bool)$s['accessible'],'tile_code'=>$s['tile_code']];
        },$rows);
    }

    private static function objects(int $layoutId,string $section): array
    {
        global $wpdb; $p=$wpdb->prefix.'pesm_';
        return array_map(static function ($row) { $o=self::json($row['payload']); $o['id']=$o['code']; return $o; },
            self::rows($wpdb->prepare("SELECT payload FROM {$p}venue_objects WHERE layout_id=%d AND section_code=%s ORDER BY id LIMIT 1000",$layoutId,$section)));
    }

    private static function objectCommand(int $layoutId,array $operation): void
    {
        global $wpdb; $p=$wpdb->prefix.'pesm_'; $o=$operation['object']??null;
        if (!is_array($o)) { throw new InvalidArgumentException('Invalid venue object edit.'); }
        $code=$o['code']??$o['id']??null;
        if (!Document::code($code)) { throw new InvalidArgumentException('Invalid object identity.'); }
        if (($operation['delete']??false)===true) { self::write($wpdb->delete($p.'venue_objects',['layout_id'=>$layoutId,'object_code'=>$code])); }
        else {
            unset($o['id']); $o['code']=$code; $o+=['section_code'=>'','rotation'=>0,'elevation'=>0];
            Document::record(['type'=>'object','data'=>$o]);
            if ($o['section_code']!==''&&!$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$p}venue_sections WHERE layout_id=%d AND section_code=%s",$layoutId,$o['section_code']))) { throw new InvalidArgumentException('Object section does not exist.'); }
            $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}venue_objects WHERE layout_id=%d AND object_code=%s",$layoutId,$code));
            $sectionCount=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}venue_objects WHERE layout_id=%d AND section_code=%s AND object_code<>%s",$layoutId,$o['section_code'],$code));
            if ($sectionCount>=1000) { throw new InvalidArgumentException('A section may contain at most 1,000 venue objects.'); }
            $data=['section_code'=>$o['section_code'],'payload'=>self::encode($o)];
            if ($exists) { self::write($wpdb->update($p.'venue_objects',$data,['id'=>(int)$exists])); }
            else {
                $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}venue_objects WHERE layout_id=%d",$layoutId));
                if ($count>=Document::MAX_OBJECTS || $wpdb->insert($p.'venue_objects',$data+['layout_id'=>$layoutId,'object_code'=>$code])!==1) { throw new RuntimeException('Unable to create venue object.'); }
            }
        }
        $layout=self::layout($layoutId); $manifest=self::json($layout->manifest);
        $manifest['object_count']=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}venue_objects WHERE layout_id=%d",$layoutId));
        self::write($wpdb->update($p.'venue_layouts',['manifest'=>self::encode($manifest)],['layout_id'=>$layoutId]));
    }

    private static function refreshSectionBounds(int $layoutId,string $section): void
    {
        global $wpdb; $p=$wpdb->prefix.'pesm_';
        $bounds=$wpdb->get_row($wpdb->prepare("SELECT MIN(s.pos_x)-400 AS x,MIN(s.pos_y)-400 AS y,MAX(s.pos_x)-MIN(s.pos_x)+800 AS width,MAX(s.pos_y)-MIN(s.pos_y)+800 AS height FROM {$p}venue_places v JOIN {$p}seats s ON s.id=v.seat_id WHERE v.layout_id=%d AND v.section_code=%s",$layoutId,$section),ARRAY_A);
        $row=$wpdb->get_row($wpdb->prepare("SELECT geometry_json FROM {$p}venue_sections WHERE layout_id=%d AND section_code=%s",$layoutId,$section));
        if (!$bounds||!$row) { throw new RuntimeException('Unable to update section bounds.'); }
        $bounds=self::mergeBounds(array_map('intval',$bounds),self::groupBounds($layoutId,$section)); $geometry=self::json($row->geometry_json); $geometry['bounds']=$bounds;
        self::write($wpdb->update($p.'venue_sections',['bounds_json'=>self::encode($bounds),'geometry_json'=>self::encode($geometry)],['layout_id'=>$layoutId,'section_code'=>$section]));
        $layout=self::layout($layoutId); $manifest=self::json($layout->manifest);
        $tiles=self::rows($wpdb->prepare("SELECT v.tile_code,COUNT(*) AS n,MIN(s.pos_x)-400 AS x,MIN(s.pos_y)-400 AS y,MAX(s.pos_x)-MIN(s.pos_x)+800 AS width,MAX(s.pos_y)-MIN(s.pos_y)+800 AS height FROM {$p}venue_places v JOIN {$p}seats s ON s.id=v.seat_id WHERE v.layout_id=%d AND v.section_code=%s GROUP BY v.tile_code",$layoutId,$section));
        $groupBounds=self::groupBounds($layoutId,$section,true);
        foreach ($tiles as $tile) {
            $summary=['count'=>(int)$tile['n'],'bounds'=>['x'=>(int)$tile['x'],'y'=>(int)$tile['y'],'width'=>(int)$tile['width'],'height'=>(int)$tile['height']]];
            $summary['bounds']=self::mergeBounds($summary['bounds'],$groupBounds[$tile['tile_code']]??null);
            self::write($wpdb->update($p.'venue_tiles',['payload'=>self::encode($summary),'content_hash'=>hash('sha256',$layoutId.':'.$tile['tile_code'].':'.((int)$layout->revision+1))],['layout_id'=>$layoutId,'tile_code'=>$tile['tile_code']]));
        }
        $categories=self::rows($wpdb->prepare("SELECT DISTINCT category_code FROM {$p}venue_places WHERE layout_id=%d LIMIT 101",$layoutId));
        if (count($categories)>100) { throw new InvalidArgumentException('At most 100 categories are supported.'); }
        $manifest['category_codes']=array_column($categories,'category_code');
        self::write($wpdb->update($p.'venue_layouts',['manifest'=>self::encode($manifest)],['layout_id'=>$layoutId]));
    }

    private static function layout(int $id,bool $lock=false): object
    {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pesm_venue_layouts WHERE layout_id=%d".($lock?' FOR UPDATE':''),$id));
        if (!$row) { throw new RuntimeException('Venue layout not found.'); }
        return $row;
    }

    /** Group surfaces can extend beyond their member-chair centres (notably paired pods). */
    private static function groupBounds(int $layoutId,?string $section=null,bool $byTile=false): array
    {
        global $wpdb; $p=$wpdb->prefix.'pesm_';
        $x="CAST(JSON_UNQUOTE(JSON_EXTRACT(g.geometry_json,'$.x')) AS SIGNED)";
        $y="CAST(JSON_UNQUOTE(JSON_EXTRACT(g.geometry_json,'$.y')) AS SIGNED)";
        $w="CAST(JSON_UNQUOTE(JSON_EXTRACT(g.geometry_json,'$.width')) AS SIGNED)";
        $h="CAST(JSON_UNQUOTE(JSON_EXTRACT(g.geometry_json,'$.height')) AS SIGNED)";
        $select=$byTile?'v.tile_code,':'';
        $join=$byTile?" JOIN {$p}venue_places v ON v.layout_id=g.layout_id AND v.group_code=g.group_code":'';
        $where=$section===null?'':' AND g.section_code=%s'; $args=[$layoutId]; if ($section!==null) { $args[]=$section; }
        $rows=self::rows($wpdb->prepare("SELECT {$select}MIN({$x}) AS x,MIN({$y}) AS y,MAX({$x}+{$w})-MIN({$x}) AS width,MAX({$y}+{$h})-MIN({$y}) AS height FROM {$p}venue_groups g{$join} WHERE g.layout_id=%d{$where}".($byTile?' GROUP BY v.tile_code':''),...$args));
        if (!$byTile) { return !$rows||$rows[0]['x']===null?[]:array_map('intval',$rows[0]); }
        $out=[];
        foreach ($rows as $row) { $code=$row['tile_code']; unset($row['tile_code']); $out[$code]=array_map('intval',$row); }
        return $out;
    }

    private static function mergeBounds(array $a,?array $b): array
    {
        if (!$b) { return $a; }
        $x=min($a['x'],$b['x']); $y=min($a['y'],$b['y']);
        return ['x'=>$x,'y'=>$y,'width'=>max($a['x']+$a['width'],$b['x']+$b['width'])-$x,'height'=>max($a['y']+$a['height'],$b['y']+$b['height'])-$y];
    }

    private static function insertMany(string $table,array $columns,array $formats,array $rows): void
    {
        if (!$rows) { return; }
        global $wpdb; $args=[]; $pattern='('.implode(',',$formats).')';
        foreach ($rows as $row) { array_push($args,...$row); }
        $result=$wpdb->query($wpdb->prepare('INSERT INTO '.$table.' ('.implode(',',$columns).') VALUES '.implode(',',array_fill(0,count($rows),$pattern)),...$args));
        if ($result!==count($rows)) { throw new RuntimeException('Venue batch persistence was incomplete.'); }
    }
    private static function rows(string $sql): array { global $wpdb; $rows=$wpdb->get_results($sql,ARRAY_A); if (!is_array($rows)||$wpdb->last_error) { throw new RuntimeException('Venue database read failed.'); } return $rows; }
    private static function json(string $json): array { $data=json_decode($json,true,64,JSON_THROW_ON_ERROR); if (!is_array($data)) { throw new RuntimeException('Stored venue data is invalid.'); } return $data; }
    private static function encode(mixed $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    private static function write(mixed $result): void { if ($result===false) { throw new RuntimeException('Venue database write failed.'); } }
    private static function begin(): void { global $wpdb; self::write($wpdb->query('START TRANSACTION')); }
    private static function commit(): void { global $wpdb; self::write($wpdb->query('COMMIT')); }
}
