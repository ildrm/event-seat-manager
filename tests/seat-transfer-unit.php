<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

/** Standalone domain-policy checks; does not simulate WordPress or database isolation. */
require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Domain/Booking/HoldPolicy.php';
require __DIR__ . '/../src/Domain/Booking/SeatingRules.php';
require __DIR__ . '/../src/Domain/Ticket/SeatTransferPolicy.php';
require __DIR__ . '/../src/Domain/Ticket/TicketToken.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Ticket\SeatTransferPolicy as Policy;
use PESM\Domain\Ticket\TicketToken;

$checks = 0;
function ok(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    ++$checks;
}
function denied(callable $callback, string $code): void {
    try { $callback(); } catch (HoldException $e) {
        ok($e->errorCode === $code, 'expected ' . $code . ', got ' . $e->errorCode); return;
    }
    throw new RuntimeException('Expected ' . $code);
}
$validKey = str_repeat('a', 32);
Policy::validate(3, 4, $validKey);
++$checks;
denied(fn() => Policy::validate(0, 3, $validKey), 'invalid_selection');
denied(fn() => Policy::validate(1, 0, $validKey), 'invalid_selection');
denied(fn() => Policy::validate(1, 2, 'abc'), 'invalid_request_key');
Policy::mustBeActive('active', 'published', 'published', '2026-11-03 12:00:00', '2026-11-02 12:00:00', false);
++$checks;
denied(fn() => Policy::mustBeActive('checked_in', 'published', 'published', '2026-11-03 12:00:00', '2026-11-02 12:00:00', true), 'ticket_not_transferable');
denied(fn() => Policy::mustBeActive('active', 'published', 'published', '2026-11-03 12:00:00', '2026-11-02 12:00:00', true), 'ticket_not_transferable');
denied(fn() => Policy::mustBeActive('active', 'cancelled', 'published', '2026-11-03 12:00:00', '2026-11-02 12:00:00', false), 'event_closed');
denied(fn() => Policy::mustBeActive('active', 'published', 'published', '2026-11-02 12:00:00', '2026-11-02 12:00:00', false), 'event_closed');
denied(fn() => Policy::assertDestination('held'), 'seat_unavailable');
denied(fn() => Policy::assertDestination('payment_pending'), 'seat_unavailable');
denied(fn() => Policy::assertDestination('sold'), 'seat_unavailable');
Policy::assertDestination('available'); ++$checks;
$rows = [];
foreach (range(1, 6) as $i) {
    $rows[] = ['seat_id'=>$i, 'status'=>'available', 'row_label'=>'A',
        'zone_label'=>'main', 'pos_x'=>$i*30, 'pos_y'=>10];
}
$rows[0]['status'] = 'sold';
$rows[1]['status'] = 'sold';
// A1,A2 sold. Moving A1 to A3 keeps A2+A3 adjacent, but leaves isolated A1.
Policy::validateTopology($rows, [2], 1, 3, true, false); ++$checks;
denied(fn() => Policy::validateTopology($rows, [2], 1, 3, true, true), 'single_seat_gap');
denied(fn() => Policy::validateTopology($rows, [2], 1, 5, true, false), 'seats_not_adjacent');
// Moving a single ticket into a different free row without a gap policy is allowed.
Policy::validateTopology($rows, [], 1, 5, false, false); ++$checks;
$publicId = str_repeat('b', 32);
$secret = str_repeat('z', 40);
$old = TicketToken::create($publicId, 1, $secret);
$new = TicketToken::create($publicId, 2, $secret);
ok($old !== $new, 'new version produces different QR');
ok(!TicketToken::valid($old, $publicId, 2, $secret), 'old QR invalid for new version');
ok(TicketToken::valid($new, $publicId, 2, $secret), 'rotated QR validates');
echo "PASS: {$checks} pure seat-transfer assertions. No real WordPress/InnoDB tests.\n";
