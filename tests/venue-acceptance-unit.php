<?php
declare(strict_types=1);

require __DIR__ . '/../src/Domain/Venue/Acceptance.php';
require __DIR__ . '/../src/Domain/Hall/LayoutDocument.php';
require_once __DIR__ . '/../src/Support/I18n.php';

use PESM\Domain\Venue\Acceptance;
use PESM\Domain\Hall\LayoutDocument;
use PESM\Domain\Hall\SeatCode;

$options = [];
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['options'][$key] ?? $default; }
function update_option(string $key, mixed $value, mixed $autoload = null): void { $GLOBALS['options'][$key] = $value; }
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { if (!$condition) { throw new RuntimeException($message); } ++$checks; };
$assert(!Acceptance::creationEnabled(), 'New session creation is closed without evidence.');
$report = ['passed'=>true,'schema'=>19,'capacity'=>150000,'concurrent_purchase_attempts'=>1000,'gate_operators'=>20,
    'oversells'=>0,'duplicate_issuance'=>0,'integration_passed'=>true,'hold_p95_ms'=>1900,'availability_p95_ms'=>490,'gate_p95_ms'=>990,'environment'=>['profile'=>'isolated-test']];
$assert(Acceptance::passes($report), 'All explicit acceptance requirements may open creation.');
foreach (['oversells'=>1,'duplicate_issuance'=>1,'capacity'=>149999,'concurrent_purchase_attempts'=>999,'gate_operators'=>19,
    'schema'=>18,'hold_p95_ms'=>2001,'availability_p95_ms'=>501,'gate_p95_ms'=>1001,'integration_passed'=>false,'passed'=>false,'environment'=>[]] as $key=>$value) {
    $bad = array_replace($report, [$key=>$value]);
    $assert(!Acceptance::passes($bad), 'Failed acceptance is rejected: ' . $key);
}
$slow = array_replace($report, ['hold_p95_ms'=>11881,'availability_p95_ms'=>10776]);
try { Acceptance::record($slow); throw new LogicException('Slow deployment accepted.'); } catch (RuntimeException $e) { $assert(!Acceptance::creationEnabled(), 'A functional load run cannot bypass latency acceptance.'); }
Acceptance::record($report);
$assert(Acceptance::creationEnabled(), 'Recorded evidence enables creation without modifying old bookings.');
foreach (['NEW-12','COPY-3','L02-S03-R014-N008','A_1.2',str_repeat('A',60)] as $code) { $assert(SeatCode::valid($code), 'Editor/import/admin share valid custom code: ' . $code); }
foreach (['',str_repeat('A',61),'seat 1','<script>','a-1'] as $code) { $assert(!SeatCode::valid($code), 'Invalid code rejected.'); }
$seat = ['code'=>'NEW-12','row_label'=>'A','number'=>1,'zone'=>str_repeat('A',60),'x'=>0,'y'=>0];
$assert(count(LayoutDocument::parse(LayoutDocument::encode([$seat])))===1, 'The storage character boundary is accepted.');
$seat['zone']=str_repeat('A',61);
try { LayoutDocument::encode([$seat]); throw new LogicException('Overlong zone accepted.'); } catch (InvalidArgumentException $e) { $assert(true,'A 61-character zone is rejected before storage.'); }
$seat['zone']=str_repeat('آ',60);
$assert(count(LayoutDocument::parse(LayoutDocument::encode([$seat])))===1,'Multibyte zones use character, not ASCII byte, limits.');
echo "PASS: {$checks} creation-gate and shared seat/zone boundary assertions.\n";
