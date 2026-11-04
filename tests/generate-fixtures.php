<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

require __DIR__ . '/../src/Domain/Hall/GridGenerator.php';

foreach ([[15,60,'layout-15x60.json'],[18,50,'layout-18x50.json']] as [$r,$c,$name]) {
    $payload = ['schema_version' => 1, 'rows' => $r, 'columns' => $c,
        'seat_count' => $r * $c, 'seats' => \PESM\Domain\Hall\GridGenerator::generate($r,$c)];
    file_put_contents(__DIR__ . '/../fixtures/' . $name,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    echo "$name generated\n";
}
