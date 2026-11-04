<?php

declare(strict_types=1);

namespace PESM\Domain\Event;

use PESM\Support\Audit;
use RuntimeException;
use Throwable;

final class EventEditor
{
    /** Caller enforces capability and center scope before invocation. */
    public static function update(int $id, string $title, array $details, int $actorId): void
    {
        global $wpdb;
        if ($id < 1 || trim($title) === '') {
            throw new RuntimeException(\PESM\Support\I18n::text('رویداد یا عنوان نامعتبر است.'));
        }
        $details = EventDetails::normalize($details);
        $p = $wpdb->prefix . 'pesm_';
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new RuntimeException(\PESM\Support\I18n::text('شروع تراکنش انجام نشد.'));
        }
        try {
            $event = $wpdb->get_row($wpdb->prepare("SELECT id,status FROM {$p}events WHERE id=%d FOR UPDATE", $id));
            if (!$event || $event->status === 'cancelled') {
                throw new RuntimeException(\PESM\Support\I18n::text('امکان ویرایش رویداد لغوشده وجود ندارد.'));
            }
            $updated = $wpdb->update($p . 'events', ['title' => $title, ...$details], ['id' => $id]);
            if ($updated === false) {
                throw new RuntimeException(\PESM\Support\I18n::text('ذخیره مشخصات رویداد ناموفق بود.'));
            }
            Audit::record('event_updated', 'event', $id, $actorId);
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException(\PESM\Support\I18n::text('ثبت تغییرات رویداد ممکن نشد.'));
            }
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }
}
