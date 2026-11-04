<?php

declare(strict_types=1);

namespace PESM\Domain\Ticket;

use PESM\Domain\Booking\HoldException;
use PESM\Security\Capabilities;
use PESM\Support\Audit;
use Throwable;

/**
 * Administrative grouping of previously issued tickets for distinct sessions.
 * No new ticket, payment, refund, or combined QR is created. Each existing QR
 * remains the only entry credential for its session.
 */
final class BundleService
{
    /** @param list<int> $ticketIds @return array<string,mixed> */
    public function create(array $ticketIds, string $requestKey, int $actorId): array
    {
        $ticketIds = BundlePolicy::ticketIds($ticketIds);
        BundlePolicy::key($requestKey);
        $this->access($actorId);
        $this->schema();
        if (!function_exists('wc_get_order')) {
            throw new HoldException('woocommerce_required', \PESM\Support\I18n::text('ووکامرس برای تشکیل بسته لازم است.'), 503);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $requestHash = hash_hmac('sha256', 'ticket-bundle:' . $requestKey, wp_salt('auth'));
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('امکان آغاز تراکنش نیست.'), 503);
        }
        try {
            // The request hash belongs to the user-supplied request key, not the ticket list.
            // Reuse with a different list must be detected, not treated as a new request.
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id,event_id,center_id FROM {$p}ticket_bundles WHERE request_hash=%s FOR UPDATE", $requestHash
            ));
            if ($existing) {
                $this->center((int) $existing->center_id, $actorId);
                $current = array_map('intval', $wpdb->get_col($wpdb->prepare(
                    "SELECT ticket_id FROM {$p}ticket_bundle_items WHERE bundle_id=%d ORDER BY ticket_id", (int)$existing->id
                )));
                if ($current !== $ticketIds) {
                    throw new HoldException('idempotency_conflict', \PESM\Support\I18n::text('این کلید درخواست قبلاً برای بلیت‌های دیگری ثبت شده است.'), 409);
                }
                $this->commit();
                return ['bundle_id'=>(int)$existing->id,'event_id'=>(int)$existing->event_id,
                    'ticket_ids'=>$ticketIds,'replayed'=>true];
            }
            // Lock the involved ticket records in stable order. Unique constraints
            // on bundle items remain authoritative under competing requests.
            $tokens = implode(',', array_fill(0, count($ticketIds), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT t.id,t.session_id,t.order_id,t.attendee_name,t.status AS ticket_status,
                    s.event_id,s.starts_at,s.status AS session_status,e.center_id,e.status AS event_status
                 FROM {$p}tickets t JOIN {$p}sessions s ON s.id=t.session_id
                 JOIN {$p}events e ON e.id=s.event_id
                 WHERE t.id IN ($tokens) ORDER BY t.id FOR UPDATE", ...$ticketIds
            ), ARRAY_A);
            if (!is_array($rows) || count($rows) !== count($ticketIds)) {
                throw new HoldException('invalid_bundle', \PESM\Support\I18n::text('تمام بلیت‌های انتخاب‌شده یافت نشدند.'), 404);
            }
            $now = (string)$wpdb->get_var('SELECT UTC_TIMESTAMP()');
            if ($now === '') {
                throw new HoldException('db_unavailable', \PESM\Support\I18n::text('زمان معتبر پایگاه داده دریافت نشد.'), 503);
            }
            $buyerEmail = '';
            $currency = '';
            $orders = [];
            foreach ($rows as &$row) {
                $row['checked_in'] = (int)(bool)$wpdb->get_var($wpdb->prepare(
                    "SELECT 1 FROM {$p}checkins WHERE ticket_id=%d LIMIT 1", (int)$row['id']
                ));
                // A previously transferred ticket is not necessarily owned by the original
                // WooCommerce buyer; do not infer its present owner from the old invoice.
                if ($wpdb->get_var($wpdb->prepare(
                    "SELECT 1 FROM {$p}ticket_owners WHERE ticket_type='seated' AND ticket_id=%d LIMIT 1",
                    (int)$row['id']
                ))) {
                    throw new HoldException('bundle_owner_unverified', \PESM\Support\I18n::text('مالک جدید بلیت باید پیش از گروه‌بندی جداگانه احراز شود.'), 409);
                }
                if ($wpdb->get_var($wpdb->prepare(
                    "SELECT 1 FROM {$p}refund_cases WHERE ticket_id=%d LIMIT 1", (int)$row['id']
                ))) {
                    throw new HoldException('bundle_refund_conflict', \PESM\Support\I18n::text('بلیت دارای پرونده استرداد را نمی‌توان گروه‌بندی کرد.'), 409);
                }
                $this->center((int)$row['center_id'], $actorId);
                $orderId = (int) $row['order_id'];
                if (!isset($orders[$orderId])) {
                    $order = wc_get_order($orderId);
                    $data = BundlePolicy::verifyPaidOrder($order, $buyerEmail, $currency);
                    $buyerEmail = $data['email'];
                    $currency = $data['currency'];
                    $orders[$orderId] = true;
                }
            }
            unset($row);
            $manifest = BundlePolicy::validateManifest($rows, $now);
            // Additional fail-closed restrictions: package purchase/refund policy is not implemented.
            if ($wpdb->insert($p . 'ticket_bundles', [
                'event_id'=>$manifest['event_id'], 'center_id'=>$manifest['center_id'],
                'request_hash'=>$requestHash, 'status'=>'linked', 'actor_user_id'=>$actorId,
                'created_at'=>$now,
            ]) !== 1 || (int)$wpdb->insert_id < 1) {
                throw new HoldException('bundle_write_failed', \PESM\Support\I18n::text('ثبت بسته ممکن نشد.'), 503);
            }
            $bundleId = (int) $wpdb->insert_id;
            foreach ($rows as $row) {
                if ($wpdb->insert($p . 'ticket_bundle_items', [
                    'bundle_id'=>$bundleId, 'ticket_id'=>(int)$row['id'],
                    'session_id'=>(int)$row['session_id'],
                ]) !== 1) {
                    throw new HoldException('bundle_conflict', \PESM\Support\I18n::text('یک بلیت یا جلسه در بسته دیگری ثبت شده است.'), 409);
                }
            }
            Audit::record('ticket_bundle_linked', 'ticket_bundle', $bundleId, $actorId);
            $this->commit();
            return ['bundle_id'=>$bundleId,'event_id'=>$manifest['event_id'],
                'ticket_ids'=>$ticketIds,'replayed'=>false,
                'message'=>\PESM\Support\I18n::text('بسته از بلیت‌های موجود ایجاد شد؛ هر جلسه همچنان بلیت و QR مستقل دارد.')];
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($e instanceof HoldException) { throw $e; }
            throw new HoldException('bundle_write_failed', \PESM\Support\I18n::text('ثبت بسته به بررسی نیاز دارد.'), 503);
        }
    }

    /** @return array<string,mixed> */
    public function view(int $bundleId, int $actorId): array
    {
        $this->access($actorId);
        $this->schema();
        if ($bundleId < 1) { throw new HoldException('invalid_bundle', \PESM\Support\I18n::text('شناسه بسته نامعتبر است.'), 422); }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $bundle = $wpdb->get_row($wpdb->prepare(
            "SELECT id,event_id,center_id,status,created_at FROM {$p}ticket_bundles WHERE id=%d", $bundleId
        ));
        if (!$bundle) { throw new HoldException('not_found', \PESM\Support\I18n::text('بسته یافت نشد.'), 404); }
        $this->center((int)$bundle->center_id, $actorId);
        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT i.ticket_id,i.session_id,t.ticket_number,t.status AS ticket_status,
                s.starts_at,seat.seat_code FROM {$p}ticket_bundle_items i
             JOIN {$p}tickets t ON t.id=i.ticket_id
             JOIN {$p}sessions s ON s.id=i.session_id
             JOIN {$p}seats seat ON seat.id=t.seat_id
             WHERE i.bundle_id=%d ORDER BY s.starts_at,i.ticket_id", $bundleId
        ), ARRAY_A);
        if (!is_array($entries)) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('دریافت اطلاعات بسته ممکن نیست.'), 503);
        }
        $allValid = count($entries) >= 2;
        foreach ($entries as $item) {
            if ((string)$item['ticket_status'] !== 'active') { $allValid = false; break; }
        }
        return ['bundle_id'=>(int)$bundle->id,'event_id'=>(int)$bundle->event_id,
            'status'=>(string)$bundle->status,'all_tickets_active'=>$allValid,
            'tickets'=>$entries,'notice'=>\PESM\Support\I18n::text('این بسته صرفاً گروه‌بندی بلیت‌هاست؛ خرید و استرداد گروهی فعال نیست.')];
    }

    private function access(int $actor): void
    {
        if ($actor < 1 || !user_can($actor, 'pesm_issue_tickets')) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('مجوز مدیریت بسته بلیت وجود ندارد.'), 403);
        }
    }

    private function center(int $center, int $actor): void
    {
        if (!Capabilities::centerAllowed($center, $actor)) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('دسترسی به مرکز این بلیت مجاز نیست.'), 403);
        }
    }

    private function schema(): void
    {
        if ((int)get_option('pesm_schema_version', 0) < 18 || get_option('pesm_ticket_bundles_preview_enabled', 'no') !== 'yes') {
            throw new HoldException('bundle_disabled', \PESM\Support\I18n::text('بسته‌های چندجلسه‌ای فقط در محیط آزمایشی فعال می‌شوند.'), 503);
        }
    }

    private function commit(): void
    {
        global $wpdb;
        if ($wpdb->query('COMMIT') === false) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('ثبت نهایی بسته انجام نشد.'), 503);
        }
    }
}
