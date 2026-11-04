<?php

declare(strict_types=1);

namespace PESM\Domain\Venue;

use InvalidArgumentException;

/** Deterministic generators: section-local row topology is independent of coordinates. */
final class Generator
{
    public static function parameters(string $presetId, array $input): array
    {
        $preset = PresetCatalog::get($presetId);
        if (array_diff(array_keys($input), array_keys($preset['defaults']))) {
            throw new InvalidArgumentException('Unsupported arrangement parameter.');
        }
        $p = array_replace($preset['defaults'], $input);
        foreach (['capacity'=>[1,150000],'rows'=>[1,150000],'columns'=>[1,1000],
            'sections'=>[1,200],'levels'=>[1,8],'spacing'=>[450,3000],'row_spacing'=>[600,5000],
            'aisle_width'=>[900,10000],'blocks'=>[1,8],'group_size'=>[1,200],'group_count'=>[1,150000],
            'min_group_size'=>[1,200],'accessible_every'=>[0,1000]] as $key=>[$min,$max]) {
            if (!is_int($p[$key]) || $p[$key] < $min || $p[$key] > $max) {
                throw new InvalidArgumentException('Invalid arrangement parameter: ' . $key);
            }
        }
        if (!in_array($p['workflow'], ['exact','advanced'], true) ||
            !in_array($p['sale_mode'], ['individual','whole','both'], true) || !is_bool($p['fixed_group_size'])) {
            throw new InvalidArgumentException('Invalid arrangement workflow or sale mode.');
        }
        if ($p['min_group_size'] > $p['group_size']) { throw new InvalidArgumentException('Minimum group size exceeds preferred size.'); }
        if ($p['workflow'] === 'advanced') {
            $p['capacity'] = self::grouped($preset,0) ? $p['group_count']*$p['group_size']*$p['sections'] : $p['rows'] * $p['columns'] * $p['sections'];
            if ($preset['variant']==='mixed_tables' && !$p['fixed_group_size']) {
                $pattern=[max($p['min_group_size'],max(1,intdiv($p['group_size'],3))),max($p['min_group_size'],max(1,intdiv(2*$p['group_size'],3))),$p['group_size']];
                $perSection=intdiv($p['group_count'],3)*array_sum($pattern);
                for ($g=0;$g<$p['group_count']%3;$g++) { $perSection+=$pattern[$g]; }
                $p['capacity']=$perSection*$p['sections'];
            }
            if ($p['capacity'] > PresetCatalog::MAX_CAPACITY) { throw new InvalidArgumentException('Arrangement exceeds 150,000 places.'); }
        }
        // Omitted recipe defaults can adapt to a smaller venue; an explicit arrangement must be honored or rejected.
        foreach (['sections'=>'capacity','levels'=>'sections','blocks'=>'columns'] as $key=>$bound) {
            if ($p[$key] <= $p[$bound]) { continue; }
            if (array_key_exists($key,$input)) {
                throw new InvalidArgumentException('Requested '.$key.' exceed '.$bound.'; use at most '.$p[$bound].' '.$key.' or at least '.$p[$key].' '.$bound.'.');
            }
            $p[$key]=$p[$bound];
        }
        return $p;
    }

