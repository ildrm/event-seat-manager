<?php

declare(strict_types=1);

namespace PESM\Domain\Booking;

use PESM\Security\Capabilities;
use PESM\Support\Audit;

/** Session-specific toggles; immutable once the first hold has existed. */
final class SeatingRulesService
{
    public static function configure(int $sessionId, bool $adjacent, bool $avoidGap, int $actorId): void
    {
        if ($sessionId < 1 || $actorId < 1 || !user_can($actorId, 'pesm_manage_sessions')) {
            throw new HoldException('invalid_session', \PESM\Support\I18n::text('شناسه جلسه یا کاربر معتبر نیست.'), 422);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('تراکنش شروع نشد.'), 503);
        }
        try {
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT s.id,e.center_id FROM {$p}sessions s JOIN {$p}events e ON e.id=s.event_id WHERE s.id=%d FOR UPDATE",
                $sessionId));
            if (!$session || !Capabilities::centerAllowed((int) $session->center_id, $actorId)) {
                throw new HoldException('permission_denied', \PESM\Support\I18n::text('دسترسی به جلسه مجاز نیست.'), 403);
            }
            $anyHold = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$p}holds WHERE session_id=%d LIMIT 1", $sessionId));
            if ($anyHold) {
                throw new HoldException('rules_locked', \PESM\Support\I18n::text('پس از اولین درخواست رزرو، قوانین چیدمان این جلسه قفل می‌شوند.'), 409);
            }
            $updated = $wpdb->update($p . 'sessions', [
                'require_adjacent' => $adjacent ? 1 : 0,
                'avoid_single_gap' => $avoidGap ? 1 : 0,
            ], ['id' => $sessionId]);
            if ($updated === false) { throw new HoldException('write_failed', \PESM\Support\I18n::text('ذخیره قوانین انجام نشد.'), 503); }
            Audit::record('seating_rules_changed', 'session', $sessionId);
            if ($wpdb->query('COMMIT') === false) { throw new HoldException('db_unavailable', \PESM\Support\I18n::text('ذخیره تراکنش ناموفق بود.'), 503); }
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($e instanceof HoldException) { throw $e; }
            throw new HoldException('write_failed', \PESM\Support\I18n::text('تنظیم قوانین صندلی انجام نشد.'), 503);
        }
    }
}
