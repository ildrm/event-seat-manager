<?php

declare(strict_types=1);

namespace PESM\Domain\Pricing;

use PESM\Domain\Booking\HoldException;
use PESM\Security\Capabilities;
use PESM\Support\Audit;

/** Limited-use coupons are claimed inside the same InnoDB transaction as inventory checkout. */
final class DiscountService
{
    /** @return array{base_minor:int,discount_minor:int,payable_minor:int,seat_discounts:array<int,int>,rule_id:int}|null
     * Caller must have locked session/hold and begun a transaction.
     * @param list<int> $seatIds
     */
    public function reserve(int $eventId, int $sessionId, int $holdId, array $seatIds, int $unitMinor,
        string $code, string $now): ?array
    {
        if (trim($code) === '') { return null; }
        // Explicit staging opt-in; do not activate new financial rules on upgrades.
        if (!function_exists('get_option') || get_option('pesm_discount_preview_enabled', 'no') !== 'yes') {
            throw new HoldException('coupon_disabled', \PESM\Support\I18n::text('کوپن‌های هدفمند هنوز در تنظیمات آزمایشی فعال نشده‌اند.'), 503);
        }
        if ((int) get_option('pesm_schema_version', 0) < 16) {
            throw new HoldException('schema_not_ready', \PESM\Support\I18n::text('پایگاه داده تخفیف‌ها نیازمند ارتقا است.'), 503);
        }
        $code = DiscountPolicy::code($code);
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        // A hold may use at most one rule. Conflicting retries must never consume another coupon.
        $claim = $wpdb->get_row($wpdb->prepare(
            "SELECT rule_id,amount_minor,status FROM {$p}discount_claims WHERE hold_id=%d FOR UPDATE", $holdId
        ));
        $rule = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$p}discount_rules WHERE event_id=%d AND code=%s FOR UPDATE", $eventId, $code
        ), ARRAY_A);
        if (!$rule || (int) $rule['session_id'] !== 0 && (int) $rule['session_id'] !== $sessionId) {
            throw new HoldException('coupon_unavailable', \PESM\Support\I18n::text('کد تخفیف برای این جلسه قابل استفاده نیست.'), 409);
        }
        if ($claim && ((int) $claim->rule_id !== (int) $rule['id'] || $claim->status !== 'reserved')) {
            throw new HoldException('coupon_conflict', \PESM\Support\I18n::text('برای این رزرو کد تخفیف دیگری استفاده شده است.'), 409);
        }
        $ids = array_values(array_unique(array_map('intval', $seatIds)));
        sort($ids, SORT_NUMERIC);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,zone_label FROM {$p}seats WHERE id IN ({$placeholders}) ORDER BY id", ...$ids
        ));
        if (!is_array($rows) || count($rows) !== count($ids)) {
            throw new HoldException('invalid_selection', \PESM\Support\I18n::text('اطلاعات جایگاه‌ها ناقص است.'), 409);
        }
        $zones = [];
        foreach ($rows as $row) { $zones[(int) $row->id] = (string) $row->zone_label; }
        $quote = DiscountPolicy::quote($rule, $zones, $unitMinor, $now);
        if ($claim) {
            if ((int) $claim->amount_minor !== $quote['discount_minor']) {
                throw new HoldException('coupon_conflict', \PESM\Support\I18n::text('مبلغ رزرو تخفیف تغییر کرده است.'), 409);
            }
        } else {
            if ((int) $rule['max_uses'] > 0 &&
                (int) $rule['reserved_uses'] + (int) $rule['redeemed_uses'] >= (int) $rule['max_uses']) {
                throw new HoldException('coupon_exhausted', \PESM\Support\I18n::text('سهمیه کد تخفیف به پایان رسیده است.'), 409);
            }
            if ($wpdb->insert($p . 'discount_claims', [
                'rule_id' => (int) $rule['id'], 'hold_id' => $holdId,
                'amount_minor' => $quote['discount_minor'], 'status' => 'reserved',
                'order_id' => 0, 'created_at' => $now,
            ]) !== 1 || $wpdb->query($wpdb->prepare(
                "UPDATE {$p}discount_rules SET reserved_uses=reserved_uses+1 WHERE id=%d", (int) $rule['id']
            )) !== 1) {
                throw new HoldException('coupon_write_failed', \PESM\Support\I18n::text('ثبت سهمیه تخفیف ممکن نشد.'), 503);
            }
        }
        return $quote + ['rule_id' => (int) $rule['id']];
    }

    /** Non-binding coupon estimate for the holder; never claims quota or exposes other buyers. */
    public function preview(int $sessionId, string $holdToken, string $couponCode): array
    {
        if ($sessionId < 1 || !preg_match('/^[a-f0-9]{64}$/D', $holdToken)) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('شناسه نگهداری معتبر نیست.'), 403);
        }
        if (!function_exists('get_option') || get_option('pesm_discount_preview_enabled', 'no') !== 'yes' ||
            (int) get_option('pesm_schema_version', 0) < 16) {
            throw new HoldException('coupon_disabled', \PESM\Support\I18n::text('پیش‌نمایش تخفیف فعال نیست.'), 503);
        }
        $code = DiscountPolicy::code($couponCode);
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT id,event_id,mode,unit_price_minor FROM {$p}sessions WHERE id=%d", $sessionId
        ));
        if (!$session || (string) $session->mode !== 'seated') {
            throw new HoldException('invalid_session', \PESM\Support\I18n::text('این نوع جلسه از کوپن پشتیبانی نمی‌کند.'), 409);
        }
        $hold = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$p}holds WHERE session_id=%d AND token_hash=%s AND status='active' AND expires_at>UTC_TIMESTAMP()",
            $sessionId, hash('sha256', $holdToken)
        ));
        if (!$hold) { throw new HoldException('hold_expired', \PESM\Support\I18n::text('رزرو فعال برای محاسبه تخفیف یافت نشد.'), 410); }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT inv.seat_id AS id,seat.zone_label FROM {$p}hold_items hi
             JOIN {$p}session_seats inv ON inv.session_id=%d AND inv.seat_id=hi.seat_id
             JOIN {$p}seats seat ON seat.id=hi.seat_id
             WHERE hi.hold_id=%d AND inv.status='held' AND inv.hold_id=%d ORDER BY inv.seat_id",
            $sessionId, (int)$hold->id, (int)$hold->id
        ));
        if (!is_array($rows) || !$rows || count($rows) > 20) {
            throw new HoldException('seat_unavailable', \PESM\Support\I18n::text('صندلی‌های رزرو نیازمند بازبینی هستند.'), 409);
        }
        $rule = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$p}discount_rules WHERE event_id=%d AND code=%s",
            (int)$session->event_id, $code
        ), ARRAY_A);
        if (!$rule || ((int)$rule['session_id'] !== 0 && (int)$rule['session_id'] !== $sessionId)) {
            throw new HoldException('coupon_unavailable', \PESM\Support\I18n::text('کد تخفیف برای این جلسه معتبر نیست.'), 409);
        }
        if ((int)$rule['max_uses'] > 0 && (int)$rule['reserved_uses']+(int)$rule['redeemed_uses'] >= (int)$rule['max_uses']) {
            throw new HoldException('coupon_exhausted', \PESM\Support\I18n::text('سهمیه تخفیف به پایان رسیده است.'), 409);
        }
        $zones=[];
        foreach ($rows as $row) { $zones[(int)$row->id] = (string)$row->zone_label; }
        $now = (string)$wpdb->get_var('SELECT UTC_TIMESTAMP()');
        if (!$now) { throw new HoldException('db_unavailable', \PESM\Support\I18n::text('زمان معتبر پایگاه داده در دسترس نیست.'), 503); }
        $quote = DiscountPolicy::quote($rule, $zones, (int)$session->unit_price_minor, $now);
        return ['base_minor'=>$quote['base_minor'], 'discount_minor'=>$quote['discount_minor'],
            'payable_minor'=>$quote['payable_minor'], 'indicative'=>true,
            'message'=>\PESM\Support\I18n::text('این مبلغ تخمینی است؛ موجودی کوپن و قیمت در ثبت نهایی سفارش دوباره کنترل می‌شود.')];
    }

    /** After confirmed issuance; safely idempotent. No remote gateway calls inside a DB transaction. */
    public function redeemOrder(int $orderId): void
    {
        if ($orderId < 1) { return; }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : false;
        if (!$order || $order->get_meta('_pesm_is_ticket_order') !== 'yes') { return; }
        $holdId = (int) $order->get_meta('_pesm_hold_id');
        if ($holdId < 1) { return; }
        if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('discount claim db unavailable'); }
        try {
            $claim = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}discount_claims WHERE hold_id=%d FOR UPDATE", $holdId
            ));
            if ($claim && $claim->status === 'reserved') {
                // Do not redeem on payment notification alone; issuance must exist.
                $issued = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}tickets WHERE hold_id=%d AND status IN ('active','checked_in')", $holdId
                ));
                $seatCount = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}hold_items WHERE hold_id=%d", $holdId
                ));
                if ($issued < 1 || $issued !== $seatCount) {
                    throw new \RuntimeException('Cannot redeem discount before all tickets are issued.');
                }
                if ($wpdb->update($p . 'discount_claims', [
                    'status' => 'redeemed', 'order_id' => $orderId,
                ], ['id' => (int) $claim->id, 'status' => 'reserved']) !== 1 ||
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$p}discount_rules SET reserved_uses=reserved_uses-1,redeemed_uses=redeemed_uses+1 WHERE id=%d AND reserved_uses>0",
                        (int) $claim->rule_id
                    )) !== 1) {
                    throw new \RuntimeException('Could not redeem discount claim.');
                }
            }
            if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Could not commit discount.'); }
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /** Cancelled/failed orders release UNREDEEMED claims only. */
    public function releaseUnredeemed(int $orderId): void
    {
        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : false;
        if (!$order || $order->get_meta('_pesm_is_ticket_order') !== 'yes') { return; }
        $holdId = (int) $order->get_meta('_pesm_hold_id');
        if (!$holdId) { return; }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('discount release db unavailable'); }
        try {
            $claim = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}discount_claims WHERE hold_id=%d FOR UPDATE", $holdId
            ));
            if ($claim && $claim->status === 'reserved') {
                if ($wpdb->update($p . 'discount_claims', ['status'=>'released'],
                    ['id'=>(int)$claim->id, 'status'=>'reserved']) !== 1 ||
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$p}discount_rules SET reserved_uses=reserved_uses-1 WHERE id=%d AND reserved_uses>0",
                        (int)$claim->rule_id
                    )) !== 1) {
                    throw new \RuntimeException('Discount release failed.');
                }
            }
            if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Discount release commit failed.'); }
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
    }

    /** Strictly scoped administrative rule creation. No edit/deletion during pending claims. */
    public function create(array $input, int $actorId): array
    {
        if ($actorId < 1 || !user_can($actorId, 'pesm_manage_pricing')) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('اجازه تعریف تخفیف ندارید.'), 403);
        }
        if ((int) get_option('pesm_schema_version', 0) < 16) {
            throw new HoldException('schema_not_ready', \PESM\Support\I18n::text('جدول‌های تخفیف هنوز آماده نیستند.'), 503);
        }
        $eventId = (int) ($input['event_id'] ?? 0);
        $sessionId = (int) ($input['session_id'] ?? 0);
        $code = DiscountPolicy::code((string) ($input['code'] ?? ''));
        $kind = (string) ($input['kind'] ?? '');
        $value = (int) ($input['value'] ?? 0);
        $zone = trim((string) ($input['zone_label'] ?? ''));
        $starts = (string) ($input['starts_at'] ?? '');
        $ends = (string) ($input['ends_at'] ?? '');
        $minimum = (int) ($input['min_order_minor'] ?? 0);
        $max = (int) ($input['max_uses'] ?? 0);
        $fromDate = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $starts, new \DateTimeZone('UTC'));
        $toDate = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $ends, new \DateTimeZone('UTC'));
        if ($eventId < 1 || $sessionId < 0 || !preg_match('/^[\p{L}\p{N}\p{Zs}_.-]{0,60}$/uD', $zone) ||
            $minimum < 0 || $minimum > 1000000000000 || $max < 0 || $max > 1000000 ||
            !$fromDate || !$toDate || $fromDate->format('Y-m-d H:i:s') !== $starts ||
            $toDate->format('Y-m-d H:i:s') !== $ends || $starts >= $ends ||
            !in_array($kind, ['fixed', 'percent'], true) || $value < 1 ||
            ($kind === 'percent' && $value > 10000) || ($kind === 'fixed' && $value > 100000000000)) {
            throw new HoldException('invalid_discount', \PESM\Support\I18n::text('مقادیر قانون تخفیف معتبر نیست.'), 422);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id,center_id FROM {$p}events WHERE id=%d", $eventId
        ));
        if (!$row) { throw new HoldException('invalid_event', \PESM\Support\I18n::text('رویداد یافت نشد.'), 404); }
        if (!Capabilities::centerAllowed((int) $row->center_id, $actorId)) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('دسترسی به این مرکز مجاز نیست.'), 403);
        }
        if ($sessionId > 0 && (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}sessions WHERE id=%d AND event_id=%d", $sessionId, $eventId
        )) !== 1) {
            throw new HoldException('invalid_session', \PESM\Support\I18n::text('جلسه انتخاب‌شده متعلق به رویداد نیست.'), 422);
        }
        $created = $wpdb->insert($p . 'discount_rules', [
            'event_id' => $eventId, 'session_id' => $sessionId,
            'code' => $code, 'zone_label' => $zone, 'kind' => $kind, 'discount_value' => $value,
            'min_order_minor' => $minimum, 'max_uses' => $max, 'starts_at' => $starts,
            'ends_at' => $ends, 'enabled' => 1,
        ]);
        if ($created !== 1) { throw new HoldException('discount_exists', \PESM\Support\I18n::text('کد تخفیف قبلاً برای این رویداد تعریف شده است.'), 409); }
        $id = (int) $wpdb->insert_id;
        Audit::record('discount_created', 'discount_rule', $id, $actorId);
        return ['id' => $id, 'event_id' => $eventId, 'session_id' => $sessionId, 'code' => $code,
            'message' => \PESM\Support\I18n::text('کد تخفیف ثبت شد. فعال‌سازی فروش واقعی نیازمند آزمون یکپارچه است.')];
    }
}
