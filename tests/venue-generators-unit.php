<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class,'PESM\\')) { require_once __DIR__.'/../src/'.str_replace('\\','/',substr($class,5)).'.php'; }
});

use PESM\Domain\Venue\Document;
use PESM\Domain\Venue\Generator;
use PESM\Domain\Venue\PresetCatalog;

$assertions=0;
function venueAssert(bool $ok,string $message): void { global $assertions; if (!$ok) { throw new RuntimeException($message); } $assertions++; }
function venueReject(callable $callback,string $message): void { try { $callback(); } catch (InvalidArgumentException $error) { venueAssert(true,$message); return; } throw new RuntimeException($message); }

$catalog=PresetCatalog::all();
venueAssert(count($catalog)===50,'Exactly fifty selectable configurations.');
venueAssert(count(array_unique(array_column($catalog,'id')))===50,'Configuration identities are unique.');
$hashes=[];
foreach ($catalog as $preset) {
    foreach ([1,49,900] as $capacity) {
        $doc=Generator::generate($preset['id'],['capacity'=>$capacity]);
        venueAssert(count($doc['seats'])===$capacity,$preset['id'].' exact capacity');
        venueAssert(count(array_unique(array_column($doc['seats'],'code')))===$capacity,'Unique stable seat codes.');
        $sectionCounts=array_count_values(array_column($doc['seats'],'section_code'));
        foreach ($doc['sections'] as $section) { venueAssert($sectionCounts[$section['code']]===$section['capacity'],'Section capacity is not double counted.'); }
        if ($capacity===49) {
            $stream=fopen('php://temp','w+b');
            Document::writeJsonLines($stream,Generator::records($preset['id'],['capacity'=>49])); rewind($stream);
            $checked=Document::validateRecords(Document::readJsonLines($stream)); fclose($stream);
            venueAssert($checked['seat_count']===49,'Streamed round trip preserves capacity.');
        }
        if ($capacity===900) { $hashes[hash('sha256',json_encode([$doc['seats'],$doc['groups'],$doc['objects']],JSON_THROW_ON_ERROR))]=true; }
    }
}
venueAssert(count($hashes)===50,'The fifty recipes produce genuinely distinct arrangements.');
$tiny=Generator::parameters('stadium_boxes',['capacity'=>1]);
venueAssert($tiny['sections']===1&&$tiny['levels']===1,'Omitted section and tier defaults adapt to an exact tiny venue.');
$narrow=Generator::parameters('cinema_three_blocks',['columns'=>1]);
venueAssert($narrow['blocks']===1,'Omitted block default adapts to an explicitly narrower row.');
foreach ([
    ['stadium_oval',['capacity'=>1,'sections'=>16],'use at most 1 sections or at least 16 capacity'],
    ['stadium_boxes',['capacity'=>1,'levels'=>3],'use at most 1 levels or at least 3 sections'],
    ['cinema_three_blocks',['columns'=>2,'blocks'=>3],'use at most 2 blocks or at least 3 columns'],
    ['stadium_boxes',['workflow'=>'advanced','sections'=>2,'levels'=>3],'use at most 2 levels or at least 3 sections'],
] as [$presetId,$parameters,$compatibleBounds]) {
    try { Generator::parameters($presetId,$parameters); throw new RuntimeException('Explicit infeasible arrangement was silently changed.'); }
    catch (InvalidArgumentException $error) { venueAssert(str_contains($error->getMessage(),$compatibleBounds),'Explicit incompatible structural controls report compatible bounds.'); }
}
$advanced=Generator::generate('cinema_central_aisle',['workflow'=>'advanced','rows'=>7,'columns'=>13,'sections'=>3]);
venueAssert(count($advanced['seats'])===273,'Advanced arrangement calculates capacity.');
$advanced=Generator::generate('restaurant_round_banquet',['workflow'=>'advanced','group_count'=>7,'group_size'=>8,'sections'=>2]);
venueAssert(count($advanced['seats'])===112&&count($advanced['groups'])===14,'Advanced tables calculate explicit group count and capacity.');
$groups=Generator::generate('restaurant_round_banquet',['capacity'=>49,'group_size'=>8,'min_group_size'=>2]);
venueAssert(array_column($groups['groups'],'capacity')===[8,8,8,8,8,7,2],'Incomplete final groups redistribute explicitly.');
$tail=Generator::generate('restaurant_round_banquet',['capacity'=>19,'group_size'=>8,'min_group_size'=>6]);
venueAssert(array_column($tail['groups'],'capacity')===[7,6,6],'Minimum group capacity can borrow from multiple preceding groups.');
$pitch=Generator::generate('restaurant_round_banquet',['capacity'=>64]);
$rows=Generator::generate('restaurant_round_banquet',['capacity'=>64,'row_spacing'=>2000]);
venueAssert($rows['groups'][3]['y']===$pitch['groups'][3]['y']+1000,'Table row-spacing control changes actual row pitch.');
$aisles=Generator::generate('restaurant_round_banquet',['capacity'=>64,'aisle_width'=>2400]);
venueAssert($aisles['groups'][1]['x']===$pitch['groups'][1]['x']+1000,'Table aisle-width control changes actual column pitch.');
$spacing=Generator::generate('restaurant_round_banquet',['capacity'=>64,'spacing'=>700]);
venueAssert($spacing['seats'][0]['x']!==$pitch['seats'][0]['x'],'Round-table chair-spacing control changes member coordinates.');
$mixed=Generator::generate('restaurant_mixed_tables',['capacity'=>49]);
venueAssert(count(array_unique(array_column($mixed['groups'],'capacity')))>=3,'Mixed tables have actual mixed capacities.');
$curved=Generator::generate('cinema_curved_tiers',['capacity'=>30,'columns'=>30,'blocks'=>2]);
venueAssert(count(array_unique(array_column($curved['seats'],'y')))>1,'Curved topology does not rely on equal y.');
$blocks=[];
foreach ($curved['seats'] as $seat) { $blocks[$seat['block_code']][]=$seat['block_position']; }
venueAssert(count($blocks)===2,'Aisles split adjacency blocks.');
foreach ($blocks as $positions) { venueAssert($positions===range(0,count($positions)-1),'Explicit block positions are contiguous.'); }
venueReject(fn()=>Generator::generate('restaurant_round_banquet',['capacity'=>49,'group_size'=>8,'fixed_group_size'=>true]),'Infeasible fixed group sizes reject.');
try { Generator::generate('restaurant_round_banquet',['capacity'=>49,'group_size'=>8,'fixed_group_size'=>true]); throw new RuntimeException('Accepted infeasible fixed capacity.'); }
catch (InvalidArgumentException $error) { venueAssert(str_contains($error->getMessage(),'48, 56'),'Infeasible exact capacity shows nearby valid fixed-group counts.'); }
venueReject(fn()=>Generator::generate('stadium_oval',['capacity'=>150001]),'Capacity ceiling enforced.');
venueReject(fn()=>Generator::generate('stadium_oval',['spacing'=>50]),'Overlapping seat spacing rejected.');
venueReject(fn()=>Generator::generate('stadium_oval',['untrusted'=>true]),'Unknown generator parameters rejected.');
$corrupt=$curved; $corrupt['seats'][1]['code']=$corrupt['seats'][0]['code'];
venueReject(fn()=>Document::validate($corrupt),'Duplicate seat codes rejected.');
$corrupt=$groups; $corrupt['seats'][0]['category_code']='OTHER';
venueReject(fn()=>Document::validate($corrupt),'Mixed-category group membership rejected.');
$corrupt=$curved; $corrupt['seats'][0]['block_position']=50;
venueReject(fn()=>Document::validate($corrupt),'Broken topology rejected.');
$corrupt=$curved; $corrupt['seats'][0]['section_code']='MISSING';
venueReject(fn()=>Document::validate($corrupt),'Unknown section rejected.');
foreach (["{}\n", "{\"type\":\"seat\",\"data\":{}}\n",str_repeat('x',Document::MAX_RECORD_BYTES+1)] as $text) {
    venueReject(static function () use ($text): void { $stream=fopen('php://temp','w+b'); fwrite($stream,$text); rewind($stream); try { iterator_to_array(Document::readJsonLines($stream)); } finally { fclose($stream); } },'Malformed stream rejected.');
}
// Exercise the maximum via bounded direct-index batches; do not materialize 150k seats.
$plan=Generator::plan('stadium_boxes',['capacity'=>150000]); $count=0;
for ($offset=0;$offset<Generator::recordCount($plan);$offset+=2000) {
    $batch=Generator::batch($plan,$offset);
    venueAssert(count($batch)<=2000,'Generation batches stay bounded.');
    foreach ($batch as $record) { Document::record($record); if ($record['type']==='seat') { $count++; } }
}
venueAssert($count===150000,'Maximum venue generation is exact.');
echo 'PASS: '.$assertions." venue generation/document assertions.\n";
