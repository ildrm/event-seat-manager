<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/VenuePrint.php';

use PESM\Support\VenuePrint;

$checks = 0;
function venuePrintAssert(bool $test, string $message): void
{
    global $checks;
    ++$checks;
    if (!$test) { throw new RuntimeException('FAIL: ' . $message); }
}
function venuePrintReject(callable $action, string $message): void
{
    try { $action(); } catch (InvalidArgumentException $e) { venuePrintAssert(true, $message); return; }
    venuePrintAssert(false, $message);
}

$overview = ['locale' => 'en_US', 'capacity' => 4, 'sections' => [
    ['code' => 'S1', 'label' => 'Stalls', 'level' => 0, 'elevation' => 0, 'capacity' => 2, 'bounds' => ['x' => -1000, 'y' => -500, 'width' => 3000, 'height' => 2000]],
    ['code' => 'S2', 'label' => 'Balcony', 'level' => 1, 'elevation' => 5000, 'capacity' => 2, 'bounds' => ['x' => -1000, 'y' => -500, 'width' => 3000, 'height' => 2000]],
]];
$seats = [
    ['id' => 1, 'code' => 'S1-R1-N1', 'section_code' => 'S1', 'row_label' => 'R1', 'category_code' => 'STANDARD', 'x' => -500, 'y' => 0, 'rotation' => 90000, 'elevation' => 0, 'accessible' => true],
    ['id' => 2, 'code' => 'S1-R1-N2', 'section_code' => 'S1', 'row_label' => 'R1', 'category_code' => 'STANDARD', 'group_code' => 'T1', 'x' => 1000, 'y' => 0, 'rotation' => 0, 'elevation' => 0],
    ['id' => 3, 'code' => 'S2-R1-N1', 'section_code' => 'S2', 'row_label' => 'R1', 'category_code' => 'PREMIUM', 'x' => -500, 'y' => 0, 'rotation' => 0, 'elevation' => 5000],
    ['id' => 4, 'code' => 'S2-R1-N2', 'section_code' => 'S2', 'row_label' => 'R1', 'category_code' => '1', 'x' => 1000, 'y' => 0, 'rotation' => 0, 'elevation' => 5000],
];
$groups = [['code' => 'T1', 'section_code' => 'S1', 'type' => 'table', 'shape' => 'ellipse', 'x' => 600, 'y' => 600, 'width' => 1200, 'height' => 800, 'rotation' => 45000, 'elevation' => 0]];
$objects = [['id' => 'STAGE', 'type' => 'stage', 'label' => 'Main stage', 'x' => -8000, 'y' => -3000, 'width' => 4000, 'height' => 2000, 'rotation' => 45000, 'elevation' => 0]];
$html = VenuePrint::render($overview, $seats, $groups, $objects, 'Venue α — سالن');
venuePrintAssert(str_contains($html, '<html lang="en" dir="ltr">'), 'English direction is explicit');
venuePrintAssert(str_contains($html, 'Venue α — سالن'), 'Unicode title retained');
venuePrintAssert(substr_count($html, 'class="page overview-page"') === 2, 'overlapping tiers receive separate overview pages');
venuePrintAssert(substr_count($html, 'class="page detail-page"') === 2, 'overlapping tiers receive separate detail pages');
venuePrintAssert(substr_count($html, 'class="place"') === 4, 'all four places have actual geometry');
venuePrintAssert(str_contains($html, 'rotate(90 -300 200)'), 'seat orientation uses millidegrees around its center');
venuePrintAssert(str_contains($html, '<ellipse '), 'round table footprint');
venuePrintAssert(str_contains($html, 'data-elevation="5000"'), 'tier elevation retained');
venuePrintAssert(str_contains($html, 'S2-R1-N2') && str_contains($html, 'coordinates'), 'readable codes and physical coordinates table');
venuePrintAssert(str_contains($html, 'Accessible'), 'accessible seat has a textual label');
venuePrintAssert(str_contains($html, 'Content-Security-Policy') && str_contains($html, "script-src &#39;none&#39;") && str_contains($html, "connect-src &#39;none&#39;"), 'strict no-script/no-network policy');
venuePrintAssert(!preg_match('/<(?:script|iframe|link|img)\b/i', $html), 'no scripts, external styles, images or frames');
venuePrintAssert(str_contains($html, '@page{size:A4 landscape') && str_contains($html, 'print-color-adjust:exact'), 'print paper and physical category colors');
preg_match('/<svg[^>]+viewBox="([^"]+)"/', $html, $view);
$box = array_map('floatval', explode(' ', $view[1]));
foreach ([[-1,-1],[-1,1],[1,-1],[1,1]] as [$sx,$sy]) {
    $x = -6000 + $sx * 2000 * cos(M_PI / 4) - $sy * 1000 * sin(M_PI / 4);
    $y = -2000 + $sx * 2000 * sin(M_PI / 4) + $sy * 1000 * cos(M_PI / 4);
    venuePrintAssert($x > $box[0] && $x < $box[0] + $box[2] && $y > $box[1] && $y < $box[1] + $box[3], 'rotated negative focal-object corner is inside viewBox');
}

