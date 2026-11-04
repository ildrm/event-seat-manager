<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

require __DIR__ . '/../src/Domain/Hall/GridGenerator.php';

use PESM\Domain\Hall\GridGenerator;

function check(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
    echo "PASS: $message\n";
}

foreach ([[15, 60], [18, 50]] as [$rows, $cols]) {
    $items = GridGenerator::generate($rows, $cols);
    check(count($items) === 900, "$rows × $cols = 900 seats");
    check(count(array_unique(array_column($items, 'code'))) === 900, "$rows × $cols unique codes");
    check($items[0]['code'] === 'A-1', "$rows × $cols first seat A-1");
    check($items[899]['code'] === GridGenerator::rowLabel($rows - 1) . "-$cols", "$rows × $cols last seat");
    check(count(array_unique(array_column($items, 'x'))) === $cols, "$rows × $cols distinct columns");
    check(count(array_unique(array_column($items, 'y'))) === $rows, "$rows × $cols distinct rows");
}
check(GridGenerator::rowLabel(25) === 'Z', 'Row 26 = Z');
check(GridGenerator::rowLabel(26) === 'AA', 'Row 27 = AA');
try {
    GridGenerator::generate(1000, 60);
    throw new RuntimeException('Expected invalid layout rejection.');
} catch (InvalidArgumentException $e) {
    check(true, 'Oversized layout rejected');
}
foreach ([[15,60,'layout-15x60.json'],[18,50,'layout-18x50.json']] as [$rows,$cols,$file]) {
    $fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/' . $file), true, 512, JSON_THROW_ON_ERROR);
    check(count($fixture['seats']) === 900, "Fixture $file has 900 seat entries");
}
echo "All pure-PHP smoke checks passed. No WordPress/MySQL integration was run.\n";
