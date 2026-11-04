<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

require_once __DIR__ . '/../src/Domain/Booking/HoldException.php';
require_once __DIR__ . '/../src/Domain/Pricing/DiscountPolicy.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Pricing\DiscountPolicy as P;

$n = 0;
function same(mixed $actual, mixed $wanted, string $description): void {
    global $n;
    if ($actual !== $wanted) {
        throw new RuntimeException($description . ': expected ' . var_export($wanted, true) . ', got ' . var_export($actual, true));
    }
    ++$n;
}
function rejects(callable $operation, string $error): void {
    global $n;
    try { $operation(); }
    catch (HoldException $e) {
        if ($e->errorCode !== $error) { throw new RuntimeException($error . ' expected, got ' . $e->errorCode); }
        ++$n; return;
    }
    throw new RuntimeException('Expected rejection: ' . $error);
}
$now = '2026-11-03 11:00:00';
$rule = ['enabled'=>1, 'starts_at'=>'2026-11-01 00:00:00', 'ends_at'=>'2026-11-30 00:00:00',
    'kind'=>'percent', 'value'=>2500, 'zone_label'=>'VIP', 'min_order_minor'=>500];
$zones = [7=>'VIP', 8=>'GENERAL', 9=>'VIP'];
same(P::code('  vip-offer  '), 'VIP-OFFER', 'normalized code');
foreach (['', 'A', 'BAD CODE', "\nBAD", '123456789012345678901234567890123', 'CODÉ'] as $bad) {
    rejects(fn() => P::code($bad), 'invalid_coupon');
}
$q = P::quote($rule, $zones, 1000, $now);
same($q['base_minor'], 3000, 'base all seats');
same($q['discount_minor'], 500, 'percent for eligible only');
same($q['payable_minor'], 2500, 'payable');
same($q['seat_discounts'], [7=>250,8=>0,9=>250], 'seat allocations');
same(array_sum($q['seat_discounts']), $q['discount_minor'], 'reconciliation');
$fixed = [...$rule, 'kind'=>'fixed', 'value'=>901];
$q = P::quote($fixed, $zones, 1000, $now);
same($q['seat_discounts'], [7=>451,8=>0,9=>450], 'deterministic odd allocation');
same($q['payable_minor'], 2099, 'fixed balance');
same(P::quote([...$fixed, 'value'=>1000000], $zones, 1000, $now)['discount_minor'], 2000, 'fixed cap');
same(P::quote([...$rule, 'value'=>10000, 'zone_label'=>''], $zones, 1000, $now)['payable_minor'], 0, 'full free coupon');
same(P::quote([...$rule, 'value'=>3333, 'zone_label'=>'', 'min_order_minor'=>0], [1=>'A',2=>'A',3=>'A'], 1, $now)['discount_minor'], 1, 'round half up');
same(P::quote([...$rule, 'value'=>3333, 'zone_label'=>'', 'min_order_minor'=>0], [1=>'A',2=>'A'], 1, $now)['discount_minor'], 1, 'aggregate rounding');
rejects(fn()=>P::quote([...$rule, 'min_order_minor'=>3001], $zones,1000,$now), 'coupon_minimum');
rejects(fn()=>P::quote([...$rule, 'zone_label'=>'BOX'], $zones,1000,$now), 'coupon_not_applicable');
rejects(fn()=>P::quote([...$rule, 'starts_at'=>'2026-11-04 00:00:00'], $zones,1000,$now), 'coupon_unavailable');
rejects(fn()=>P::quote([...$rule, 'ends_at'=>$now], $zones,1000,$now), 'coupon_unavailable');
rejects(fn()=>P::quote([...$rule, 'enabled'=>0], $zones,1000,$now), 'coupon_unavailable');
rejects(fn()=>P::quote([...$rule, 'kind'=>'percent','value'=>10001], $zones,1000,$now), 'invalid_coupon');
rejects(fn()=>P::quote([...$rule, 'kind'=>'fixed','value'=>0], $zones,1000,$now), 'invalid_coupon');
rejects(fn()=>P::quote($rule, [], 1000, $now), 'invalid_price');
rejects(fn()=>P::quote($rule, $zones, -1, $now), 'invalid_price');
rejects(fn()=>P::quote($rule, $zones, PHP_INT_MAX, $now), 'invalid_price');
rejects(fn()=>P::quote($rule, array_fill_keys(range(1,21), 'VIP'), 1000, $now), 'invalid_price');
echo "PASS: {$n} discount policy assertions (PHP only).\n";