    /** A small plan can be persisted as a job cursor; no full-venue seat array is needed. */
    public static function plan(string $presetId, array $input): array
    {
        $preset = PresetCatalog::get($presetId);
        $p = self::parameters($presetId, $input);
        $sections = [];
        $start = 0;
        $groupStart = 0;
        $counts=[];
        for ($i=0;$i<$p['sections'];$i++) { $counts[]=intdiv($p['capacity'],$p['sections'])+($i<$p['capacity']%$p['sections']?1:0); }
        if ($p['fixed_group_size']) {
            $groupedIndices=[]; $plainIndices=[];
            foreach ($counts as $i=>$count) { if (self::grouped($preset,$i%$p['levels'])) { $groupedIndices[]=$i; } else { $plainIndices[]=$i; } }
            if ($groupedIndices && !$plainIndices) {
                self::groupSizes($p['capacity'],$p['group_size'],$p['min_group_size'],true);
                $units=intdiv($p['capacity'],$p['group_size']);
                if ($units<$p['sections']) { throw new InvalidArgumentException('There are fewer fixed-size groups than sections; nearest feasible capacity: '.($p['sections']*$p['group_size']).'.'); }
                foreach ($counts as $i=>$count) { $counts[$i]=(intdiv($units,$p['sections'])+($i<$units%$p['sections']?1:0))*$p['group_size']; }
            } elseif ($groupedIndices) {
                $balance=0;
                foreach ($groupedIndices as $i) { $rounded=max($p['group_size'],intdiv($counts[$i],$p['group_size'])*$p['group_size']); $balance+=$counts[$i]-$rounded; $counts[$i]=$rounded; }
                for ($i=0;$balance>0;$i++,$balance--) { $counts[$plainIndices[$i%count($plainIndices)]]++; }
                while ($balance<0) {
                    $changed=false;
                    foreach ($plainIndices as $i) { if ($counts[$i]>1) { $counts[$i]--; $balance++; $changed=true; if ($balance===0) { break; } } }
                    if (!$changed) { throw new InvalidArgumentException('Capacity cannot fit fixed-size groups in every configured section; nearest feasible capacity: '.(count($groupedIndices)*$p['group_size']+count($plainIndices)).'.'); }
                }
            }
        }
        for ($i = 0; $i < $p['sections']; $i++) {
            $count = $counts[$i];
            $level = $i % $p['levels'];
            $code = sprintf('S%03d', $i + 1);
            $grouped = self::grouped($preset, $level);
            $sizes = $grouped ? self::groupSizes($count, $p['group_size'], $p['min_group_size'], $p['fixed_group_size']) : [0,0,0];
            $section = ['code'=>$code,'label'=>'Section ' . ($i + 1),'level'=>$level,'elevation'=>$level * 5000,
                'capacity'=>$count,'start'=>$start,'group_start'=>$groupStart,'group_count'=>$sizes[0],
                'last_group_size'=>$sizes[1],'penultimate_group_size'=>$sizes[2],
                'category_code'=>$level > 0 ? 'PREMIUM' : 'STANDARD','index'=>$i,'origin_x'=>0];
            if (!empty($sizes[3])) { $section['tail_sizes']=$sizes[3]; }
            if ($preset['variant']==='mixed_tables' && !$p['fixed_group_size']) {
                $section['mixed_sizes']=[]; $section['mixed_offsets']=[]; $remaining=$count; $offset=0; $g=0;
                $pattern=[max(1,intdiv($p['group_size'],3)),max(1,intdiv(2*$p['group_size'],3)),$p['group_size']];
                while ($remaining>0) {
                    $size=min($remaining,max($p['min_group_size'],$pattern[$g%3]));
                    $section['mixed_offsets'][]=$offset; $section['mixed_sizes'][]=$size;
                    $offset+=$size; $remaining-=$size; $g++;
                }
                if (end($section['mixed_sizes'])<$p['min_group_size']) {
                    $last=count($section['mixed_sizes'])-1; $need=$p['min_group_size']-$section['mixed_sizes'][$last];
                    $slack=0; for ($donor=0;$donor<$last;$donor++) { $slack+=$section['mixed_sizes'][$donor]-$p['min_group_size']; }
                    if ($slack>=$need) {
                        for ($donor=$last-1;$need>0;$donor--) {
                            $take=min($need,$section['mixed_sizes'][$donor]-$p['min_group_size']);
                            $section['mixed_sizes'][$donor]-=$take; $section['mixed_sizes'][$last]+=$take; $need-=$take;
                        }
                    } else {
                        $remaining=array_pop($section['mixed_sizes']);
                        for ($donor=count($section['mixed_sizes'])-1;$remaining>0&&$donor>=0;$donor--) {
                            $take=min($remaining,$p['group_size']-$section['mixed_sizes'][$donor]);
                            $section['mixed_sizes'][$donor]+=$take; $remaining-=$take;
                        }
                        if ($remaining>0) {
                            $g=(int)ceil($count/$p['group_size']);
                            $near=array_filter([($g-1)*$p['group_size'],$g*$p['min_group_size']],static fn($n)=>$n>0);
                            throw new InvalidArgumentException('Capacity cannot fit the minimum group size; nearest counts: '.implode(', ',$near).'.');
                        }
                    }
                    $section['mixed_offsets']=[]; $offset=0;
                    foreach ($section['mixed_sizes'] as $size) { $section['mixed_offsets'][]=$offset; $offset+=$size; }
                }
                $section['group_count']=count($section['mixed_sizes']);
            }
            // Bounded arithmetic work; only this section's extrema are retained.
            $bounds = null;
            for ($j = 0; $j < $count; $j++) {
                $seat = self::place($preset, $p, $section, $j);
                $bounds = self::expand($bounds, $seat['x'] - 400, $seat['y'] - 400, 800, 800);
            }
            for ($g=0;$g<$section['group_count'];$g++) {
                $group=self::group($preset,$p,$section,$g);
                $bounds=self::expand($bounds,$group['x'],$group['y'],$group['width'],$group['height']);
            }
            $section['bounds'] = $bounds;
            $section['tiles'] = [];
            for ($t=0; $t<(int) ceil($count/2000); $t++) { $section['tiles'][] = sprintf('%s-T%04d', $code, $t+1); }
            $sections[] = $section;
            $start += $count;
            $groupStart += $section['group_count'];
        }
        if ($preset['generator']!=='bowl') {
            // Pack independent floor sections by measured bounds; levels may share a footprint.
            $cursor=0;
            for ($slot=0;$slot<(int)ceil(count($sections)/$p['levels']);$slot++) {
                $indices=range($slot*$p['levels'],min(count($sections)-1,($slot+1)*$p['levels']-1));
                $left=min(array_map(static fn($i)=>$sections[$i]['bounds']['x'],$indices));
                $right=max(array_map(static fn($i)=>$sections[$i]['bounds']['x']+$sections[$i]['bounds']['width'],$indices));
                $origin=$slot===0?0:$cursor-$left;
                foreach ($indices as $i) { $sections[$i]['origin_x']=$origin; $sections[$i]['bounds']['x']+=$origin; }
                $cursor=$right+$origin+max(3000,$p['aisle_width']);
            }
        }
        return ['preset'=>$preset,'parameters'=>$p,'sections'=>$sections,'group_count'=>$groupStart,
            'manifest'=>['format'=>Document::FORMAT,'preset_id'=>$presetId,'preset_version'=>$preset['version'],
                'units'=>'mm','capacity'=>$p['capacity'],'parameters'=>$p,'category_codes'=>['STANDARD','PREMIUM'],
                'section_count'=>count($sections),'group_count'=>$groupStart,'object_count'=>count(self::objects($preset,$p))]];
    }

