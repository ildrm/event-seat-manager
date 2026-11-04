<?php

declare(strict_types=1);

namespace PESM\Domain\Booking;

use Throwable;
use PESM\Support\Audit;

/**
 * General-admission inventory, independent of seat IDs. All mutations lock
 * session -> capacity inventory -> capacity hold (in that order). The guarded
 * UPDATE is the final oversell barrier, even if application validation changes.
 *
 * IMPORTANT: payment / ticket issuance for this inventory is intentionally NOT
 * implemented. Holds are previews only and cannot be checked out in this release.
 */
final class CapacityService
{
    /** @return array<string,mixed> */
    public function acquire(int $sessionId, int $quantity, string $key): array
    {
        if ($sessionId < 1) {
            throw new HoldException('invalid_session', \PESM\Support\I18n::text('شناسه جلسه معتبر نیست.'), 422);
        }
        CapacityPolicy::quantity($quantity);
        HoldPolicy::validateKey($key);
        $requestHash = hash_hmac('sha256', 'capacity:request:' . $sessionId . ':' . $key, wp_salt('auth'));
        $token = hash_hmac('sha256', 'capacity:token:' . $sessionId . ':' . $key, wp_salt('auth'));
        return $this->transaction(function () use ($sessionId, $quantity, $requestHash, $token): array {
            global $wpdb;
            $p = $wpdb->prefix . 'pesm_';
            $session = $this->lockSession($sessionId);
            $now = $this->now();
            $this->lockInventory($sessionId);
            // Drain expired rows in bounded batches while still holding the session
            // lock. Never depend on WP-Cron to release a capacity unit.
            for ($batch = 0; $batch < 100; ++$batch) {
                if ($this->expireLocked($sessionId, $now, 500) < 500) { break; }
            }
            if ($batch === 100) {
                throw new HoldException('cleanup_required', \PESM\Support\I18n::text('پاکسازی رزروهای منقضی به زمان بیشتری نیاز دارد.'), 503);
            }
            $previous = $wpdb->get_row($wpdb->prepare(
                "SELECT id,session_id,quantity,status,expires_at FROM {$p}capacity_holds WHERE request_hash=%s FOR UPDATE",
                $requestHash
            ));
            if ($previous) {
                if ((int) $previous->session_id !== $sessionId || (int) $previous->quantity !== $quantity) {
                    throw new HoldException('idempotency_conflict', \PESM\Support\I18n::text('شناسه درخواست با تعداد متفاوتی استفاده شده است.'), 409);
                }
                if ($previous->status !== 'active' || $previous->expires_at <= $now) {
                    throw new HoldException('hold_expired', \PESM\Support\I18n::text('مهلت این رزرو پایان یافته است.'), 410);
                }
                return $this->result((int) $previous->id, $quantity, (string) $previous->expires_at, $token, $now, true);
            }
            CapacityPolicy::saleOpen($session, $now);
            $expires = HoldPolicy::expiry($now, min((string) $session->sale_ends_at, (string) $session->starts_at));
            // Capacity is allocated atomically; SELECT COUNT followed by INSERT is never used.
            $changed = $wpdb->query($wpdb->prepare(
                "UPDATE {$p}session_capacity SET held_units=held_units+%d,version=version+1
                 WHERE session_id=%d AND total_capacity-blocked_units-held_units-pending_units-sold_units >= %d",
                $quantity, $sessionId, $quantity
            ));
            if ($changed !== 1) {
                throw new HoldException('capacity_unavailable', \PESM\Support\I18n::text('ظرفیت کافی برای این تعداد وجود ندارد.'), 409);
            }
            if ($wpdb->insert($p . 'capacity_holds', [
                'session_id' => $sessionId, 'request_hash' => $requestHash,
                'token_hash' => hash('sha256', $token), 'quantity' => $quantity,
                'status' => 'active', 'expires_at' => $expires, 'created_at' => $now,
            ]) !== 1) {
                throw new HoldException('write_failed', \PESM\Support\I18n::text('ذخیره رزرو ظرفیت انجام نشد.'), 503);
            }
            $id = (int) $wpdb->insert_id;
            Audit::record('capacity_held', 'capacity_hold', $id);
            return $this->result($id, $quantity, $expires, $token, $now, false);
        });
    }

