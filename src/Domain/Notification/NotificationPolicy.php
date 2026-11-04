<?php

declare(strict_types=1);

namespace PESM\Domain\Notification;

use InvalidArgumentException;

/** All notification content is derived from verified server-side order data. */
final class NotificationPolicy
{
    public const EVENTS = ['ticket_confirmed'];
    public const MAX_ATTEMPTS = 4;

    public static function normalizeIranMobile(string $mobile): string
    {
        $mobile = strtr($mobile, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            \PESM\Support\I18n::text('٠')=>'0',\PESM\Support\I18n::text('١')=>'1',\PESM\Support\I18n::text('٢')=>'2',\PESM\Support\I18n::text('٣')=>'3',\PESM\Support\I18n::text('٤')=>'4',\PESM\Support\I18n::text('٥')=>'5',\PESM\Support\I18n::text('٦')=>'6',\PESM\Support\I18n::text('٧')=>'7',\PESM\Support\I18n::text('٨')=>'8',\PESM\Support\I18n::text('٩')=>'9']);
        $mobile = preg_replace('/[\s()\-]/u', '', $mobile) ?? '';
        if (str_starts_with($mobile, '+98')) { $mobile = '0' . substr($mobile, 3); }
        elseif (str_starts_with($mobile, '0098')) { $mobile = '0' . substr($mobile, 4); }
        elseif (str_starts_with($mobile, '98') && strlen($mobile) === 12) { $mobile = '0' . substr($mobile, 2); }
        if (!preg_match('/^09\d{9}$/D', $mobile)) {
            throw new InvalidArgumentException('Invalid Iranian mobile.');
        }
        return $mobile;
    }

    /** An OTP, QR, name, guest token or telephone number must never appear in this text. */
    public static function orderText(int $orderId): string
    {
        if ($orderId < 1) { throw new InvalidArgumentException('Invalid order.'); }
        return \PESM\Support\I18n::text('بلیت‌های سفارش ') . $orderId . \PESM\Support\I18n::text(' صادر شدند. برای مشاهده از بخش بلیت‌های من در وب‌سایت استفاده کنید.');
    }

    /** The provider's approved pattern accepts one order number as a parameter. */
    public static function patternVariables(int $orderId): string
    {
        if ($orderId < 1) { throw new InvalidArgumentException('Invalid order.'); }
        return (string) $orderId;
    }

    public static function retryDelay(int $attempts): int
    {
        return min(3600, 60 * (2 ** max(0, min($attempts - 1, 6))));
    }

    public static function dedupeKey(string $event, int $orderId, string $channel): string
    {
        if (!in_array($event, self::EVENTS, true) || $orderId <= 0 || !in_array($channel, ['email','sms'], true)) {
            throw new InvalidArgumentException('Invalid notification identity.');
        }
        return hash('sha256', 'pesm-notify-v1|' . $event . '|' . $orderId . '|' . $channel);
    }
}
