<?php

declare(strict_types=1);

namespace PESM\Domain\Ticket;

use PESM\Domain\Booking\HoldException;
use PESM\Security\Capabilities;
use PESM\Support\Audit;
use Throwable;

/**
 * Manager-only attendee correction; keeps QR identity and financial data intact.
 * The row lock serializes against gate entry (ticket FOR UPDATE) and cancellation.
 */
final class AttendeeChangeService
{
    /** @return array<string,mixed> */
    public function change(string $type, int $ticketId, string $newName, string $requestKey, int $actorId): array
    {
        if ($actorId < 1 || !user_can($actorId, 'pesm_issue_tickets')) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('مجوز ویرایش بلیت وجود ندارد.'), 403);
        }
        if ((int) get_option('pesm_schema_version', 0) < 12) {
            throw new HoldException('schema_not_ready', \PESM\Support\I18n::text('ارتقای پایگاه داده ضروری است.'), 503);
        }
        if (!in_array($type, ['seated', 'general'], true) || $ticketId < 1 ||
            !preg_match('/^[a-f0-9]{32,128}$/D', $requestKey)) {
            throw new HoldException('invalid_request', \PESM\Support\I18n::text('شناسه درخواست نامعتبر است.'), 422);
        }
        $newName = AttendeeChangePolicy::normalizedName($newName);
        $table = $type === 'seated' ? 'tickets' : 'capacity_tickets';
        $checkins = $type === 'seated' ? 'checkins' : 'capacity_checkins';
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        // Scope must be checked before attempting to lock or expose the ticket.
        $locator = $wpdb->get_row($wpdb->prepare(
            "SELECT t.session_id,t.order_id,e.center_id FROM {$p}{$table} t
             JOIN {$p}sessions s ON s.id=t.session_id JOIN {$p}events e ON e.id=s.event_id
             WHERE t.id=%d", $ticketId
        ));
        if (!$locator || !Capabilities::centerAllowed((int) $locator->center_id, $actorId)) {
            throw new HoldException('ticket_not_found', \PESM\Support\I18n::text('بلیت در محدوده مجاز پیدا نشد.'), 404);
        }
        if (!function_exists('wc_get_order')) {
            throw new HoldException('woocommerce_required', \PESM\Support\I18n::text('ووکامرس فعال نیست.'), 503);
        }
        $order = wc_get_order((int) $locator->order_id);
        if (!$order || $order->get_meta('_pesm_is_ticket_order') !== 'yes' ||
            !in_array((string) $order->get_status(), ['processing', 'completed'], true)) {
            throw new HoldException('order_unavailable', \PESM\Support\I18n::text('سفارش این بلیت قابل ویرایش نیست.'), 409);
        }
        $hash = hash_hmac('sha256', "$type:$ticketId:$actorId:$requestKey", wp_salt('auth'));
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('آغاز تراکنش ممکن نشد.'), 503);
        }
        try {
            // Ticket is the single mutable identity here. Gate scanner locks ticket FIRST.
            // Do not lock session FOR UPDATE before it: avoiding inverse lock ordering.
            $ticket = $wpdb->get_row($wpdb->prepare(
                "SELECT id,session_id,order_id,attendee_name,status FROM {$p}{$table} WHERE id=%d FOR UPDATE", $ticketId
            ));
            if (!$ticket || (int) $ticket->session_id !== (int) $locator->session_id ||
                (int) $ticket->order_id !== (int) $locator->order_id) {
                throw new HoldException('ticket_changed', \PESM\Support\I18n::text('مشخصات بلیت هم‌زمان تغییر کرده است.'), 409);
            }
            $previous = $wpdb->get_row($wpdb->prepare(
                "SELECT old_name,new_name FROM {$p}ticket_attendee_changes WHERE request_hash=%s", $hash
            ));
            if ($previous) {
                if (!hash_equals((string) $previous->new_name, $newName)) {
                    throw new HoldException('idempotency_conflict', \PESM\Support\I18n::text('این شناسه درخواست برای تغییر دیگری استفاده شده است.'), 409);
                }
                self::commit($wpdb);
                return ['ticket_id'=>$ticketId, 'type'=>$type, 'attendee_name'=>$newName, 'replayed'=>true];
            }
            // A non-locking session read matches gate's lock order and the session's
            // published start is immutable under existing session lifecycle constraints.
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT s.starts_at,s.status,e.status AS event_status,e.center_id FROM {$p}sessions s
                 JOIN {$p}events e ON e.id=s.event_id WHERE s.id=%d", (int) $ticket->session_id
            ));
            $now = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
            $checkedIn = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$p}{$checkins} WHERE ticket_id=%d LIMIT 1", $ticketId
            ));
            if (!$session || (int) $session->center_id !== (int) $locator->center_id ||
                !Capabilities::centerAllowed((int) $session->center_id, $actorId) ||
                $session->status === 'cancelled' || $session->event_status === 'cancelled') {
                throw new HoldException('ticket_unavailable', \PESM\Support\I18n::text('جلسه این بلیت قابل ویرایش نیست.'), 409);
            }
            AttendeeChangePolicy::canChange((string) $ticket->status, $checkedIn, (string) $session->starts_at, $now);
            // Recheck order state: avoid changing a ticket if refund/cancellation completed.
            $order = wc_get_order((int) $ticket->order_id);
            if (!$order || !in_array((string) $order->get_status(), ['processing', 'completed'], true)) {
                throw new HoldException('order_unavailable', \PESM\Support\I18n::text('وضعیت سفارش اجازه تغییر را نمی‌دهد.'), 409);
            }
            $oldName = (string) $ticket->attendee_name;
            $unchanged = hash_equals($oldName, $newName);
            // Reserve idempotency key even for no-op corrections. A replay with a
            // different name must not mutate a ticket under the same key.
            if ((!$unchanged && $wpdb->update($p . $table, ['attendee_name'=>$newName],
                ['id'=>$ticketId, 'attendee_name'=>$oldName, 'status'=>'active']) !== 1) ||
                $wpdb->insert($p . 'ticket_attendee_changes', [
                    'ticket_type'=>$type, 'ticket_id'=>$ticketId, 'request_hash'=>$hash,
                    'old_name'=>$oldName, 'new_name'=>$newName, 'actor_user_id'=>$actorId,
                    'created_at'=>$now,
                ]) !== 1) {
                throw new HoldException('write_failed', \PESM\Support\I18n::text('تغییرات شرکت‌کننده ثبت نشد.'), 503);
            }
            Audit::record('attendee_name_changed', $type === 'seated' ? 'ticket' : 'capacity_ticket', $ticketId, $actorId);
            self::commit($wpdb);
            return ['ticket_id'=>$ticketId, 'type'=>$type, 'attendee_name'=>$newName, 'replayed'=>false, 'unchanged'=>$unchanged];
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($e instanceof HoldException) { throw $e; }
            throw new HoldException('change_failed', \PESM\Support\I18n::text('تغییر مشخصات شرکت‌کننده ناموفق بود.'), 503);
        }
    }

    private static function commit(object $wpdb): void
    {
        if ($wpdb->query('COMMIT') === false) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('تراکنش ثبت نشد.'), 503);
        }
    }
}