    /** @return array<string,mixed> */
    public function release(int $sessionId, string $token): array
    {
        if ($sessionId < 1 || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new HoldException('invalid_token', \PESM\Support\I18n::text('شناسه دسترسی به رزرو معتبر نیست.'), 403);
        }
        return $this->transaction(function () use ($sessionId, $token): array {
            global $wpdb;
            $p = $wpdb->prefix . 'pesm_';
            $this->lockSession($sessionId);
            $now = $this->now();
            $this->lockInventory($sessionId);
            // Do not expire the caller's reservation before inspecting its state;
            // a late release should return a stable idempotent outcome.
            $hold = $wpdb->get_row($wpdb->prepare(
                "SELECT id,quantity,status,expires_at FROM {$p}capacity_holds
                 WHERE session_id=%d AND token_hash=%s FOR UPDATE", $sessionId, hash('sha256', $token)
            ));
            if (!$hold) {
                throw new HoldException('permission_denied', \PESM\Support\I18n::text('رزرو متعلق به این دسترسی نیست.'), 403);
            }
            if ($hold->status === 'released' || $hold->status === 'expired') {
                return ['released' => true, 'already_released' => true];
            }
            if ($hold->status !== 'active') {
                throw new HoldException('not_releasable', \PESM\Support\I18n::text('این رزرو قابل آزادسازی نیست.'), 409);
            }
            $status = $hold->expires_at <= $now ? 'expired' : 'released';
            $this->changeStatus((int) $hold->id, (int) $hold->quantity, $status);
            Audit::record('capacity_' . $status, 'capacity_hold', (int) $hold->id);
            return ['released' => true, 'already_released' => false, 'expired' => $status === 'expired'];
        });
    }

