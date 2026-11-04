<?php

declare(strict_types=1);

namespace PESM\Domain\GeneralAdmission;

use PESM\Domain\Booking\HoldException;

/** Pure policy for free, unnumbered admission. No payment assumptions. */
final class AdmissionPolicy
{
    public static function assertFree(int $unitPriceMinor, string $mode): void
    {
        if ($mode !== 'general') {
            throw new HoldException('invalid_session', \PESM\Support\I18n::text('این جلسه از نوع ظرفیت آزاد نیست.'), 409);
        }
        if ($unitPriceMinor !== 0) {
            throw new HoldException('paid_admission_disabled', \PESM\Support\I18n::text('پرداخت ظرفیت آزاد هنوز در محیط واقعی تأیید نشده است.'), 409);
        }
    }

    /** Paid GA is gated until real WooCommerce/InnoDB acceptance tests succeed. */
    public static function assertCheckoutAllowed(int $minor, string $mode): void
    {
        if ($mode !== 'general' || $minor < 0) {
            throw new HoldException('invalid_session', \PESM\Support\I18n::text('نوع یا مبلغ جلسه معتبر نیست.'), 409);
        }
        if ($minor > 0) {
            if (get_option('pesm_enable_paid_general_preview', 'no') !== 'yes') {
                throw new HoldException('paid_admission_disabled', \PESM\Support\I18n::text('فروش پولی ظرفیت آزاد هنوز فعال نشده است.'), 409);
            }
            // Order totals are the server snapshot; until taxes are reconciled,
            // fail closed when WooCommerce tax calculations are enabled.
            if (!function_exists('wc_tax_enabled') || wc_tax_enabled()) {
                throw new HoldException('paid_tax_unsupported', \PESM\Support\I18n::text('فروش پولی با مالیات فعال هنوز پشتیبانی نمی‌شود.'), 409);
            }
        }
    }

    public static function assertHold(object $hold, string $now): void
    {
        if ($hold->status !== 'active' || $hold->expires_at <= $now) {
            throw new HoldException('hold_expired', \PESM\Support\I18n::text('مهلت رزرو موقت پایان یافته است.'), 410);
        }
        if ((int) $hold->quantity < 1 || (int) $hold->quantity > 20) {
            throw new HoldException('invalid_quantity', \PESM\Support\I18n::text('تعداد ظرفیت رزرو معتبر نیست.'), 422);
        }
    }

    public static function assertPending(object $hold, string $now): void
    {
        if ($hold->status !== 'payment_pending' || $hold->expires_at <= $now) {
            throw new HoldException('reconciliation_required', \PESM\Support\I18n::text('مهلت ثبت بلیت پایان یافته یا نیازمند بررسی است.'), 409);
        }
    }
}
