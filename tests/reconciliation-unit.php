<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';
require __DIR__ . '/../src/Domain/Reconciliation/ReconciliationService.php';
use PESM\Domain\Reconciliation\ReconciliationService as R;
$now = '2026-11-02 12:00:00';
$base = ['hold_status'=>'payment_pending','link_status'=>'pending', 'expires_at'=>'2026-11-02 12:30:00', 'seat_count'=>2,'ticket_count'=>0,'linked_order_id'=>42];
$cases = [
    [$base, null],
    [array_merge($base,['expires_at'=>$now]),'expired_payment'],
    [array_merge($base,['linked_order_id'=>null]),'missing_order_link'],
    [array_merge($base,['hold_status'=>'checkout_creating','expires_at'=>$now]),'abandoned_checkout'],
    [array_merge($base,['link_status'=>'review_required']),'manual_review'],
    [array_merge($base,['hold_status'=>'sold','link_status'=>'issued','ticket_count'=>2]),null],
    [array_merge($base,['hold_status'=>'sold','link_status'=>'issued','ticket_count'=>1]),'ticket_inventory_mismatch'],
    [array_merge($base,['hold_status'=>'sold','link_status'=>'pending','ticket_count'=>2]),'ticket_inventory_mismatch'],
];
foreach ($cases as $i => [$row,$expected]) {
    $got=R::classify($row,$now);
    if ($got !== $expected) { throw new RuntimeException("Case $i: " . var_export($got,true)); }
}
echo 'PASS: '.count($cases)." reconciliation classification tests.\n";
$capacityCases = [
    [$base, null],
    [array_merge($base,['expires_at'=>$now]), 'expired_payment'],
    [array_merge($base,['linked_order_id'=>0]), 'missing_order_link'],
    [array_merge($base,['hold_status'=>'checkout_creating','expires_at'=>$now]), 'abandoned_checkout'],
    [array_merge($base,['link_status'=>'review_required']), 'manual_review'],
    [array_merge($base,['hold_status'=>'issued','link_status'=>'issued','ticket_count'=>2]), null],
    [array_merge($base,['hold_status'=>'issued','link_status'=>'issued','ticket_count'=>1]), 'ticket_inventory_mismatch'],
    [array_merge($base,['hold_status'=>'issued','link_status'=>'pending','ticket_count'=>2]), 'ticket_inventory_mismatch'],
];
foreach ($capacityCases as $i => [$row,$expected]) {
    if (R::classifyCapacity($row,$now) !== $expected) {
        throw new RuntimeException('General admission reconciliation case ' . $i);
    }
}
echo 'PASS: '.count($capacityCases)." general-admission reconciliation classifications.\n";
