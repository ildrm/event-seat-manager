<?php

declare(strict_types=1);

namespace PESM\Domain\Ticket;

use PESM\Domain\Booking\HoldException;

/** Rules shared by seated and general-admission ownership transfers. */
final class OwnershipTransferPolicy
{
    public const OTP_TTL = 600;
    public const MAX_ATTEMPTS = 5;

    public static function validateKind(string $kind): void
    {
        if (!in_array($kind, ['seated', 'general'], true)) {
            throw new HoldException('invalid_ticket_type', \PESM\Support\I18n::text('نوع بلیت نامعتبر است.'), 422);
        }
    }

    public static function validateKey(string $key): void
    {
        if (!preg_match('/^[a-f0-9]{32,128}$/D', $key)) {
            throw new HoldException('invalid_request_key', \PESM\Support\I18n::text('شناسه درخواست نامعتبر است.'), 422);
        }
    }

    public static function validateBearer(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('مجوز مشاهده یا انتقال بلیت معتبر نیست.'), 403);
        }
    }

    public static function transferable(string $ticketStatus, bool $hasCheckin, string $startUtc, string $nowUtc, string $eventStatus, string $sessionStatus): void
    {
        if ($ticketStatus !== 'active' || $hasCheckin || $eventStatus === 'cancelled' || $sessionStatus === 'cancelled') {
            throw new HoldException('ticket_locked', \PESM\Support\I18n::text('این بلیت برای انتقال فعال نیست.'), 409);
        }
        if ($startUtc === '' || $nowUtc === '' || $startUtc <= $nowUtc) {
            throw new HoldException('transfer_window_closed', \PESM\Support\I18n::text('مهلت انتقال بلیت پایان یافته است.'), 409);
        }
    }

    public static function verifyOtp(string $submitted, string $expectedHash, string $secret, int $id, int $attempts, string $deadline, string $now): bool
    {
        if ($attempts >= self::MAX_ATTEMPTS || $deadline <= $now || !preg_match('/^[0-9]{8}$/D', $submitted)) {
            return false;
        }
        return hash_equals($expectedHash, hash_hmac('sha256', "ownership-transfer:$id:$submitted", $secret));
    }
}
