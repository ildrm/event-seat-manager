<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Domain/Booking/HoldPolicy.php';

use PESM\Domain\Booking\HoldPolicy;
use PESM\Domain\Booking\HoldException;

$count = 0;
function test(bool $ok, string $label): void {
    global $count;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    ++$count;
}
function rejected(callable $fn, string $code): void {
    try { $fn(); } catch (HoldException $e) {
        test($e->errorCode === $code, 'reject ' . $code); return;
    }
    throw new RuntimeException('Expected: ' . $code);
}
$created = '2026-11-02 12:00:00';
test(HoldPolicy::renewalExpiry('2026-11-02 12:08:00', $created, '2026-11-02 12:10:00',
        '2026-11-02 13:00:00', '2026-11-02 15:00:00', 0) === '2026-11-02 12:18:00', 'renewal adds 10 minutes');
test(HoldPolicy::renewalExpiry('2026-11-02 12:22:00', $created, '2026-11-02 12:25:00',
        '2026-11-02 14:00:00', '2026-11-02 15:00:00', 1) === '2026-11-02 12:30:00', 'absolute 30-minute cap');
test(HoldPolicy::renewalExpiry('2026-11-02 12:08:00', $created, '2026-11-02 12:10:00',
        '2026-11-02 12:15:00', '2026-11-02 15:00:00', 0) === '2026-11-02 12:15:00', 'sale end bound');
rejected(fn() => HoldPolicy::renewalExpiry('2026-11-02 12:20:00', $created, '2026-11-02 12:20:00',
    '2026-11-02 18:00:00', '2026-11-02 20:00:00', 1), 'hold_not_renewable');
rejected(fn() => HoldPolicy::renewalExpiry('2026-11-02 12:21:00', $created, '2026-11-02 12:25:00',
    '2026-11-02 18:00:00', '2026-11-02 20:00:00', 2), 'hold_not_renewable');
rejected(fn() => HoldPolicy::renewalExpiry('2026-11-02 12:29:00', $created, '2026-11-02 12:30:00',
    '2026-11-02 18:00:00', '2026-11-02 20:00:00', 1), 'hold_not_renewable');
echo "PASS: {$count} bounded hold-renewal assertions. No DB integration tested.\n";
