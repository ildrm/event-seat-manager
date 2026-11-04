<?php

declare(strict_types=1);

/** Disposable WordPress/InnoDB acceptance. Deliberately creates test venue records. */
if (getenv('PESM_RUN_VENUE_INTEGRATION')!=='1' || !defined('ABSPATH')) { throw new RuntimeException('Run only in an explicitly enabled disposable WordPress installation.'); }

use PESM\Domain\Hall\HallService;
use PESM\Domain\Venue\Document;
use PESM\Domain\Venue\Generator;
use PESM\Domain\Venue\JobService;
use PESM\Domain\Venue\LayoutRepository;

wp_set_current_user(1);
$GLOBALS['venue_integration_checks']=0; $evidence=[];
function venueIntegrationAssert(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException('Venue integration: '.$message); } $GLOBALS['venue_integration_checks']++; }
function venueDrain(int $id,int $max=500): array {
    for ($i=0;$i<$max;$i++) {
        JobService::run($id); $job=JobService::status($id);
        if ($job['status']==='complete') { return $job; }
        if ($job['status']==='failed') { throw new RuntimeException('Venue job failed: '.$job['error']); }
    }
    throw new RuntimeException('Venue job did not complete within its batch budget.');
}

$center=HallService::createCenter('Venue integration '.bin2hex(random_bytes(5)));
$hall=HallService::createHall($center,'Venue integration hall',1,1);
$job=JobService::create($center,$hall,'generate',['preset_id'=>'restaurant_mixed_tables','parameters'=>['capacity'=>4097]],1);
$job=venueDrain($job['id']); $layout=$job['layout_id'];
$overview=LayoutRepository::overview($layout);
venueIntegrationAssert($overview['capacity']===4097,'Generated exact capacity.');
venueIntegrationAssert(!isset($overview['seats']),'Overview omits individual seats.');
venueIntegrationAssert(count($overview['tiles'])===3,'Bounded geometry tiles.');
$first=LayoutRepository::tile($layout,$overview['tiles'][0]['code']);
venueIntegrationAssert(count($first['seats'])===2000,'Tile respects 2,000-record bound.');
$firstSeat=$first['seats'][0];
venueIntegrationAssert($firstSeat['id']>0&&$firstSeat['seat_id']===$firstSeat['id'],'Canonical DB seat IDs.');
$page=LayoutRepository::units($layout,['limit'=>50]);
venueIntegrationAssert(count($page['items'])===50&&$page['next_cursor']!==null,'Accessible inventory pagination.');
$next=LayoutRepository::units($layout,['limit'=>50,'cursor'=>$page['next_cursor']]);
venueIntegrationAssert(!array_intersect(array_column($page['items'],'id'),array_column($next['items'],'id')),'List pages do not repeat seats.');
$located=LayoutRepository::locate($layout,$firstSeat['code']);
venueIntegrationAssert($located['id']===$firstSeat['id'],'Venue-wide exact seat lookup.');
$key=bin2hex(random_bytes(32)); $operation=[['op'=>'move','seat_ids'=>[$firstSeat['id']],'dx'=>250,'dy'=>125]];
$saved=LayoutRepository::commands($layout,0,$key,$operation);
$replayed=LayoutRepository::commands($layout,0,$key,$operation);
venueIntegrationAssert($saved===$replayed&&$saved['revision']===1,'Committed draft retry returns saved receipt.');
try { LayoutRepository::commands($layout,0,$key,[['op'=>'move','seat_ids'=>[$firstSeat['id']],'dx'=>1]]); throw new RuntimeException('Accepted reused key with different content.'); }
catch (RuntimeException $error) { venueIntegrationAssert(str_contains($error->getMessage(),'already used'),'Mismatched replay rejected.'); }
$moved=LayoutRepository::tile($layout,$firstSeat['tile_code'])['seats'][0];
venueIntegrationAssert($moved['x']===$firstSeat['x']+250&&$moved['y']===$firstSeat['y']+125,'Replay does not move a seat twice.');
$saved=LayoutRepository::commands($layout,1,bin2hex(random_bytes(32)),[['op'=>'category','seat_ids'=>[$firstSeat['id']],'category_code'=>'VIP']]);
$saved=LayoutRepository::commands($layout,2,bin2hex(random_bytes(32)),[['op'=>'object','object'=>['id'=>'TEST-OBJECT','type'=>'door','label'=>'Door','x'=>0,'y'=>0,'width'=>1000,'height'=>1000]]]);
$saved=LayoutRepository::commands($layout,3,bin2hex(random_bytes(32)),[['op'=>'object','object'=>['id'=>'TEST-OBJECT'],'delete'=>true]]);
$groupCode=$first['groups'][0]['code'];
$beforeGroup=LayoutRepository::tile($layout,$firstSeat['tile_code']);
$groupBefore=array_values(array_filter($beforeGroup['groups'],static fn($g)=>$g['code']===$groupCode))[0];
$membersBefore=array_values(array_filter($beforeGroup['seats'],static fn($s)=>$s['group_code']===$groupCode));
$saved=LayoutRepository::commands($layout,4,bin2hex(random_bytes(32)),[['op'=>'move_group','group_code'=>$groupCode,'dx'=>1250,'dy'=>750]]);
$afterGroup=LayoutRepository::tile($layout,$firstSeat['tile_code']);
$groupAfter=array_values(array_filter($afterGroup['groups'],static fn($g)=>$g['code']===$groupCode))[0];
$membersAfter=array_values(array_filter($afterGroup['seats'],static fn($s)=>$s['group_code']===$groupCode));
venueIntegrationAssert($groupAfter['x']===$groupBefore['x']+1250&&$groupAfter['y']===$groupBefore['y']+750,'Table footprint moves atomically.');
foreach ($membersAfter as $i=>$member) { venueIntegrationAssert($member['x']===$membersBefore[$i]['x']+1250&&$member['y']===$membersBefore[$i]['y']+750,'Every table member moves with its footprint.'); }
$saved=LayoutRepository::commands($layout,5,bin2hex(random_bytes(32)),[['op'=>'move_group','group_code'=>$groupCode,'dx'=>-1250,'dy'=>-750]]);
$saved=LayoutRepository::commands($layout,6,bin2hex(random_bytes(32)),[['op'=>'group_category','group_code'=>$groupCode,'category_code'=>'GROUP-CATEGORY']]);
$saved=LayoutRepository::commands($layout,7,bin2hex(random_bytes(32)),[['op'=>'group_category','group_code'=>$groupCode,'category_code'=>'VIP']]);
$stream=fopen('php://temp','w+b'); LayoutRepository::export($layout,$stream); rewind($stream);
$checked=Document::validateRecords(Document::readJsonLines($stream));
venueIntegrationAssert($checked['seat_count']===4097,'Stored edits export as a valid complete document.');
rewind($stream); $token=JobService::stageImport($stream,1); fclose($stream);
$import=venueDrain(JobService::create($center,$hall,'import',['upload_token'=>$token],1)['id']);
venueIntegrationAssert($import['layout_id']!==$layout,'Import creates an independent draft.');
$originalHash=LayoutRepository::validateStored($layout)['content_hash'];
$importedHash=LayoutRepository::validateStored($import['layout_id'])['content_hash'];
venueIntegrationAssert($originalHash===$importedHash,'Streamed database import/export preserves canonical geometry.');
$published=venueDrain(JobService::create($center,$hall,'publish',['layout_id'=>$layout],1)['id']);
venueIntegrationAssert($published['result']['status']==='published','Publication is a durable job.');
global $wpdb; $p=$wpdb->prefix.'pesm_';
venueIntegrationAssert((int)$wpdb->get_var($wpdb->prepare("SELECT current_layout_id FROM {$p}halls WHERE id=%d",$hall))===$layout,'Publication changes only the future hall default.');
try { LayoutRepository::commands($layout,8,bin2hex(random_bytes(32)),$operation); throw new RuntimeException('Published layout was edited.'); }
catch (RuntimeException $error) { venueIntegrationAssert(str_contains($error->getMessage(),'conflict'),'Published layouts remain immutable.'); }
$again=LayoutRepository::publish($layout);
venueIntegrationAssert($again['status']==='published','Lost publication receipt can be replayed.');

