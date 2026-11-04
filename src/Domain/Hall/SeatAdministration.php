<?php

declare(strict_types=1);

namespace PESM\Domain\Hall;

use PESM\Domain\Booking\HoldPolicy;
use PESM\Support\Audit;
use RuntimeException;
use Throwable;

/** Limited preview controls: no ticket, checkout or paid-seat overrides. */
final class SeatAdministration
{
    public static function changeBlocked(int $sessionId, string $seatCode, bool $blocked, int $actorUserId): void
    {
        global $wpdb;
        if ($sessionId < 1 || !preg_match('/^[A-Z]{1,2}-[1-9][0-9]{0,2}$/D', $seatCode)) {
            throw new RuntimeException(\PESM\Support\I18n::text('کد صندلی یا جلسه معتبر نیست.'));
        }
        $p = $wpdb->prefix . 'pesm_';
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new RuntimeException(\PESM\Support\I18n::text('ارتباط پایگاه داده برقرار نشد.'));
        }
        try {
            $layout = $wpdb->get_var($wpdb->prepare(
                "SELECT layout_id FROM {$p}sessions WHERE id = %d FOR UPDATE", $sessionId
            ));
            if (!$layout) {
                throw new RuntimeException(\PESM\Support\I18n::text('جلسه پیدا نشد.'));
            }
            $seatId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$p}seats WHERE layout_id=%d AND seat_code=%s", (int) $layout, $seatCode
            ));
            if ($seatId < 1) {
                throw new RuntimeException(\PESM\Support\I18n::text('صندلی در نقشه جلسه وجود ندارد.'));
            }
            $inventory = $wpdb->get_row($wpdb->prepare(
                "SELECT status,expires_at FROM {$p}session_seats WHERE session_id=%d AND seat_id=%d FOR UPDATE",
                $sessionId, $seatId
            ));
            $now = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
            if (!$inventory || !$now) {
                throw new RuntimeException(\PESM\Support\I18n::text('موجودی صندلی یا زمان پایگاه داده نامعتبر است.'));
            }
            if ($blocked && !HoldPolicy::canAcquire((string) $inventory->status, $inventory->expires_at, $now)) {
                throw new RuntimeException(\PESM\Support\I18n::text('صندلی نگهداری‌شده یا فروخته‌شده قابل مسدودکردن نیست.'));
            }
            if (!$blocked && $inventory->status !== 'blocked') {
                throw new RuntimeException(\PESM\Support\I18n::text('فقط صندلی مسدودشده را می‌توان آزاد کرد.'));
            }
            $status = $blocked ? 'blocked' : 'available';
            if ($wpdb->update($p . 'session_seats', [
                'status' => $status, 'hold_id' => null, 'expires_at' => null, 'updated_at' => $now,
            ], ['session_id' => $sessionId, 'seat_id' => $seatId]) !== 1) {
                throw new RuntimeException(\PESM\Support\I18n::text('تغییر وضعیت صندلی ثبت نشد.'));
            }
            Audit::record($blocked ? 'seat_blocked' : 'seat_unblocked', 'seat', $seatId, $actorUserId);
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException(\PESM\Support\I18n::text('ثبت تغییر وضعیت ناموفق بود.'));
            }
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }
}
