<?php

declare(strict_types=1);

namespace PESM\Domain\Event;

use PESM\Domain\Booking\HoldException;

/** Pure validation of administrative event changes; no payment side effects. */
final class EventChangePolicy
{
    public static function requestKey(string $key): void
    {
        if (!preg_match('/^[a-zA-Z0-9-]{32,128}$/D', $key)) {
            throw new HoldException('invalid_request_key', \PESM\Support\I18n::text('شناسه یکتای درخواست معتبر نیست.'), 422);
        }
    }

    public static function reason(string $reason): string
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        $length = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : preg_match_all('/./us', $reason);
        if ($length < 10 || $length > 500) {
            throw new HoldException('invalid_reason', \PESM\Support\I18n::text('علت تغییر باید بین ۱۰ تا ۵۰۰ نویسه باشد.'), 422);
        }
        return $reason;
    }

    public static function postpone(string $old, string $new, string $now, string $saleEnd): void
    {
        foreach ([$old, $new, $now, $saleEnd] as $value) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) {
                throw new HoldException('invalid_date', \PESM\Support\I18n::text('قالب تاریخ میلادی UTC نامعتبر است.'), 422);
            }
        }
        $oldTs = strtotime($old . ' UTC');
        $newTs = strtotime($new . ' UTC');
        $nowTs = strtotime($now . ' UTC');
        $saleTs = strtotime($saleEnd . ' UTC');
        if ($oldTs === false || $newTs === false || $nowTs === false || $saleTs === false ||
            gmdate('Y-m-d H:i:s', $newTs) !== $new || $newTs === $oldTs ||
            $newTs <= $nowTs + 3600 || $newTs <= $saleTs) {
            throw new HoldException('invalid_new_date', \PESM\Support\I18n::text('زمان جدید باید حداقل یک ساعت آینده و پس از پایان فروش باشد.'), 422);
        }
    }

    public static function cancellable(string $status): void
    {
        if (!in_array($status, ['draft', 'published', 'paused'], true)) {
            throw new HoldException('event_not_cancellable', \PESM\Support\I18n::text('این رویداد یا جلسه قابل لغو مجدد نیست.'), 409);
        }
    }
}
