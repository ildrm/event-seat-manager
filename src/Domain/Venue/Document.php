<?php

declare(strict_types=1);

namespace PESM\Domain\Venue;

use InvalidArgumentException;
use RuntimeException;

/** Data-only v2 interchange. JSONL keeps import/export memory independent of venue size. */
final class Document
{
    public const FORMAT = 'pesm.layout.v2';
    public const MAX_BYTES = 134217728;
    public const MAX_RECORD_BYTES = 1048576;
    public const MAX_SEATS = 150000;
    public const MAX_OBJECTS = 10000;
    public const TYPES = ['manifest','section','group','seat','object'];
    public const OBJECT_TYPES = ['stage','runway','field','round_table','rect_table','aisle','door','emergency_exit','label','separator','accessible_area'];

    public static function validate(array $doc): array
    {
        if (($doc['format']??null)!==self::FORMAT) { throw new InvalidArgumentException('Unsupported layout format.'); }
        foreach (['manifest','sections','groups','seats','objects'] as $key) {
            if (!isset($doc[$key]) || !is_array($doc[$key]) || ($key!=='manifest' && !array_is_list($doc[$key]))) {
                throw new InvalidArgumentException('Invalid layout collection: ' . $key);
            }
        }
        $records=(static function () use ($doc): iterable {
            yield ['type'=>'manifest','data'=>$doc['manifest']];
            foreach (['section'=>'sections','group'=>'groups','seat'=>'seats','object'=>'objects'] as $type=>$collection) {
                foreach ($doc[$collection] as $data) { yield ['type'=>$type,'data'=>$data]; }
            }
        })();
        self::validateRecords($records);
        return $doc;
    }

    /** Validates foreign references, exact counts, topology and disjoint group membership. */
    public static function validateRecords(iterable $records): array
    {
        $manifest=null; $sections=[]; $groups=[]; $codes=[]; $objects=[]; $blocks=[]; $tiles=[]; $objectSections=[]; $count=0;
        foreach ($records as $record) {
            $record=self::record($record);
            $d=$record['data'];
            if ($manifest===null && $record['type']!=='manifest') { throw new InvalidArgumentException('Manifest must be the first record.'); }
            switch ($record['type']) {
                case 'manifest':
                    if ($manifest!==null) { throw new InvalidArgumentException('Duplicate manifest.'); }
                    $manifest=$d;
                    if (count(array_unique($d['category_codes']))!==count($d['category_codes'])) { throw new InvalidArgumentException('Duplicate price category.'); }
                    break;
                case 'section':
                    if (isset($sections[$d['code']])) { throw new InvalidArgumentException('Duplicate section code.'); }
                    $sections[$d['code']]=['capacity'=>$d['capacity'],'seen'=>0,'tiles'=>$d['tiles']??null];
                    break;
                case 'group':
                    if (!isset($sections[$d['section_code']]) || isset($groups[$d['code']]) || !in_array($d['category_code'],$manifest['category_codes'],true)) { throw new InvalidArgumentException('Invalid group section, category, or duplicate code.'); }
                    $groups[$d['code']]=['section'=>$d['section_code'],'category'=>$d['category_code'],'capacity'=>$d['capacity'],'seen'=>0];
                    break;
                case 'seat':
                    if (++$count>self::MAX_SEATS || isset($codes[$d['code']]) || !isset($sections[$d['section_code']]) || !in_array($d['category_code'],$manifest['category_codes'],true)) {
                        throw new InvalidArgumentException('Duplicate seat, unknown section, or excessive capacity.');
                    }
                    $codes[$d['code']]=true; $sections[$d['section_code']]['seen']++;
                    $tile=$tiles[$d['tile_code']]??['section'=>$d['section_code'],'count'=>0];
                    if ($tile['section']!==$d['section_code'] || ++$tile['count']>2000) { throw new InvalidArgumentException('A geometry tile must belong to one section and contain at most 2,000 places.'); }
                    $tiles[$d['tile_code']]=$tile;
                    $block=$d['block_code'];
                    if (!isset($blocks[$block])) { $blocks[$block]=['section'=>$d['section_code'],'loop'=>$d['closed_loop'],'positions'=>[]]; }
                    if ($blocks[$block]['section']!==$d['section_code'] || $blocks[$block]['loop']!==$d['closed_loop'] || isset($blocks[$block]['positions'][$d['block_position']])) {
                        throw new InvalidArgumentException('A block must have unique positions in a single section.');
                    }
                    $blocks[$block]['positions'][$d['block_position']]=true;
                    if ($d['group_code']!=='') {
                        $g=$groups[$d['group_code']]??null;
                        if (!$g || $g['section']!==$d['section_code'] || $g['category']!==$d['category_code']) { throw new InvalidArgumentException('Invalid group membership.'); }
                        $groups[$d['group_code']]['seen']++;
                    }
                    break;
                case 'object':
                    if (isset($objects[$d['code']]) || count($objects)>=self::MAX_OBJECTS || ($d['section_code']!=='' && !isset($sections[$d['section_code']]))) { throw new InvalidArgumentException('Invalid object code or section.'); }
                    $objectSections[$d['section_code']]=($objectSections[$d['section_code']]??0)+1;
                    if ($objectSections[$d['section_code']]>1000) { throw new InvalidArgumentException('A section may contain at most 1,000 venue objects.'); }
                    $objects[$d['code']]=true;
                    break;
            }
        }
        if (!$manifest || $count!==$manifest['capacity'] || count($sections)!==$manifest['section_count'] || count($groups)!==$manifest['group_count'] || count($objects)!==$manifest['object_count']) {
            throw new InvalidArgumentException('Layout counts do not match the manifest.');
        }
        foreach (array_merge(array_values($sections),array_values($groups)) as $row) {
            if ($row['capacity']!==$row['seen']) { throw new InvalidArgumentException('Section or group capacity mismatch.'); }
        }
        foreach ($sections as $code=>$section) {
            if ($section['tiles']===null) { continue; }
            if (!is_array($section['tiles'])||!array_is_list($section['tiles'])) { throw new InvalidArgumentException('Section tile manifest must be a list.'); }
            $expected=[]; foreach ($tiles as $tileCode=>$tile) { if ($tile['section']===$code) { $expected[]=$tileCode; } }
            $declared=$section['tiles']; sort($declared); sort($expected);
            if ($declared!==$expected) { throw new InvalidArgumentException('Section tile manifest does not match its places.'); }
        }
        foreach ($blocks as $block) {
            if (min(array_keys($block['positions']))!==0 || max(array_keys($block['positions']))!==count($block['positions'])-1) { throw new InvalidArgumentException('Block positions must be contiguous.'); }
        }
        return ['manifest'=>$manifest,'seat_count'=>$count,'section_count'=>count($sections),'group_count'=>count($groups)];
    }

