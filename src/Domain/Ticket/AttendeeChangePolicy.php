<?php

declare(strict_types=1);

namespace PESM\Domain\Ticket;

use PESM\Domain\Booking\HoldException;

/** No WordPress or database coupling: policy can be unit-tested independently. */
final class AttendeeChangePolicy
{
    public static function normalizedName(string $name): string
    {
        if (preg_match('/[\p{C}\x00-\x1F\x7F<>]/u', $name)) {
            throw new HoldException('invalid_attendee', \PESM\Support\I18n::text('نام شرکت‌کننده نامعتبر است.'), 422);
        }
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || strlen($name) > 190 || preg_match('/[\p{C}<>]/u', $name) ||
            !preg_match('/\p{L}/u', $name)) {
            throw new HoldException('invalid_attendee', \PESM\Support\I18n::text('نام شرکت‌کننده نامعتبر است.'), 422);
        }
        return $name;
    }

    public static function canChange(string $status, bool $checkedIn, string $sessionStartUtc, string $nowUtc): void
    {
        if ($status !== 'active' || $checkedIn) {
            throw new HoldException('ticket_locked', \PESM\Support\I18n::text('بلیت استفاده‌شده یا غیرفعال قابل ویرایش نیست.'), 409);
        }
        if ($sessionStartUtc === '' || $nowUtc === '' || $sessionStartUtc <= $nowUtc) {
            throw new HoldException('change_window_closed', \PESM\Support\I18n::text('مهلت ویرایش مشخصات شرکت‌کننده پایان یافته است.'), 409);
        }
    }
}
