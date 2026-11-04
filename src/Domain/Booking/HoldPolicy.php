<?php

declare(strict_types=1);

namespace PESM\Domain\Booking;

use DateTimeImmutable;
use DateTimeZone;

/** Pure validation/state rules. All timestamps are database UTC strings. */
final class HoldPolicy
{
    public const TTL_SECONDS = 600;
    public const MAX_SEATS = 20;
    public const MAX_RENEWALS = 2;
    public const MAX_TOTAL_SECONDS = 1800;

    /** A hold cannot outlive the session or sale window, or be renewed indefinitely. */
    public static function renewalExpiry(
        string $now, string $created, string $currentExpiry,
        string $saleEnds, string $starts, int $renewals
    ): string {
        if ($renewals >= self::MAX_RENEWALS || $currentExpiry <= $now) {
            throw new HoldException('hold_not_renewable', \PESM\Support\I18n::text('مهلت تمدید رزرو پایان یافته است.'), 409);
        }
        $absolute = gmdate('Y-m-d H:i:s', strtotime($created . ' UTC') + self::MAX_TOTAL_SECONDS);
        $extended = min(self::expiry($now, min($saleEnds, $starts)), $absolute);
        if ($extended <= $currentExpiry) {
            throw new HoldException('hold_not_renewable', \PESM\Support\I18n::text('رزرو قابل تمدید بیشتر نیست.'), 409);
        }
        return $extended;
    }


    /** @param list<int> $seats @return list<int> */
    public static function normalizeSeats(array $seats): array
    {
        if (!$seats || count($seats) > self::MAX_SEATS) {
            throw new HoldException('invalid_selection', \PESM\Support\I18n::text('بین ۱ تا ۲۰ صندلی انتخاب کنید.'), 422);
        }
        foreach ($seats as $seat) {
            if (!is_int($seat) || $seat < 1) {
                throw new HoldException('invalid_selection', \PESM\Support\I18n::text('شناسه صندلی نامعتبر است.'), 422);
            }
        }
        $unique = array_values(array_unique($seats));
        sort($unique, SORT_NUMERIC);
        if (count($unique) !== count($seats)) {
            throw new HoldException('invalid_selection', \PESM\Support\I18n::text('صندلی تکراری در درخواست وجود دارد.'), 422);
        }
        return $unique;
    }

    public static function validateKey(string $key): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{32,128}$/D', $key)) {
            throw new HoldException('invalid_request_key', \PESM\Support\I18n::text('شناسه یکتای درخواست معتبر نیست.'), 422);
        }
    }

    public static function expiry(string $nowUtc, string $saleEndsUtc): string
    {
        $utc = new DateTimeZone('UTC');
        $now = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $nowUtc, $utc);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $saleEndsUtc, $utc);
        if (!$now || !$end || $now->format('Y-m-d H:i:s') !== $nowUtc || $end->format('Y-m-d H:i:s') !== $saleEndsUtc) {
            throw new HoldException('invalid_time', \PESM\Support\I18n::text('زمان معتبر در پایگاه داده یافت نشد.'), 503);
        }
        $expires = $now->modify('+' . self::TTL_SECONDS . ' seconds');
        return ($end < $expires ? $end : $expires)->format('Y-m-d H:i:s');
    }

    public static function canAcquire(string $status, ?string $expires, string $now): bool
    {
        return $status === 'available' || (in_array($status, ['held', 'payment_pending'], true) && $expires !== null && $expires <= $now);
    }
}
