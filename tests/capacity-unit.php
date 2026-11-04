<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Domain/Booking/HoldPolicy.php';
require __DIR__ . '/../src/Domain/Booking/CapacityPolicy.php';

use PESM\Domain\Booking\CapacityPolicy;
use PESM\Domain\Booking\HoldException;

$checks = 0;
function checkCapacity(bool $condition, string $description): void {
    global $checks;
    ++$checks;
    if (!$condition) throw new RuntimeException($description);
}
function expectCapacityError(callable $operation, string $code): void {
    try { $operation(); }
    catch (HoldException $e) {
        checkCapacity($e->errorCode === $code, 'Expected ' . $code . ', got ' . $e->errorCode);
        return;
    }
    throw new RuntimeException('Expected exception: ' . $code);
}
foreach ([1, 20] as $q) checkCapacity(CapacityPolicy::quantity($q) === $q, 'quantity');
foreach ([0,-1,21,2000] as $q) expectCapacityError(fn() => CapacityPolicy::quantity($q), 'invalid_quantity');
foreach ([1,900,100000] as $c) checkCapacity(CapacityPolicy::capacity($c) === $c, 'capacity');
foreach ([-1,0,100001] as $c) expectCapacityError(fn() => CapacityPolicy::capacity($c), 'invalid_capacity');
checkCapacity(CapacityPolicy::available(900,5,20,30,100)===745,'inventory subtraction');
checkCapacity(CapacityPolicy::available(1,0,0,0,1)===0,'sold out');
expectCapacityError(fn() => CapacityPolicy::available(5,1,4,0,1),'inventory_inconsistent');
expectCapacityError(fn() => CapacityPolicy::available(5,-1,0,0,0),'inventory_inconsistent');
$session=(object)[
    'mode'=>'general','status'=>'published','event_status'=>'published',
    'sale_starts_at'=>'2026-11-02 10:00:00','sale_ends_at'=>'2026-11-02 18:00:00',
    'starts_at'=>'2026-11-03 10:00:00'
];
CapacityPolicy::saleOpen($session,'2026-11-02 12:00:00'); checkCapacity(true,'sale open');
foreach (['mode'=>'seated','status'=>'draft','event_status'=>'cancelled',
    'sale_starts_at'=>'2026-11-02 13:00:00','sale_ends_at'=>'2026-11-02 12:00:00',
    'starts_at'=>'2026-11-02 12:00:00'] as $prop=>$val) {
    $copy=clone $session; $copy->$prop=$val;
    expectCapacityError(fn() => CapacityPolicy::saleOpen($copy,'2026-11-02 12:00:00'),'event_closed');
}
echo "PASS: {$checks} pure general-admission capacity assertions.\n";
