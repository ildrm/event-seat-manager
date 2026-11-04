<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Domain/Booking/HoldPolicy.php';
require __DIR__ . '/../src/Domain/Booking/SeatingRules.php';
require __DIR__ . '/../src/Domain/Booking/SeatSuggestions.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Booking\SeatingRules;
use PESM\Domain\Booking\SeatSuggestions;

function assertSeating(bool $condition, string $label): void
{
    global $checked;
    if (!$condition) { throw new RuntimeException('FAIL ' . $label); }
    ++$checked;
}
function expectsCode(callable $fn, string $code): void
{
    try { $fn(); }
    catch (HoldException $error) {
        assertSeating($error->errorCode === $code, $code . ' error code');
        return;
    }
    throw new RuntimeException('Expected ' . $code);
}
$checked = 0;
$now = '2026-11-02 12:00:00';
$fixture = json_decode(file_get_contents(__DIR__ . '/../fixtures/layout-15x60.json'), true, 512, JSON_THROW_ON_ERROR);
assertSeating(is_array($fixture), 'read fixture');
$source = $fixture['seats'] ?? $fixture;
$rows = [];
foreach ($source as $i => $seat) {
    $rows[] = [
        'seat_id' => $i + 1, 'row_label' => $seat['row_label'],
        'zone_label' => $seat['zone'] ?? $seat['zone_label'], 'pos_x' => $seat['x'] ?? $seat['pos_x'],
        'pos_y' => $seat['y'] ?? $seat['pos_y'], 'status' => 'available',
        'hold_id' => null, 'expires_at' => null,
    ];
}
assertSeating(count($rows) === 900, '900 real seats');
$graph = SeatingRules::graph($rows);
assertSeating(in_array(14, $graph[15], true), 'same block connected');
assertSeating(!in_array(16, $graph[15], true), 'aisle separates neighbors');
assertSeating(!in_array(61, $graph[1], true), 'rows never cross-connect');
SeatingRules::validate($rows, [1,2,3], $now, true, false);
assertSeating(true, 'contiguous selection');
expectsCode(fn()=>SeatingRules::validate($rows, [15,16], $now, true, false), 'seats_not_adjacent');
expectsCode(fn()=>SeatingRules::validate($rows, [1,3], $now, true, false), 'seats_not_adjacent');
SeatingRules::validate($rows, [15,16], $now, false, false);
assertSeating(true, 'rule off allows crossing an aisle');
expectsCode(fn()=>SeatingRules::validate($rows, [2,4], $now, false, true), 'single_seat_gap');
SeatingRules::validate($rows, [1,2,3], $now, true, true);
assertSeating(true, 'no gap when group starts row');
$rows[0]['status'] = 'sold';
expectsCode(fn()=>SeatingRules::validate($rows, [3], $now, false, true), 'single_seat_gap');
$rows[1]['status'] = 'blocked';
SeatingRules::validate($rows, [10], $now, false, true);
assertSeating(true, 'existing isolated unavailable position does not poison unrelated selections');
$rows[0]['status'] = 'available'; $rows[1]['status'] = 'available';
$rows[2]['status'] = 'held'; $rows[2]['hold_id'] = 123; $rows[2]['expires_at'] = '2026-11-02 12:09:00';
SeatingRules::validate($rows, [4], $now, false, true, 123);
assertSeating(true, 'own held seat released in baseline during replacement');
$rows[2]['status'] = 'available'; $rows[2]['hold_id'] = null; $rows[2]['expires_at'] = null;
$rows[3]['status'] = 'held'; $rows[3]['expires_at'] = '2026-11-02 12:01:00';
$recommended = SeatSuggestions::recommend($rows, 3, $now, true);
assertSeating(count($recommended) === 5, 'five suggested groups');
foreach ($recommended as $group) {
    assertSeating(count($group) === 3, 'suggested group size');
    assertSeating(!in_array(4, $group, true), 'active hold not recommended');
    SeatingRules::validate($rows, $group, $now, true, true);
}
$rows[3]['expires_at'] = '2026-11-02 11:59:59';
$recommended2 = SeatSuggestions::recommend($rows, 2, $now, false);
assertSeating((bool) $recommended2, 'expired hold may be recommended');
expectsCode(fn()=>SeatSuggestions::recommend($rows, 21, $now), 'invalid_selection');
$custom = [
    ['seat_id'=>1,'row_label'=>'A','zone_label'=>'a','pos_x'=>10,'pos_y'=>10,'status'=>'available'],
    ['seat_id'=>2,'row_label'=>'A','zone_label'=>'b','pos_x'=>46,'pos_y'=>10,'status'=>'available'],
];
expectsCode(fn()=>SeatingRules::validate($custom, [1,2], $now, true, false), 'seats_not_adjacent');
assertSeating(SeatingRules::graph($custom)[1] === [], 'different zones are not adjacent');
echo "PASS: {$checked} seating topology / gap / suggestion assertions (pure PHP).\n";
