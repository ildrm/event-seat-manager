<?php

declare(strict_types=1);

namespace PESM\Domain\Ticket;

use PESM\Domain\Booking\HoldException;

/** Fail-closed rules for a per-ticket, WooCommerce-recorded refund. */
final class RefundPolicy
{
    public static function requestKey(string $key): void
    {
        if (!preg_match('/^[a-f0-9]{32,128}$/D', $key)) {
            throw new HoldException('invalid_request_key', \PESM\Support\I18n::text('کلید درخواست استرداد نامعتبر است.'), 422);
        }
    }

    public static function reason(string $reason): string
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        $length = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
        if ($length < 10 || $length > 500) {
            throw new HoldException('invalid_reason', \PESM\Support\I18n::text('علت استرداد باید بین ۱۰ و ۵۰۰ نویسه باشد.'), 422);
        }
        return $reason;
    }

    public static function ticketEligible(string $status, bool $checkedIn, string $start, string $now): void
    {
        if ($status !== 'active' || $checkedIn) {
            throw new HoldException('ticket_not_refundable', \PESM\Support\I18n::text('بلیت استفاده‌شده یا غیرفعال قابل استرداد از این مسیر نیست.'), 409);
        }
        if ($start <= $now) {
            throw new HoldException('event_started', \PESM\Support\I18n::text('پس از آغاز جلسه، استرداد خودکار جایگاه مجاز نیست.'), 409);
        }
    }

    /**
     * Converts a WooCommerce line-item total into an exact integer minor-unit amount.
     * Taxes and previously refunded orders are rejected in this initial implementation.
     */
    public static function ticketAmount(string $total, string $tax, int $quantity, int $decimals, int $alreadyRefundedMinor): int
    {
        if ($quantity !== 1 || $decimals < 0 || $decimals > 4 || $alreadyRefundedMinor !== 0 ||
            !preg_match('/^\d+(?:\.\d{1,4})?$/D', $total) ||
            !preg_match('/^\d+(?:\.\d{1,4})?$/D', $tax)) {
            throw new HoldException('refund_manual_review', \PESM\Support\I18n::text('محاسبه این استرداد نیازمند بررسی مالی است.'), 409);
        }
        if ((float) $tax !== 0.0) {
            throw new HoldException('refund_tax_unsupported', \PESM\Support\I18n::text('استرداد مالیات‌دار فعلاً به بررسی دستی نیاز دارد.'), 409);
        }
        $parts = explode('.', $total, 2);
        $fraction = $parts[1] ?? '';
        if (strlen($fraction) > $decimals || strlen($parts[0]) > 14) {
            throw new HoldException('refund_manual_review', \PESM\Support\I18n::text('دقت پولی سفارش قابل پشتیبانی نیست.'), 409);
        }
        $scale = 10 ** $decimals;
        $amount = ((int)$parts[0]) * $scale + (int)str_pad($fraction, $decimals, '0');
        if ($amount <= 0 || $amount > PHP_INT_MAX / 10) {
            throw new HoldException('refund_manual_review', \PESM\Support\I18n::text('مبلغ بلیت برای استرداد قابل تعیین نیست.'), 409);
        }
        return $amount;
    }

    /**
     * @param array<int,array{item_id:int,quantity:int,total_minor:int}> $refundLines
     * @return int matching refund id, or zero when it is not yet recorded
     */
    public static function matchingRefund(array $refundLines, int $originalItemId, int $expectedMinor): bool
    {
        $found = 0;
        foreach ($refundLines as $line) {
            if (($line['item_id'] ?? 0) !== $originalItemId) { continue; }
            if (($line['quantity'] ?? 0) !== 1 || ($line['total_minor'] ?? 0) !== $expectedMinor) {
                return false;
            }
            ++$found;
        }
        return $found === 1;
    }
}
