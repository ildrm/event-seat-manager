<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

require_once __DIR__ . '/../src/Domain/Booking/HoldException.php';
require_once __DIR__ . '/../src/Domain/Checkout/CheckoutPolicy.php';
require_once __DIR__ . '/../src/Domain/Checkout/AttendeeManifest.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Checkout\AttendeeManifest as A;

$checks = 0;
function eq(mixed $expected, mixed $got, string $message): void
{
    global $checks;
    ++$checks;
    if ($expected !== $got) { throw new RuntimeException("$message: expected " . var_export($expected, true) . ' got ' . var_export($got, true)); }
}
function rejected(callable $f, string $message): void
{
    global $checks;
    ++$checks;
    try { $f(); } catch (HoldException $e) {
        if ($e->errorCode !== 'invalid_attendees' && $e->errorCode !== 'invalid_name') {
            throw new RuntimeException('Wrong code: ' . $e->errorCode);
        }
        return;
    }
    throw new RuntimeException('Accepted invalid manifest: ' . $message);
}
$buyer = A::seats(null, [4, 2], 'خریدار', 'اصلی');
eq([2, 4], array_column($buyer, 'seat_id'), 'seats sorted');
eq('خریدار اصلی', A::fullName($buyer[0]), 'buyer fallback name');
$people = A::seats([
    ['seat_id'=>4,'first_name'=>'علی','last_name'=>'نوری'],
    ['seat_id'=>2,'first_name'=>'سارا','last_name'=>'احمدی']
], [4,2], 'خریدار','اصلی');
eq('سارا احمدی', A::fullName($people[0]), 'unique name for seat 2');
eq('علی نوری', A::fullName($people[1]), 'unique name for seat 4');
eq($people, A::storedSeats($people, [2,4], 'x','y'), 'order meta snapshot stable');
eq(2, count(A::general(null, 2, 'خریدار', 'اصلی')), 'GA buyer fallback');
$g = A::general([
    ['first_name'=>'مهسا','last_name'=>'راد'],
    ['first_name'=>'امیر','last_name'=>'صالحی'],
], 2, 'خریدار', 'اصلی');
eq('مهسا راد', A::fullName($g[0]), 'first GA attendee');
eq(2, $g[1]['admission_index'], 'second GA index');
eq($g, A::storedGeneral($g, 2, 'x','y'), 'GA stored meta');
$invalidSeats = [
    [['seat_id'=>1,'first_name'=>'a','last_name'=>'b']],
    [['seat_id'=>2,'first_name'=>'a','last_name'=>'b']],
    [['seat_id'=>2,'first_name'=>'a','last_name'=>'b'], ['seat_id'=>2,'first_name'=>'c','last_name'=>'d']],
    [['seat_id'=>2,'first_name'=>'a','last_name'=>'b'], ['seat_id'=>99,'first_name'=>'c','last_name'=>'d']],
    [['seat_id'=>'2','first_name'=>'a','last_name'=>'b'], ['seat_id'=>4,'first_name'=>'c','last_name'=>'d']],
    [['seat_id'=>2,'first_name'=>'','last_name'=>'b'], ['seat_id'=>4,'first_name'=>'c','last_name'=>'d']],
    [['seat_id'=>2,'first_name'=>12,'last_name'=>'b'], ['seat_id'=>4,'first_name'=>'c','last_name'=>'d']],
    [['seat_id'=>2,'first_name'=>"Eve\n<script>",'last_name'=>'b'], ['seat_id'=>4,'first_name'=>'c','last_name'=>'d']],
];
foreach ($invalidSeats as $i => $bad) {
    rejected(static fn()=> A::seats($bad, [2,4], 'x','y'), 'seat case ' . $i);
}
rejected(static fn()=>A::seats('attacker', [2], 'x','y'), 'non array');
rejected(static fn()=>A::seats([], [2], 'x','y'), 'empty override');
rejected(static fn()=>A::seats(null, [2,2], 'x','y'), 'duplicate canonical seat ids');
rejected(static fn()=>A::seats(null, [], 'x','y'), 'zero seats');
rejected(static fn()=>A::general([], 2, 'x','y'), 'empty GA list');
rejected(static fn()=>A::general([['first_name'=>'A','last_name'=>'B']], 2, 'x','y'), 'incomplete GA list');
rejected(static fn()=>A::general([['seat_id'=>2,'first_name'=>'A','last_name'=>'B']], 1, 'x','y'), 'seat id in GA');
rejected(static fn()=>A::general([['admission_index'=>2,'first_name'=>'A','last_name'=>'B']], 1, 'x','y'), 'index tamper');
rejected(static fn()=>A::general(null, 21, 'x','y'), 'over 20 GA');
rejected(static fn()=>A::storedSeats([['seat_id'=>6,'first_name'=>'X','last_name'=>'Y']], [7], 'x','y'), 'tampered order meta');
rejected(static fn()=>A::storedGeneral([['admission_index'=>2,'first_name'=>'X','last_name'=>'Y']], 1, 'x','y'), 'tampered GA meta');
// Lightweight wiring regression: ensure the snapshot is both written and consumed.
$files = [
    __DIR__ . '/../src/Domain/Checkout/CheckoutService.php',
    __DIR__ . '/../src/Domain/GeneralAdmission/FreeAdmissionService.php',
    __DIR__ . '/../src/Domain/Ticket/TicketService.php',
];
foreach ($files as $file) {
    ++$checks;
    if (!str_contains(file_get_contents($file), '_pesm_attendees_v1')) {
        throw new RuntimeException('Missing order meta link: ' . basename($file));
    }
}
echo "OK: $checks attendee snapshot and validation assertions\n";
