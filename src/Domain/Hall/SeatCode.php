<?php

declare(strict_types=1);

namespace PESM\Domain\Hall;

/** The storage/import/editor contract for a display code, never an SQL identifier. */
final class SeatCode
{
    public static function valid(mixed $code): bool
    {
        return is_string($code) && preg_match('/^[A-Z0-9][A-Z0-9._-]{0,59}$/D', $code) === 1;
    }
}
