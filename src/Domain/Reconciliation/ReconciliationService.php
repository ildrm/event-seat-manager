<?php

declare(strict_types=1);

namespace PESM\Domain\Reconciliation;

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Ticket\TicketService;
use PESM\Domain\GeneralAdmission\FreeAdmissionService;
use PESM\Security\Capabilities;

/** Center-scoped financial/inventory discrepancy inspection and conservative repair. */
final class ReconciliationService
{
    /** @return array{items:array<int,array<string,mixed>>,count:int} */
    public function anomalies(int $userId, int $centerId, int $limit = 100): array
    {
        if ($userId < 1 || !user_can($userId, 'pesm_view_orders') ||
            !Capabilities::centerAllowed($centerId, $userId)) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('اجازه مشاهده سفارش‌های این مرکز را ندارید.'), 403);
        }
        if ((int) get_option('pesm_schema_version', 0) < 3) {
            throw new HoldException('schema_not_ready', \PESM\Support\I18n::text('ساختار پایگاه داده آماده نیست.'), 503);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $limit = max(1, min(200, $limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT h.id AS hold_id,h.order_id,h.status AS hold_status,h.expires_at,
                    s.id AS session_id,s.event_id,l.status AS link_status,
                    l.order_id AS linked_order_id,
                    (SELECT COUNT(*) FROM {$p}hold_items hi WHERE hi.hold_id=h.id) AS seat_count,
                    (SELECT COUNT(*) FROM {$p}tickets t WHERE t.hold_id=h.id) AS ticket_count
               FROM {$p}holds h
               JOIN {$p}sessions s ON s.id=h.session_id
               JOIN {$p}events e ON e.id=s.event_id
               LEFT JOIN {$p}order_links l ON l.hold_id=h.id
              WHERE e.center_id=%d
                AND (h.status IN ('checkout_creating','payment_pending','sold') OR l.status='review_required')
              ORDER BY h.id DESC LIMIT %d", $centerId, $limit
        ), ARRAY_A);
        if (!is_array($rows)) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('خواندن گزارش مغایرت امکان‌پذیر نیست.'), 503);
        }
        $items = [];
        $now = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
        foreach ($rows as $row) {
            $kind = self::classify($row, $now);
            if ($kind === null) { continue; }
            // Never disclose customer names, contact data, payment secrets or hold hashes.
            $items[] = [
                'hold_id' => (int) $row['hold_id'], 'order_id' => (int) $row['order_id'],
                'session_id' => (int) $row['session_id'], 'event_id' => (int) $row['event_id'],
                'hold_status' => (string) $row['hold_status'],
                'link_status' => (string) ($row['link_status'] ?? ''),
                'seat_count' => (int) $row['seat_count'], 'ticket_count' => (int) $row['ticket_count'],
                'problem' => $kind,
            ];
        }
        if ((int) get_option('pesm_schema_version', 0) >= 10) {
            $ga = $wpdb->get_results($wpdb->prepare(
                "SELECT h.id AS hold_id,h.order_id,h.status AS hold_status,h.expires_at,
                        h.quantity AS seat_count,s.id AS session_id,s.event_id,
                        l.status AS link_status,l.order_id AS linked_order_id,
                        (SELECT COUNT(*) FROM {$p}capacity_tickets t WHERE t.hold_id=h.id) AS ticket_count
                   FROM {$p}capacity_holds h JOIN {$p}sessions s ON s.id=h.session_id
                   JOIN {$p}events e ON e.id=s.event_id
                   LEFT JOIN {$p}capacity_order_links l ON l.hold_id=h.id
                  WHERE e.center_id=%d AND
                        (h.status IN ('checkout_creating','payment_pending','issued') OR l.status='review_required')
                  ORDER BY h.id DESC LIMIT %d", $centerId, $limit
            ), ARRAY_A);
            if (!is_array($ga)) {
                throw new HoldException('db_unavailable', \PESM\Support\I18n::text('خواندن مغایرت ظرفیت آزاد ممکن نشد.'), 503);
            }
            foreach ($ga as $row) {
                $kind = self::classifyCapacity($row, $now);
                if ($kind === null) { continue; }
                $items[] = [
                    'inventory_type' => 'general',
                    'hold_id' => (int) $row['hold_id'], 'order_id' => (int) $row['order_id'],
                    'session_id' => (int) $row['session_id'], 'event_id' => (int) $row['event_id'],
                    'hold_status' => (string) $row['hold_status'],
                    'link_status' => (string) ($row['link_status'] ?? ''),
                    'seat_count' => (int) $row['seat_count'],
                    'ticket_count' => (int) $row['ticket_count'], 'problem' => $kind,
                ];
            }
        }
        return ['items' => $items, 'count' => count($items)];
    }

    /** Pure classification, independently testable. */
    public static function classify(array $row, string $now): ?string
    {
        if (($row['link_status'] ?? '') === 'review_required') { return 'manual_review'; }
        if ($row['hold_status'] === 'sold') {
            return (int) $row['seat_count'] !== (int) $row['ticket_count'] ||
                ($row['link_status'] ?? '') !== 'issued' ? 'ticket_inventory_mismatch' : null;
        }
        if ($row['hold_status'] === 'checkout_creating' && (string) $row['expires_at'] <= $now) {
            return 'abandoned_checkout';
        }
        if ($row['hold_status'] === 'payment_pending' && (string) $row['expires_at'] <= $now) {
            return 'expired_payment';
        }
        if ($row['hold_status'] === 'payment_pending' && empty($row['linked_order_id'])) {
            return 'missing_order_link';
        }
        return null;
    }

    /** Pure classification for unnumbered admission: quantities are ticket counts. */
    public static function classifyCapacity(array $row, string $now): ?string
    {
        if (($row['link_status'] ?? '') === 'review_required') { return 'manual_review'; }
        if ($row['hold_status'] === 'issued') {
            return (int) $row['seat_count'] !== (int) $row['ticket_count'] ||
                ($row['link_status'] ?? '') !== 'issued' ? 'ticket_inventory_mismatch' : null;
        }
        if ($row['hold_status'] === 'checkout_creating' && (string) $row['expires_at'] <= $now) {
            return 'abandoned_checkout';
        }
        if ($row['hold_status'] === 'payment_pending' && (string) $row['expires_at'] <= $now) {
            return 'expired_payment';
        }
        if ($row['hold_status'] === 'payment_pending' && empty($row['linked_order_id'])) {
            return 'missing_order_link';
        }
        return null;
    }

    /** Free general-admission retry; authenticated, scoped, never reassigns expired capacity. */
    public function retryFreeAdmission(int $userId, int $centerId, int $orderId): string
    {
        if ($userId < 1 || !user_can($userId, 'pesm_manage_refunds') ||
            !Capabilities::centerAllowed($centerId, $userId)) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('اجازه رسیدگی به سفارش مرکز را ندارید.'), 403);
        }
        if ($orderId < 1 || (int) get_option('pesm_schema_version', 0) < 10 || !function_exists('wc_get_order')) {
            throw new HoldException('invalid_order', \PESM\Support\I18n::text('سفارش در دسترس نیست.'), 404);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $match = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}capacity_order_links l
              JOIN {$p}capacity_holds h ON h.id=l.hold_id
              JOIN {$p}sessions s ON s.id=h.session_id
              JOIN {$p}events e ON e.id=s.event_id
              WHERE l.order_id=%d AND e.center_id=%d AND h.order_id=%d",
            $orderId, $centerId, $orderId
        ));
        if ($match !== 1) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('سفارش به مرکز انتخاب‌شده تعلق ندارد.'), 403);
        }
        $result = (new FreeAdmissionService())->confirmFree($orderId);
        if ($result === 'issued' || $result === 'already_issued') {
            $order = wc_get_order($orderId);
            if ($order && $order->get_status() === 'pending') {
                $order->update_status('completed', \PESM\Support\I18n::text('صدور مجددِ کنترل‌شده بلیت رایگان ظرفیت آزاد.'), true);
            }
        }
        return $result;
    }

    /** Paid general-admission repair is manager-only and cannot reclaim expired capacity. */
    public function retryPaidAdmission(int $userId, int $centerId, int $orderId): string
    {
        if ($userId < 1 || !user_can($userId, 'pesm_manage_refunds') ||
            !Capabilities::centerAllowed($centerId, $userId)) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('اجازه رسیدگی به سفارش این مرکز را ندارید.'), 403);
        }
        if ($orderId < 1 || (int) get_option('pesm_schema_version', 0) < 10 || !function_exists('wc_get_order')) {
            throw new HoldException('invalid_order', \PESM\Support\I18n::text('سفارش یافت نشد.'), 404);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $match = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}capacity_order_links l
             JOIN {$p}capacity_holds h ON h.id=l.hold_id
             JOIN {$p}sessions s ON s.id=h.session_id
             JOIN {$p}events e ON e.id=s.event_id
             WHERE l.order_id=%d AND e.center_id=%d AND h.order_id=%d", $orderId, $centerId, $orderId
        ));
        if ($match !== 1) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('سفارش متعلق به مرکز انتخاب‌شده نیست.'), 403);
        }
        return (new FreeAdmissionService())->confirmPaid($orderId);
    }

    /** Only retries paid orders. Never releases stock, changes order totals or refunds money. */
    public function retryPaidOrder(int $userId, int $centerId, int $orderId): string
    {
        if ($userId < 1 || !user_can($userId, 'pesm_manage_refunds') ||
            !Capabilities::centerAllowed($centerId, $userId)) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('اجازه بررسی مالی این مرکز را ندارید.'), 403);
        }
        if ($orderId < 1 || !function_exists('wc_get_order')) {
            throw new HoldException('invalid_order', \PESM\Support\I18n::text('سفارش در دسترس نیست.'), 404);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $match = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}order_links l
                 JOIN {$p}holds h ON h.id=l.hold_id
                 JOIN {$p}sessions s ON s.id=h.session_id
                 JOIN {$p}events e ON e.id=s.event_id
                WHERE l.order_id=%d AND e.center_id=%d AND h.order_id=%d", $orderId, $centerId, $orderId
        ));
        if ($match !== 1) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('سفارش به مرکز انتخاب‌شده تعلق ندارد.'), 403);
        }
        $order = wc_get_order($orderId);
        if (!$order || $order->get_meta('_pesm_is_ticket_order') !== 'yes' || !$order->is_paid()) {
            throw new HoldException('payment_unverified', \PESM\Support\I18n::text('وضعیت پرداخت قابل تأیید نیست.'), 409);
        }
        return (new TicketService())->confirmOrder($orderId, false);
    }
}
