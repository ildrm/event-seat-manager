<?php

declare(strict_types=1);

namespace PESM\Domain\Event;

final class LifecyclePolicy
{
    /** @return list<string> */
    public static function allowedTargets(string $status): array
    {
        return match ($status) {
            'draft' => ['published', 'cancelled'],
            'published' => ['paused', 'cancelled'],
            'paused' => ['published', 'cancelled'],
            default => [],
        };
    }

    public static function allowed(string $from, string $to): bool
    {
        return in_array($to, self::allowedTargets($from), true);
    }
}
