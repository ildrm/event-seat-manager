<?php

declare(strict_types=1);

namespace PESM\Domain\Checkout;

use PESM\Domain\Booking\HoldException;

/** Pure checkout validation; every price comes from the session row, never the browser. */
final class CheckoutPolicy
{
    public static function normalizePhone(string $raw): string
    {
        $raw = strtr(trim($raw), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            \PESM\Support\I18n::text('٠')=>'0',\PESM\Support\I18n::text('١')=>'1',\PESM\Support\I18n::text('٢')=>'2',\PESM\Support\I18n::text('٣')=>'3',\PESM\Support\I18n::text('٤')=>'4',\PESM\Support\I18n::text('٥')=>'5',\PESM\Support\I18n::text('٦')=>'6',\PESM\Support\I18n::text('٧')=>'7',\PESM\Support\I18n::text('٨')=>'8',\PESM\Support\I18n::text('٩')=>'9']);
        $raw = preg_replace('/[\s()\-]/u', '', $raw) ?? '';
        if (str_starts_with($raw, '0098')) { $raw = substr($raw, 4); }
        elseif (str_starts_with($raw, '+98')) { $raw = substr($raw, 3); }
        elseif (str_starts_with($raw, '98') && strlen($raw) === 12) { $raw = substr($raw, 2); }
        if (preg_match('/^9\d{9}$/D', $raw)) { $raw = '0' . $raw; }
        if (!preg_match('/^09\d{9}$/D', $raw)) {
            throw new HoldException('invalid_phone', \PESM\Support\I18n::text('شماره موبایل ایران معتبر نیست.'), 422);
        }
        return $raw;
    }

    public static function normalizeName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 90) {
            throw new HoldException('invalid_name', \PESM\Support\I18n::text('نام یا نام خانوادگی معتبر نیست.'), 422);
        }
        return $name;
    }

    public static function amount(int $minor, int $decimals): string
    {
        if ($minor < 0 || $decimals < 0 || $decimals > 6) {
            throw new HoldException('invalid_price', \PESM\Support\I18n::text('قیمت رویداد معتبر نیست.'), 503);
        }
        if ($decimals === 0) { return (string) $minor; }
        $digits = str_pad((string) $minor, $decimals + 1, '0', STR_PAD_LEFT);
        return substr($digits, 0, -$decimals) . '.' . substr($digits, -$decimals);
    }

    public static function phoneLimitAllows(int $existing, int $requested, int $limit): bool
    {
        return $existing >= 0 && $requested > 0 && $limit >= 1 && $existing + $requested <= $limit;
    }

    public static function payable(int $priceMinor, int $quantity, string $now, string $expiry): bool
    {
        // Match the pricing engine's bound and keep totals exactly representable
        // when WooCommerce converts decimal amounts to minor units.
        return $priceMinor >= 0 && $priceMinor <= 100000000000 &&
            $quantity > 0 && $quantity <= 20 && $expiry > $now;
    }
}