    /** Validate one record before persistence; cross-record constraints are checked before publication. */
    public static function record(array $record): array
    {
        if (array_diff(array_keys($record),['type','data']) || !isset($record['type'],$record['data']) || !in_array($record['type'],self::TYPES,true) || !is_array($record['data']) || array_is_list($record['data'])) {
            throw new InvalidArgumentException('Invalid layout record.');
        }
        $d=$record['data'];
        if ($record['type']==='manifest') {
            if (($d['format']??null)!==self::FORMAT || ($d['units']??null)!=='mm' || !self::int($d['capacity']??null,1,self::MAX_SEATS) ||
                !self::int($d['section_count']??null,1,200) || !self::int($d['group_count']??null,0,self::MAX_SEATS) || !self::int($d['object_count']??null,0,self::MAX_OBJECTS) ||
                !self::code($d['preset_id']??null) || !self::int($d['preset_version']??null,1,1000000) || !is_array($d['parameters']??null) ||
                !is_array($d['category_codes']??null) || count($d['category_codes'])<1 || count($d['category_codes'])>100) { throw new InvalidArgumentException('Invalid v2 manifest.'); }
            foreach ($d['category_codes'] as $code) { if (!self::code($code)) { throw new InvalidArgumentException('Invalid price category.'); } }
        } elseif ($record['type']==='section') {
            if (!self::code($d['code']??null) || !self::text($d['label']??null,120) || !self::int($d['level']??null,0,100) ||
                !self::int($d['elevation']??null,-1000000,1000000) || !self::int($d['capacity']??null,1,self::MAX_SEATS) || !self::bounds($d['bounds']??null)) { throw new InvalidArgumentException('Invalid section.'); }
        } elseif ($record['type']==='seat') {
            if (!\PESM\Domain\Hall\SeatCode::valid($d['code']??null)) { throw new InvalidArgumentException('Invalid canonical seat code.'); }
            foreach (['code','section_code','block_code','category_code','tile_code'] as $key) { if (!self::code($d[$key]??null,$key==='tile_code'?80:60)) { throw new InvalidArgumentException('Invalid seat identity.'); } }
            if (!is_string($d['group_code']??null) || ($d['group_code']!==''&&!self::code($d['group_code'])) ||
                !self::code($d['row_label']??null,20) || !self::text($d['zone']??null,60) || !self::int($d['number']??null,1,self::MAX_SEATS) ||
                !self::int($d['x']??null,-10000000,10000000) || !self::int($d['y']??null,-10000000,10000000) ||
                !self::int($d['block_position']??null,0,self::MAX_SEATS-1) || !self::int($d['rotation']??null,0,359999) ||
                !self::int($d['elevation']??null,-1000000,1000000) || !is_bool($d['closed_loop']??null) || !is_bool($d['accessible']??null)) { throw new InvalidArgumentException('Invalid seat geometry or topology.'); }
        } elseif ($record['type']==='group') {
            if (!self::code($d['code']??null) || !self::code($d['section_code']??null) || !self::code($d['category_code']??null) ||
                !in_array($d['type']??null,['table','box'],true) || !in_array($d['sale_mode']??null,['individual','whole','both'],true) ||
                !self::int($d['capacity']??null,1,200) || !self::bounds($d)) { throw new InvalidArgumentException('Invalid table or box.'); }
        } else {
            if (!self::code($d['code']??null) || !is_string($d['section_code']??null) || ($d['section_code']!==''&&!self::code($d['section_code'])) ||
                !in_array($d['type']??null,self::OBJECT_TYPES,true) || !self::text($d['label']??null,120) || !self::bounds($d) ||
                !self::int($d['rotation']??null,0,359999) || !self::int($d['elevation']??null,-1000000,1000000)) { throw new InvalidArgumentException('Invalid venue object.'); }
        }
        return $record;
    }

