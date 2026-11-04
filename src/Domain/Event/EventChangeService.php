<?php

declare(strict_types=1);

namespace PESM\Domain\Event;

use PESM\Domain\Booking\HoldException;
use PESM\Security\Capabilities;
use PESM\Support\Audit;
use Throwable;

/**
 * Operational changes are committed with a durable financial-review ledger.
 * Payment/refund is NEVER issued within this SQL transaction or claimed complete.
 * Tickets remain financial records; gate/checkouts respect cancellation status.
 */
final class EventChangeService
{
    private const MAX_CASES = 2000;

    /** @return array<string,mixed> */
    public function cancel(string $entity, int $id, string $reason, string $requestKey, int $actorId): array
    {
        return $this->apply($entity, $id, 'cancel', null, $reason, $requestKey, $actorId);
    }

    /** @return array<string,mixed> */
    public function postponeSession(int $sessionId, string $newStartUtc, string $reason, string $requestKey, int $actorId): array
    {
        return $this->apply('session', $sessionId, 'postpone', $newStartUtc, $reason, $requestKey, $actorId);
    }

    /** @return array<string,mixed> */
    private function apply(string $entity, int $id, string $action, ?string $newStart,
                           string $reason, string $requestKey, int $actorId): array
    {
        if (!in_array($entity, ['event', 'session'], true) || $id < 1 || $actorId < 1 ||
            !in_array($action, ['cancel', 'postpone'], true) ||
            ($action === 'postpone' && ($entity !== 'session' || $newStart === null))) {
            throw new HoldException('invalid_change', \PESM\Support\I18n::text('درخواست تغییر رویداد نامعتبر است.'), 422);
        }
        if (!user_can($actorId, $entity === 'event' ? 'pesm_manage_events' : 'pesm_manage_sessions')) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('مجوز تغییر رویداد یا جلسه را ندارید.'), 403);
        }
        if ((int) get_option('pesm_schema_version', 0) < 14) {
            throw new HoldException('schema_not_ready', \PESM\Support\I18n::text('برای ثبت تغییرات رویداد، پایگاه داده را ارتقا دهید.'), 503);
        }
        EventChangePolicy::requestKey($requestKey);
        $reason = EventChangePolicy::reason($reason);
        $fingerprint = hash('sha256', json_encode([$entity,$id,$action,$newStart,$reason], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $requestHash = hash_hmac('sha256', $requestKey, wp_salt('auth'));
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        // Locate the center before transaction; verify AGAIN after taking the event lock.
        $centerId = $entity === 'event' ? (int) $wpdb->get_var($wpdb->prepare(
            "SELECT center_id FROM {$p}events WHERE id=%d", $id)) : (int) $wpdb->get_var($wpdb->prepare(
            "SELECT e.center_id FROM {$p}sessions s JOIN {$p}events e ON e.id=s.event_id WHERE s.id=%d", $id));
        if (!Capabilities::centerAllowed($centerId, $actorId)) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('این مرکز در محدوده دسترسی شما نیست.'), 403);
        }
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('امکان آغاز تراکنش وجود ندارد.'), 503);
        }
        try {
            $eventId = $entity === 'event' ? $id : (int) $wpdb->get_var($wpdb->prepare(
                "SELECT event_id FROM {$p}sessions WHERE id=%d", $id));
            $event = $wpdb->get_row($wpdb->prepare(
                "SELECT id,center_id,status FROM {$p}events WHERE id=%d FOR UPDATE", $eventId));
            if (!$event || (int) $event->center_id !== $centerId ||
                !Capabilities::centerAllowed((int) $event->center_id, $actorId)) {
                throw new HoldException('permission_denied', \PESM\Support\I18n::text('محدوده مجوز رویداد تغییر کرده است.'), 403);
            }
            $previous = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}event_changes WHERE request_hash=%s FOR UPDATE", $requestHash));
            if ($previous) {
                if (!hash_equals((string) $previous->payload_hash, $fingerprint)) {
                    throw new HoldException('idempotency_conflict', \PESM\Support\I18n::text('این کلید قبلاً برای درخواست متفاوتی استفاده شده است.'), 409);
                }
                if ($wpdb->query('COMMIT') === false) { throw new HoldException('write_failed', \PESM\Support\I18n::text('تراکنش نهایی نشد.'), 503); }
                return ['change_id'=>(int)$previous->id,'affected_orders'=>(int)$previous->affected_orders,
                    'action'=>$action,'replayed'=>true,'financial_status'=>'review_required'];
            }
            $sessions = $wpdb->get_results($wpdb->prepare(
                "SELECT id,event_id,status,starts_at,sale_ends_at FROM {$p}sessions WHERE event_id=%d ORDER BY id FOR UPDATE", $eventId));
            if (!is_array($sessions)) { throw new HoldException('db_unavailable', \PESM\Support\I18n::text('خواندن جلسات ممکن نیست.'), 503); }
            $session = null;
            foreach ($sessions as $candidate) {
                if ((int)$candidate->id === $id && $entity === 'session') { $session = $candidate; }
            }
            if ($entity === 'session' && !$session) { throw new HoldException('invalid_session', \PESM\Support\I18n::text('جلسه پیدا نشد.'), 404); }
            $now = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
            if (!$now) { throw new HoldException('db_unavailable', \PESM\Support\I18n::text('ساعت سرور قابل دریافت نیست.'), 503); }
            $oldStart = null;
            if ($action === 'postpone') {
                if ((string)$event->status === 'cancelled' || (string)$session->status === 'cancelled') {
                    throw new HoldException('event_cancelled', \PESM\Support\I18n::text('زمان رویداد لغوشده قابل تغییر نیست.'), 409);
                }
                $oldStart = (string) $session->starts_at;
                EventChangePolicy::postpone($oldStart, (string)$newStart, $now, (string)$session->sale_ends_at);
                // Cannot rewrite a lease, or invalidate a check-in already consumed.
                $activeSeated = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}holds WHERE session_id=%d AND status IN ('active','checkout_creating','payment_pending') AND expires_at>UTC_TIMESTAMP()", $id));
                $activeGeneral = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}capacity_holds WHERE session_id=%d AND status IN ('active','checkout_creating','payment_pending') AND expires_at>UTC_TIMESTAMP()", $id));
                if ((int) get_option('pesm_schema_version', 0) >= 19) {
                    $activeGeneral += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}allocation_holds WHERE session_id=%d AND status IN ('active','checkout_creating','payment_pending') AND expires_at>UTC_TIMESTAMP()", $id));
                    $activeGeneral += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}allocation_tickets WHERE session_id=%d AND status='checked_in'", $id));
                }
                $admitted = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}checkins c JOIN {$p}tickets t ON t.id=c.ticket_id WHERE t.session_id=%d", $id)) +
                    (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}capacity_checkins c JOIN {$p}capacity_tickets t ON t.id=c.ticket_id WHERE t.session_id=%d", $id));
                if ($activeSeated + $activeGeneral + $admitted > 0) {
                    throw new HoldException('change_conflict', \PESM\Support\I18n::text('برای تغییر تاریخ، ابتدا رزروهای فعال و ورودهای ثبت‌شده را بررسی کنید.'), 409);
                }
            } else {
                EventChangePolicy::cancellable((string) ($entity === 'event' ? $event->status : $session->status));
            }
            $targetSessions = $entity === 'event' ? array_map(static fn(object $s): int => (int)$s->id, $sessions) : [$id];
            $orderIds = $this->affectedOrders($targetSessions);
            if (count($orderIds) > self::MAX_CASES) {
                throw new HoldException('too_many_orders', \PESM\Support\I18n::text('تعداد سفارش‌ها زیاد است؛ ابتدا پردازش دسته‌ای و اعلان‌ها را راه‌اندازی کنید.'), 409);
            }
            if ($action === 'cancel') {
                if ($entity === 'event') {
                    if ($wpdb->update($p.'events', ['status'=>'cancelled'], ['id'=>$id, 'status'=>(string)$event->status]) !== 1) {
                        throw new HoldException('write_failed', \PESM\Support\I18n::text('لغو رویداد ممکن نشد.'), 503);
                    }
                    foreach ($sessions as $s) {
                        if ((string)$s->status === 'cancelled') { continue; }
                        if ($wpdb->update($p.'sessions', ['status'=>'cancelled'], ['id'=>(int)$s->id,'status'=>(string)$s->status]) !== 1) {
                            throw new HoldException('write_failed', \PESM\Support\I18n::text('لغو یکی از جلسات ممکن نشد.'), 503);
                        }
                    }
                } elseif ($wpdb->update($p.'sessions', ['status'=>'cancelled'], ['id'=>$id,'status'=>(string)$session->status]) !== 1) {
                    throw new HoldException('write_failed', \PESM\Support\I18n::text('لغو جلسه ممکن نشد.'), 503);
                }
            } elseif ($wpdb->update($p.'sessions', ['starts_at'=>$newStart,'status'=>'paused'],
                ['id'=>$id,'starts_at'=>$oldStart,'status'=>(string)$session->status]) !== 1) {
                throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت تاریخ جدید ممکن نشد.'), 503);
            }
            if ($wpdb->insert($p.'event_changes', [
                'event_id'=>$eventId,'session_id'=>$entity==='session'?$id:0,
                'action'=>$action,'request_hash'=>$requestHash,'payload_hash'=>$fingerprint,
                'old_starts_at'=>$oldStart,'new_starts_at'=>$newStart,
                'reason'=>$reason,'actor_user_id'=>$actorId,'affected_orders'=>count($orderIds),
                'created_at'=>$now,
            ]) !== 1) { throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت سابقه تغییر ممکن نشد.'), 503); }
            $changeId = (int)$wpdb->insert_id;
            foreach ($orderIds as $orderId) {
                if ($wpdb->insert($p.'event_change_cases', [
                    'change_id'=>$changeId,'order_id'=>$orderId,'status'=>'review_required','created_at'=>$now,
                ]) !== 1) { throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت سفارش‌های نیازمند پیگیری ممکن نشد.'), 503); }
            }
            Audit::record($action === 'cancel' ? 'event_change_cancelled' : 'event_change_postponed', $entity, $id, $actorId);
            if ($wpdb->query('COMMIT') === false) { throw new HoldException('write_failed', \PESM\Support\I18n::text('تراکنش نهایی نشد.'), 503); }
            return ['change_id'=>$changeId,'affected_orders'=>count($orderIds),'action'=>$action,
                'replayed'=>false,'financial_status'=>'review_required'];
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($e instanceof HoldException) { throw $e; }
            throw new HoldException('change_failed', \PESM\Support\I18n::text('تغییر رویداد ذخیره نشد و نیازمند بررسی است.'), 503);
        }
    }

    /** @param list<int> $sessionIds @return list<int> */
    private function affectedOrders(array $sessionIds): array
    {
        if (!$sessionIds) { return []; }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $placeholders = implode(',', array_fill(0, count($sessionIds), '%d'));
        $queries = [
            "SELECT DISTINCT order_id FROM {$p}tickets WHERE session_id IN ($placeholders)",
            "SELECT DISTINCT order_id FROM {$p}capacity_tickets WHERE session_id IN ($placeholders)",
            "SELECT DISTINCT order_id FROM {$p}holds WHERE session_id IN ($placeholders) AND order_id>0",
            "SELECT DISTINCT order_id FROM {$p}capacity_holds WHERE session_id IN ($placeholders) AND order_id>0",
        ];
        if ((int) get_option('pesm_schema_version', 0) >= 19) {
            $queries[] = "SELECT DISTINCT order_id FROM {$p}allocation_holds WHERE session_id IN ($placeholders) AND order_id>0";
        }
        $ids = [];
        foreach ($queries as $sql) {
            $found = $wpdb->get_col($wpdb->prepare($sql, ...$sessionIds));
            if (!is_array($found)) { throw new HoldException('db_unavailable', \PESM\Support\I18n::text('فهرست سفارش‌ها قابل خواندن نیست.'), 503); }
            foreach ($found as $orderId) { if ((int)$orderId > 0) { $ids[(int)$orderId] = (int)$orderId; } }
            if (count($ids) > self::MAX_CASES) { break; }
        }
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    /** @return array<string,mixed> */
    public function cases(int $changeId, int $actorId, int $limit = 100): array
    {
        if (!user_can($actorId, 'pesm_manage_refunds')) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('اجازه مشاهده پرونده‌های مالی را ندارید.'), 403);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id,event_id,action,affected_orders,created_at FROM {$p}event_changes WHERE id=%d", $changeId));
        if (!$row) { throw new HoldException('not_found', \PESM\Support\I18n::text('تغییر موردنظر یافت نشد.'), 404); }
        $center = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT center_id FROM {$p}events WHERE id=%d", (int)$row->event_id));
        if (!Capabilities::centerAllowed($center, $actorId)) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('مجوز مرکز معتبر نیست.'), 403);
        }
        $limit = max(1, min(200, $limit));
        $cases = $wpdb->get_results($wpdb->prepare(
            "SELECT order_id,status FROM {$p}event_change_cases WHERE change_id=%d ORDER BY order_id LIMIT %d", $changeId, $limit));
        return ['change_id'=>$changeId,'action'=>(string)$row->action,'affected_orders'=>(int)$row->affected_orders,
            'orders'=>array_map(static fn(object $c): array => ['order_id'=>(int)$c->order_id,'status'=>(string)$c->status], is_array($cases)?$cases:[])];
    }
}