$faOverview = $overview; $faOverview['locale'] = 'fa_IR';
$fa = VenuePrint::render($faOverview, $seats, $groups, $objects, 'سالن همایش');
venuePrintAssert(str_contains($fa, '<html lang="fa" dir="rtl">') && str_contains($fa, 'طبقه 2'), 'Persian body and level labels');
venuePrintAssert(str_contains($fa, '.geometry{') && str_contains($fa, 'direction:ltr'), 'physical geometry keeps its direction in RTL');
venuePrintAssert(str_contains($fa, '<bdi>S1-R1-N1</bdi>'), 'seat codes are directionally isolated');

$higher = $overview;
$higher['sections'][1]['level'] = 8;
$highPrint = VenuePrint::render($higher, $seats, $groups, $objects);
venuePrintAssert(str_contains($highPrint, 'data-level="8"') && str_contains($highPrint, 'Level 9'), 'canonical level 8 receives its own printable page');
venuePrintAssert(substr_count($highPrint, 'class="page detail-page"') === 2 && str_contains($highPrint, 'S2-R1-N1'), 'level 8 retains associated individual geometry');
$highest = $higher;
$highest['sections'][1]['level'] = 100;
venuePrintAssert(str_contains(VenuePrint::render($highest, $seats), 'data-level="100"'), 'canonical highest level 100 is printable');
$invalidLevel = $higher;
$invalidLevel['sections'][1]['level'] = 101;
venuePrintReject(static fn() => VenuePrint::render($invalidLevel), 'levels above the canonical maximum are rejected');

$attack = '"><script>alert(1)</script><img src=x onerror=alert(2)>';
$badOverview = $overview; $badOverview['sections'][0]['label'] = $attack;
$badSeat = $seats[0]; $badSeat['code'] = $attack; $badSeat['category_code'] = $attack; $badSeat['attendee_name'] = 'SECRET CUSTOMER'; $badSeat['token'] = 'PRIVATE-BEARER';
$badObject = $objects[0]; $badObject['label'] = $attack;
$safe = VenuePrint::render($badOverview, [$badSeat], [], [$badObject], $attack);
venuePrintAssert(!preg_match('/<(?:script|img)\b/i', $safe), 'untrusted title/label/code cannot create executable tags');
venuePrintAssert(str_contains($safe, '&lt;script&gt;') && str_contains($safe, '&quot;'), 'untrusted content remains escaped and visible');
venuePrintAssert(!str_contains($safe, 'SECRET CUSTOMER') && !str_contains($safe, 'PRIVATE-BEARER'), 'print output excludes attendee and credential fields');
if (class_exists(DOMDocument::class)) {
    $dom = new DOMDocument(); $before = libxml_use_internal_errors(true); $dom->loadHTML($safe); libxml_clear_errors(); libxml_use_internal_errors($before);
    $xpath = new DOMXPath($dom);
    venuePrintAssert($xpath->query('//script|//img|//*[@onload]|//*[@onerror]|//*[@href]|//*[@src]')->length === 0, 'parsed document contains no executable, URL or event attributes');
}

$many = [];
for ($i = 0; $i < 1100; ++$i) { $many[] = ['code' => 'S1-' . ($i + 1), 'section_code' => 'S1', 'row_label' => 'R' . intdiv($i, 30), 'category_code' => 'GENERAL', 'x' => ($i % 30) * 600, 'y' => intdiv($i, 30) * 1000]; }
$partialOverview = ['locale' => 'en', 'capacity' => 3000, 'selected_section' => 'S1', 'sections' => [['code' => 'S1', 'label' => 'Stadium stand', 'level' => 0, 'capacity' => 3000, 'bounds' => ['x' => 0, 'y' => 0, 'width' => 30000, 'height' => 100000]]]];
$partial = VenuePrint::render($partialOverview, $many);
venuePrintAssert(substr_count($partial, 'class="place"') === 1000, 'detail geometry is capped at 1000');
venuePrintAssert(str_contains($partial, 'Partial detail: 1000 places shown out of 3000'), 'explicit truthful partial-section notice');
venuePrintAssert(!str_contains($partial, 'data-code="S1-1001"'), 'overflow places are not silently rendered');
venuePrintAssert(substr_count($partial, 'class="page code-page"') === 32, '1000 readable place codes are paginated in bounded tables');
venuePrintAssert(strlen($partial) < 1_500_000, 'large print output remains bounded');
$overviewOnly = VenuePrint::render($partialOverview);
venuePrintAssert(!str_contains($overviewOnly, 'class="place"') && str_contains($overviewOnly, 'Stadium stand'), 'overview does not load or invent individual places');

foreach (['0" onload="x', INF, NAN, 10000001] as $invalid) {
    venuePrintReject(static fn() => VenuePrint::render($overview, [['code' => 'BAD', 'x' => $invalid, 'y' => 0]]), 'invalid numeric geometry rejected');
}
venuePrintReject(static fn() => VenuePrint::render($overview, [], [], [['x' => 0, 'y' => 0, 'width' => -1, 'height' => 20]]), 'negative dimensions rejected');
venuePrintReject(static fn() => VenuePrint::render($overview, [['x' => 0, 'y' => 0, 'rotation' => '0) onload=x']]), 'rotation attribute injection rejected');
venuePrintReject(static fn() => VenuePrint::render(['sections' => array_fill(0, 201, [])]), 'overview section cap');
venuePrintReject(static fn() => VenuePrint::render($overview, [['x' => 0, 'y' => 0, 'code' => []]]), 'nonscalar labels rejected');

echo "PASS: {$checks} venue-print assertions (SVG geometry, bounded detail, tier isolation, Unicode, RTL, CSP and escaping).\n";
