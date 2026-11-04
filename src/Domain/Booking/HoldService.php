<?php

declare(strict_types=1);

namespace PESM\Domain\Booking;

use PESM\Support\Audit;
use Throwable;

/**
 * Atomically manages temporary reservations; checkout and payment are handled separately.
 * Every mutating path locks the session row first to prevent lock-order inversion
 * with release, hold acquisition and administrative session suspension.
 */
final class HoldService
{
    public const TTL_SECONDS = HoldPolicy::TTL_SECONDS;
    public const MAX_SEATS = HoldPolicy::MAX_SEATS;

    /** @param list<int> $seatIds @return array<string,mixed> */
    public function acquire(int $sessionId, array $seatIds, string $requestKey): array
    {
        if ($sessionId < 1) {
            throw new HoldException('invalid_session', \PESM\Support\I18n::text('شناسه جلسه معتبر نیست.'), 422);
        }
        $seatIds = HoldPolicy::normalizeSeats($seatIds);
        HoldPolicy::validateKey($requestKey);
        $requestHash = hash_hmac('sha256', 'request:' . $sessionId . ':' . $requestKey, wp_salt('auth'));
        $token = hash_hmac('sha256', 'token:' . $sessionId . ':' . $requestKey, wp_salt('auth'));

        return $this->transaction(function (string $now) use ($sessionId, $seatIds, $requestHash, $token): array {
            global $wpdb;
            $p = $wpdb->prefix . 'pesm_';
            // All commands lock session -> holds -> inventory in the same order.
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT s.id,s.mode,s.status,s.starts_at,s.sale_starts_at,s.sale_ends_at,s.require_adjacent,s.avoid_single_gap,e.status AS event_status
                 FROM {$p}sessions s INNER JOIN {$p}events e ON e.id = s.event_id
                 WHERE s.id = %d FOR UPDATE", $sessionId
            ));
            if (!$session) {
                throw new HoldException('invalid_session', \PESM\Support\I18n::text('جلسه موردنظر وجود ندارد.'), 404);
            }
            if (($session->mode ?? 'seated') !== 'seated') {
                throw new HoldException('layout_requires_v2', 'Use the allocated reservation interface for this session.', 409);
            }
            $now = $this->lockedNow();
            $previous = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}holds WHERE request_hash = %s FOR UPDATE", $requestHash
            ));
            if ($previous) {
                $already = array_map('intval', $wpdb->get_col($wpdb->prepare(
                    "SELECT seat_id FROM {$p}hold_items WHERE hold_id = %d ORDER BY seat_id", (int) $previous->id
                )));
                if ((int) $previous->session_id !== $sessionId || $already !== $seatIds) {
                    throw new HoldException('idempotency_conflict', \PESM\Support\I18n::text('شناسه درخواست قبلاً برای انتخاب دیگری مصرف شده است.'), 409);
                }
                if ($previous->status !== 'active' || $previous->expires_at <= $now) {
                    throw new HoldException('hold_expired', \PESM\Support\I18n::text('مهلت این درخواست پایان یافته است؛ انتخاب تازه انجام دهید.'), 410);
                }
                return $this->result((int) $previous->id, $seatIds, $token, (string) $previous->expires_at, $now, true);
            }
            if ($session->status !== 'published' || $session->event_status !== 'published'
                || $session->sale_starts_at > $now || $session->sale_ends_at <= $now
                || $session->starts_at <= $now) {
                throw new HoldException('event_closed', \PESM\Support\I18n::text('رزرو موقت این جلسه فعال نیست.'), 409);
            }
            $in = implode(',', array_fill(0, count($seatIds), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT seat_id,status,expires_at FROM {$p}session_seats
                 WHERE session_id = %d AND seat_id IN ($in) ORDER BY seat_id FOR UPDATE", $sessionId, ...$seatIds
            ));
            if (!is_array($rows) || count($rows) !== count($seatIds)) {
                throw new HoldException('invalid_seat', \PESM\Support\I18n::text('یک یا چند صندلی متعلق به جلسه نیست.'), 422);
            }
            foreach ($rows as $row) {
                if (!HoldPolicy::canAcquire((string) $row->status, $row->expires_at, $now)) {
                    throw new HoldException('seat_unavailable', \PESM\Support\I18n::text('یکی از صندلی‌ها دیگر آزاد نیست.'), 409);
                }
            }
            $this->checkRules($sessionId, $session, $seatIds, $now);
            $expires = HoldPolicy::expiry($now, min((string) $session->sale_ends_at, (string) $session->starts_at));
            if ($wpdb->insert($p . 'holds', [
                'session_id' => $sessionId, 'request_hash' => $requestHash,
                'token_hash' => hash('sha256', $token), 'status' => 'active',
                'created_at' => $now, 'expires_at' => $expires,
            ]) !== 1) {
                throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت نگهداری موقت انجام نشد.'), 503);
            }
            $holdId = (int) $wpdb->insert_id;
            foreach ($seatIds as $seatId) {
                if ($wpdb->insert($p . 'hold_items', ['hold_id' => $holdId, 'seat_id' => $seatId]) !== 1) {
                    throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت صندلی انتخابی انجام نشد.'), 503);
                }
                $updated = $wpdb->update($p . 'session_seats', [
                    'status' => 'held', 'hold_id' => $holdId,
                    'expires_at' => $expires, 'updated_at' => $now,
                ], ['session_id' => $sessionId, 'seat_id' => $seatId]);
                if ($updated !== 1) {
                    throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت وضعیت صندلی انجام نشد.'), 503);
                }
            }
            Audit::record('hold_created', 'hold', $holdId);
            return $this->result($holdId, $seatIds, $token, $expires, $now, false);
        });
    }

    /**
     * Changes a held selection without first releasing its existing seats.
     * The SAME hold/token is retained. No payment-pending or sold hold may be changed.
     * @param list<int>|null $desiredSeats null means bounded renewal, not replacement
     * @return array<string,mixed>
     */
    public function modify(int $sessionId, string $token, string $requestKey, ?array $desiredSeats = null): array
    {
        if ($sessionId < 1 || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new HoldException('invalid_token', \PESM\Support\I18n::text('شناسه دسترسی معتبر نیست.'), 403);
        }
        HoldPolicy::validateKey($requestKey);
        if ($desiredSeats !== null) {
            $desiredSeats = HoldPolicy::normalizeSeats($desiredSeats);
        }
        $requestHash = hash_hmac('sha256', 'mutation:' . $sessionId . ':' . $requestKey, wp_salt('auth'));
        return $this->transaction(function (string $now) use ($sessionId, $token, $desiredSeats, $requestHash): array {
            global $wpdb;
            $p = $wpdb->prefix . 'pesm_';
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT s.id,s.mode,s.status,s.starts_at,s.sale_starts_at,s.sale_ends_at,s.require_adjacent,s.avoid_single_gap,e.status AS event_status
                 FROM {$p}sessions s JOIN {$p}events e ON e.id=s.event_id
                 WHERE s.id=%d FOR UPDATE", $sessionId
            ));
            if (!$session) {
                throw new HoldException('invalid_session', \PESM\Support\I18n::text('جلسه پیدا نشد.'), 404);
            }
            if (($session->mode ?? 'seated') !== 'seated') {
                throw new HoldException('layout_requires_v2', 'Use the allocated reservation interface for this session.', 409);
            }
            $now = $this->lockedNow();
            $hold = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}holds WHERE session_id=%d AND token_hash=%s FOR UPDATE",
                $sessionId, hash('sha256', $token)
            ));
            if (!$hold) {
                throw new HoldException('permission_denied', \PESM\Support\I18n::text('دسترسی به رزرو مجاز نیست.'), 403);
            }
            if ($hold->status !== 'active' || $hold->expires_at <= $now) {
                throw new HoldException('hold_expired', \PESM\Support\I18n::text('مهلت رزرو موقت پایان یافته است.'), 410);
            }
            if ($session->status !== 'published' || $session->event_status !== 'published'
                || $session->sale_starts_at > $now || $session->sale_ends_at <= $now || $session->starts_at <= $now) {
                throw new HoldException('event_closed', \PESM\Support\I18n::text('ویرایش رزرو این جلسه بسته شده است.'), 409);
            }
            $previous = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT seat_id FROM {$p}hold_items WHERE hold_id=%d ORDER BY seat_id", (int) $hold->id
            )));
            if (!$previous) {
                throw new HoldException('invalid_selection', \PESM\Support\I18n::text('رزرو فعلی صندلی ندارد.'), 409);
            }
            $target = $desiredSeats ?? $previous;
            $selectionHash = hash('sha256', implode(',', $target));
            $action = $desiredSeats === null ? 'renew' : 'replace';
            $replay = $wpdb->get_row($wpdb->prepare(
                "SELECT hold_id,action,selection_hash,expires_at FROM {$p}hold_mutations
                 WHERE request_hash=%s FOR UPDATE", $requestHash
            ));
            if ($replay) {
                if ((int) $replay->hold_id !== (int) $hold->id || $replay->action !== $action ||
                    !hash_equals((string) $replay->selection_hash, $selectionHash) ||
                    (string) $hold->expires_at !== (string) $replay->expires_at ||
                    $previous !== $target) {
                    throw new HoldException('idempotency_conflict', \PESM\Support\I18n::text('این شناسه درخواست قبلاً برای تغییر دیگری مصرف شده است.'), 409);
                }
                return $this->result((int) $hold->id, $target, $token, (string) $hold->expires_at, $now, true);
            }
            $union = array_values(array_unique(array_merge($previous, $target)));
            sort($union, SORT_NUMERIC);
            $in = implode(',', array_fill(0, count($union), '%d'));
            $inventory = $wpdb->get_results($wpdb->prepare(
                "SELECT seat_id,status,hold_id,expires_at FROM {$p}session_seats
                 WHERE session_id=%d AND seat_id IN ($in) ORDER BY seat_id FOR UPDATE",
                $sessionId, ...$union
            ));
            if (!is_array($inventory) || count($inventory) !== count($union)) {
                throw new HoldException('invalid_seat', \PESM\Support\I18n::text('صندلی خارج از چیدمان جلسه است.'), 422);
            }
            $existing = array_fill_keys($previous, true);
            $wanted = array_fill_keys($target, true);
            foreach ($inventory as $seat) {
                $seatId = (int) $seat->seat_id;
                if (isset($existing[$seatId]) &&
                    ($seat->status !== 'held' || (int) $seat->hold_id !== (int) $hold->id)) {
                    throw new HoldException('hold_conflict', \PESM\Support\I18n::text('مالکیت یک صندلی رزرو قبلی تغییر کرده است.'), 409);
                }
                if (!isset($existing[$seatId]) && isset($wanted[$seatId]) &&
                    !HoldPolicy::canAcquire((string) $seat->status, $seat->expires_at, $now)) {
                    throw new HoldException('seat_unavailable', \PESM\Support\I18n::text('صندلی جدید در دسترس نیست.'), 409);
                }
            }
            if ($desiredSeats !== null) {
                $this->checkRules($sessionId, $session, $target, $now, (int) $hold->id);
            }
            $expiry = (string) $hold->expires_at;
            if ($desiredSeats === null) {
                $expiry = HoldPolicy::renewalExpiry($now, (string) $hold->created_at, $expiry,
                    (string) $session->sale_ends_at, (string) $session->starts_at, (int) $hold->renewal_count);
            }
            foreach ($union as $seatId) {
                if (isset($existing[$seatId]) && !isset($wanted[$seatId])) {
                    if ($wpdb->update($p . 'session_seats', [
                        'status' => 'available', 'hold_id' => null, 'expires_at' => null, 'updated_at' => $now,
                    ], ['session_id' => $sessionId, 'seat_id' => $seatId, 'status' => 'held', 'hold_id' => (int) $hold->id]) !== 1 ||
                        $wpdb->delete($p . 'hold_items', ['hold_id' => (int) $hold->id, 'seat_id' => $seatId]) !== 1) {
                        throw new HoldException('write_failed', \PESM\Support\I18n::text('حذف صندلی از نگهداری ثبت نشد.'), 503);
                    }
                } elseif (!isset($existing[$seatId]) && isset($wanted[$seatId])) {
                    if ($wpdb->update($p . 'session_seats', [
                        'status' => 'held', 'hold_id' => (int) $hold->id, 'expires_at' => $expiry,
                        'updated_at' => $now,
                    ], ['session_id' => $sessionId, 'seat_id' => $seatId]) !== 1 ||
                        $wpdb->insert($p . 'hold_items', ['hold_id' => (int) $hold->id, 'seat_id' => $seatId]) !== 1) {
                        throw new HoldException('write_failed', \PESM\Support\I18n::text('جایگزینی صندلی ثبت نشد.'), 503);
                    }
                } elseif ($desiredSeats === null) {
                    if ($wpdb->update($p . 'session_seats', [
                        'expires_at' => $expiry, 'updated_at' => $now,
                    ], ['session_id' => $sessionId, 'seat_id' => $seatId, 'status' => 'held', 'hold_id' => (int) $hold->id]) !== 1) {
                        throw new HoldException('write_failed', \PESM\Support\I18n::text('تمدید صندلی انجام نشد.'), 503);
                    }
                }
            }
            if ($desiredSeats === null) {
                if ($wpdb->update($p . 'holds', [
                    'expires_at' => $expiry, 'renewal_count' => (int) $hold->renewal_count + 1,
                ], ['id' => (int) $hold->id, 'status' => 'active']) !== 1) {
                    throw new HoldException('write_failed', \PESM\Support\I18n::text('تمدید رزرو ثبت نشد.'), 503);
                }
            }
            if ($wpdb->insert($p . 'hold_mutations', [
                'hold_id' => (int) $hold->id, 'request_hash' => $requestHash, 'action' => $action,
                'selection_hash' => $selectionHash, 'expires_at' => $expiry, 'created_at' => $now,
            ]) !== 1) {
                throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت درخواست تکرارپذیر انجام نشد.'), 503);
            }
            Audit::record($desiredSeats === null ? 'hold_renewed' : 'hold_replaced', 'hold', (int) $hold->id);
            return $this->result((int) $hold->id, $target, $token, $expiry, $now, false);
        });
    }

    /** @return array{released:bool} */
    public function release(int $sessionId, string $token): array
    {
        if ($sessionId < 1 || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new HoldException('invalid_token', \PESM\Support\I18n::text('شناسه دسترسی معتبر نیست.'), 403);
        }
        return $this->transaction(function (string $now) use ($sessionId, $token): array {
            global $wpdb;
            $p = $wpdb->prefix . 'pesm_';
            $session = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$p}sessions WHERE id = %d FOR UPDATE", $sessionId
            ));
            if (!$session) {
                throw new HoldException('invalid_session', \PESM\Support\I18n::text('جلسه وجود ندارد.'), 404);
            }
            $now = $this->lockedNow();
            $hold = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}holds WHERE token_hash = %s AND session_id = %d FOR UPDATE",
                hash('sha256', $token), $sessionId
            ));
            if (!$hold) {
                throw new HoldException('permission_denied', \PESM\Support\I18n::text('اجازه آزادسازی این صندلی‌ها وجود ندارد.'), 403);
            }
            if ($hold->status !== 'active') {
                return ['released' => false];
            }
            $seats = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT seat_id FROM {$p}hold_items WHERE hold_id = %d ORDER BY seat_id", (int) $hold->id
            )));
            foreach ($seats as $seatId) {
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$p}session_seats SET status = 'available',hold_id = NULL,expires_at = NULL,updated_at = %s
                     WHERE session_id = %d AND seat_id = %d AND hold_id = %d AND status = 'held'",
                    $now, $sessionId, $seatId, (int) $hold->id
                ));
                if ($updated === false) {
                    throw new HoldException('write_failed', \PESM\Support\I18n::text('آزادسازی صندلی انجام نشد.'), 503);
                }
            }
            if ($wpdb->update($p . 'holds', ['status' => $hold->expires_at <= $now ? 'expired' : 'released'],
                ['id' => (int) $hold->id]) !== 1) {
                throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت آزادسازی انجام نشد.'), 503);
            }
            Audit::record('hold_released', 'hold', (int) $hold->id);
            return ['released' => true];
        });
    }

    /**
     * Called after locking the session row, which serializes all booking
     * mutations. Lock the complete inventory in seat order for a consistent
     * geometry + availability snapshot only when rules are configured.
     * @param list<int> $target
     */
    private function checkRules(int $sessionId, object $session, array $target, string $now, int $ownerHoldId = 0): void
    {
        if (!(int) ($session->require_adjacent ?? 0) && !(int) ($session->avoid_single_gap ?? 0)) {
            return;
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT inv.seat_id,inv.status,inv.expires_at,inv.hold_id,seat.row_label,seat.zone_label,seat.pos_x,seat.pos_y
             FROM {$p}session_seats inv JOIN {$p}seats seat ON seat.id=inv.seat_id
             WHERE inv.session_id=%d ORDER BY inv.seat_id FOR UPDATE", $sessionId
        ));
        if (!is_array($rows) || !$rows) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('موجودی کامل جلسه برای بررسی قوانین در دسترس نیست.'), 503);
        }
        SeatingRules::validate(SeatingRules::fromObjects($rows), $target, $now,
            (bool) $session->require_adjacent, (bool) $session->avoid_single_gap, $ownerHoldId);
    }

    /** Re-evaluate expiry after waiting for the session's serialization lock. */
    private function lockedNow(): string
    {
        global $wpdb;
        $now = $wpdb->get_var('SELECT UTC_TIMESTAMP()');
        if (!is_string($now) || $now === '') {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('ساعت معتبر پایگاه داده در دسترس نیست.'), 503);
        }
        return $now;
    }

    /** @param callable(string):array<string,mixed> $callback @return array<string,mixed> */
    private function transaction(callable $callback): array
    {
        global $wpdb;
        if ((int) get_option('pesm_schema_version', 0) < 2) {
            throw new HoldException('schema_not_ready', \PESM\Support\I18n::text('ساختار پایگاه داده هنوز آماده نیست.'), 503);
        }
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            if ($wpdb->query('START TRANSACTION') === false) {
                throw new HoldException('db_unavailable', \PESM\Support\I18n::text('ارتباط با پایگاه داده برقرار نشد.'), 503);
            }
            try {
                $now = $wpdb->get_var('SELECT UTC_TIMESTAMP()');
                if (!is_string($now) || !$now) {
                    throw new HoldException('db_unavailable', \PESM\Support\I18n::text('ساعت پایگاه داده قابل دریافت نیست.'), 503);
                }
                $result = $callback($now);
                if ($wpdb->query('COMMIT') === false) {
                    throw new HoldException('db_unavailable', \PESM\Support\I18n::text('ثبت تراکنش انجام نشد.'), 503);
                }
                return $result;
            } catch (Throwable $error) {
                $sqlError = (string) $wpdb->last_error;
                $wpdb->query('ROLLBACK');
                if ($attempt < 2 && self::isTransientDatabaseError($sqlError)) {
                    usleep(($attempt + 1) * 50000);
                    continue;
                }
                if ($error instanceof HoldException) {
                    throw $error;
                }
                throw new HoldException('write_failed', \PESM\Support\I18n::text('در پردازش رزرو موقت خطای پایگاه داده رخ داد.'), 503);
            }
        }
        throw new HoldException('db_busy', \PESM\Support\I18n::text('سامانه رزرو موقت مشغول است؛ دوباره تلاش کنید.'), 503);
    }

    private static function isTransientDatabaseError(string $error): bool
    {
        return (bool) preg_match('/\b(1213|1205)\b|deadlock|lock wait timeout/i', $error);
    }

    /** @param list<int> $seatIds @return array<string,mixed> */
    private function result(int $holdId, array $seatIds, string $token, string $expires, string $now, bool $replay): array
    {
        return [
            'hold_id' => $holdId, 'seat_ids' => $seatIds, 'token' => $token,
            'server_now_utc' => str_replace(' ', 'T', $now) . 'Z',
            'expires_at_utc' => str_replace(' ', 'T', $expires) . 'Z',
            'ttl_seconds' => max(0, strtotime($expires . ' UTC') - strtotime($now . ' UTC')),
            'idempotent_replay' => $replay,
            'warning' => \PESM\Support\I18n::text('صندلی‌ها تا پایان مهلت نمایش‌داده‌شده نگهداری می‌شوند. برای ثبت سفارش، اطلاعات خریدار را ارسال کنید.'),
        ];
    }
}
