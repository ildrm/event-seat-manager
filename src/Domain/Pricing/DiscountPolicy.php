<?php

declare(strict_types=1);

namespace PESM\Domain\Pricing;

use PESM\Domain\Booking\HoldException;

/** Pure integer-minor-unit arithmetic. No browser-supplied prices. */
final class DiscountPolicy
{
    public static function code(string $code): string
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{3,31}$/D', $code)) {
            throw new HoldException('invalid_coupon', \PESM\Support\I18n::text('کد تخفیف معتبر نیست.'), 422);
        }
        return $code;
    }

    /** @param array<string,mixed> $rule @param array<int,string> $zonesBySeat
     * @return array{base_minor:int,discount_minor:int,payable_minor:int,seat_discounts:array<int,int>}
     */
    public static function quote(array $rule, array $zonesBySeat, int $unitMinor, string $now): array
    {
        if ($unitMinor < 0 || $unitMinor > 100000000000 || !$zonesBySeat || count($zonesBySeat) > 20) {
            throw new HoldException('invalid_price', \PESM\Support\I18n::text('مبلغ پایه سفارش معتبر نیست.'), 422);
        }
        if ($unitMinor > intdiv(PHP_INT_MAX, count($zonesBySeat))) {
            throw new HoldException('invalid_price', \PESM\Support\I18n::text('مبلغ سفارش بیش از حد مجاز است.'), 422);
        }
        $base = $unitMinor * count($zonesBySeat);
        if ((int) ($rule['enabled'] ?? 0) !== 1 ||
            (string) ($rule['starts_at'] ?? '') > $now || (string) ($rule['ends_at'] ?? '') <= $now) {
            throw new HoldException('coupon_unavailable', \PESM\Support\I18n::text('کد تخفیف در این زمان فعال نیست.'), 409);
        }
        if ($base < (int) ($rule['min_order_minor'] ?? 0)) {
            throw new HoldException('coupon_minimum', \PESM\Support\I18n::text('حداقل مبلغ خرید برای این تخفیف رعایت نشده است.'), 409);
        }
        $zone = (string) ($rule['zone_label'] ?? '');
        $eligible = [];
        foreach ($zonesBySeat as $seatId => $seatZone) {
            if ($zone === '' || $zone === $seatZone) { $eligible[] = (int) $seatId; }
        }
        sort($eligible, SORT_NUMERIC);
        if (!$eligible) { throw new HoldException('coupon_not_applicable', \PESM\Support\I18n::text('کد تخفیف برای جایگاه انتخاب‌شده معتبر نیست.'), 409); }
        $kind = (string) ($rule['kind'] ?? '');
        $value = (int) ($rule['discount_value'] ?? $rule['value'] ?? -1);
        $eligibleBase = $unitMinor * count($eligible);
        if ($kind === 'percent' && $value >= 1 && $value <= 10000) {
            // Basis points, half-up once at the aggregate level; distribute deterministically.
            $discount = intdiv($eligibleBase * $value + 5000, 10000);
        } elseif ($kind === 'fixed' && $value >= 1 && $value <= 100000000000) {
            $discount = min($eligibleBase, $value);
        } else {
            throw new HoldException('invalid_coupon', \PESM\Support\I18n::text('تنظیمات مبلغ تخفیف معتبر نیست.'), 422);
        }
        $bySeat = array_fill_keys(array_map('intval', array_keys($zonesBySeat)), 0);
        $each = intdiv($discount, count($eligible));
        $remainder = $discount % count($eligible);
        foreach ($eligible as $index => $seatId) {
            $bySeat[$seatId] = $each + ($index < $remainder ? 1 : 0);
            if ($bySeat[$seatId] > $unitMinor) {
                throw new HoldException('invalid_coupon', \PESM\Support\I18n::text('توزیع تخفیف معتبر نیست.'), 422);
            }
        }
        ksort($bySeat, SORT_NUMERIC);
        return ['base_minor' => $base, 'discount_minor' => $discount,
            'payable_minor' => $base - $discount, 'seat_discounts' => $bySeat];
    }
}