    /** @return iterable<array{type:string,data:array}> */
    public static function records(string $presetId, array $input): iterable
    {
        $plan = self::plan($presetId, $input);
        $total = self::recordCount($plan);
        for ($i=0; $i<$total; $i++) { yield self::record($plan, $i); }
    }

    public static function recordCount(array $plan): int
    {
        return 1 + count($plan['sections']) + $plan['group_count'] + $plan['parameters']['capacity'] + $plan['manifest']['object_count'];
    }

    public static function batch(array $plan, int $offset, int $limit = 2000): array
    {
        if ($offset < 0 || $limit < 1 || $limit > 2000) { throw new InvalidArgumentException('Invalid generation cursor.'); }
        $out = [];
        for ($i=$offset; $i<min(self::recordCount($plan), $offset+$limit); $i++) { $out[] = self::record($plan,$i); }
        return $out;
    }

    public static function generate(string $presetId, array $input): array
    {
        $doc = ['format'=>Document::FORMAT,'manifest'=>[],'sections'=>[],'groups'=>[],'seats'=>[],'objects'=>[]];
        foreach (self::records($presetId,$input) as $record) {
            $key = ['manifest'=>'manifest','section'=>'sections','group'=>'groups','seat'=>'seats','object'=>'objects'][$record['type']];
            if ($key==='manifest') { $doc[$key]=$record['data']; } else { $doc[$key][]=$record['data']; }
        }
        return Document::validate($doc);
    }

