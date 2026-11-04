<?php

declare(strict_types=1);

namespace PESM\Domain\Checkout;

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Booking\SeatingRules;
use PESM\Domain\Ticket\TicketService;
use PESM\Domain\GeneralAdmission\FreeAdmissionService;
use PESM\Domain\Pricing\DiscountService;
use Throwable;

/**
 * Creates an HPOS-safe WooCommerce order. Inventory has one owner: the session
 * seat row. Payment is NOT a SQL transaction; unresolved cases remain pending
 * review and are never silently marked sold.
 */
final class CheckoutService
{
    /** @param array<string,mixed> $buyer @return array<string,mixed> */
    public function prepare(int $sessionId, string $token, array $buyer): array
    {
        global $wpdb;
        $mode = $wpdb->get_var($wpdb->prepare(
            "SELECT mode FROM {$wpdb->prefix}pesm_sessions WHERE id=%d", $sessionId
        ));
        if ($mode === 'general') {
            if (trim((string) ($buyer['coupon_code'] ?? '')) !== '') {
                throw new HoldException('coupon_unsupported', \PESM\Support\I18n::text('کد تخفیف برای بلیت بدون شماره صندلی هنوز فعال نیست.'), 409);
            }
            return (new FreeAdmissionService())->prepare($sessionId, $token, $buyer);
        }
        if ((int) get_option('pesm_schema_version', 0) < 3) {
            throw new HoldException('schema_not_ready', \PESM\Support\I18n::text('پایگاه داده نیازمند ارتقا است.'), 503);
        }
        if (!function_exists('wc_create_order') || !function_exists('wc_get_order')) {
            throw new HoldException('woocommerce_required', \PESM\Support\I18n::text('برای صدور سفارش باید ووکامرس فعال باشد.'), 503);
        }
        if ($sessionId < 1 || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new HoldException('invalid_token', \PESM\Support\I18n::text('شناسه رزرو نامعتبر است.'), 403);
        }
        $firstName = CheckoutPolicy::normalizeName((string) ($buyer['first_name'] ?? ''));
        $lastName = CheckoutPolicy::normalizeName((string) ($buyer['last_name'] ?? ''));
        $phone = CheckoutPolicy::normalizePhone((string) ($buyer['phone'] ?? ''));
        $email = trim((string) ($buyer['email'] ?? ''));
        if ($email !== '' && !is_email($email)) {
            throw new HoldException('invalid_email', \PESM\Support\I18n::text('نشانی ایمیل معتبر نیست.'), 422);
        }

        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $now = '';
        $hold = null;
        $session = null;
        $seatIds = [];
        $discountQuote = null;
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('پایگاه داده در دسترس نیست.'), 503);
        }
        try {
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT s.id,s.event_id,s.mode,s.unit_price_minor,s.require_adjacent,s.avoid_single_gap,s.starts_at,s.sale_starts_at,s.sale_ends_at,s.status,e.status AS event_status FROM {$p}sessions s JOIN {$p}events e ON e.id=s.event_id WHERE s.id=%d FOR UPDATE", $sessionId
            ));
            if (!$session) { throw new HoldException('invalid_session', \PESM\Support\I18n::text('جلسه یافت نشد.'), 404); }
            if ((string) $session->mode !== 'seated') {
                throw new HoldException('checkout_unsupported', \PESM\Support\I18n::text('پرداخت و صدور بلیت فروش آزاد هنوز فعال نیست.'), 409);
            }
            // Timestamp must be fetched AFTER acquiring the session lock;
            // waiting on a concurrent checkout must not extend an expired hold.
            $now = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
            if (!$now) { throw new HoldException('db_unavailable', \PESM\Support\I18n::text('ساعت پایگاه داده در دسترس نیست.'), 503); }
            if ($session->status !== 'published' || $session->event_status !== 'published' ||
                $session->sale_starts_at > $now || $session->sale_ends_at <= $now || $session->starts_at <= $now) {
                throw new HoldException('event_closed', \PESM\Support\I18n::text('فروش این جلسه فعال نیست.'), 409);
            }
            $hold = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}holds WHERE session_id=%d AND token_hash=%s FOR UPDATE",
                $sessionId, hash('sha256', $token)
            ));
            if (!$hold) { throw new HoldException('permission_denied', \PESM\Support\I18n::text('اجازه استفاده از این رزرو وجود ندارد.'), 403); }
            if (in_array($hold->status, ['payment_pending', 'sold'], true) && (int) $hold->order_id > 0) {
                if ($hold->status === 'payment_pending' && $hold->expires_at <= $now) {
                    throw new HoldException('payment_deadline_passed', \PESM\Support\I18n::text('مهلت پرداخت پایان یافته است؛ از پرداخت این سفارش خودداری کنید.'), 410);
                }
                $wpdb->query('COMMIT');
                return $this->existingOrder((int) $hold->order_id, (int) $hold->id, $token);
            }
            if ($hold->status !== 'active' || $hold->expires_at <= $now) {
                throw new HoldException('hold_expired', \PESM\Support\I18n::text('رزرو موقت منقضی شده است.'), 410);
            }
            $seatIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT seat_id FROM {$p}hold_items WHERE hold_id=%d ORDER BY seat_id", (int) $hold->id
            )));
            if (!$seatIds || count($seatIds) > 20) {
                throw new HoldException('invalid_selection', \PESM\Support\I18n::text('صندلی‌های انتخاب‌شده نامعتبر هستند.'), 422);
            }
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT seat_id,status,hold_id FROM {$p}session_seats WHERE session_id=%d AND hold_id=%d ORDER BY seat_id FOR UPDATE",
                $sessionId, (int) $hold->id
            ));
            if (count($rows) !== count($seatIds)) {
                throw new HoldException('seat_unavailable', \PESM\Support\I18n::text('صندلی انتخاب‌شده دیگر در اختیار شما نیست.'), 409);
            }
            foreach ($rows as $row) {
                if ($row->status !== 'held') { throw new HoldException('seat_unavailable', \PESM\Support\I18n::text('صندلی قابل ثبت نیست.'), 409); }
            }
            // Repeat configurable seating constraints before converting the hold
            // to a payment lease; do not rely solely on the browser or initial hold.
            if ((int) ($session->require_adjacent ?? 0) || (int) ($session->avoid_single_gap ?? 0)) {
                $snapshot = $wpdb->get_results($wpdb->prepare(
                    "SELECT inv.seat_id,inv.status,inv.expires_at,inv.hold_id,seat.row_label,seat.zone_label,seat.pos_x,seat.pos_y
                     FROM {$p}session_seats inv JOIN {$p}seats seat ON seat.id=inv.seat_id
                     WHERE inv.session_id=%d ORDER BY inv.seat_id FOR UPDATE", $sessionId
                ));
                if (!is_array($snapshot) || !$snapshot) {
                    throw new HoldException('db_unavailable', \PESM\Support\I18n::text('بررسی نهایی چیدمان ممکن نشد.'), 503);
                }
                SeatingRules::validate(SeatingRules::fromObjects($snapshot), $seatIds, $now,
                    (bool) $session->require_adjacent, (bool) $session->avoid_single_gap, (int) $hold->id);
            }
            // Validate the complete person-to-seat assignment while holding inventory locks.
            $attendeeSnapshot = AttendeeManifest::seats(
                $buyer['attendees'] ?? null, $seatIds, $firstName, $lastName
            );
            $price = (int) $session->unit_price_minor;
            if (!CheckoutPolicy::payable($price, count($seatIds), $now, (string) $hold->expires_at)) {
                throw new HoldException('invalid_price', \PESM\Support\I18n::text('قیمت یا مهلت رزرو معتبر نیست.'), 409);
            }
            if ($price > 0 && $email === '') {
                throw new HoldException('email_required', \PESM\Support\I18n::text('برای پیگیری سفارش پولی، ایمیل لازم است.'), 422);
            }
            // Tax-aware pricing / invoice reconciliation needs a separate integration test.
            if ($price > 0 && get_option('woocommerce_calc_taxes', 'no') === 'yes') {
                throw new HoldException('tax_configuration_unsupported', \PESM\Support\I18n::text('برای خرید پولی با مالیات فعال، اتصال مالیاتی هنوز تأیید نشده است.'), 503);
            }
            // Serialize checkouts for the SAME phone across every session of an event.
            // Expired pending orders are excluded by database UTC, no cron dependency.
            $phoneHash = hash_hmac('sha256', $phone, wp_salt('auth'));
            $phoneLimit = max(1, min(200, (int) get_option('pesm_phone_event_limit', 20)));
            if ($wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$p}phone_locks (event_id,phone_hash) VALUES (%d,%s)",
                (int) $session->event_id, $phoneHash
            )) === false) {
                throw new HoldException('db_unavailable', \PESM\Support\I18n::text('کنترل محدودیت خرید ممکن نشد.'), 503);
            }
            $wpdb->get_var($wpdb->prepare(
                "SELECT event_id FROM {$p}phone_locks WHERE event_id=%d AND phone_hash=%s FOR UPDATE",
                (int) $session->event_id, $phoneHash
            ));
            $usage = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}hold_items hi JOIN {$p}holds h ON hi.hold_id=h.id
                 JOIN {$p}sessions s ON s.id=h.session_id
                 WHERE s.event_id=%d AND h.phone_hash=%s
                   AND (h.status='sold' OR (h.status IN ('checkout_creating','payment_pending') AND h.expires_at>UTC_TIMESTAMP()))",
                (int) $session->event_id, $phoneHash
            ));
            // General-admission tickets count toward the same per-event phone limit.
            // Never count active preview holds, only committed/pending orders.
            if ((int) get_option('pesm_schema_version', 0) >= 10) {
                $usage += (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(ch.quantity),0) FROM {$p}capacity_holds ch
                     JOIN {$p}sessions cs ON cs.id=ch.session_id
                     WHERE cs.event_id=%d AND ch.phone_hash=%s AND
                     (ch.status='issued' OR
                     (ch.status IN ('checkout_creating','payment_pending') AND ch.expires_at>UTC_TIMESTAMP()))",
                    (int) $session->event_id, $phoneHash
                ));
            }
            if ((int) get_option('pesm_schema_version', 0) >= 19) {
                $usage += (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(l.admission_count),0) FROM {$p}allocation_lines l
                     JOIN {$p}allocation_holds h ON h.id=l.hold_id JOIN {$p}sessions s ON s.id=h.session_id
                     WHERE s.event_id=%d AND h.phone_hash=%s AND (h.status='sold' OR
                     (h.status IN ('checkout_creating','payment_pending') AND h.expires_at>UTC_TIMESTAMP()))",
                    (int) $session->event_id, $phoneHash
                ));
            }
            if (!CheckoutPolicy::phoneLimitAllows($usage, count($seatIds), $phoneLimit)) {
                throw new HoldException('phone_limit', \PESM\Support\I18n::text('سقف خرید مجاز برای این شماره موبایل در این رویداد پر شده است.'), 409);
            }
            // Coupon quota is claimed while the same inventory transaction owns the hold.
            // Failure rolls back BOTH the coupon claim and the seat payment lease.
            $discountQuote = (new DiscountService())->reserve(
                (int) $session->event_id, $sessionId, (int) $hold->id, $seatIds, $price,
                (string) ($buyer['coupon_code'] ?? ''), $now
            );
            $deadline = gmdate('Y-m-d H:i:s', min(strtotime($now . ' UTC') + 1800, strtotime($session->starts_at . ' UTC')));
            if ($deadline <= $now) { throw new HoldException('event_closed', \PESM\Support\I18n::text('مهلت ثبت سفارش پایان یافته است.'), 409); }
            if ($wpdb->update($p . 'holds', ['status' => 'checkout_creating', 'phone_hash' => $phoneHash, 'expires_at' => $deadline], ['id' => (int) $hold->id]) !== 1) {
                throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت مرحله سفارش ممکن نشد.'), 503);
            }
            foreach ($seatIds as $seatId) {
                if ($wpdb->update($p . 'session_seats', [
                    'status' => 'payment_pending', 'expires_at' => $deadline, 'updated_at' => $now,
                ], ['session_id' => $sessionId, 'seat_id' => $seatId, 'hold_id' => (int) $hold->id]) !== 1) {
                    throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت مرحله پرداخت صندلی ناموفق بود.'), 503);
                }
            }
            if ($wpdb->query('COMMIT') === false) { throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت تراکنش ممکن نشد.'), 503); }
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($e instanceof HoldException) { throw $e; }
            throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت مرحله سفارش ناموفق بود.'), 503);
        }

        // WooCommerce writes happen outside the inventory SQL transaction.
        // Crashes leave a checkout_creating lease that requires reconciliation.
        try {
            $product = $this->product();
            $order = wc_create_order(['created_via' => 'pesm']);
            if (is_wp_error($order)) { throw new \RuntimeException('Order creation failed.'); }
            $order->set_address([
                'first_name' => $firstName, 'last_name' => $lastName, 'phone' => $phone, 'email' => $email,
            ], 'billing');
            $order->set_customer_id(get_current_user_id());
            $order->set_currency(get_woocommerce_currency());
            $order->update_meta_data('_pesm_is_ticket_order', 'yes');
            $order->update_meta_data('_pesm_hold_id', (int) $hold->id);
            $order->update_meta_data('_pesm_session_id', $sessionId);
            $order->update_meta_data('_pesm_attendees_v1', $attendeeSnapshot);
            // Keep the checkout agreement independent of later session-price,
            // shop-currency and currency-decimal changes.
            $expectedMinor = $discountQuote
                ? $discountQuote['payable_minor']
                : (int) $session->unit_price_minor * count($seatIds);
            $order->update_meta_data('_pesm_unit_price_minor', (int) $session->unit_price_minor);
            $order->update_meta_data('_pesm_total_minor', $expectedMinor);
            $order->update_meta_data('_pesm_price_decimals', wc_get_price_decimals());
            $order->update_meta_data('_pesm_currency', get_woocommerce_currency());
            $personBySeat = [];
            foreach ($attendeeSnapshot as $person) { $personBySeat[$person['seat_id']] = AttendeeManifest::fullName($person); }
            $unit = CheckoutPolicy::amount((int) $session->unit_price_minor, wc_get_price_decimals());
            if ($discountQuote) {
                $order->update_meta_data('_pesm_discount_rule_id', $discountQuote['rule_id']);
                $order->update_meta_data('_pesm_discount_minor', $discountQuote['discount_minor']);
            }
            foreach ($seatIds as $seatId) {
                $item = new \WC_Order_Item_Product();
                $item->set_product($product);
                $item->set_quantity(1);
                $item->set_subtotal($unit);
                $seatDiscount = (int) ($discountQuote['seat_discounts'][$seatId] ?? 0);
                $item->set_total(CheckoutPolicy::amount((int) $session->unit_price_minor - $seatDiscount, wc_get_price_decimals()));
                if ($seatDiscount > 0) {
                    $item->add_meta_data('_pesm_discount_minor', $seatDiscount, true);
                }
                $item->add_meta_data('_pesm_session_id', $sessionId, true);
                $item->add_meta_data('_pesm_seat_id', $seatId, true);
                $item->add_meta_data('_pesm_attendee_name', $personBySeat[$seatId], true);
                $order->add_item($item);
            }
            $order->calculate_totals(false);
            $actualMinor = (int) round((float) $order->get_total() * (10 ** wc_get_price_decimals()));
            if ($actualMinor !== $expectedMinor) {
                throw new \RuntimeException('WooCommerce total differs from server price snapshot.');
            }
            $order->set_status('pending');
            $order->save();
            $orderId = (int) $order->get_id();
            $guestToken = $this->guestToken((int) $hold->id, $token);
            if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('Could not start mapping transaction.'); }
            try {
                $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}sessions WHERE id=%d FOR UPDATE", $sessionId));
                $locked = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}holds WHERE id=%d FOR UPDATE", (int) $hold->id));
                if (!$locked || $locked->status !== 'checkout_creating' || $locked->expires_at <= (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()')) {
                    throw new \RuntimeException('Reservation expired while creating WooCommerce order.');
                }
                if ($wpdb->insert($p . 'order_links', [
                    'hold_id' => (int) $hold->id, 'order_id' => $orderId,
                    'guest_token_hash' => hash('sha256', $guestToken),
                    'status' => 'pending', 'created_at' => $now,
                ]) !== 1 || $wpdb->update($p . 'holds', [
                    'status' => 'payment_pending', 'order_id' => $orderId,
                ], ['id' => (int) $hold->id]) !== 1) {
                    throw new \RuntimeException('Could not bind order to inventory.');
                }
                if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Could not commit order link.'); }
            } catch (Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
            if ($expectedMinor === 0) {
                $result = (new TicketService())->confirmOrder($orderId, true);
                if ($result !== 'issued' && $result !== 'already_issued') {
                    throw new \RuntimeException('Ticket issuance requires reconciliation.');
                }
                $order->update_status('completed', \PESM\Support\I18n::text('رزرو رایگان و بلیت‌ها تأیید شدند.'), true);
                (new DiscountService())->redeemOrder($orderId);
                \PESM\Domain\Notification\NotificationQueue::onTicketsIssued($orderId);
            }
            return $this->response($order, $guestToken, (int) $hold->id);
        } catch (Throwable $e) {
            // Leave the hold for supervised reconciliation; never auto-issue or release an order.
            throw new HoldException('checkout_reconciliation_required', \PESM\Support\I18n::text('تکمیل سفارش ناموفق بود؛ برای بررسی وضعیت پرداخت یا رزرو با پشتیبانی تماس بگیرید.'), 503);
        }
    }

    /** @return array<string,mixed> */
    private function existingOrder(int $orderId, int $holdId, string $token): array
    {
        $order = wc_get_order($orderId);
        if (!$order || $order->get_meta('_pesm_hold_id') != $holdId) {
            throw new HoldException('checkout_reconciliation_required', \PESM\Support\I18n::text('سفارش نیازمند بررسی است.'), 503);
        }
        return $this->response($order, $this->guestToken($holdId, $token), $holdId);
    }

    /** @return array<string,mixed> */
    private function response(object $order, string $guestToken, int $holdId): array
    {
        return [
            'order_id' => (int) $order->get_id(), 'hold_id' => $holdId,
            'order_status' => (string) $order->get_status(),
            'payment_required' => (float) $order->get_total() > 0,
            'payment_url' => (float) $order->get_total() > 0 ? $order->get_checkout_payment_url() : null,
            'guest_access_token' => $guestToken,
            'message' => (float) $order->get_total() > 0
                ? \PESM\Support\I18n::text('سفارش ثبت شد؛ پرداخت باید در درگاه ووکامرس تأیید شود.') : \PESM\Support\I18n::text('سفارش رایگان ثبت و بلیت صادر شد.'),
        ];
    }

    private function guestToken(int $holdId, string $holdToken): string
    {
        return hash_hmac('sha256', "pesm:guest:{$holdId}:{$holdToken}", wp_salt('secure_auth'));
    }

    private function product(): object
    {
        $id = (int) get_option('pesm_wc_product_id', 0);
        $existing = $id ? wc_get_product($id) : false;
        if ($existing && $existing->is_type('simple')) { return $existing; }
        $product = new \WC_Product_Simple();
        $product->set_name(\PESM\Support\I18n::text('بلیت رویداد (محصول فنی)'));
        $product->set_status('publish');
        $product->set_catalog_visibility('hidden');
        $product->set_virtual(true);
        $product->set_sold_individually(false);
        $product->set_regular_price('0');
        $product->set_tax_status('none');
        $product->save();
        update_option('pesm_wc_product_id', $product->get_id(), false);
        return $product;
    }
}
