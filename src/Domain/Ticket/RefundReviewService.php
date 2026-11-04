<?php

declare(strict_types=1);

namespace PESM\Domain\Ticket;

use PESM\Domain\Booking\HoldException;
use PESM\Security\Capabilities;
use PESM\Support\Audit;
use Throwable;

/**
 * Controlled ticket-level refund reconciliation. NO money is moved by this class.
 * An operator refunds the matching order line via WooCommerce, then reconciles.
 * A pending case invalidates gate entry before a financial refund can be recorded.
 */
final class RefundReviewService
{
    /** @return array<string,mixed> */
    public function request(int $ticketId, string $reason, string $requestKey, int $actorId): array
    {
        $this->authorize($actorId);
        RefundPolicy::requestKey($requestKey);
        $reason = RefundPolicy::reason($reason);
        $this->schema();
        $loc = $this->locate($ticketId, $actorId);
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $keyHash = hash_hmac('sha256', "refund:{$ticketId}:{$requestKey}", wp_salt('auth'));
        $this->begin();
        try {
            // All ticket and gate mutations share the session-first lock discipline.
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT id,starts_at FROM {$p}sessions WHERE id=%d FOR UPDATE", (int)$loc->session_id
            ));
            $now = (string)$wpdb->get_var('SELECT UTC_TIMESTAMP()');
            $hold = $wpdb->get_row($wpdb->prepare(
                "SELECT id,status,order_id FROM {$p}holds WHERE id=%d FOR UPDATE", (int)$loc->hold_id
            ));
            $link = $wpdb->get_row($wpdb->prepare(
                "SELECT id,status FROM {$p}order_links WHERE hold_id=%d AND order_id=%d FOR UPDATE",
                (int)$loc->hold_id, (int)$loc->order_id
            ));
            $prior = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}refund_cases WHERE ticket_id=%d FOR UPDATE", $ticketId
            ));
            if ($prior) {
                if (hash_equals((string)$prior->request_hash, $keyHash)) {
                    if ((string)$prior->reason !== $reason) {
                        throw new HoldException('idempotency_conflict', \PESM\Support\I18n::text('کلید درخواست برای علت استرداد متفاوت استفاده شده است.'), 409);
                    }
                    $this->commit();
                    return self::response($prior, true);
                }
                throw new HoldException('refund_case_exists', \PESM\Support\I18n::text('برای این بلیت پرونده استرداد دیگری وجود دارد.'), 409);
            }
            // Verify the actual WooCommerce financial snapshot before freezing ticket access.
            $order = $this->order((int)$loc->order_id);
            if (!$session || !$now || !$hold || !$link || $hold->status !== 'sold' ||
                $link->status !== 'issued' || (int)$hold->order_id !== (int)$loc->order_id ||
                !$order->is_paid() || $order->get_meta('_pesm_mode') === 'general') {
                throw new HoldException('refund_manual_review', \PESM\Support\I18n::text('سفارش برای استرداد خودکار بلیت مناسب نیست.'), 409);
            }
            $ticketSnapshot = $wpdb->get_row($wpdb->prepare(
                "SELECT id,seat_id,status,order_id,hold_id,session_id FROM {$p}tickets WHERE id=%d", $ticketId
            ));
            $inventory = $ticketSnapshot ? $wpdb->get_row($wpdb->prepare(
                "SELECT status,hold_id FROM {$p}session_seats WHERE session_id=%d AND seat_id=%d FOR UPDATE",
                (int)$loc->session_id, (int)$ticketSnapshot->seat_id
            )) : null;
            $ticket = $wpdb->get_row($wpdb->prepare(
                "SELECT id,seat_id,status,order_id,hold_id,session_id FROM {$p}tickets WHERE id=%d FOR UPDATE", $ticketId
            ));
            $checked = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$p}checkins WHERE ticket_id=%d LIMIT 1", $ticketId
            ));
            if (!$ticket || !$ticketSnapshot || (int)$ticket->seat_id !== (int)$ticketSnapshot->seat_id ||
                !$inventory || (int)$ticket->order_id !== (int)$loc->order_id ||
                (int)$ticket->hold_id !== (int)$loc->hold_id || (int)$ticket->session_id !== (int)$loc->session_id ||
                $inventory->status !== 'sold' || (int)$inventory->hold_id !== (int)$loc->hold_id) {
                throw new HoldException('seat_conflict', \PESM\Support\I18n::text('وضعیت تخصیص جایگاه معتبر نیست.'), 409);
            }
            RefundPolicy::ticketEligible((string)$ticket->status, (bool)$checked, (string)$session->starts_at, $now);
            [$orderItemId, $amount] = $this->itemAmount($order, (int)$loc->session_id, (int)$ticket->seat_id);
            if ($wpdb->insert($p . 'refund_cases', [
                'ticket_id'=>$ticketId, 'order_id'=>(int)$loc->order_id,
                'request_hash'=>$keyHash, 'order_item_id'=>$orderItemId,
                'amount_minor'=>$amount, 'currency'=>(string)$order->get_currency(),
                'status'=>'awaiting_woocommerce', 'reason'=>$reason,
                'actor_user_id'=>$actorId, 'created_at'=>$now, 'updated_at'=>$now,
            ]) !== 1) {
                throw new HoldException('refund_write_failed', \PESM\Support\I18n::text('ثبت درخواست استرداد موفق نبود.'), 503);
            }
            $id = (int)$wpdb->insert_id;
            if ($id < 1 || $wpdb->update($p . 'tickets', ['status'=>'refund_pending'],
                ['id'=>$ticketId, 'status'=>'active']) !== 1) {
                throw new HoldException('refund_write_failed', \PESM\Support\I18n::text('مسدودسازی ورود بلیت ناموفق بود.'), 503);
            }
            Audit::record('refund_requested', 'ticket', $ticketId, $actorId);
            $this->commit();
            return ['case_id'=>$id,'ticket_id'=>$ticketId,'order_id'=>(int)$loc->order_id,
                'order_item_id'=>$orderItemId, 'amount_minor'=>$amount, 'currency'=>(string)$order->get_currency(),
                'status'=>'awaiting_woocommerce','replayed'=>false,
                'message'=>\PESM\Support\I18n::text('ورود این بلیت متوقف شد. استرداد سطر سفارش را در ووکامرس ثبت کنید و سپس تطبیق را انجام دهید.')];
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            $this->raise($e, 'refund_write_failed', \PESM\Support\I18n::text('ثبت درخواست استرداد نیازمند بررسی است.'));
        }
    }

    /** @return array<string,mixed> */
    public function reconcile(int $ticketId, int $actorId): array
    {
        $this->authorize($actorId);
        $this->schema();
        $loc = $this->locate($ticketId, $actorId);
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $this->begin();
        try {
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT id,starts_at FROM {$p}sessions WHERE id=%d FOR UPDATE", (int)$loc->session_id
            ));
            $now = (string)$wpdb->get_var('SELECT UTC_TIMESTAMP()');
            $hold = $wpdb->get_row($wpdb->prepare(
                "SELECT id,status,order_id FROM {$p}holds WHERE id=%d FOR UPDATE", (int)$loc->hold_id
            ));
            $wpdb->get_row($wpdb->prepare(
                "SELECT id,status FROM {$p}order_links WHERE hold_id=%d AND order_id=%d FOR UPDATE",
                (int)$loc->hold_id, (int)$loc->order_id
            ));
            $case = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}refund_cases WHERE ticket_id=%d FOR UPDATE", $ticketId
            ));
            if (!$case || !$session || !$now || !$hold || (int)$case->order_id !== (int)$loc->order_id) {
                throw new HoldException('refund_case_missing', \PESM\Support\I18n::text('پرونده استرداد یافت نشد.'), 404);
            }
            if ($case->status === 'finalized') { $this->commit(); return self::response($case, true); }
            if ($case->status !== 'awaiting_woocommerce') {
                throw new HoldException('refund_manual_review', \PESM\Support\I18n::text('وضعیت پرونده نیازمند بررسی دستی است.'), 409);
            }
            $snapshot = $wpdb->get_row($wpdb->prepare(
                "SELECT id,seat_id,status,hold_id,session_id,order_id FROM {$p}tickets WHERE id=%d", $ticketId
            ));
            $inv = $snapshot ? $wpdb->get_row($wpdb->prepare(
                "SELECT status,hold_id FROM {$p}session_seats WHERE session_id=%d AND seat_id=%d FOR UPDATE",
                (int)$loc->session_id, (int)$snapshot->seat_id
            )) : null;
            $ticket = $wpdb->get_row($wpdb->prepare(
                "SELECT id,seat_id,status,hold_id,session_id,order_id FROM {$p}tickets WHERE id=%d FOR UPDATE", $ticketId
            ));
            if (!$ticket || !$snapshot || (int)$ticket->seat_id !== (int)$snapshot->seat_id || $ticket->status !== 'refund_pending' || !$inv || $inv->status !== 'sold' ||
                (int)$inv->hold_id !== (int)$loc->hold_id || (int)$ticket->hold_id !== (int)$loc->hold_id) {
                throw new HoldException('refund_manual_review', \PESM\Support\I18n::text('موجودی و وضعیت بلیت برای استرداد مغایرت دارند.'), 409);
            }
            if ($wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$p}checkins WHERE ticket_id=%d LIMIT 1", $ticketId))) {
                throw new HoldException('refund_manual_review', \PESM\Support\I18n::text('برای این بلیت ورود ثبت شده است.'), 409);
            }
            $order = $this->order((int)$loc->order_id);
            $refundId = $this->confirmedRefundId($order, (int)$case->order_item_id,
                (int)$case->amount_minor, (string)$case->currency);
            if (!$refundId) {
                throw new HoldException('refund_not_recorded', \PESM\Support\I18n::text('استرداد دقیق این ردیف در ووکامرس ثبت نشده است.'), 409);
            }
            if ($wpdb->update($p . 'tickets', ['status'=>'refunded'],
                ['id'=>$ticketId, 'status'=>'refund_pending']) !== 1) {
                throw new HoldException('refund_write_failed', \PESM\Support\I18n::text('ابطال بلیت ثبت نشد.'), 503);
            }
            // No resale after session start. Even before start, release ONLY verified same-owner inventory.
            $released = false;
            if ((string)$session->starts_at > $now) {
                if ($wpdb->update($p . 'session_seats', [
                    'status'=>'available','hold_id'=>null,'expires_at'=>null,'updated_at'=>$now,
                ], ['session_id'=>(int)$loc->session_id,'seat_id'=>(int)$ticket->seat_id,
                    'status'=>'sold','hold_id'=>(int)$loc->hold_id]) !== 1 ||
                    $wpdb->delete($p . 'hold_items', [
                        'hold_id'=>(int)$loc->hold_id,'seat_id'=>(int)$ticket->seat_id,
                    ]) !== 1) {
                    throw new HoldException('refund_write_failed', \PESM\Support\I18n::text('آزادسازی صندلی کامل نشد.'), 503);
                }
                $released = true;
            }
            if ($wpdb->update($p . 'refund_cases', [
                'status'=>'finalized','wc_refund_id'=>$refundId, 'updated_at'=>$now,
            ], ['id'=>(int)$case->id,'status'=>'awaiting_woocommerce']) !== 1) {
                throw new HoldException('refund_write_failed', \PESM\Support\I18n::text('بستن پرونده استرداد ناموفق بود.'), 503);
            }
            Audit::record('refund_reconciled', 'ticket', $ticketId, $actorId);
            $this->commit();
            return ['case_id'=>(int)$case->id,'ticket_id'=>$ticketId,'status'=>'finalized',
                'wc_refund_id'=>$refundId,'seat_released'=>$released,'replayed'=>false];
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            $this->raise($e, 'refund_write_failed', \PESM\Support\I18n::text('تطبیق استرداد به بررسی نیاز دارد.'));
        }
    }

    /** @return array{int,int} */
    private function itemAmount(object $order, int $sessionId, int $seatId): array
    {
        $decimals = (int)wc_get_price_decimals();
        $match = null;
        foreach ($order->get_items('line_item') as $itemId => $item) {
            if ((int)$item->get_meta('_pesm_session_id') !== $sessionId ||
                (int)$item->get_meta('_pesm_seat_id') !== $seatId) { continue; }
            if ($match !== null) {
                throw new HoldException('refund_manual_review', \PESM\Support\I18n::text('سطر سفارش تکراری است.'), 409);
            }
            $match = [(int)$itemId, RefundPolicy::ticketAmount((string)$item->get_total(),
                (string)$item->get_total_tax(), (int)$item->get_quantity(), $decimals,
                (int)round(abs((float)$order->get_total_refunded_for_item((int)$itemId)) * (10 ** $decimals)))];
        }
        if ($match === null) {
            throw new HoldException('refund_manual_review', \PESM\Support\I18n::text('سطر بلیت در سفارش ووکامرس پیدا نشد.'), 409);
        }
        return $match;
    }

    private function confirmedRefundId(object $order, int $orderItemId, int $amountMinor, string $currency): int
    {
        if ((string)$order->get_currency() !== $currency) { return 0; }
        $decimals = (int)wc_get_price_decimals();
        foreach ($order->get_refunds() as $refund) {
            $lines = [];
            foreach ($refund->get_items('line_item') as $item) {
                $lines[] = [
                    'item_id'=>(int)$item->get_meta('_refunded_item_id'),
                    'quantity'=>abs((int)$item->get_quantity()),
                    'total_minor'=>(int)round(abs((float)$item->get_total()) * (10 ** $decimals)),
                ];
            }
            if (RefundPolicy::matchingRefund($lines, $orderItemId, $amountMinor)) {
                return (int)$refund->get_id();
            }
        }
        return 0;
    }

    private function locate(int $ticketId, int $actorId): object
    {
        if ($ticketId < 1) {
            throw new HoldException('invalid_ticket', \PESM\Support\I18n::text('شناسه بلیت معتبر نیست.'), 422);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $loc = $wpdb->get_row($wpdb->prepare(
            "SELECT t.session_id,t.hold_id,t.order_id,e.center_id FROM {$p}tickets t
             JOIN {$p}sessions s ON s.id=t.session_id JOIN {$p}events e ON e.id=s.event_id
             WHERE t.id=%d", $ticketId
        ));
        if (!$loc || !Capabilities::centerAllowed((int)$loc->center_id, $actorId)) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('این بلیت در محدوده مرکز شما نیست.'), 403);
        }
        return $loc;
    }

    private function order(int $orderId): object
    {
        if (!function_exists('wc_get_order')) {
            throw new HoldException('woocommerce_required', \PESM\Support\I18n::text('ووکامرس فعال نیست.'), 503);
        }
        $order = wc_get_order($orderId);
        if (!$order || $order->get_meta('_pesm_is_ticket_order') !== 'yes' ||
            $order->get_meta('_pesm_mode') === 'general') {
            throw new HoldException('invalid_order', \PESM\Support\I18n::text('سفارش بلیت صندلی‌دار یافت نشد.'), 409);
        }
        return $order;
    }

    private function authorize(int $actorId): void
    {
        if ($actorId < 1 || !user_can($actorId, 'pesm_manage_refunds')) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('مجوز مدیریت استرداد را ندارید.'), 403);
        }
    }

    private function schema(): void
    {
        if ((int)get_option('pesm_schema_version', 0) < 15 || get_option('pesm_schema_error')) {
            throw new HoldException('schema_not_ready', \PESM\Support\I18n::text('ساختار پایگاه داده استرداد آماده نیست.'), 503);
        }
    }

    private function begin(): void
    {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('شروع تراکنش ممکن نشد.'), 503);
        }
    }

    private function commit(): void
    {
        global $wpdb;
        if ($wpdb->query('COMMIT') === false) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('ثبت تراکنش ناموفق بود.'), 503);
        }
    }

    private function raise(Throwable $error, string $code, string $message): never
    {
        if ($error instanceof HoldException) { throw $error; }
        throw new HoldException($code, $message, 503);
    }

    /** @return array<string,mixed> */
    private static function response(object $case, bool $replayed): array
    {
        return [
            'case_id'=>(int)$case->id,'ticket_id'=>(int)$case->ticket_id,
            'order_id'=>(int)$case->order_id,'order_item_id'=>(int)$case->order_item_id,
            'amount_minor'=>(int)$case->amount_minor,'currency'=>(string)$case->currency,
            'status'=>(string)$case->status,'wc_refund_id'=>(int)$case->wc_refund_id,
            'replayed'=>$replayed,
        ];
    }
}
