<?php

declare(strict_types=1);

namespace PESM\Domain\Booking;

/** Pure, reusable invariants for unnumbered general-admission inventory. */
final class CapacityPolicy
{
    public const MAX_PER_REQUEST = 20;
    public const MAX_CAPACITY = 100000;

    public static function quantity(int $quantity): int
    {
        if ($quantity < 1 || $quantity > self::MAX_PER_REQUEST) {
            throw new HoldException('invalid_quantity', \PESM\Support\I18n::text('تعداد بلیت باید بین ۱ و ۲۰ باشد.'), 422);
        }
        return $quantity;
    }

    public static function capacity(int $capacity): int
    {
        if ($capacity < 1 || $capacity > self::MAX_CAPACITY) {
            throw new HoldException('invalid_capacity', \PESM\Support\I18n::text('ظرفیت باید بین ۱ و ۱۰۰٬۰۰۰ باشد.'), 422);
        }
        return $capacity;
    }

    public static function available(int $total, int $blocked, int $held, int $pending, int $sold): int
    {
        if (min($total, $blocked, $held, $pending, $sold) < 0 || $blocked + $held + $pending + $sold > $total) {
            throw new HoldException('inventory_inconsistent', \PESM\Support\I18n::text('شمارنده‌های موجودی با ظرفیت تطابق ندارند.'), 503);
        }
        return $total - $blocked - $held - $pending - $sold;
    }

    public static function saleOpen(object $session, string $now): void
    {
        if ((string) $session->mode !== 'general' || (string) $session->status !== 'published'
            || (string) $session->event_status !== 'published'
            || (string) $session->sale_starts_at > $now || (string) $session->sale_ends_at <= $now
            || (string) $session->starts_at <= $now) {
            throw new HoldException('event_closed', \PESM\Support\I18n::text('فروش ظرفیت آزاد این جلسه فعال نیست.'), 409);
        }
    }
}