    public static function readJsonLines($stream): iterable
    {
        if (!is_resource($stream)) { throw new InvalidArgumentException('Import requires a readable stream.'); }
        $bytes=0; $line=0; $first=true;
        while (($json=fgets($stream,self::MAX_RECORD_BYTES+2))!==false) {
            $line++; $bytes+=strlen($json);
            if ($bytes>self::MAX_BYTES || strlen($json)>self::MAX_RECORD_BYTES || (!str_ends_with($json,"\n")&&!feof($stream))) { throw new InvalidArgumentException('Layout file or record exceeds its size limit.'); }
            if (trim($json)==='') { continue; }
            try { $record=json_decode($json,true,32,JSON_THROW_ON_ERROR); }
            catch (\JsonException $error) { throw new InvalidArgumentException('Invalid JSON on layout line ' . $line . '.',0,$error); }
            if (!is_array($record)) { throw new InvalidArgumentException('Invalid layout record.'); }
            $record=self::record($record);
            if ($first && $record['type']!=='manifest') { throw new InvalidArgumentException('Manifest must be the first record.'); }
            if (!$first && $record['type']==='manifest') { throw new InvalidArgumentException('Duplicate manifest.'); }
            $first=false;
            yield $record;
        }
        if (!feof($stream)) { throw new RuntimeException('Unable to read layout stream.'); }
        if ($first) { throw new InvalidArgumentException('Layout file is empty.'); }
    }

    public static function writeJsonLines($stream, iterable $records): void
    {
        if (!is_resource($stream)) { throw new InvalidArgumentException('Export requires a writable stream.'); }
        $bytes=0;
        foreach ($records as $record) {
            $json=json_encode(self::record($record),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            $bytes+=strlen($json);
            if (strlen($json)>self::MAX_RECORD_BYTES || $bytes>self::MAX_BYTES) { throw new InvalidArgumentException('Layout export exceeds its size limit.'); }
            $offset=0;
            while ($offset<strlen($json)) {
                $written=fwrite($stream,substr($json,$offset));
                if ($written===false || $written===0) { throw new RuntimeException('Unable to write layout export.'); }
                $offset+=$written;
            }
        }
    }

    public static function code(mixed $value,int $max=60): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,'.($max-1).'}$/D',$value)===1;
    }

    private static function int(mixed $v,int $min,int $max): bool { return is_int($v)&&$v>=$min&&$v<=$max; }
    private static function text(mixed $v,int $max): bool { return is_string($v)&&trim($v)!==''&&strlen($v)<=$max*4&&preg_match('/\A.{1,'.$max.'}\z/usD',$v)===1&&!preg_match('/[\x00-\x1F\x7F]/',$v); }
    private static function bounds(mixed $v): bool
    {
        return is_array($v)&&self::int($v['x']??null,-10000000,10000000)&&self::int($v['y']??null,-10000000,10000000)&&self::int($v['width']??null,1,20000000)&&self::int($v['height']??null,1,20000000);
    }
}
