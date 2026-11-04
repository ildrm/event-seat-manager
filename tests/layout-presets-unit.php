<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';
require __DIR__.'/../src/Domain/Hall/LayoutDocument.php';
require __DIR__.'/../src/Domain/Hall/GridGenerator.php';
require __DIR__.'/../src/Domain/Hall/LayoutPresets.php';
use PESM\Domain\Hall\LayoutPresets;
use PESM\Domain\Hall\LayoutDocument;
$n=0;
function assert17(bool $ok,string $what):void { global $n; if (!$ok) throw new RuntimeException('FAILED: '.$what); $n++; }
foreach (array_keys(LayoutPresets::PRESETS) as $preset) {
    foreach ([[15,60],[18,50],[3,7],[1,1]] as [$rows,$columns]) {
        $seats=LayoutPresets::generate($preset,$rows,$columns);
        assert17(count($seats)===$rows*$columns,"$preset total $rows/$columns");
        assert17(count(array_unique(array_column($seats,'code')))===$rows*$columns,"$preset unique IDs");
        assert17(LayoutDocument::parse(LayoutDocument::encode($seats))==$seats,"$preset roundtrip");
        assert17(count(array_filter($seats,fn($s)=>$s['zone']===''))===0,"$preset zones");
    }
}
foreach ([['invalid',15,60],['cinema',0,4],['cinema',101,3],['vip',100,101]] as [$p,$r,$c]) {
    try {LayoutPresets::generate($p,$r,$c);throw new RuntimeException('Accepted invalid preset');}
    catch (InvalidArgumentException $e) {assert17(true,'invalid config rejected');}
}
foreach ([['cinema','classroom'],['cinema','u_shape'],['cinema','roundtable'],['cinema','curved']] as [$a,$b]) {
    assert17(LayoutPresets::generate($a,5,12)!==LayoutPresets::generate($b,5,12), 'distinct geometry');
}
echo "PASS: {$n} layout preset assertions (pure PHP).\n";
