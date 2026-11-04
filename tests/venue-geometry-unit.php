<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class,'PESM\\')) { require_once __DIR__.'/../src/'.str_replace('\\','/',substr($class,5)).'.php'; }
});

use PESM\Domain\Venue\Generator;
use PESM\Domain\Venue\PresetCatalog;

$n=0;
function geometryCheck(string $preset,array $parameters): void
{
    global $n;
    $levels=[]; $sectionBounds=[]; $objects=[]; $plan=Generator::plan($preset,$parameters);
    foreach ($plan['sections'] as $section) { $levels[$section['code']]=$section['level']; $sectionBounds[$section['code']]=$section['bounds']; }
    foreach (Generator::batch($plan,Generator::recordCount($plan)-$plan['manifest']['object_count'],$plan['manifest']['object_count']) as $record) {
        $d=$record['data']; if (($d['obstruction']??true)&&in_array($d['type'],['stage','runway','field'],true)) { $objects[]=$d; }
    }
    $hash=[];
    for ($offset=0;$offset<Generator::recordCount($plan);$offset+=2000) {
      foreach (Generator::batch($plan,$offset) as $record) {
        if ($record['type']==='group') {
            $group=$record['data']; $b=$sectionBounds[$group['section_code']];
            if ($group['x']<$b['x']||$group['y']<$b['y']||$group['x']+$group['width']>$b['x']+$b['width']||$group['y']+$group['height']>$b['y']+$b['height']) { throw new RuntimeException($preset.' group footprint is clipped by its section bounds.'); }
        }
        if ($record['type']!=='seat') { continue; } $seat=$record['data'];
        $x=(int)floor($seat['x']/450); $y=(int)floor($seat['y']/450); $level=$levels[$seat['section_code']];
        for ($dx=-1;$dx<=1;$dx++) {
            for ($dy=-1;$dy<=1;$dy++) {
                foreach ($hash[$level.':'.($x+$dx).':'.($y+$dy)]??[] as $packed) {
                    $other=unpack('lx/ly',substr($packed,0,8));
                    $distance=hypot($seat['x']-$other['x'],$seat['y']-$other['y']);
                    if ($distance<449) { throw new RuntimeException($preset.' seat overlap '.$seat['code'].'/'.substr($packed,8).' distance '.$distance); }
                }
            }
        }
        $hash[$level.':'.$x.':'.$y][]=pack('l2',$seat['x'],$seat['y']).$seat['code'];
        foreach ($objects as $object) {
            $angle=-deg2rad($object['rotation']/1000); $cx=$object['x']+$object['width']/2; $cy=$object['y']+$object['height']/2;
            $sx=$seat['x']-$cx; $sy=$seat['y']-$cy;
            $localX=$sx*cos($angle)-$sy*sin($angle); $localY=$sx*sin($angle)+$sy*cos($angle);
            $outsideX=max(0,abs($localX)-$object['width']/2); $outsideY=max(0,abs($localY)-$object['height']/2);
            if (hypot($outsideX,$outsideY)<224) { throw new RuntimeException($preset.' seat '.$seat['code'].' overlaps '.$object['type'].' '.$object['code']); }
        }
      }
    }
    $n++;
}

foreach (PresetCatalog::all() as $preset) {
    foreach ([49,900] as $capacity) { geometryCheck($preset['id'],['capacity'=>$capacity]); }
}
foreach (['restaurant_round_banquet','restaurant_rectangular_banquet','restaurant_booths','conference_cabaret'] as $preset) {
    geometryCheck($preset,['capacity'=>2000,'sections'=>2,'group_size'=>200]);
    geometryCheck($preset,['capacity'=>900,'row_spacing'=>600,'aisle_width'=>900,'spacing'=>450]);
}
$fixed=Generator::generate('restaurant_round_banquet',['capacity'=>48,'sections'=>5,'group_size'=>8,'fixed_group_size'=>true]);
if (array_column($fixed['sections'],'capacity')!==[16,8,8,8,8]) { throw new RuntimeException('Fixed-size groups must allocate section capacity in group units.'); }
$n++;
geometryCheck('stadium_boxes',['capacity'=>150000]);
echo 'PASS: '.$n." physical geometry scenarios; section/group allocation, chair spacing and focal-object separation.\n";