    private static function record(array $plan, int $index): array
    {
        if ($index===0) { return ['type'=>'manifest','data'=>$plan['manifest']]; }
        $index--;
        if ($index<count($plan['sections'])) {
            $s=$plan['sections'][$index];
            return ['type'=>'section','data'=>array_intersect_key($s,array_flip(['code','label','level','elevation','capacity','bounds','tiles']))];
        }
        $index-=count($plan['sections']);
        if ($index<$plan['group_count']) {
            foreach ($plan['sections'] as $s) {
                if ($index>=$s['group_start'] && $index<$s['group_start']+$s['group_count']) {
                    return ['type'=>'group','data'=>self::group($plan['preset'],$plan['parameters'],$s,$index-$s['group_start'])];
                }
            }
        }
        $index-=$plan['group_count'];
        if ($index<$plan['parameters']['capacity']) {
            foreach ($plan['sections'] as $s) {
                if ($index>=$s['start'] && $index<$s['start']+$s['capacity']) {
                    return ['type'=>'seat','data'=>self::place($plan['preset'],$plan['parameters'],$s,$index-$s['start'])];
                }
            }
        }
        $index-=$plan['parameters']['capacity'];
        $objects=self::objects($plan['preset'],$plan['parameters']);
        if (!isset($objects[$index])) { throw new InvalidArgumentException('Invalid generation record.'); }
        return ['type'=>'object','data'=>$objects[$index]];
    }

    private static function grouped(array $preset, int $level): bool
    {
        return in_array($preset['generator'], ['table','table_rect','booth'],true) || ($preset['variant']==='boxes' && $level===2);
    }

    private static function groupSizes(int $count,int $preferred,int $minimum,bool $fixed): array
    {
        if ($fixed && $count%$preferred!==0) {
            $near=array_filter([intdiv($count,$preferred)*$preferred,(intdiv($count,$preferred)+1)*$preferred],static fn($n)=>$n>0);
            throw new InvalidArgumentException('Fixed group size cannot fit this capacity; nearest counts: '.implode(', ',$near).'.');
        }
        $groups=(int)ceil($count/$preferred); $last=$count-($groups-1)*$preferred; $previous=$preferred;
        if ($last<$minimum) {
            $need=$minimum-$last;
            if ($count<$groups*$minimum) {
                $near=array_filter([($groups-1)*$preferred,$groups*$minimum],static fn($n)=>$n>0);
                throw new InvalidArgumentException('Capacity cannot fit the minimum group size; nearest counts: '.implode(', ',$near).'.');
            }
            if ($preferred-$need<$minimum) {
                $tail=[$minimum]; $remaining=$need;
                for ($g=$groups-2;$remaining>0&&$g>=0;$g--) {
                    $take=min($preferred-$minimum,$remaining); array_unshift($tail,$preferred-$take); $remaining-=$take;
                }
                return [$groups,$minimum,$tail[count($tail)-2]??$minimum,$tail];
            }
            $previous-=$need; $last=$minimum;
        }
        return [$groups,$last,$previous];
    }

