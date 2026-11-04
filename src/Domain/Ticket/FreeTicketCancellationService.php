<?php

declare(strict_types=1);

namespace PESM\Domain\Ticket;

use PESM\Domain\Booking\HoldException;
use PESM\Security\Capabilities;
use PESM\Support\Audit;
use Throwable;

/**
 * Cancels ONE free ticket, without calling a monetary-refund gateway.
 * All inventory modifications are in one transaction. No used ticket is resold.
 */
final class FreeTicketCancellationService
{
    /** @return array<string,mixed> */
    public function cancel(int $ticketId, int $actorId): array
    {
        if ($ticketId < 1 || $actorId < 1 || !user_can($actorId, 'pesm_manage_refunds')) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('اجازه لغو بلیت را ندارید.'), 403);
        }
        if ((int) get_option('pesm_schema_version', 0) < 7) {
            throw new HoldException('schema_not_ready', \PESM\Support\I18n::text('پایگاه داده نیازمند ارتقا است.'), 503);
        }
        if (!function_exists('wc_get_order')) {
            throw new HoldException('woocommerce_required', \PESM\Support\I18n::text('ووکامرس فعال نیست.'), 503);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $locator = $wpdb->get_row($wpdb->prepare(
            "SELECT t.session_id,t.hold_id,t.order_id,e.center_id FROM {$p}tickets t
             JOIN {$p}sessions s ON s.id=t.session_id JOIN {$p}events e ON e.id=s.event_id
             WHERE t.id=%d", $ticketId
        ));
        if (!$locator || !Capabilities::centerAllowed((int) $locator->center_id, $actorId)) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('این بلیت در محدوده مرکز شما نیست.'), 403);
        }
        self::verifyFreeOrder(wc_get_order((int) $locator->order_id));
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('شروع تراکنش ممکن نشد.'), 503);
        }
        try {
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT s.id,s.starts_at,s.status,e.status AS event_status,e.center_id
                 FROM {$p}sessions s JOIN {$p}events e ON e.id=s.event_id WHERE s.id=%d FOR UPDATE",
                (int) $locator->session_id
            ));
            $now = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
            $hold = $wpdb->get_row($wpdb->prepare(
                "SELECT id,status,order_id FROM {$p}holds WHERE id=%d FOR UPDATE", (int) $locator->hold_id
            ));
            $link = $wpdb->get_row($wpdb->prepare(
                "SELECT id,status FROM {$p}order_links WHERE hold_id=%d AND order_id=%d FOR UPDATE",
                (int) $locator->hold_id, (int) $locator->order_id
            ));
            if (!$session || !$now || !$hold || !$link || $hold->status !== 'sold' || $link->status !== 'issued' ||
                (int) $hold->order_id !== (int) $locator->order_id ||
                (int) $session->center_id !== (int) $locator->center_id ||
                !Capabilities::centerAllowed((int) $session->center_id, $actorId)) {
                throw new HoldException('invalid_order', \PESM\Support\I18n::text('رابطه بلیت و سفارش نیازمند بررسی است.'), 409);
            }
            self::verifyFreeOrder(wc_get_order((int) $locator->order_id));
            // Read before inventory locking. Session lock serializes all sale/transfer mutations.
            $snapshot = $wpdb->get_row($wpdb->prepare(
                "SELECT id,seat_id,status,session_id,hold_id,order_id FROM {$p}tickets WHERE id=%d", $ticketId
            ));
            if (!$snapshot || (int) $snapshot->session_id !== (int) $locator->session_id ||
                (int) $snapshot->hold_id !== (int) $locator->hold_id ||
                (int) $snapshot->order_id !== (int) $locator->order_id) {
                throw new HoldException('invalid_ticket', \PESM\Support\I18n::text('بلیت پیدا نشد یا تغییر کرده است.'), 409);
            }
            $inventory = $wpdb->get_row($wpdb->prepare(
                "SELECT seat_id,status,hold_id FROM {$p}session_seats
                 WHERE session_id=%d AND seat_id=%d FOR UPDATE", (int) $locator->session_id, (int) $snapshot->seat_id
            ));
            $ticket = $wpdb->get_row($wpdb->prepare(
                "SELECT id,seat_id,status,session_id,hold_id,order_id FROM {$p}tickets WHERE id=%d FOR UPDATE", $ticketId
            ));
            if (!$ticket || (int) $ticket->seat_id !== (int) $snapshot->seat_id) {
                throw new HoldException('seat_conflict', \PESM\Support\I18n::text('بلیت هم‌زمان تغییر کرده است.'), 409);
            }
            if ($ticket->status === 'cancelled') {
                self::commit($wpdb);
                return ['ticket_id'=>$ticketId, 'cancelled'=>true, 'idempotent_replay'=>true];
            }
            $checked = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$p}checkins WHERE ticket_id=%d LIMIT 1", $ticketId
            ));
            if ((string) $ticket->status !== 'active' || $checked) {
                throw new HoldException('ticket_not_cancelable', \PESM\Support\I18n::text('بلیت استفاده‌شده یا غیرفعال قابل لغو نیست.'), 409);
            }
            if ((string) $session->starts_at <= $now) {
                throw new HoldException('event_closed', \PESM\Support\I18n::text('پس از آغاز جلسه آزادسازی خودکار صندلی مجاز نیست.'), 409);
            }
            if (!$inventory || $inventory->status !== 'sold' ||
                (int) $inventory->hold_id !== (int) $locator->hold_id) {
                throw new HoldException('seat_conflict', \PESM\Support\I18n::text('صندلی این بلیت قابل آزادسازی نیست.'), 409);
            }
            if ($wpdb->update($p . 'tickets', ['status'=>'cancelled'],
                ['id'=>$ticketId, 'status'=>'active', 'seat_id'=>(int) $ticket->seat_id]) !== 1 ||
                $wpdb->update($p . 'session_seats', [
                    'status'=>'available', 'hold_id'=>null, 'expires_at'=>null, 'updated_at'=>$now,
                ], ['session_id'=>(int) $locator->session_id, 'seat_id'=>(int) $ticket->seat_id,
                    'status'=>'sold', 'hold_id'=>(int) $locator->hold_id]) !== 1 ||
                $wpdb->delete($p . 'hold_items', [
                    'hold_id'=>(int) $locator->hold_id, 'seat_id'=>(int) $ticket->seat_id,
                ]) !== 1) {
                throw new HoldException('cancel_write_failed', \PESM\Support\I18n::text('لغو و آزادسازی صندلی ثبت نشد.'), 503);
            }
            // The order itself and other tickets remain unchanged. Its financial
            // snapshot is retained (zero total); the ticket status is authoritative.
            Audit::record('free_ticket_cancelled', 'ticket', $ticketId, $actorId);
            self::commit($wpdb);
            return ['ticket_id'=>$ticketId, 'cancelled'=>true, 'idempotent_replay'=>false,
                'message'=>\PESM\Support\I18n::text('بلیت رایگان لغو شد و صندلی، بدون عملیات مالی، آزاد شد.')];
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($e instanceof HoldException) { throw $e; }
            throw new HoldException('cancel_failed', \PESM\Support\I18n::text('لغو بلیت به بررسی نیاز دارد.'), 503);
        }
    }

    private static function verifyFreeOrder(mixed $order): void
    {
        if (!$order || $order->get_meta('_pesm_is_ticket_order') !== 'yes' ||
            (float) $order->get_total() !== 0.0 ||
            !in_array((string) $order->get_status(), ['completed', 'processing'], true)) {
            throw new HoldException('not_free_ticket', \PESM\Support\I18n::text('فقط بلیت رایگانِ ثبت‌شده قابل لغو از این مسیر است.'), 409);
        }
    }

    private static function commit(object $db): void
    {
        if ($db->query('COMMIT') === false) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('تراکنش لغو تکمیل نشد.'), 503);
        }
    }
}
