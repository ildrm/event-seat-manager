<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

require __DIR__ . '/../src/Domain/Reporting/XlsxExporter.php';
use PESM\Domain\Reporting\XlsxExporter;

$assertions = 0;
$report = ['center_id' => 7, 'sessions' => [
    ['session_id' => 1, 'title' => '=HYPERLINK("https://invalid.test") & <دو>', 'starts_at' => '2026-11-02 15:00:00',
        'capacity' => 900, 'available' => 650, 'held' => 5, 'payment_pending' => 5,
        'sold' => 240, 'blocked' => 0, 'active_tickets' => 220, 'checked_in' => 48, 'no_show' => 172],
    ['session_id' => 2, 'title' => 'رویداد دوم', 'starts_at' => '2026-11-03 15:00:00',
        'capacity' => 900, 'available' => 900, 'held' => 0, 'payment_pending' => 0,
        'sold' => 0, 'blocked' => 0, 'active_tickets' => 0, 'checked_in' => 0, 'no_show' => 0]
]];
$file = XlsxExporter::toFile($report);
if (!is_file($file)) { throw new RuntimeException('Missing XLSX file'); }
$raw = file_get_contents($file);
if (strlen((string) $raw) < 1500 || !str_starts_with((string) $raw, 'PK')) { throw new RuntimeException('Not a ZIP-based OOXML file'); }
$assertions += 2;
$archive = new PharData($file, 0, null, Phar::ZIP);
foreach (['[Content_Types].xml', 'xl/workbook.xml','xl/styles.xml','xl/worksheets/sheet1.xml','xl/_rels/workbook.xml.rels'] as $name) {
    if (!isset($archive[$name])) { throw new RuntimeException('Missing OOXML part ' . $name); }
    $assertions++;
}
$sheet = file_get_contents('phar://' . $file . '/xl/worksheets/sheet1.xml');
foreach (['rightToLeft="1"', 't="inlineStr"', 'r="D2"', '<v>900</v>', 'HYPERLINK', '&lt;دو&gt;', 'autoFilter ref="A1:L3"'] as $token) {
    if (!str_contains((string) $sheet, $token)) { throw new RuntimeException('Expected token absent: ' . $token); }
    $assertions++;
}
if (str_contains((string) $sheet, '<f>') || str_contains((string) $sheet, 'https://invalid.test"')) {
    throw new RuntimeException('Unwanted formula or unescaped string');
}
$assertions++;
unset($archive);
@unlink($file);
try {
    XlsxExporter::toFile(['center_id'=>7,'sessions'=>[['session_id'=>'abc']]]);
    throw new RuntimeException('Invalid numeric value accepted');
} catch (InvalidArgumentException $e) { $assertions++; }
try {
    XlsxExporter::toFile(['center_id'=>7,'sessions'=>array_fill(0, 5001, [])]);
    throw new RuntimeException('Unbounded export accepted');
} catch (InvalidArgumentException $e) { $assertions++; }
echo "PASS: $assertions XLSX integrity/injection/bounds assertions.\n";