    private static function groupInfo(array $p,array $s,int $seat): array
    {
        if (isset($s['tail_sizes'])) {
            $full=$s['group_count']-count($s['tail_sizes']); $start=$full*$p['group_size'];
            if ($seat<$start) { return [intdiv($seat,$p['group_size']),$seat%$p['group_size'],$p['group_size']]; }
            foreach ($s['tail_sizes'] as $g=>$size) { if ($seat<$start+$size) { return [$full+$g,$seat-$start,$size]; } $start+=$size; }
        }
        if (isset($s['mixed_sizes'])) {
            $lo=0; $hi=count($s['mixed_offsets'])-1;
            while ($lo<$hi) { $mid=(int)ceil(($lo+$hi)/2); if ($s['mixed_offsets'][$mid]<=$seat) { $lo=$mid; } else { $hi=$mid-1; } }
            return [$lo,$seat-$s['mixed_offsets'][$lo],$s['mixed_sizes'][$lo]];
        }
        $regular=max(0,$s['group_count']-2)*$p['group_size'];
        if ($s['group_count']===1) { return [0,$seat,$s['last_group_size']]; }
        if ($seat<$regular) { return [intdiv($seat,$p['group_size']),$seat%$p['group_size'],$p['group_size']]; }
        if ($seat<$regular+$s['penultimate_group_size']) { return [$s['group_count']-2,$seat-$regular,$s['penultimate_group_size']]; }
        return [$s['group_count']-1,$seat-$regular-$s['penultimate_group_size'],$s['last_group_size']];
    }

    private static function group(array $preset,array $p,array $s,int $g): array
    {
        $size=$s['mixed_sizes'][$g]??($g===$s['group_count']-1 ? $s['last_group_size'] : ($g===$s['group_count']-2 ? $s['penultimate_group_size'] : $p['group_size']));
        if (isset($s['tail_sizes'])&&$g>=$s['group_count']-count($s['tail_sizes'])) { $size=$s['tail_sizes'][$g-($s['group_count']-count($s['tail_sizes']))]; }
        [$width,$height]=self::groupDimensions($preset,$p,$size);
        [$cellWidth,$cellHeight]=self::groupDimensions($preset,$p,$p['group_size']);
        $columns=max(1,(int)ceil(sqrt(max(1,$s['group_count']))));
        $x=(int)round(($g%$columns)*($cellWidth+$p['aisle_width'])+($cellWidth-$width)/2)+self::offsetX($s,$p);
        $y=(int)round(intdiv($g,$columns)*($cellHeight+$p['row_spacing'])+($cellHeight-$height)/2);
        if ($preset['variant']==='dining_aisle' && $g%$columns>=intdiv($columns,2)) { $x+=3000; }
        if ($preset['variant']==='boxes') {
            $columns=min(10,$columns); $span=2*M_PI/max(1,(int)ceil($p['sections']/$p['levels']));
            $radius=max(30000,$columns*(hypot($cellWidth,$cellHeight)+$p['aisle_width'])/$span)+intdiv($g,$columns)*(hypot($cellWidth,$cellHeight)+$p['row_spacing']);
            $angle=intdiv($s['index'],$p['levels'])*$span-$span/2+($g%$columns+0.5)*$span/$columns;
            $x=(int)round($radius*sin($angle)-$width/2); $y=(int)round(-$radius*cos($angle)-$height/2);
        }
        return ['code'=>sprintf('%s-G%05d',$s['code'],$g+1),'section_code'=>$s['code'],
            'type'=>$preset['category']==='cinema' || $preset['variant']==='boxes' ? 'box' : 'table',
            'category_code'=>$s['category_code'],'capacity'=>$size,'sale_mode'=>$p['sale_mode'],
            'x'=>$x,'y'=>$y,'width'=>$width,'height'=>$height,'shape'=>in_array($preset['generator'],['table_rect','booth'],true)||in_array($preset['category'],['cinema','stadium'],true)?'rectangle':'ellipse'];
    }

