<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

require __DIR__ . '/../src/Domain/Hall/LayoutDocument.php';
require __DIR__ . '/../src/Domain/Hall/LayoutPrintRenderer.php';

use PESM\Domain\Hall\LayoutDocument;
use PESM\Domain\Hall\LayoutPrintRenderer;

$checks = 0;
function checkObject(bool $condition, string $reason): void {
    global $checks;
    ++$checks;
    if (!$condition) { throw new RuntimeException('FAILED: ' . $reason); }
}
function invalidObject(array $seats, array $objects, string $reason): void {
    try { LayoutDocument::encodeFull($seats, $objects); }
    catch (InvalidArgumentException $e) { checkObject(true, $reason); return; }
    throw new RuntimeException('Unexpected acceptance: ' . $reason);
}
$seats = [['code'=>'A-1','row_label'=>'A','number'=>1,'zone'=>'عمومی','x'=>20,'y'=>20]];
$types = LayoutDocument::OBJECT_TYPES;
$objects=[];
foreach ($types as $i=>$type) {
    $objects[] = ['id'=>'O-' . $i,'type'=>$type,'x'=>$i*65,'y'=>40,
        'width'=>80,'height'=>50,'label'=>$type==='stage' ? 'سن ویژه' : $type];
}
$json=LayoutDocument::encodeFull($seats,$objects);
checkObject(LayoutDocument::parseFull($json)['objects']===$objects,'9 types roundtrip');
checkObject(LayoutDocument::parse($json)===$seats,'legacy parse continues to extract seats');
checkObject(LayoutDocument::parseFull(LayoutDocument::encode($seats))['objects']===[], 'legacy documents without objects');
checkObject(count(LayoutDocument::parseFull($json)['objects'])===9,'non seat objects have no seat IDs');
checkObject(str_contains($json,'سن ویژه'),'Persian preserved');
$unsafe = $objects;
$unsafe[0]['label'] = '<script>alert("x")</script>';
$html = LayoutPrintRenderer::render(LayoutDocument::encodeFull($seats,$unsafe),'تالار <مزاحم>', 'A3');
checkObject(!str_contains($html,'<script>'),'object captions escaped in printable view');
checkObject(str_contains($html,'&lt;script&gt;'),'escaped text retained');
checkObject(str_contains($html,'&lt;مزاحم&gt;'),'hall title escaped');
checkObject(str_contains($html,'<ellipse'),'round tables rendered');
checkObject(str_contains($html,'<line'),'separators rendered');
checkObject(str_contains($html,'<rect'),'stage rendered');
checkObject(str_contains($html,'ظرفیت فیزیکی: 1 صندلی'),'object does not increase capacity');

$dupe=$objects;$dupe[1]['id']=$dupe[0]['id'];invalidObject($seats,$dupe,'duplicate ID');
foreach (['id'=>'bad id!','type'=>'ticket','x'=>'20','y'=>1000001,'width'=>0,'height'=>10001,
          'label'=>"hello\nworld"] as $field=>$value) {
    $changed=$objects;$changed[0][$field]=$value;
    invalidObject($seats,$changed,'invalid ' . $field);
}
$extra=$objects;$extra[0]['php_payload']='x';invalidObject($seats,$extra,'unknown fields');
$missing=$objects;unset($missing[0]['label']);invalidObject($seats,$missing,'missing label');
$tooMany=array_fill(0,LayoutDocument::MAX_OBJECTS+1,$objects[0]);
invalidObject($seats,$tooMany,'object limit');
$maxLabel=$objects;$maxLabel[0]['label']=str_repeat('x',121);
invalidObject($seats,$maxLabel,'column length guard');
$wrong=LayoutDocument::encode($seats);
$wrong=str_replace('"seats": [','"objects": {}, "seats": [',$wrong);
try { LayoutDocument::parseFull($wrong);throw new RuntimeException('Object dictionary accepted'); }
catch (InvalidArgumentException $e) { checkObject(true,'object list required'); }

echo "PASS: {$checks} layout object assertions (pure PHP, not WordPress/MySQL).\n";
