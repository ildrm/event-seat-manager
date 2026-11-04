<?php

declare(strict_types=1);

namespace PESM\Domain\Ticket;

require_once __DIR__ . '/../Checkout/FinancialSnapshot.php';

use PESM\Domain\Booking\HoldException;
use PESM\Support\Audit;
use Throwable;

/** Issues tickets under the session -> hold -> inventory lock order. */
final class TicketService
{
    /**
     * Payment verified is ONLY supplied by the trusted WooCommerce payment_complete hook;
     * free orders are verified against exact zero total and a server-side price snapshot.
     * @return 'issued'|'already_issued'|'review_required'
     */
    public function confirmOrder(int $orderId, bool $free = false): string
    {
        if ((int) get_option('pesm_schema_version', 0) < 3) {
            throw new HoldException('schema_not_ready', \PESM\Support\I18n::text('ساختار پایگاه داده آماده نیست.'), 503);
        }
        if (!function_exists('wc_get_order')) { throw new HoldException('woocommerce_required', \PESM\Support\I18n::text('ووکامرس فعال نیست.'), 503); }
        $order = wc_get_order($orderId);
        if (!$order || $order->get_meta('_pesm_is_ticket_order') !== 'yes') {
            throw new HoldException('invalid_order', \PESM\Support\I18n::text('سفارش بلیت معتبر نیست.'), 404);
        }
        if ($free) {
            if ((float) $order->get_total() !== 0.0) {
                throw new HoldException('payment_unverified', \PESM\Support\I18n::text('پرداخت سفارش تأیید نشده است.'), 409);
            }
        } elseif (!$order->is_paid() || !method_exists($order, 'get_date_paid') || !$order->get_date_paid()) {
            throw new HoldException('payment_unverified', \PESM\Support\I18n::text('پرداخت سفارش هنوز تأیید نشده است.'), 409);
        }
        if (in_array((string) $order->get_status(), ['cancelled', 'failed', 'refunded', 'trash'], true)) {
            throw new HoldException('order_inactive', \PESM\Support\I18n::text('سفارش لغوشده یا ناموفق قابل صدور بلیت نیست.'), 409);
        }
        $holdId = (int) $order->get_meta('_pesm_hold_id');
        $sessionId = (int) $order->get_meta('_pesm_session_id');
        if (!$holdId || !$sessionId) {
            throw new HoldException('invalid_order', \PESM\Support\I18n::text('اطلاعات رزرو سفارش ناقص است.'), 409);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        if ($wpdb->query('START TRANSACTION') === false) { throw new HoldException('db_unavailable', \PESM\Support\I18n::text('پایگاه داده در دسترس نیست.'), 503); }
        try {
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT s.id,s.mode,s.unit_price_minor,s.status,e.status AS event_status FROM {$p}sessions s
                 JOIN {$p}events e ON e.id=s.event_id WHERE s.id=%d FOR UPDATE", $sessionId
            ));
            $hold = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}holds WHERE id=%d AND session_id=%d FOR UPDATE", $holdId, $sessionId
            ));
            $link = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}order_links WHERE hold_id=%d AND order_id=%d FOR UPDATE", $holdId, $orderId
            ));
            // A worker can wait on another checkout; never use a clock value
            // read before acquiring the locks to decide whether the lease lives.
            $now = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
            if (!$now) { throw new HoldException('db_unavailable', \PESM\Support\I18n::text('ساعت پایگاه داده در دسترس نیست.'), 503); }
            if ($session && (string) $session->mode !== 'seated') {
                throw new HoldException('checkout_unsupported', \PESM\Support\I18n::text('صدور بلیت ظرفیت آزاد هنوز فعال نیست.'), 409);
            }
            if (!$session || !$hold || !$link || (int) $hold->order_id !== $orderId) {
                throw new HoldException('invalid_order', \PESM\Support\I18n::text('اطلاعات سفارش و رزرو یکسان نیست.'), 409);
            }
            if ($link->status === 'issued' && $hold->status === 'sold') {
                $wpdb->query('COMMIT');
                return 'already_issued';
            }
            if ($session->status === 'cancelled' || $session->event_status === 'cancelled') {
                $wpdb->update($p . 'order_links', ['status' => 'review_required'], ['id' => (int) $link->id]);
                $wpdb->query('COMMIT');
                return 'review_required';
            }
            $seatIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT seat_id FROM {$p}hold_items WHERE hold_id=%d ORDER BY seat_id", $holdId
            )));
            if (!$seatIds) { throw new HoldException('invalid_order', \PESM\Support\I18n::text('صندلی سفارش یافت نشد.'), 409); }
            \PESM\Domain\Checkout\FinancialSnapshot::uniform($order, count($seatIds), $free);
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT seat_id,status,hold_id,expires_at FROM {$p}session_seats WHERE session_id=%d AND hold_id=%d ORDER BY seat_id FOR UPDATE",
                $sessionId, $holdId
            ));
            $conflict = $hold->status !== 'payment_pending' || $hold->expires_at <= $now || count($rows) !== count($seatIds);
            foreach ($rows as $row) {
                if ($row->status !== 'payment_pending' || (int) $row->hold_id !== $holdId || $row->expires_at <= $now) {
                    $conflict = true;
                }
            }
            if ($conflict) {
                $wpdb->update($p . 'order_links', ['status' => 'review_required'], ['id' => (int) $link->id]);
                $wpdb->query('COMMIT');
                return 'review_required';
            }
            $attendees = \PESM\Domain\Checkout\AttendeeManifest::storedSeats(
                $order->get_meta('_pesm_attendees_v1'), $seatIds,
                (string) $order->get_billing_first_name(), (string) $order->get_billing_last_name()
            );
            $personBySeat = [];
            foreach ($attendees as $person) {
                $personBySeat[$person['seat_id']] = \PESM\Domain\Checkout\AttendeeManifest::fullName($person);
            }
            foreach ($seatIds as $seatId) {
                $publicId = bin2hex(random_bytes(16));
                $ticketNumber = 'P-' . strtoupper(substr($publicId, 0, 16));
                $qr = TicketToken::create($publicId, 1, wp_salt('secure_auth'));
                if ($wpdb->insert($p . 'tickets', [
                    'public_id' => $publicId, 'ticket_number' => $ticketNumber,
                    'hold_id' => $holdId, 'session_id' => $sessionId,
                    'seat_id' => $seatId, 'order_id' => $orderId,
                    'attendee_name' => $personBySeat[$seatId], 'status' => 'active',
                    'qr_version' => 1, 'qr_token_hash' => hash('sha256', $qr),
                    'created_at' => $now,
                ]) !== 1) {
                    throw new HoldException('ticket_write_failed', \PESM\Support\I18n::text('صدور بلیت انجام نشد.'), 503);
                }
                if ($wpdb->update($p . 'session_seats', [
                    'status' => 'sold', 'expires_at' => null, 'updated_at' => $now,
                ], ['session_id' => $sessionId, 'seat_id' => $seatId, 'hold_id' => $holdId]) !== 1) {
                    throw new HoldException('ticket_write_failed', \PESM\Support\I18n::text('تثبیت جایگاه انجام نشد.'), 503);
                }
            }
            if ($wpdb->update($p . 'holds', ['status' => 'sold'], ['id' => $holdId]) !== 1 ||
                $wpdb->update($p . 'order_links', ['status' => 'issued'], ['id' => (int) $link->id]) !== 1) {
                throw new HoldException('ticket_write_failed', \PESM\Support\I18n::text('ثبت تأیید بلیت کامل نشد.'), 503);
            }
            Audit::record('tickets_issued', 'order', $orderId);
            if ($wpdb->query('COMMIT') === false) {
                throw new HoldException('ticket_write_failed', \PESM\Support\I18n::text('تراکنش صدور بلیت ثبت نشد.'), 503);
            }
            return 'issued';
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($e instanceof HoldException) { throw $e; }
            throw new HoldException('ticket_write_failed', \PESM\Support\I18n::text('صدور بلیت نیازمند بررسی است.'), 503);
        }
    }

    /**
     * Cancels unfulfilled seat allocations on a WooCommerce cancellation/refund.
     * For issued tickets, revokes entry but leaves seat status sold until manual
     * financial reconciliation (deliberately no automatic resale).
     */
    public function revokeOrder(int $orderId): string
    {
        $order = wc_get_order($orderId);
        if (!$order || $order->get_meta('_pesm_is_ticket_order') !== 'yes') { return 'not_pesm'; }
        $sessionId = (int) $order->get_meta('_pesm_session_id');
        $holdId = (int) $order->get_meta('_pesm_hold_id');
        if (!$sessionId || !$holdId) { return 'manual_review'; }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        if ($wpdb->query('START TRANSACTION') === false) { return 'manual_review'; }
        try {
            $now = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
            $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}sessions WHERE id=%d FOR UPDATE", $sessionId));
            $hold = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}holds WHERE id=%d FOR UPDATE", $holdId));
            $link = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}order_links WHERE hold_id=%d AND order_id=%d FOR UPDATE", $holdId, $orderId));
            if (!$hold || !$link) { throw new \RuntimeException('Order link missing.'); }
            if ($link->status === 'cancelled') { $wpdb->query('COMMIT'); return 'already_cancelled'; }
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id,seat_id,status FROM {$p}session_seats WHERE session_id=%d AND hold_id=%d ORDER BY seat_id FOR UPDATE", $sessionId, $holdId
            ));
            if ($hold->status === 'payment_pending') {
                foreach ($rows as $row) {
                    if ($row->status === 'payment_pending') {
                        if ($wpdb->update($p . 'session_seats', [
                            'status' => 'available', 'hold_id' => null, 'expires_at' => null, 'updated_at' => $now,
                        ], ['id' => (int) $row->id, 'status' => 'payment_pending', 'hold_id' => $holdId]) !== 1) {
                            throw new \RuntimeException('Failed to free pending seat.');
                        }
                    }
                }
            }
            // A sold seat remains occupied, even if the ticket is revoked.
            $tickets = $wpdb->get_results($wpdb->prepare(
                "SELECT id,status FROM {$p}tickets WHERE hold_id=%d ORDER BY id FOR UPDATE", $holdId
            ));
            foreach ($tickets as $ticket) {
                if ($ticket->status === 'active') {
                    if ($wpdb->update($p . 'tickets', ['status' => 'cancelled'], ['id' => (int) $ticket->id, 'status' => 'active']) !== 1) {
                        throw new \RuntimeException('Failed to revoke ticket.');
                    }
                }
            }
            if ($wpdb->update($p . 'holds', ['status' => 'cancelled'], ['id' => $holdId]) !== 1 ||
                $wpdb->update($p . 'order_links', ['status' => 'cancelled'], ['id' => (int) $link->id]) !== 1) {
                throw new \RuntimeException('Failed to revoke order link.');
            }
            Audit::record('tickets_revoked', 'order', $orderId);
            if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Revoke commit failed.'); }
            return 'cancelled';
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return 'manual_review';
        }
    }

    /** @return array<string,mixed> */
    public function forGuest(int $orderId, string $accessToken): array
    {
        if ((int) get_option('pesm_schema_version', 0) < 3) {
            throw new HoldException('schema_not_ready', \PESM\Support\I18n::text('ساختار پایگاه داده آماده نیست.'), 503);
        }
        if ($orderId < 1 || !preg_match('/^[a-f0-9]{64}$/D', $accessToken)) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('اجازه مشاهده بلیت‌ها وجود ندارد.'), 403);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $general = function_exists('wc_get_order') && ($candidate = wc_get_order($orderId))
            && $candidate->get_meta('_pesm_mode') === 'general';
        $linksTable = $general ? $p . 'capacity_order_links' : $p . 'order_links';
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT id,status FROM {$linksTable} WHERE order_id=%d AND guest_token_hash=%s",
            $orderId, hash('sha256', $accessToken)
        ));
        if (!$link) {
            // Email-recovered bearer tokens expire independently of the order token.
            $valid = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$p}guest_access_tokens WHERE order_id=%d AND token_hash=%s
                 AND expires_at>UTC_TIMESTAMP() AND revoked_at IS NULL LIMIT 1",
                $orderId, hash('sha256', $accessToken)
            ));
            if (!$valid) { throw new HoldException('permission_denied', \PESM\Support\I18n::text('توکن دسترسی معتبر یا فعال نیست.'), 403); }
            // Recovered tokens authorize this *order*, but do not match the original
            // order_link token hash. Load its fulfillment state independently.
            $link = $wpdb->get_row($wpdb->prepare(
                "SELECT id,status FROM {$linksTable} WHERE order_id=%d LIMIT 1", $orderId
            ));
            if (!$link) { throw new HoldException('invalid_order', \PESM\Support\I18n::text('اطلاعات سفارش یافت نشد.'), 404); }
        }
        $rows = $general
            ? $wpdb->get_results($wpdb->prepare(
                "SELECT t.id,t.public_id,t.ticket_number,t.attendee_name,t.status,t.qr_version,t.session_id,
                 'ورود آزاد' AS seat_code,'' AS row_label,t.admission_index AS seat_number,
                 'ظرفیت آزاد' AS zone_label FROM {$p}capacity_tickets t WHERE t.order_id=%d
                 AND NOT EXISTS (SELECT 1 FROM {$p}ticket_owners o WHERE o.ticket_type='general' AND o.ticket_id=t.id)
                 ORDER BY t.admission_index",
                $orderId
              ), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare(
                "SELECT t.id,t.public_id,t.ticket_number,t.attendee_name,t.status,t.qr_version,t.session_id,
                 s.seat_code,s.row_label,s.seat_number,s.zone_label
                 FROM {$p}tickets t JOIN {$p}seats s ON s.id=t.seat_id WHERE t.order_id=%d
                 AND NOT EXISTS (SELECT 1 FROM {$p}ticket_owners o WHERE o.ticket_type='seated' AND o.ticket_id=t.id)
                 ORDER BY t.id",
                $orderId
              ), ARRAY_A);
        if (!is_array($rows)) { throw new HoldException('db_unavailable', \PESM\Support\I18n::text('دریافت بلیت‌ها ممکن نشد.'), 503); }
        foreach ($rows as &$row) {
            if ($general) {
                $row['seat_code'] = \PESM\Support\I18n::text('ورود آزاد');
                $row['zone_label'] = \PESM\Support\I18n::text('ظرفیت آزاد');
            }
            $row['qr_payload'] = in_array($row['status'], ['active', 'checked_in'], true)
                ? TicketToken::create((string) $row['public_id'], (int) $row['qr_version'], wp_salt('secure_auth')) : null;
        }
        unset($row);
        // The invoice is disclosed ONLY after validating the guest bearer token.
        // WooCommerce CRUD is used (not direct wp_posts queries) for HPOS-safe access.
        if (!function_exists('wc_get_order')) {
            throw new HoldException('woocommerce_required', \PESM\Support\I18n::text('برای مشاهده صورتحساب ووکامرس لازم است.'), 503);
        }
        $order = wc_get_order($orderId);
        if (!$order || $order->get_meta('_pesm_is_ticket_order') !== 'yes') {
            throw new HoldException('invalid_order', \PESM\Support\I18n::text('سفارش معتبر نیست.'), 404);
        }
        $invoice = [
            'order_status' => (string) $order->get_status(),
            'currency' => (string) $order->get_currency(),
            'total' => (string) $order->get_total(),
            'discount_total' => (string) $order->get_discount_total(),
            'tax_total' => (string) $order->get_total_tax(),
            'refunded_total' => (string) $order->get_total_refunded(),
            'payment_method' => (string) $order->get_payment_method_title(),
            'transaction_id' => (string) $order->get_transaction_id(),
            'date_created' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : null,
        ];
        return ['order_id' => $orderId, 'fulfillment_status' => $link->status, 'tickets' => $rows, 'invoice' => $invoice];
    }
}
