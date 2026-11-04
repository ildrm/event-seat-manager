<?php

declare(strict_types=1);

namespace PESM\Domain\Ticket;

use PESM\Domain\Booking\HoldException;
use PESM\Security\Capabilities;
use PESM\Support\Audit;
use Throwable;

/**
 * Controlled same-price post-issuance transfer. Never touches WooCommerce money.
 * Lock order: session -> hold -> order link -> inventory(sorted) -> ticket.
 * The immutable transfer ledger permits safe retries after an HTTP timeout.
 */
final class SeatTransferService
{
    /** @return array<string,int|bool|string> */
    public function transfer(int $ticketId, int $destinationSeatId, string $requestKey, int $actorId): array
    {
        SeatTransferPolicy::validate($ticketId, $destinationSeatId, $requestKey);
        if ($actorId < 1 || !(user_can($actorId, 'pesm_issue_tickets') || user_can($actorId, 'pesm_manage_refunds'))) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('مجوز تغییر صندلی بلیت را ندارید.'), 403);
        }
        if ((int) get_option('pesm_schema_version', 0) < 7) {
            throw new HoldException('schema_not_ready', \PESM\Support\I18n::text('ابتدا ساختار پایگاه داده را ارتقا دهید.'), 503);
        }
        if (!function_exists('wc_get_order')) {
            throw new HoldException('woocommerce_required', \PESM\Support\I18n::text('ووکامرس در دسترس نیست.'), 503);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $locator = $wpdb->get_row($wpdb->prepare(
            "SELECT t.session_id,t.hold_id,t.order_id,e.center_id FROM {$p}tickets t
             JOIN {$p}sessions s ON s.id=t.session_id JOIN {$p}events e ON e.id=s.event_id
             WHERE t.id=%d", $ticketId
        ));
        if (!$locator || !Capabilities::centerAllowed((int) $locator->center_id, $actorId)) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('بلیت در محدوده مجاز شما نیست.'), 403);
        }
        // WooCommerce order is the financial source of truth, but its state
        // cannot participate in the inventory transaction. Reconciliation is mandatory.
        $order = wc_get_order((int) $locator->order_id);
        self::verifyOrder($order);
        $requestHash = hash_hmac('sha256', "ticket-seat-transfer:{$ticketId}:{$requestKey}", wp_salt('auth'));
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('ارتباط پایگاه داده برقرار نیست.'), 503);
        }
        try {
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT s.id,s.starts_at,s.status,s.require_adjacent,s.avoid_single_gap,
                    e.status AS event_status,e.center_id
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
                (int) $session->center_id !== (int) $locator->center_id) {
                throw new HoldException('invalid_order', \PESM\Support\I18n::text('تخصیص بلیت و سفارش نیازمند بررسی است.'), 409);
            }
            if (!Capabilities::centerAllowed((int) $session->center_id, $actorId)) {
                throw new HoldException('permission_denied', \PESM\Support\I18n::text('مجوز این مرکز معتبر نیست.'), 403);
            }
            // Recheck financial state immediately before the inventory change.
            self::verifyOrder(wc_get_order((int) $locator->order_id));
            $ticketSnapshot = $wpdb->get_row($wpdb->prepare(
                "SELECT id,seat_id,status,qr_version,public_id,session_id,hold_id,order_id FROM {$p}tickets WHERE id=%d",
                $ticketId
            ));
            if (!$ticketSnapshot || (int) $ticketSnapshot->session_id !== (int) $locator->session_id ||
                (int) $ticketSnapshot->hold_id !== (int) $locator->hold_id ||
                (int) $ticketSnapshot->order_id !== (int) $locator->order_id) {
                throw new HoldException('invalid_ticket', \PESM\Support\I18n::text('بلیت تغییر کرده یا پیدا نشد.'), 409);
            }
            $history = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}ticket_seat_transfers WHERE request_hash=%s FOR UPDATE", $requestHash
            ));
            if ($history) {
                if ((int) $history->ticket_id !== $ticketId || (int) $history->to_seat_id !== $destinationSeatId ||
                    (int) $ticketSnapshot->seat_id !== $destinationSeatId ||
                    (int) $ticketSnapshot->qr_version !== (int) $history->new_qr_version ||
                    (string) $ticketSnapshot->status !== 'active') {
                    throw new HoldException('idempotency_conflict', \PESM\Support\I18n::text('این کلید برای انتقال دیگری ثبت شده است.'), 409);
                }
                self::commit($wpdb);
                return self::response($ticketId, (int) $history->from_seat_id, $destinationSeatId,
                    (int) $history->new_qr_version, true);
            }
            $oldSeatId = (int) $ticketSnapshot->seat_id;
            if ($oldSeatId === $destinationSeatId) {
                throw new HoldException('invalid_selection', \PESM\Support\I18n::text('صندلی مقصد با صندلی فعلی یکسان است.'), 422);
            }
            $in = [$oldSeatId, $destinationSeatId];
            sort($in, SORT_NUMERIC);
            $inventory = $wpdb->get_results($wpdb->prepare(
                "SELECT seat_id,status,hold_id FROM {$p}session_seats WHERE session_id=%d AND seat_id IN (%d,%d)
                 ORDER BY seat_id FOR UPDATE", (int) $locator->session_id, ...$in
            ));
            if (!is_array($inventory) || count($inventory) !== 2) {
                throw new HoldException('invalid_seat', \PESM\Support\I18n::text('صندلی مقصد در این جلسه وجود ندارد.'), 422);
            }
            $bySeat = [];
            foreach ($inventory as $entry) { $bySeat[(int) $entry->seat_id] = $entry; }
            if ((string) $bySeat[$oldSeatId]->status !== 'sold' ||
                (int) $bySeat[$oldSeatId]->hold_id !== (int) $locator->hold_id) {
                throw new HoldException('seat_conflict', \PESM\Support\I18n::text('مالکیت صندلی فعلی معتبر نیست.'), 409);
            }
            SeatTransferPolicy::assertDestination((string) $bySeat[$destinationSeatId]->status);
            // Scanner locks the ticket, not session/inventory. Revalidate after waiting for it.
            $ticket = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}tickets WHERE id=%d FOR UPDATE", $ticketId
            ));
            $checkedIn = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$p}checkins WHERE ticket_id=%d LIMIT 1", $ticketId
            ));
            if (!$ticket || (int) $ticket->seat_id !== $oldSeatId ||
                (int) $ticket->qr_version !== (int) $ticketSnapshot->qr_version) {
                throw new HoldException('seat_conflict', \PESM\Support\I18n::text('بلیت هم‌زمان تغییر کرده است.'), 409);
            }
            SeatTransferPolicy::mustBeActive((string) $ticket->status, (string) $session->status,
                (string) $session->event_status, (string) $session->starts_at, $now, $checkedIn);
            if ((int) $session->require_adjacent || (int) $session->avoid_single_gap) {
                $otherSeats = array_map('intval', $wpdb->get_col($wpdb->prepare(
                    "SELECT seat_id FROM {$p}hold_items WHERE hold_id=%d AND seat_id<>%d ORDER BY seat_id",
                    (int) $locator->hold_id, $oldSeatId
                )));
                $allRows = $wpdb->get_results($wpdb->prepare(
                    "SELECT inv.seat_id,inv.status,seat.row_label,seat.zone_label,seat.pos_x,seat.pos_y
                     FROM {$p}session_seats inv JOIN {$p}seats seat ON seat.id=inv.seat_id
                     WHERE inv.session_id=%d ORDER BY inv.seat_id FOR UPDATE",
                    (int) $locator->session_id
                ));
                if (!is_array($allRows) || !$allRows) {
                    throw new HoldException('db_unavailable', \PESM\Support\I18n::text('بررسی قوانین صندلی ممکن نیست.'), 503);
                }
                SeatTransferPolicy::validateTopology(array_map(static fn(object $r): array => (array) $r, $allRows),
                    $otherSeats, $oldSeatId, $destinationSeatId,
                    (bool) $session->require_adjacent, (bool) $session->avoid_single_gap);
            }
            if ((int) $ticket->qr_version >= 2147483646) {
                throw new HoldException('qr_version_limit', \PESM\Support\I18n::text('شماره نسخه QR قابل افزایش نیست.'), 409);
            }
            $nextQrVersion = (int) $ticket->qr_version + 1;
            $nextQr = TicketToken::create((string) $ticket->public_id, $nextQrVersion, wp_salt('secure_auth'));
            if ($wpdb->update($p . 'session_seats', [
                'status' => 'sold', 'hold_id' => (int) $locator->hold_id, 'expires_at' => null, 'updated_at' => $now,
            ], ['session_id' => (int) $locator->session_id, 'seat_id' => $destinationSeatId, 'status' => 'available']) !== 1 ||
                $wpdb->update($p . 'tickets', [
                    'seat_id' => $destinationSeatId, 'qr_version' => $nextQrVersion,
                    'qr_token_hash' => hash('sha256', $nextQr),
                ], ['id' => $ticketId, 'seat_id' => $oldSeatId, 'status' => 'active']) !== 1 ||
                $wpdb->delete($p . 'hold_items', ['hold_id' => (int) $locator->hold_id, 'seat_id' => $oldSeatId]) !== 1 ||
                $wpdb->insert($p . 'hold_items', ['hold_id' => (int) $locator->hold_id, 'seat_id' => $destinationSeatId]) !== 1 ||
                $wpdb->update($p . 'session_seats', [
                    'status' => 'available', 'hold_id' => null, 'expires_at' => null, 'updated_at' => $now,
                ], ['session_id' => (int) $locator->session_id, 'seat_id' => $oldSeatId,
                    'status' => 'sold', 'hold_id' => (int) $locator->hold_id]) !== 1 ||
                $wpdb->insert($p . 'ticket_seat_transfers', [
                    'ticket_id' => $ticketId, 'request_hash' => $requestHash,
                    'from_seat_id' => $oldSeatId, 'to_seat_id' => $destinationSeatId,
                    'old_qr_version' => (int) $ticket->qr_version, 'new_qr_version' => $nextQrVersion,
                    'actor_user_id' => $actorId, 'created_at' => $now,
                ]) !== 1) {
                throw new HoldException('transfer_write_failed', \PESM\Support\I18n::text('ذخیره انتقال بلیت انجام نشد.'), 503);
            }
            Audit::record('ticket_seat_transferred', 'ticket', $ticketId, $actorId);
            self::commit($wpdb);
            // Woo order item retains the ORIGINAL seat as the historical purchase snapshot.
            // Never silently edit paid financial line items as a side-effect of seat transfer.
            return self::response($ticketId, $oldSeatId, $destinationSeatId, $nextQrVersion, false);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($e instanceof HoldException) { throw $e; }
            throw new HoldException('transfer_failed', \PESM\Support\I18n::text('انتقال صندلی به بررسی نیاز دارد.'), 503);
        }
    }

    /** @return array<string,mixed> No request hashes or bearer credentials leave the server. */
    public function history(int $ticketId, int $actorId): array
    {
        if ($ticketId < 1 || $actorId < 1 ||
            !(user_can($actorId, 'pesm_view_orders') || user_can($actorId, 'pesm_issue_tickets') ||
              user_can($actorId, 'pesm_manage_refunds'))) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('اجازه مشاهده تاریخچه بلیت وجود ندارد.'), 403);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $center = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT e.center_id FROM {$p}tickets t JOIN {$p}sessions s ON s.id=t.session_id
             JOIN {$p}events e ON e.id=s.event_id WHERE t.id=%d", $ticketId
        ));
        if (!Capabilities::centerAllowed($center, $actorId)) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('دسترسی به تاریخچه این مرکز مجاز نیست.'), 403);
        }
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT tr.id,tr.from_seat_id,tr.to_seat_id,tr.old_qr_version,tr.new_qr_version,
                    tr.actor_user_id,tr.created_at,oldseat.seat_code AS from_seat_code,
                    newseat.seat_code AS to_seat_code
             FROM {$p}ticket_seat_transfers tr
             JOIN {$p}seats oldseat ON oldseat.id=tr.from_seat_id
             JOIN {$p}seats newseat ON newseat.id=tr.to_seat_id
             WHERE tr.ticket_id=%d ORDER BY tr.id DESC LIMIT 50", $ticketId
        ), ARRAY_A);
        if (!is_array($items)) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('دریافت تاریخچه انتقال ممکن نیست.'), 503);
        }
        return ['ticket_id' => $ticketId, 'transfers' => $items];
    }

    private static function verifyOrder(mixed $order): void
    {
        if (!$order || $order->get_meta('_pesm_is_ticket_order') !== 'yes' ||
            in_array((string) $order->get_status(), ['failed', 'cancelled', 'refunded', 'trash'], true) ||
            (method_exists($order, 'get_total_refunded') && (float) $order->get_total_refunded() > 0.0) ||
            (!$order->is_paid() && !((float) $order->get_total() === 0.0 &&
                in_array((string) $order->get_status(), ['completed', 'processing'], true)))) {
            throw new HoldException('order_unsettled', \PESM\Support\I18n::text('وضعیت مالی سفارش برای جابه‌جایی معتبر نیست.'), 409);
        }
    }

    private static function commit(object $wpdb): void
    {
        if ($wpdb->query('COMMIT') === false) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('ثبت انتقال نهایی نشد.'), 503);
        }
    }

    /** @return array<string,int|bool|string> */
    private static function response(int $ticketId, int $oldSeatId, int $newSeatId, int $qrVersion, bool $replay): array
    {
        return [
            'ticket_id' => $ticketId, 'old_seat_id' => $oldSeatId, 'new_seat_id' => $newSeatId,
            'qr_version' => $qrVersion, 'idempotent_replay' => $replay,
            'message' => \PESM\Support\I18n::text('صندلی تغییر کرد. QR قدیمی باطل است؛ بلیت جدید را از پنل مشتری دریافت کنید.'),
        ];
    }
}