    private static function groupDimensions(array $preset,array $p,int $size): array
    {
        if ($preset['category']==='cinema') { return [max(1200,($size+1)*$p['spacing']),1200]; }
        if ($preset['generator']==='table_rect'||$preset['variant']==='boxes') { return [max(2400,((int)ceil($size/2)+1)*$p['spacing']),2400]; }
        if ($size===1) { return [max(900,$p['spacing']),max(900,$p['spacing'])]; }
        $angle=$preset['generator']==='booth'?M_PI/$size:($preset['variant']==='cabaret'?min(1.5*M_PI/($size-1),M_PI/2):2*M_PI/$size);
        $radius=(int)ceil($p['spacing']/(2*sin($angle/2)));
        return [$radius*2,$radius*2];
    }

    private static function place(array $preset,array $p,array $s,int $i): array
    {
        $grouped=self::grouped($preset,$s['level']);
        $groupCode=''; $loop=false; $rotation=0;
        $row=intdiv($i,$p['columns']); $column=$i%$p['columns'];
        $rowLength=min($p['columns'],$s['capacity']-$row*$p['columns']);
        $block=(int)floor($column*$p['blocks']/max(1,$p['columns']));
        $blockStart=(int)ceil($block*$p['columns']/$p['blocks']);
        $x=(int)round(($column-($rowLength-1)/2)*$p['spacing'])+$block*$p['aisle_width'];
        $y=$row*$p['row_spacing']; $position=$column-$blockStart;
        $blockCode=sprintf('%s-R%05d-B%d',$s['code'],$row+1,$block+1);
        if ($grouped) {
            [$g,$member,$size]=self::groupInfo($p,$s,$i); $group=self::group($preset,$p,$s,$g);
            $groupCode=$group['code']; $blockCode=$groupCode; $position=$member; $loop=true;
            $angle=2*M_PI*$member/max(1,$size);
            if ($preset['generator']==='booth') {
                $angle=M_PI+M_PI*($member+0.5)/max(1,$size);
                $x=(int)round($group['x']+$group['width']/2+$group['width']/2*cos($angle));
                $y=(int)round($group['y']+$group['height']/2+$group['height']/2*sin($angle));
                $rotation=(int)round(fmod(rad2deg($angle)+270,360)*1000); $loop=false;
            } elseif ($preset['category']==='cinema') {
                $x=$group['x']+($member+1)*$p['spacing']; $y=$group['y']+$group['height']; $loop=false;
            } elseif ($group['shape']==='rectangle') {
                $half=(int)ceil($size/2); $side=$member<$half?0:1; $slot=$side===0?$member:$member-$half;
                $x=$group['x']+($slot+1)*$p['spacing']; $y=$group['y']+($side===0?0:$group['height']); $rotation=$side===0?180000:0;
            } else {
                if ($preset['variant']==='cabaret') { $angle=M_PI/4+1.5*M_PI*$member/max(1,$size-1); $loop=false; }
                $x=(int)round($group['x']+$group['width']/2+($group['width']/2)*cos($angle));
                $y=(int)round($group['y']+$group['height']/2+$group['height']/2*sin($angle)); $rotation=(int)round(fmod(rad2deg($angle)+270,360)*1000);
            }
            $row=$g; $column=$member;
        } elseif (in_array($preset['generator'],['arc','radial','bowl'],true)) {
            $span=$preset['generator']==='radial'?2*M_PI:($preset['variant']==='horseshoe'?1.5*M_PI:($preset['variant']==='proscenium'?0.9:1.2));
            if ($preset['generator']==='bowl') {
                $span=(($preset['variant']==='open_horseshoe'?1.5:2)*M_PI)/max(1,(int)ceil($p['sections']/$p['levels']));
            }
            $aisles=$preset['generator']==='bowl'?$p['blocks']:($preset['generator']==='radial'?($p['blocks']===1?0:$p['blocks']):$p['blocks']-1);
            $radius=max(in_array($preset['category'],['sports','stadium'],true)?20000:7000,($p['columns']*$p['spacing']+$aisles*$p['aisle_width'])/$span)+$row*$p['row_spacing'];
            $usable=$span-$aisles*$p['aisle_width']/$radius;
            $angle=-$usable/2+($column+0.5)*$usable/$p['columns'];
            $angle+=($block-($p['blocks']-1)/2)*$p['aisle_width']/$radius;
            if ($preset['generator']==='bowl') { $angle+=intdiv($s['index'],$p['levels'])*$span; }
            $x=(int)round($radius*sin($angle)); $y=(int)round(-$radius*cos($angle));
            if ($preset['category']==='stadium' && $preset['variant']==='oval') { $x=(int)round($x*1.5); }
            if ($preset['variant']==='corner_gaps') { $x+=(int)round(2500*sin($angle)); $y-=(int)round(2500*cos($angle)); }
            $rotation=(int)round(fmod(rad2deg(-$angle)+360,360)*1000);
            $loop=$preset['generator']==='radial' && $p['blocks']===1;
        } elseif (in_array($preset['generator'],['perimeter','thrust','lshape','runway'],true)) {
            $sides=$preset['generator']==='thrust'?3:($preset['generator']==='lshape'?2:$preset['shape']);
            $sides=max(1,min(4,$sides)); $side=$i%$sides; $along=intdiv($i,$sides);
            $row=intdiv($along,$p['columns']); $column=$along%$p['columns'];
            $x=(int)round(($column-($p['columns']-1)/2)*$p['spacing']);
            $y=$row*$p['row_spacing']+max($p['columns']*$p['spacing']/2+3000,in_array($preset['category'],['sports','stadium'],true)?20000:($preset['category']==='fashion'?15000:5000));
            $angle=$side*2*M_PI/$sides;
            if ($sides===3) { $angle=$side*M_PI/2; }
            if ($preset['generator']==='runway' && $preset['variant']==='u') { $angle=-M_PI/2+$side*M_PI/2; }
            if ($preset['generator']==='lshape') { $angle=$side*M_PI/2; }
            if ($preset['generator']==='runway' && $preset['variant']==='x') { $angle+=M_PI/4; }
            $oldX=$x; $x=(int)round($oldX*cos($angle)-$y*sin($angle)); $y=(int)round($oldX*sin($angle)+$y*cos($angle));
            if ($preset['generator']==='lshape') {
                $x=$side===0?6000+$column*$p['spacing']:-$row*$p['row_spacing'];
                $y=$side===0?$row*$p['row_spacing']:6000+$column*$p['spacing'];
            }
            if ($preset['generator']==='runway') {
                $along=(int)round(($column-($p['columns']-1)/2)*$p['spacing']);
                if ($preset['variant']==='straight') {
                    $x=$along; $y=($side===0?-1:1)*(4000+$row*$p['row_spacing']);
                } elseif (in_array($preset['variant'],['t','u'],true)) {
                    if ($side===0) { $x=$along; $y=-4000-$row*$p['row_spacing']; }
                    else { $x=($side===1?-1:1)*(($preset['variant']==='u'?14000:4000)+$row*$p['row_spacing']); $y=2000+$column*$p['spacing']; }
                } else {
                    $radius=max(22000,$p['columns']*$p['spacing']/0.5)+$row*$p['row_spacing'];
                    $angle=$side*M_PI/2+(($column+0.5)/$p['columns']-0.5)*0.5;
                    $x=(int)round($radius*cos($angle)); $y=(int)round($radius*sin($angle));
                }
            }
            $rotation=(int)round(fmod(rad2deg($angle)+180,360)*1000);
            $blockCode=sprintf('%s-SD%d-R%05d',$s['code'],$side+1,$row+1); $position=$column;
        } elseif ($preset['generator']==='chevron') {
            $y+=(int)round(abs($x)*0.35); $rotation=$x<0?15000:345000;
        }
        if (!$grouped && $preset['generator']!=='bowl') { $x+=self::offsetX($s,$p); }
        if ($preset['generator']==='rows'&&$preset['category']==='sports'&&$preset['variant']!=='retractable') { $y+=20000; }
        if ($preset['variant']==='staggered_tiers' && $row%2===1) { $x+=(int)($p['spacing']/2); }
        if ($preset['variant']==='classroom') { $x+=(intdiv($column,2))*500; $y*=2; }
        if ($preset['variant']==='double_u') { $x+=(($row%2)*1800); $y+=(($row%2)*1800); }
        $elevation=$s['elevation']+((in_array($preset['category'],['cinema','theater','sports','stadium'],true)&&!$grouped)?$row*250:0);
        if ($preset['variant']==='terrace') { $elevation+=intdiv($row,max(1,(int)ceil(sqrt($s['group_count']))))*400; }
        return ['code'=>sprintf('%s-P%06d',$s['code'],$i+1),
            'row_label'=>sprintf('R%05d',$row+1),'number'=>$column+1,'zone'=>$s['category_code'],
            'x'=>$x,'y'=>$y,'section_code'=>$s['code'],'block_code'=>$blockCode,'block_position'=>$position,
            'closed_loop'=>$loop,'group_code'=>$groupCode,'category_code'=>$s['category_code'],
            'rotation'=>$rotation%360000,'elevation'=>$elevation,
            'accessible'=>$p['accessible_every']>0 && ($s['start']+$i)%$p['accessible_every']===0,
            'tile_code'=>sprintf('%s-T%04d',$s['code'],intdiv($i,2000)+1)];
    }

