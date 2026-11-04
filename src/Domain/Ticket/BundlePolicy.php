<?php

declare(strict_types=1);

namespace PESM\Domain\Ticket;

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Booking\HoldPolicy;

/** Pure constraints for the initial multi-session ticket pass. */
final class BundlePolicy
{
    public const MAX_SESSIONS = 8;

    /** @param array<mixed> $ids @return list<int> */
    public static function ticketIds(array $ids): array
    {
        if (count($ids) < 2 || count($ids) > self::MAX_SESSIONS) {
            throw new HoldException('invalid_bundle', \PESM\Support\I18n::text('بسته باید شامل ۲ تا ۸ بلیت از جلسه‌های متفاوت باشد.'), 422);
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new HoldException('invalid_bundle', \PESM\Support\I18n::text('شناسه بلیت نامعتبر است.'), 422);
            }
        }
        $unique = array_values(array_unique($ids));
        sort($unique, SORT_NUMERIC);
        if (count($unique) !== count($ids)) {
            throw new HoldException('duplicate_ticket', \PESM\Support\I18n::text('شناسه تکراری بلیت در بسته وجود دارد.'), 422);
        }
        return $unique;
    }

    public static function key(string $key): void
    {
        HoldPolicy::validateKey($key);
    }

    /**
     * @param list<array<string,mixed>> $tickets
     * @return array{event_id:int,center_id:int,attendee_name:string,session_ids:list<int>}
     */
    public static function validateManifest(array $tickets, string $now): array
    {
        if (count($tickets) < 2 || count($tickets) > self::MAX_SESSIONS) {
            throw new HoldException('invalid_bundle', \PESM\Support\I18n::text('تعداد بلیت‌های بسته معتبر نیست.'), 422);
        }
        $first = $tickets[0];
        $eventId = (int) ($first['event_id'] ?? 0);
        $centerId = (int) ($first['center_id'] ?? 0);
        $owner = trim((string) ($first['attendee_name'] ?? ''));
        if ($eventId < 1 || $centerId < 1 || $owner === '') {
            throw new HoldException('invalid_bundle', \PESM\Support\I18n::text('مشخصات بلیت‌های بسته معتبر نیست.'), 422);
        }
        $sessionIds = [];
        foreach ($tickets as $ticket) {
            $sessionId = (int) ($ticket['session_id'] ?? 0);
            if ($sessionId < 1 || isset($sessionIds[$sessionId])) {
                throw new HoldException('duplicate_session', \PESM\Support\I18n::text('برای هر جلسه فقط یک بلیت مجاز است.'), 409);
            }
            $sessionIds[$sessionId] = true;
            if ((int) ($ticket['event_id'] ?? 0) !== $eventId ||
                (int) ($ticket['center_id'] ?? 0) !== $centerId ||
                trim((string) ($ticket['attendee_name'] ?? '')) !== $owner) {
                throw new HoldException('bundle_owner_mismatch', \PESM\Support\I18n::text('بلیت‌ها باید متعلق به یک رویداد و شرکت‌کننده باشند.'), 409);
            }
            if ((string) ($ticket['ticket_status'] ?? '') !== 'active' ||
                (string) ($ticket['session_status'] ?? '') !== 'published' ||
                (string) ($ticket['event_status'] ?? '') !== 'published' ||
                (string) ($ticket['starts_at'] ?? '') <= $now ||
                (int) ($ticket['checked_in'] ?? 0) !== 0) {
                throw new HoldException('bundle_ticket_ineligible', \PESM\Support\I18n::text('بلیت نامعتبر، استفاده‌شده یا مربوط به جلسه شروع‌شده در بسته وجود دارد.'), 409);
            }
        }
        $sessions = array_keys($sessionIds);
        sort($sessions, SORT_NUMERIC);
        return ['event_id'=>$eventId, 'center_id'=>$centerId, 'attendee_name'=>$owner, 'session_ids'=>$sessions];
    }

    public static function verifyPaidOrder(mixed $order, string $email, string $currency): array
    {
        if (!$order || !method_exists($order, 'get_meta') ||
            $order->get_meta('_pesm_is_ticket_order') !== 'yes' ||
            !method_exists($order, 'get_billing_email') ||
            !method_exists($order, 'get_currency') ||
            !method_exists($order, 'get_status') ||
            !method_exists($order, 'is_paid')) {
            throw new HoldException('bundle_order_invalid', \PESM\Support\I18n::text('سفارش بلیت برای تشکیل بسته معتبر نیست.'), 409);
        }
        $mail = strtolower(trim((string) $order->get_billing_email()));
        $valid = function_exists('is_email') ? is_email($mail) : filter_var($mail, FILTER_VALIDATE_EMAIL);
        $status = (string) $order->get_status();
        $total = method_exists($order, 'get_total') ? (float) $order->get_total() : -1;
        if (!$valid || ($email !== '' && $mail !== $email) ||
            ($currency !== '' && $currency !== (string) $order->get_currency()) ||
            in_array($status, ['cancelled','failed','refunded','trash'], true) ||
            (method_exists($order, 'get_total_refunded') && (float) $order->get_total_refunded() > 0.0) ||
            (!$order->is_paid() && !($total === 0.0 && in_array($status, ['completed','processing'], true)))) {
            throw new HoldException('bundle_order_invalid', \PESM\Support\I18n::text('پرداخت، ارز یا هویت خریدار سفارش‌های بسته یکسان نیست.'), 409);
        }
        return ['email'=>$mail, 'currency'=>(string) $order->get_currency()];
    }
}
