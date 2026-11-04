<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

require __DIR__ . '/../src/Domain/Hall/LayoutDocument.php';
require __DIR__ . '/../src/Domain/Calendar/CalendarFeed.php';

use PESM\Domain\Hall\LayoutDocument;
use PESM\Domain\Calendar\CalendarFeed;

$count = 0;
function verify09(bool $condition, string $text): void
{
    global $count;
    $count++;
    if (!$condition) { throw new RuntimeException("FAILED: {$text}"); }
}
function rejects09(string $json, string $message): void
{
    try { LayoutDocument::parse($json); } catch (InvalidArgumentException $e) { verify09(true, $message); return; }
    throw new RuntimeException("Unexpected acceptance: {$message}");
}
$fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/layout-15x60.json'), true, 16, JSON_THROW_ON_ERROR);
$export = LayoutDocument::encode($fixture['seats']);
$decoded = LayoutDocument::parse($export);
verify09(count($decoded) === 900, '900 seats round-trip');
verify09($decoded[0]['code'] === 'A-1' && $decoded[899]['code'] === 'O-60', 'stable codes');
verify09($decoded[0]['zone'] === 'جلو', 'Persian zone preserved');
verify09($decoded[0]['number'] === 1 && is_int($decoded[0]['number']), 'canonical numeric type');
verify09(LayoutDocument::parse(LayoutDocument::encode($decoded)) === $decoded, 'repeated round trip');
$fixture2 = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/layout-18x50.json'), true, 16, JSON_THROW_ON_ERROR);
verify09(count(LayoutDocument::parse(LayoutDocument::encode($fixture2['seats']))) === 900, '18x50 round-trip');
rejects09('', 'empty import');
rejects09('{', 'broken JSON');
rejects09('[]', 'root array');
rejects09('{"format":"evil","seats":[]}', 'bad format');
rejects09('{"format":"pesm.layout.v1","seats":[]}', 'empty seats');
rejects09(str_repeat('x', LayoutDocument::MAX_BYTES + 1), 'excess file size');
$one = $decoded[0];
$make = static fn (array $seats) => json_encode(['format'=>LayoutDocument::FORMAT,'seats'=>$seats], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
rejects09($make([$one, $one]), 'duplicated seat code');
foreach (['code'=>"S\nX",'row_label'=>'<row>', 'number'=>'1', 'zone'=>"bad\0",'x'=>1.5,'y'=>1000001] as $key=>$value) {
    $item=$one; $item[$key]=$value;
    rejects09($make([$item]), 'invalid field ' . $key);
}
$item=$one; $item['script']='<script>'; rejects09($make([$item]), 'unexpected imported property');
$item=$one; unset($item['zone']); rejects09($make([$item]), 'required property missing');
$cal = CalendarFeed::build([
    'session_id'=>123,'starts_at'=>'2026-12-15 16:30:00',
    'title'=>"اجلاس, توسعه; نرم‌افزار\nBEGIN:FAKE",'venue'=>'تالار اصلی',
    'description'=>str_repeat('توضیح فارسی ', 40),
], 'example.org', '20261102T080000Z');
verify09(str_contains($cal, 'BEGIN:VCALENDAR\r\n') === false, 'literal escaped line not in output');
verify09(str_contains($cal, "BEGIN:VCALENDAR\r\n"), 'CRLF calendar');
verify09(str_contains($cal, "DTSTART:20261215T163000Z\r\n"), 'UTC date');
verify09(str_contains($cal, 'UID:pesm-session-123@example.org'), 'stable UID');
verify09(str_contains($cal, '\\,') && str_contains($cal, '\\;'), 'escaped delimiters');
verify09(!str_contains($cal, "\r\nBEGIN:FAKE\r\n"), 'ICS injection blocked');
foreach (explode("\r\n", trim($cal)) as $line) {
    verify09(strlen($line) <= 75, 'line octet folding');
    verify09(preg_match('//u', $line) === 1, 'fold does not break UTF-8');
}
try {
    CalendarFeed::build(['session_id'=>1,'starts_at'=>'2026-02-30 10:00:00','title'=>'x','venue'=>'','description'=>''], 'example.org','20261102T080000Z');
    throw new RuntimeException('Invalid date accepted');
} catch (InvalidArgumentException $e) { verify09(true, 'invalid UTC date rejected'); }
try {
    CalendarFeed::build(['session_id'=>1,'starts_at'=>'2026-12-15 10:00:00','title'=>'x','venue'=>'','description'=>''], 'host\r\nX-TEST:1','20261102T080000Z');
    throw new RuntimeException('Invalid host accepted');
} catch (InvalidArgumentException $e) { verify09(true, 'host header injection rejected'); }
echo "PASS: {$count} layout/calendar unit assertions (no WordPress or DB).\n";