    /**
     * Summary is a read-only consistent calculation; the number of expired
     * holds is subtracted without requiring a cron or a modifying GET request.
     * @return array<string,int>
     */
    public function availability(int $sessionId): array
    {
        if ($sessionId < 1) {
            throw new HoldException('invalid_session', \PESM\Support\I18n::text('شناسه جلسه معتبر نیست.'), 422);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT c.total_capacity,c.blocked_units,c.held_units,c.pending_units,c.sold_units,
                    (SELECT COALESCE(SUM(h.quantity),0) FROM {$p}capacity_holds h
                     WHERE h.session_id=c.session_id AND h.status='active'
                       AND h.expires_at<=UTC_TIMESTAMP()) AS expired_units,
                    s.mode,s.status,s.unit_price_minor,e.status AS event_status
             FROM {$p}session_capacity c JOIN {$p}sessions s ON s.id=c.session_id
             JOIN {$p}events e ON e.id=s.event_id WHERE c.session_id=%d", $sessionId
        ));
        if (!$row || $row->mode !== 'general' || $row->status !== 'published' || $row->event_status !== 'published') {
            throw new HoldException('invalid_session', \PESM\Support\I18n::text('جلسه ظرفیت آزاد منتشر نشده است.'), 404);
        }
        $expired = (int) $row->expired_units;
        $held = max(0, (int) $row->held_units - $expired);
        return [
            'total' => (int) $row->total_capacity, 'blocked' => (int) $row->blocked_units,
            'held' => $held, 'pending' => (int) $row->pending_units,
            'sold' => (int) $row->sold_units,
            'unit_price_minor' => (int) ($row->unit_price_minor ?? 0),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
            'currency_decimals' => function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 0,
            'paid_preview_enabled' => function_exists('get_option') && get_option('pesm_enable_paid_general_preview', 'no') === 'yes'
                && function_exists('wc_tax_enabled') && !wc_tax_enabled(),
            'available' => CapacityPolicy::available((int) $row->total_capacity, (int) $row->blocked_units,
                $held, (int) $row->pending_units, (int) $row->sold_units),
        ];
    }

    /** Return number of expired holds cleaned up, at most $limit per run. */
    public function cleanup(int $sessionId, int $limit = 100): int
    {
        if ($sessionId < 1 || $limit < 1 || $limit > 500) {
            throw new HoldException('invalid_selection', \PESM\Support\I18n::text('پارامترهای پاکسازی معتبر نیستند.'), 422);
        }
        return $this->transaction(function () use ($sessionId, $limit): int {
            $this->lockSession($sessionId);
            $now = $this->now();
            $this->lockInventory($sessionId);
            return $this->expireLocked($sessionId, $now, $limit);
        });
    }

    /** UTC timestamp obtained after the session lock has been granted. */
    private function now(): string
    {
        global $wpdb;
        $now = $wpdb->get_var('SELECT UTC_TIMESTAMP()');
        if (!is_string($now) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $now)) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('زمان معتبر پایگاه داده دریافت نشد.'), 503);
        }
        return $now;
    }

    private function lockSession(int $sessionId): object
    {
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT s.id,s.mode,s.status,s.sale_starts_at,s.sale_ends_at,s.starts_at,e.status AS event_status
             FROM {$p}sessions s JOIN {$p}events e ON e.id=s.event_id WHERE s.id=%d FOR UPDATE", $sessionId
        ));
        if (!$session || $session->mode !== 'general') {
            throw new HoldException('invalid_session', \PESM\Support\I18n::text('جلسه ظرفیت آزاد یافت نشد.'), 404);
        }
        return $session;
    }

    private function lockInventory(int $sessionId): object
    {
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $inventory = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$p}session_capacity WHERE session_id=%d FOR UPDATE", $sessionId
        ));
        if (!$inventory) {
            throw new HoldException('inventory_inconsistent', \PESM\Support\I18n::text('رکورد موجودی جلسه یافت نشد.'), 503);
        }
        CapacityPolicy::available((int) $inventory->total_capacity, (int) $inventory->blocked_units,
            (int) $inventory->held_units, (int) $inventory->pending_units, (int) $inventory->sold_units);
        return $inventory;
    }

    private function expireLocked(int $sessionId, string $now, int $limit = 500): int
    {
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,quantity FROM {$p}capacity_holds WHERE session_id=%d AND status='active'
             AND expires_at<=%s ORDER BY id LIMIT %d FOR UPDATE", $sessionId, $now, $limit
        ));
        if (!is_array($rows)) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('بررسی انقضای رزروهای ظرفیت ممکن نشد.'), 503);
        }
        foreach ($rows as $row) {
            $this->changeStatus((int) $row->id, (int) $row->quantity, 'expired');
        }
        return count($rows);
    }

    private function changeStatus(int $holdId, int $quantity, string $status): void
    {
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        if ($wpdb->query($wpdb->prepare(
            "UPDATE {$p}session_capacity SET held_units=held_units-%d,version=version+1
             WHERE session_id=(SELECT session_id FROM {$p}capacity_holds WHERE id=%d)
             AND held_units >= %d", $quantity, $holdId, $quantity
        )) !== 1 || $wpdb->update($p . 'capacity_holds', ['status' => $status],
            ['id' => $holdId, 'status' => 'active']) !== 1) {
            throw new HoldException('write_failed', \PESM\Support\I18n::text('آزادسازی موجودی ظرفیت ناموفق بود.'), 503);
        }
    }

    /** @return array<string,mixed> */
    private function result(int $id, int $quantity, string $expires, string $token, string $now, bool $replayed): array
    {
        return ['hold_id' => $id, 'quantity' => $quantity, 'expires_at_utc' => $expires,
            'server_time_utc' => $now, 'token' => $token, 'replayed' => $replayed,
            'message' => \PESM\Support\I18n::text('ظرفیت موقتاً نگهداری شده است؛ پرداخت و صدور بلیت این نوع جلسه هنوز فعال نیست.')];
    }

    /** @template T @param callable():T $task @return T */
    private function transaction(callable $task): mixed
    {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('تراکنش پایگاه داده در دسترس نیست.'), 503);
        }
        try {
            $result = $task();
            if ($wpdb->query('COMMIT') === false) {
                throw new HoldException('write_failed', \PESM\Support\I18n::text('تراکنش موجودی ثبت نشد.'), 503);
            }
            return $result;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($e instanceof HoldException) { throw $e; }
            throw new HoldException('write_failed', \PESM\Support\I18n::text('عملیات موجودی نیازمند بررسی است.'), 503);
        }
    }
}
