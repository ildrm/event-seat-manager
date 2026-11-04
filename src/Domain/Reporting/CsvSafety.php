<?php

declare(strict_types=1);

namespace PESM\Domain\Reporting;

/** Neutralizes spreadsheet formula injection in user-controlled CSV fields. */
final class CsvSafety
{
    public static function safeCell(string $value): string
    {
        return preg_match('/^[\s\x00-\x1F]*[=+@\-]/u', $value) ? "'" . $value : $value;
    }
}
