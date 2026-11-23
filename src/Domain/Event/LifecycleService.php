<?php

declare(strict_types=1);

namespace PESM\Domain\Event;

use PESM\Support\Audit;
use RuntimeException;
use Throwable;

/** Administrative publish/pause/cancel transitions, with center-scoped authorization in the caller. */
final class LifecycleService
{
    /** Keep both lifecycle rows stable until the caller commits its transaction. */
    public static function lockSession(int $eventId, int $sessionId): ?object
    {
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT center_id,status FROM {$p}events WHERE id=%d LOCK IN SHARE MODE", $eventId
        ));
        if (!$event) { return null; }
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT starts_at,status FROM {$p}sessions WHERE id=%d AND event_id=%d LOCK IN SHARE MODE", $sessionId, $eventId
        ));
        if (!$session) { return null; }
        $session->center_id = (int) $event->center_id;
        $session->event_status = (string) $event->status;
        return $session;
    }

    public static function change(string $entity, int $id, string $target, int $actorUserId, bool $withinTransaction = false): void
    {
        global $wpdb;
        if (!in_array($entity, ['event', 'session'], true) || $id < 1 || !in_array($target, ['published', 'paused', 'cancelled'], true)) {
            throw new RuntimeException(\PESM\Support\I18n::text('عملیات تغییر وضعیت نامعتبر است.'));
        }
        $table = $wpdb->prefix . 'pesm_' . ($entity === 'event' ? 'events' : 'sessions');
        if (!$withinTransaction && $wpdb->query('START TRANSACTION') === false) {
            throw new RuntimeException(\PESM\Support\I18n::text('امکان شروع تراکنش وجود ندارد.'));
        }
        try {
            if ($entity === 'event') {
                $item = $wpdb->get_row($wpdb->prepare("SELECT id,status FROM {$table} WHERE id=%d FOR UPDATE", $id));
            } else {
                $item = $wpdb->get_row($wpdb->prepare("SELECT id,status,event_id,mode FROM {$table} WHERE id=%d FOR UPDATE", $id));
                if ($item && $target === 'published') {
                    if (($item->mode ?? 'seated') === 'allocated' &&
                        ($item->status === 'draft' && !\PESM\Domain\Venue\Acceptance::creationEnabled())) {
                        throw new RuntimeException('Allocated inventory and operational acceptance must be ready before publication.');
                    }
                    if (($item->mode ?? 'seated') === 'allocated') { \PESM\Domain\Allocation\SessionService::assertPublishable($id); }
                    $eventStatus = $wpdb->get_var($wpdb->prepare(
                        "SELECT status FROM {$wpdb->prefix}pesm_events WHERE id=%d", (int) $item->event_id
                    ));
                    if ($eventStatus !== 'published') {
                        throw new RuntimeException(\PESM\Support\I18n::text('برای انتشار جلسه، ابتدا رویداد آن را منتشر کنید.'));
                    }
                }
            }
            if (!$item || !LifecyclePolicy::allowed((string) $item->status, $target)) {
                throw new RuntimeException(\PESM\Support\I18n::text('تغییر وضعیت در این مرحله مجاز نیست.'));
            }
            if ($wpdb->update($table, ['status' => $target], ['id' => $id, 'status' => (string) $item->status]) !== 1) {
                throw new RuntimeException(\PESM\Support\I18n::text('وضعیت به‌روز نشد.'));
            }
            Audit::record($entity . '_' . $target, $entity, $id, $actorUserId);
            if (!$withinTransaction && $wpdb->query('COMMIT') === false) {
                throw new RuntimeException(\PESM\Support\I18n::text('ثبت تغییر وضعیت ناموفق بود.'));
            }
        } catch (Throwable $e) {
            if (!$withinTransaction) { $wpdb->query('ROLLBACK'); }
            throw $e;
        }
    }
}
