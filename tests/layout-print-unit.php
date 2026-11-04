<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

require __DIR__ . '/../src/Domain/Hall/LayoutDocument.php';
require __DIR__ . '/../src/Domain/Hall/LayoutPrintRenderer.php';

use PESM\Domain\Hall\LayoutDocument;
use PESM\Domain\Hall\LayoutPrintRenderer;

$checks = 0;
function qa10(bool $test, string $message): void {
    global $checks;
    $checks++;
    if (!$test) { throw new RuntimeException('FAIL: ' . $message); }
}
foreach (['15x60', '18x50'] as $fixture) {
    $json = file_get_contents(__DIR__ . '/../fixtures/layout-' . $fixture . '.json');
    qa10(is_string($json), 'fixture exists');
    $original = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    $encoded = LayoutDocument::encode(array_map(static fn(array $seat): array => [
        'code' => $seat['code'], 'row_label' => $seat['row_label'], 'number' => $seat['number'],
        'zone' => $seat['zone'], 'x' => $seat['x'], 'y' => $seat['y'],
    ], $original['seats']));
    foreach (['A4', 'A3'] as $paper) {
        $html = LayoutPrintRenderer::render($encoded, 'سالن آزمایشی', $paper);
        qa10(str_contains($html, '@page{size:' . $paper . ' landscape'), 'paper');
        qa10(substr_count($html, '<circle ') === 900, 'overview has 900 physical seats');
        qa10(substr_count($html, '<div class="seat"') === 900, 'details have 900 numbered seats');
        qa10(substr_count($html, '<section class="page"') > 20, 'multiple detailed pages');
        qa10(str_contains($html, 'سالن آزمایشی'), 'fa heading');
        qa10(strlen($html) < 700_000, 'bounded output');
    }
}
$bad = LayoutDocument::encode([['code'=>'X-1','row_label'=>'X','number'=>1,'zone'=>'<script>alert(1)</script>', 'x'=>0,'y'=>0]]);
$html = LayoutPrintRenderer::render($bad, '<img src=x onerror=alert(1)>', 'A4');
qa10(!str_contains($html, '<script>') && !str_contains($html, '<img'), 'untrusted strings escaped');
qa10(str_contains($html, '&lt;script&gt;') && str_contains($html,'&lt;img'), 'escaped strings still shown');
try { LayoutPrintRenderer::render($bad, 'name', 'Letter'); throw new RuntimeException('Invalid paper accepted'); }
catch (InvalidArgumentException $e) { qa10(true, 'invalid paper rejected'); }
try { LayoutPrintRenderer::render('bad', 'name', 'A4'); throw new RuntimeException('Broken JSON accepted'); }
catch (InvalidArgumentException $e) { qa10(true, 'bad JSON rejected'); }
echo "PASS: {$checks} print-unit assertions (HTML not browser/PDF integration).\n";