    private static function offsetX(array $s,array $p): int
    {
        return (int)($s['origin_x']??0);
    }

    private static function expand(?array $b,int $x,int $y,int $w,int $h): array
    {
        if ($b===null) { return ['x'=>$x,'y'=>$y,'width'=>$w,'height'=>$h]; }
        $right=max($b['x']+$b['width'],$x+$w); $bottom=max($b['y']+$b['height'],$y+$h);
        $left=min($b['x'],$x); $top=min($b['y'],$y);
        return ['x'=>$left,'y'=>$top,'width'=>$right-$left,'height'=>$bottom-$top];
    }

    private static function objects(array $preset,array $p): array
    {
        $kind=match($preset['category']) {'fashion'=>'runway','sports','stadium'=>'field',default=>'stage'};
        $rectangles=[[-5000,-5000,10000,3000,0]];
        if ($kind==='field') { $rectangles=[[-10000,-15000,20000,30000,0]]; }
        if ($preset['category']==='theater') { $rectangles=$preset['variant']==='circular'?[[-3000,-3000,6000,6000,0]]:[[-5000,-3000,10000,6000,0]]; }
        if ($preset['category']==='conference'&&$preset['generator']==='perimeter') { $rectangles=[[-3000,-1000,6000,2000,0]]; }
        if ($preset['category']==='fashion') {
            $rectangles=match($preset['variant']) {
                'straight'=>[[-15000,-1500,30000,3000,0]],
                't'=>[[-10000,-1500,20000,3000,0],[-1500,0,3000,20000,0]],
                'u'=>[[-10000,-1500,20000,3000,0],[-11500,0,3000,20000,0],[8500,0,3000,20000,0]],
                'x'=>[[-15000,-1500,30000,3000,45000],[-15000,-1500,30000,3000,135000]],
                'loop'=>[[-10000,-11500,20000,3000,0],[-10000,8500,20000,3000,0],[-11500,-10000,3000,20000,0],[8500,-10000,3000,20000,0]],
                default=>[[-3000,-3000,6000,6000,0]],
            };
        }
        $objects=[];
        foreach ($rectangles as $i=>[$x,$y,$w,$h,$rotation]) {
            $objects[]=['code'=>'FOCAL-'.($i+1),'type'=>$kind,'section_code'=>'','label'=>ucfirst($kind),
                'x'=>$x,'y'=>$y,'width'=>$w,'height'=>$h,'rotation'=>$rotation,'elevation'=>0,
                'obstruction'=>$preset['variant']!=='retractable'];
        }
        return $objects;
    }
}