if (getenv('PESM_VENUE_LARGE')==='1') {
    $started=microtime(true);
    $large=venueDrain(JobService::create($center,$hall,'generate',['preset_id'=>'stadium_boxes','parameters'=>['capacity'=>150000]],1)['id']);
    $largeOverview=LayoutRepository::overview($large['layout_id']);
    venueIntegrationAssert($largeOverview['capacity']===150000,'Large fixture has 150,000 places.');
    venueIntegrationAssert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}venue_places WHERE layout_id=%d",$large['layout_id']))===150000,'All large places have canonical metadata.');
    $count=0;
    foreach ($largeOverview['tiles'] as $tile) { venueIntegrationAssert($tile['count']<=2000,'Large tile bound.'); $count+=$tile['count']; }
    venueIntegrationAssert($count===150000,'Large manifest counts every place once.');
    $evidence=['large_layout_id'=>$large['layout_id'],'generation_database_seconds'=>round(microtime(true)-$started,3),
        'peak_php_memory_mib'=>round(memory_get_peak_usage(true)/1048576,2),'geometry_tiles'=>count($largeOverview['tiles'])];
}
echo json_encode(['passed'=>true,'checks'=>$GLOBALS['venue_integration_checks'],'center_id'=>$center,'hall_id'=>$hall,'layout_id'=>$layout,
    'imported_layout_id'=>$import['layout_id'],'evidence'=>$evidence],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
