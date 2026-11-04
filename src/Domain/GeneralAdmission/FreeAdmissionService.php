<?php

declare(strict_types=1);

namespace PESM\Domain\GeneralAdmission;

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Booking\CapacityPolicy;
use PESM\Domain\Checkout\CheckoutPolicy;
use PESM\Domain\Ticket\TicketToken;
use PESM\Support\Audit;
use Throwable;

require_once __DIR__ . '/../Checkout/FinancialSnapshot.php';

/**
 * General-admission order creation (free and opt-in paid preview). Dedicated tables prevent collisions
 * between general-admission hold IDs and numbered-seat hold IDs.
 * Never treats an external payment as part of the inventory SQL transaction.
 */
final class FreeAdmissionService
{
    /** @param array<string,mixed> $buyer @return array<string,mixed> */
    public function prepare(int $sessionId, string $token, array $buyer): array
    {
        if ((int) get_option('pesm_schema_version', 0) < 10) {
            throw new HoldException('schema_not_ready', \PESM\Support\I18n::text('پایگاه داده نیازمند ارتقا است.'), 503);
        }
        if (!function_exists('wc_create_order') || !function_exists('wc_get_order')) {
            throw new HoldException('woocommerce_required', \PESM\Support\I18n::text('ووکامرس باید فعال باشد.'), 503);
        }
        if ($sessionId < 1 || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new HoldException('invalid_token', \PESM\Support\I18n::text('شناسه رزرو نامعتبر است.'), 403);
        }
        $first = CheckoutPolicy::normalizeName((string) ($buyer['first_name'] ?? ''));
        $last = CheckoutPolicy::normalizeName((string) ($buyer['last_name'] ?? ''));
        $phone = CheckoutPolicy::normalizePhone((string) ($buyer['phone'] ?? ''));
        $email = trim((string) ($buyer['email'] ?? ''));
        if ($email !== '' && !is_email($email)) {
            throw new HoldException('invalid_email', \PESM\Support\I18n::text('ایمیل واردشده معتبر نیست.'), 422);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('امکان آغاز تراکنش وجود ندارد.'), 503);
        }
        try {
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT s.id,s.event_id,s.mode,s.unit_price_minor,s.status,s.sale_starts_at,s.sale_ends_at,s.starts_at,
                 e.status AS event_status FROM {$p}sessions s JOIN {$p}events e ON e.id=s.event_id WHERE s.id=%d FOR UPDATE", $sessionId
            ));
            if (!$session) { throw new HoldException('invalid_session', \PESM\Support\I18n::text('جلسه یافت نشد.'), 404); }
            AdmissionPolicy::assertCheckoutAllowed((int) $session->unit_price_minor, (string) $session->mode);
            $now = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
            $wpdb->get_var($wpdb->prepare("SELECT session_id FROM {$p}session_capacity WHERE session_id=%d FOR UPDATE", $sessionId));
            $hold = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}capacity_holds WHERE session_id=%d AND token_hash=%s FOR UPDATE",
                $sessionId, hash('sha256', $token)
            ));
            if (!$hold) { throw new HoldException('permission_denied', \PESM\Support\I18n::text('رزرو متعلق به این دسترسی نیست.'), 403); }
            if ($hold->status === 'issued' && (int) $hold->order_id > 0) {
                if ($wpdb->query('COMMIT') === false) { throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت تراکنش ناموفق بود.'), 503); }
                return $this->existing((int) $hold->order_id, (int) $hold->id, $token);
            }
            if ($hold->status === 'payment_pending' && (int) $hold->order_id > 0) {
                if ($wpdb->query('COMMIT') === false) { throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت تراکنش ناموفق بود.'), 503); }
                // A pending paid order must never be converted to a free ticket.
                if ((int) $session->unit_price_minor === 0) {
                    $result = $this->confirmFree((int) $hold->order_id);
                    if (!in_array($result, ['issued', 'already_issued'], true)) {
                        throw new HoldException('reconciliation_required', \PESM\Support\I18n::text('این سفارش به بررسی نیاز دارد.'), 503);
                    }
                }
                return $this->existing((int) $hold->order_id, (int) $hold->id, $token);
            }
            if ($hold->status === 'checkout_creating') {
                throw new HoldException('checkout_reconciliation_required', \PESM\Support\I18n::text('ثبت سفارش قبلی هنوز نیازمند بررسی است.'), 503);
            }
            AdmissionPolicy::assertHold($hold, $now);
            CapacityPolicy::saleOpen($session, $now);
            $quantity = (int) $hold->quantity;
            // Keep each guest's name independently from the billing contact.
            $attendeeSnapshot = \PESM\Domain\Checkout\AttendeeManifest::general(
                $buyer['attendees'] ?? null, $quantity, $first, $last
            );
            $phoneHash = hash_hmac('sha256', $phone, wp_salt('auth'));
            // Stable lock per event/phone. Count both numbered-seat and GA orders.
            if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$p}phone_locks (event_id,phone_hash) VALUES (%d,%s)",
                (int) $session->event_id, $phoneHash)) === false) {
                throw new HoldException('db_unavailable', \PESM\Support\I18n::text('کنترل سقف خرید ممکن نشد.'), 503);
            }
            $wpdb->get_var($wpdb->prepare("SELECT event_id FROM {$p}phone_locks WHERE event_id=%d AND phone_hash=%s FOR UPDATE",
                (int) $session->event_id, $phoneHash));
            $seatUsed = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}hold_items hi JOIN {$p}holds h ON hi.hold_id=h.id
                 JOIN {$p}sessions s ON s.id=h.session_id WHERE s.event_id=%d AND h.phone_hash=%s
                 AND (h.status='sold' OR (h.status IN ('checkout_creating','payment_pending') AND h.expires_at>UTC_TIMESTAMP()))",
                (int) $session->event_id, $phoneHash
            ));
            $generalUsed = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(h.quantity),0) FROM {$p}capacity_holds h
                 JOIN {$p}sessions s ON s.id=h.session_id WHERE s.event_id=%d AND h.phone_hash=%s
                 AND (h.status='issued' OR (h.status IN ('checkout_creating','payment_pending') AND h.expires_at>UTC_TIMESTAMP()))",
                (int) $session->event_id, $phoneHash
            ));
            $allocatedUsed = 0;
            if ((int) get_option('pesm_schema_version', 0) >= 19) {
                $allocatedUsed = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(l.admission_count),0) FROM {$p}allocation_lines l
                     JOIN {$p}allocation_holds h ON h.id=l.hold_id JOIN {$p}sessions s ON s.id=h.session_id
                     WHERE s.event_id=%d AND h.phone_hash=%s AND (h.status='sold' OR
                     (h.status IN ('checkout_creating','payment_pending') AND h.expires_at>UTC_TIMESTAMP()))",
                    (int) $session->event_id, $phoneHash
                ));
            }
            $limit = max(1, min(200, (int) get_option('pesm_phone_event_limit', 20)));
            if ($seatUsed + $generalUsed + $allocatedUsed + $quantity > $limit) {
                throw new HoldException('phone_limit', \PESM\Support\I18n::text('سقف خرید این شماره موبایل پر شده است.'), 409);
            }
            // Payment pending has a bounded lease, no implicit extension on replay.
            $leaseSeconds = (int) $session->unit_price_minor > 0 ? 1800 : 600;
            $deadline = gmdate('Y-m-d H:i:s', min(strtotime($now . ' UTC') + $leaseSeconds, strtotime((string) $session->starts_at . ' UTC')));
            if ($deadline <= $now) { throw new HoldException('event_closed', \PESM\Support\I18n::text('زمان صدور بلیت پایان یافته است.'), 409); }
            if ($wpdb->query($wpdb->prepare(
                "UPDATE {$p}session_capacity SET held_units=held_units-%d,pending_units=pending_units+%d,version=version+1
                 WHERE session_id=%d AND held_units>=%d", $quantity, $quantity, $sessionId, $quantity
            )) !== 1 || $wpdb->update($p . 'capacity_holds', [
                'status' => 'checkout_creating', 'expires_at' => $deadline, 'phone_hash' => $phoneHash,
            ], ['id' => (int) $hold->id, 'status' => 'active']) !== 1) {
                throw new HoldException('write_failed', \PESM\Support\I18n::text('انتقال رزرو به مرحله صدور بلیت انجام نشد.'), 503);
            }
            if ($wpdb->query('COMMIT') === false) { throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت تراکنش ناموفق بود.'), 503); }
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($e instanceof HoldException) { throw $e; }
            throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت سفارش ظرفیت آزاد ناموفق بود.'), 503);
        }

        try {
            $product = $this->product();
            $order = wc_create_order(['created_via' => 'pesm']);
            if (is_wp_error($order)) { throw new \RuntimeException('Order creation failed.'); }
            $order->set_address(['first_name' => $first, 'last_name' => $last, 'phone' => $phone, 'email' => $email], 'billing');
            $order->set_customer_id(get_current_user_id());
            $order->set_currency(get_woocommerce_currency());
            $order->update_meta_data('_pesm_is_ticket_order', 'yes');
            $order->update_meta_data('_pesm_mode', 'general');
            $order->update_meta_data('_pesm_session_id', $sessionId);
            $order->update_meta_data('_pesm_capacity_hold_id', (int) $hold->id);
            $order->update_meta_data('_pesm_attendees_v1', $attendeeSnapshot);
            $order->update_meta_data('_pesm_unit_price_minor', (int) $session->unit_price_minor);
            $order->update_meta_data('_pesm_total_minor', (int) $session->unit_price_minor * (int) $hold->quantity);
            $order->update_meta_data('_pesm_currency', (string) $order->get_currency());
            $order->update_meta_data('_pesm_price_decimals', wc_get_price_decimals());
            $item = new \WC_Order_Item_Product();
            $item->set_product($product);
            $item->set_quantity((int) $hold->quantity);
            $totalMinor = (int) $session->unit_price_minor * (int) $hold->quantity;
            $unit = CheckoutPolicy::amount((int) $session->unit_price_minor, wc_get_price_decimals());
            $total = CheckoutPolicy::amount($totalMinor, wc_get_price_decimals());
            $item->set_subtotal($total); $item->set_total($total);
            $item->add_meta_data('_pesm_unit_price_minor', (int) $session->unit_price_minor, true);
            $item->add_meta_data('_pesm_session_id', $sessionId, true);
            $item->add_meta_data('_pesm_admission_count', (int) $hold->quantity, true);
            $item->add_meta_data('_pesm_attendee_names', array_map(
                static fn(array $person): string => \PESM\Domain\Checkout\AttendeeManifest::fullName($person),
                $attendeeSnapshot
            ), true);
            $order->add_item($item);
            $order->calculate_totals(false);
            $actualMinor = (int) round((float) $order->get_total() * (10 ** wc_get_price_decimals()));
            if ($actualMinor !== $totalMinor) {
                throw new \RuntimeException('WooCommerce amount differs from immutable server price snapshot.');
            }
            $order->set_status('pending');
            $order->save();
            $orderId = (int) $order->get_id();
            $guest = $this->guestToken((int) $hold->id, $token);
            if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('DB not available.'); }
            try {
                $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}sessions WHERE id=%d FOR UPDATE", $sessionId));
                $wpdb->get_var($wpdb->prepare("SELECT session_id FROM {$p}session_capacity WHERE session_id=%d FOR UPDATE", $sessionId));
                $locked = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$p}capacity_holds WHERE id=%d AND session_id=%d FOR UPDATE", (int) $hold->id, $sessionId
                ));
                $now2 = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
                if (!$locked || $locked->status !== 'checkout_creating' || $locked->expires_at <= $now2) {
                    throw new \RuntimeException('Checkout creation expired.');
                }
                if ($wpdb->insert($p . 'capacity_order_links', [
                    'hold_id' => (int) $hold->id, 'order_id' => $orderId,
                    'guest_token_hash' => hash('sha256', $guest), 'status' => 'pending', 'created_at' => $now2,
                ]) !== 1 || $wpdb->update($p . 'capacity_holds', [
                    'status' => 'payment_pending', 'order_id' => $orderId,
                ], ['id' => (int) $hold->id, 'status' => 'checkout_creating']) !== 1) {
                    throw new \RuntimeException('Could not link order.');
                }
                if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Link commit failed.'); }
            } catch (Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
            if ((int) $session->unit_price_minor === 0) {
                $result = $this->confirmFree($orderId);
                if (!in_array($result, ['issued', 'already_issued'], true)) {
                    throw new \RuntimeException('Fulfillment requires reconciliation.');
                }
                $order->update_status('completed', \PESM\Support\I18n::text('بلیت رایگان ظرفیت آزاد صادر شد.'), true);
                \PESM\Domain\Notification\NotificationQueue::onTicketsIssued($orderId);
            }
            return $this->response($order, $guest, (int) $hold->id);
        } catch (Throwable $e) {
            // Keep pending inventory for supervisor reconciliation, never silently resell.
            throw new HoldException('checkout_reconciliation_required', \PESM\Support\I18n::text('صدور سفارش نیازمند بررسی است؛ رزرو به‌طور خودکار آزاد نمی‌شود.'), 503);
        }
    }

    /** @return 'issued'|'already_issued'|'review_required' */
    public function confirmFree(int $orderId): string
    {
        return $this->confirmOrder($orderId, false);
    }

    /** Only called after WooCommerce's verified payment_complete or manager reconciliation. */
    public function confirmPaid(int $orderId): string
    {
        return $this->confirmOrder($orderId, true);
    }

    private function confirmOrder(int $orderId, bool $paid): string
    {
        if (!function_exists('wc_get_order')) { throw new HoldException('woocommerce_required', \PESM\Support\I18n::text('ووکامرس فعال نیست.'), 503); }
        $order = wc_get_order($orderId);
        if (!$order || $order->get_meta('_pesm_mode') !== 'general' ||
            $order->get_meta('_pesm_is_ticket_order') !== 'yes') {
            throw new HoldException('invalid_order', \PESM\Support\I18n::text('سفارش ظرفیت آزاد معتبر نیست.'), 409);
        }
        if ($paid) {
            // A manually changed order status is not evidence of successful payment.
            if ((float) $order->get_total() <= 0 || !method_exists($order, 'is_paid') ||
                !$order->is_paid() || !method_exists($order, 'get_date_paid') || !$order->get_date_paid()) {
                throw new HoldException('payment_unverified', \PESM\Support\I18n::text('پرداخت این سفارش تأیید نشده است.'), 409);
            }
        } elseif ((float) $order->get_total() !== 0.0) {
            throw new HoldException('invalid_order', \PESM\Support\I18n::text('این سفارش رایگان نیست.'), 409);
        }
        if (in_array((string) $order->get_status(), ['cancelled', 'failed', 'refunded', 'trash'], true)) {
            throw new HoldException('order_inactive', \PESM\Support\I18n::text('سفارش لغوشده یا ناموفق قابل صدور بلیت نیست.'), 409);
        }
        $sessionId = (int) $order->get_meta('_pesm_session_id');
        $holdId = (int) $order->get_meta('_pesm_capacity_hold_id');
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        if ($wpdb->query('START TRANSACTION') === false) { throw new HoldException('db_unavailable', \PESM\Support\I18n::text('پایگاه داده در دسترس نیست.'), 503); }
        try {
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT s.id,s.mode,s.unit_price_minor,s.status,e.status AS event_status FROM {$p}sessions s
                 JOIN {$p}events e ON e.id=s.event_id WHERE s.id=%d FOR UPDATE", $sessionId
            ));
            if (!$session) { throw new HoldException('invalid_session', \PESM\Support\I18n::text('جلسه یافت نشد.'), 404); }
            if ((string) $session->mode !== 'general' || ($paid && (int) $session->unit_price_minor < 0)) {
                throw new HoldException('invalid_session', \PESM\Support\I18n::text('نوع جلسه نامعتبر است.'), 409);
            }
            if (!$paid) { AdmissionPolicy::assertFree((int) $session->unit_price_minor, (string) $session->mode); }
            $inventory = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}session_capacity WHERE session_id=%d FOR UPDATE", $sessionId));
            $hold = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}capacity_holds WHERE id=%d AND session_id=%d FOR UPDATE", $holdId, $sessionId
            ));
            $link = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}capacity_order_links WHERE hold_id=%d AND order_id=%d FOR UPDATE", $holdId, $orderId
            ));
            if (!$inventory || !$hold || !$link || (int) $hold->order_id !== $orderId) {
                throw new HoldException('invalid_order', \PESM\Support\I18n::text('ارتباط رزرو و سفارش معتبر نیست.'), 409);
            }
            \PESM\Domain\Checkout\FinancialSnapshot::uniform($order, (int) $hold->quantity, !$paid);
            if ($hold->status === 'issued' && $link->status === 'issued') {
                if ($wpdb->query('COMMIT') === false) { throw new HoldException('write_failed', \PESM\Support\I18n::text('تراکنش نهایی نشد.'), 503); }
                return 'already_issued';
            }
            $now = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
            if ($session->status === 'cancelled' || $session->event_status === 'cancelled' ||
                $hold->status !== 'payment_pending' || $hold->expires_at <= $now ||
                (int) $inventory->pending_units < (int) $hold->quantity) {
                $wpdb->update($p . 'capacity_order_links', ['status'=>'review_required'], ['id'=>(int) $link->id]);
                $wpdb->query('COMMIT');
                return 'review_required';
            }
            $attendees = \PESM\Domain\Checkout\AttendeeManifest::storedGeneral(
                $order->get_meta('_pesm_attendees_v1'), (int) $hold->quantity,
                (string) $order->get_billing_first_name(), (string) $order->get_billing_last_name()
            );
            for ($i = 1; $i <= (int) $hold->quantity; ++$i) {
                $publicId = bin2hex(random_bytes(16));
                $number = 'P-' . strtoupper(substr($publicId, 0, 16));
                $qr = TicketToken::create($publicId, 1, wp_salt('secure_auth'));
                if ($wpdb->insert($p . 'capacity_tickets', [
                    'public_id'=>$publicId, 'ticket_number'=>$number, 'hold_id'=>$holdId,
                    'admission_index'=>$i, 'session_id'=>$sessionId, 'order_id'=>$orderId,
                    'attendee_name'=>\PESM\Domain\Checkout\AttendeeManifest::fullName($attendees[$i - 1]),
                    'status'=>'active', 'qr_version'=>1,
                    'qr_token_hash'=>hash('sha256', $qr), 'created_at'=>$now,
                ]) !== 1) {
                    throw new HoldException('ticket_write_failed', \PESM\Support\I18n::text('صدور بلیت ظرفیت آزاد ناموفق بود.'), 503);
                }
            }
            $q = (int) $hold->quantity;
            if ($wpdb->query($wpdb->prepare(
                "UPDATE {$p}session_capacity SET pending_units=pending_units-%d,sold_units=sold_units+%d,version=version+1
                 WHERE session_id=%d AND pending_units>=%d", $q, $q, $sessionId, $q
            )) !== 1 || $wpdb->update($p . 'capacity_holds', ['status'=>'issued'],
                ['id'=>$holdId,'status'=>'payment_pending']) !== 1 ||
                $wpdb->update($p . 'capacity_order_links', ['status'=>'issued'],
                ['id'=>(int) $link->id]) !== 1) {
                throw new HoldException('ticket_write_failed', \PESM\Support\I18n::text('ثبت نهایی موجودی ناموفق بود.'), 503);
            }
            Audit::record('general_tickets_issued', 'order', $orderId);
            if ($wpdb->query('COMMIT') === false) { throw new HoldException('ticket_write_failed', \PESM\Support\I18n::text('تراکنش تأیید نشد.'), 503); }
            return 'issued';
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($e instanceof HoldException) { throw $e; }
            throw new HoldException('ticket_write_failed', \PESM\Support\I18n::text('صدور بلیت نیازمند بررسی است.'), 503);
        }
    }

    public function revokeOrder(int $orderId): string
    {
        $order = wc_get_order($orderId);
        if (!$order || $order->get_meta('_pesm_mode') !== 'general') { return 'not_general'; }
        $sessionId = (int) $order->get_meta('_pesm_session_id');
        $holdId = (int) $order->get_meta('_pesm_capacity_hold_id');
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        if ($wpdb->query('START TRANSACTION') === false) { return 'manual_review'; }
        try {
            $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}sessions WHERE id=%d FOR UPDATE", $sessionId));
            $wpdb->get_var($wpdb->prepare("SELECT session_id FROM {$p}session_capacity WHERE session_id=%d FOR UPDATE", $sessionId));
            $hold = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}capacity_holds WHERE id=%d FOR UPDATE", $holdId));
            $link = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}capacity_order_links WHERE hold_id=%d AND order_id=%d FOR UPDATE", $holdId, $orderId
            ));
            if (!$hold || !$link) { throw new \RuntimeException('Missing link'); }
            if ($link->status === 'cancelled') { $wpdb->query('COMMIT'); return 'already_cancelled'; }
            if ($hold->status === 'payment_pending') {
                if ($wpdb->query($wpdb->prepare(
                    "UPDATE {$p}session_capacity SET pending_units=pending_units-%d,version=version+1 WHERE session_id=%d AND pending_units>=%d",
                    (int) $hold->quantity, $sessionId, (int) $hold->quantity
                )) !== 1) { throw new \RuntimeException('Pending inventory changed'); }
            } elseif ($hold->status === 'issued') {
                // Do NOT make sold capacity available on cancellation; gate access is revoked.
                $tickets = $wpdb->get_results($wpdb->prepare(
                    "SELECT id,status FROM {$p}capacity_tickets WHERE hold_id=%d ORDER BY id FOR UPDATE", $holdId
                ));
                foreach ($tickets as $ticket) {
                    if ($ticket->status === 'active' && $wpdb->update($p . 'capacity_tickets',
                        ['status'=>'cancelled'], ['id'=>(int) $ticket->id,'status'=>'active']) !== 1) {
                        throw new \RuntimeException('Ticket revoke failed');
                    }
                }
            } else { throw new \RuntimeException('Unsupported hold state'); }
            if ($wpdb->update($p . 'capacity_holds', ['status'=>'cancelled'], ['id'=>$holdId]) !== 1 ||
                $wpdb->update($p . 'capacity_order_links', ['status'=>'cancelled'], ['id'=>(int) $link->id]) !== 1) {
                throw new \RuntimeException('Revoke failed');
            }
            Audit::record('general_tickets_revoked', 'order', $orderId);
            if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Commit failed'); }
            return 'cancelled';
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); return 'manual_review'; }
    }

    private function product(): object
    {
        $id = (int) get_option('pesm_wc_product_id', 0);
        $product = $id ? wc_get_product($id) : false;
        if ($product && $product->is_type('simple')) { return $product; }
        $product = new \WC_Product_Simple();
        $product->set_name(\PESM\Support\I18n::text('بلیت رویداد (محصول فنی)'));
        $product->set_status('publish'); $product->set_catalog_visibility('hidden');
        $product->set_virtual(true); $product->set_sold_individually(false);
        $product->set_regular_price('0'); $product->set_tax_status('none');
        $product->save();
        update_option('pesm_wc_product_id', $product->get_id(), false);
        return $product;
    }

    private function guestToken(int $holdId, string $token): string
    {
        return hash_hmac('sha256', "pesm:capacity:guest:{$holdId}:{$token}", wp_salt('secure_auth'));
    }

    /** @return array<string,mixed> */
    private function existing(int $orderId, int $holdId, string $token): array
    {
        $order = wc_get_order($orderId);
        if (!$order || $order->get_meta('_pesm_capacity_hold_id') != $holdId) {
            throw new HoldException('reconciliation_required', \PESM\Support\I18n::text('سفارش قبلی نیازمند بررسی است.'), 503);
        }
        // Never force a paid pending order to completed on replay.
        if ($order->get_status() === 'pending' && (float) $order->get_total() === 0.0) {
            $order->update_status('completed', \PESM\Support\I18n::text('بلیت‌های موجود این سفارش رایگان بازیابی شدند.'), true);
        }
        return $this->response($order, $this->guestToken($holdId, $token), $holdId);
    }

    /** @return array<string,mixed> */
    private function response(object $order, string $guest, int $holdId): array
    {
        $paid = (float) $order->get_total() > 0;
        return ['order_id'=>(int) $order->get_id(), 'hold_id'=>$holdId,
            'order_status'=>(string) $order->get_status(), 'payment_required'=>$paid,
            'payment_url'=>$paid ? $order->get_checkout_payment_url() : null,
            'guest_access_token'=>$guest,
            'message'=>$paid ? \PESM\Support\I18n::text('سفارش ثبت شد؛ پس از تأیید پرداخت، بلیت صادر می‌شود.') : \PESM\Support\I18n::text('سفارش رایگان ظرفیت آزاد ثبت شد و بلیت‌ها صادر شدند.')];
    }
}
