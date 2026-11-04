<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

/** Deterministic, WordPress-independent tests; DOES NOT replace DB/concurrency tests. */
require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Domain/Booking/HoldPolicy.php';
require __DIR__ . '/../src/Domain/Event/LifecyclePolicy.php';
require __DIR__ . '/../src/Domain/Event/EventDetails.php';
require __DIR__ . '/../src/Support/Jalali.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Booking\HoldPolicy;
use PESM\Domain\Event\LifecyclePolicy;
use PESM\Domain\Event\EventDetails;
use PESM\Support\Jalali;

$checks = 0;
function same(mixed $actual, mixed $expected, string $message): void
{
    global $checks;
    if ($actual !== $expected) {
        throw new RuntimeException("FAIL: $message: " . json_encode([$actual, $expected], JSON_UNESCAPED_UNICODE));
    }
    ++$checks;
}
function fails(callable $fn, string $code): void
{
    global $checks;
    try { $fn(); }
    catch (HoldException $e) {
        same($e->errorCode, $code, 'expected error code'); return;
    }
    throw new RuntimeException("Expected failure: $code");
}
same(HoldPolicy::normalizeSeats([12, 3, 8]), [3, 8, 12], 'sort seats');
same(HoldPolicy::normalizeSeats([99]), [99], 'single seat');
same(count(HoldPolicy::normalizeSeats(range(1, 20))), 20, 'max batch');
fails(fn () => HoldPolicy::normalizeSeats([]), 'invalid_selection');
fails(fn () => HoldPolicy::normalizeSeats([1,1]), 'invalid_selection');
fails(fn () => HoldPolicy::normalizeSeats([0]), 'invalid_selection');
fails(fn () => HoldPolicy::normalizeSeats([-2]), 'invalid_selection');
fails(fn () => HoldPolicy::normalizeSeats([1, '2']), 'invalid_selection');
fails(fn () => HoldPolicy::normalizeSeats(range(1,21)), 'invalid_selection');
fails(fn () => HoldPolicy::validateKey('predictable'), 'invalid_request_key');
HoldPolicy::validateKey(str_repeat('f', 32)); ++$checks;
same(HoldPolicy::expiry('2026-11-02 12:00:00','2026-11-02 12:30:00'), '2026-11-02 12:10:00', 'regular ttl');
same(HoldPolicy::expiry('2026-11-02 12:00:00','2026-11-02 12:01:30'), '2026-11-02 12:01:30', 'sale end ttl clamp');
fails(fn () => HoldPolicy::expiry('not a date','2026-11-02 12:10:00'), 'invalid_time');
same(HoldPolicy::canAcquire('available', null,'2026-11-02 12:00:00'),true,'available');
same(HoldPolicy::canAcquire('held','2026-11-02 11:59:59','2026-11-02 12:00:00'),true,'expired hold');
same(HoldPolicy::canAcquire('held','2026-11-02 12:00:00','2026-11-02 12:00:00'),true,'boundary expired');
same(HoldPolicy::canAcquire('held','2026-11-02 12:00:01','2026-11-02 12:00:00'),false,'active hold');
same(HoldPolicy::canAcquire('held',null,'2026-11-02 12:00:00'),false,'unknown expiry denied');
same(HoldPolicy::canAcquire('sold',null,'2026-11-02 12:00:00'),false,'sold denied');
same(HoldPolicy::canAcquire('blocked',null,'2026-11-02 12:00:00'),false,'blocked denied');
same(HoldPolicy::canAcquire('payment_pending',null,'2026-11-02 12:00:00'),false,'active payment pending denied');
same(HoldPolicy::canAcquire('payment_pending','2026-11-02 11:59:59','2026-11-02 12:00:00'),true,'expired payment pending reclaimed');
foreach ([['draft','published',true],['published','paused',true],['paused','published',true],
    ['published','draft',false],['cancelled','published',false],['draft','paused',false]] as [$from,$to,$expected]) {
    same(LifecyclePolicy::allowed($from,$to),$expected,"$from -> $to");
}
same(Jalali::fromGregorian(2024, 3, 20), [1403,1,1], 'jalali Nowruz 1403');
same(Jalali::fromGregorian(2025, 3, 21), [1404,1,1], 'jalali Nowruz 1404');
same(Jalali::fromGregorian(2026, 11, 1), [1405,8,10], 'jalali 1405-08-10');
same(Jalali::formatTehran('2026-11-02 12:00:00'), '1405/08/11 — 15:30', 'UTC to Tehran');
try {
    Jalali::fromGregorian(2026, 2, 30);
    throw new RuntimeException('Accepted invalid date');
} catch (InvalidArgumentException $e) { ++$checks; }
same(EventDetails::normalize([])['description'], '', 'empty event detail');
same(EventDetails::normalize(['organizer' => '  برگزارکننده  '])['organizer'], 'برگزارکننده', 'event detail trimmed');
same(EventDetails::normalize(['poster_attachment_id' => 123])['poster_attachment_id'], 123, 'attachment ID');
try { EventDetails::normalize(['contact' => [123]]); throw new RuntimeException('accepted invalid detail'); }
catch (InvalidArgumentException $e) { ++$checks; }
try { EventDetails::normalize(['poster_attachment_id' => -2]); throw new RuntimeException('accepted negative attachment'); }
catch (InvalidArgumentException $e) { ++$checks; }
echo "PASS: $checks pure-PHP assertions; no WordPress/MySQL integration performed.\n";
