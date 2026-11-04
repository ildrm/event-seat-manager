<?php

declare(strict_types=1);

namespace PESM\Domain\Notification;

use Throwable;

/**
 * Durable at-most-once provider submission per worker claim. Transport ambiguity is reviewed,
 * not retried automatically; email failure can retry safely when WP returned false.
 */
final class NotificationQueue
{
    private const TABLE = 'notification_jobs';

    /** Never throws into a payment/fulfillment callback. */
    public static function onTicketsIssued(int $orderId): void
    {
        if ($orderId <= 0 || get_option('pesm_notifications_enabled', 'no') !== 'yes' ||
            (int) get_option('pesm_schema_version', 0) < 11 || !function_exists('wc_get_order')) { return; }
        try {
            $order = wc_get_order($orderId);
            if (!$order || $order->get_meta('_pesm_is_ticket_order') !== 'yes') { return; }
            global $wpdb;
            $table = $wpdb->prefix . 'pesm_';
            $links = (int) $wpdb->get_var($wpdb->prepare(
                $order->get_meta('_pesm_mode') === 'general'
                    ? "SELECT COUNT(*) FROM {$table}capacity_tickets WHERE order_id=%d AND status='active'"
                    : "SELECT COUNT(*) FROM {$table}tickets WHERE order_id=%d AND status='active'", $orderId
            ));
            if ($links < 1) { return; }
            $email = trim((string) $order->get_billing_email());
            $phone = trim((string) $order->get_billing_phone());
            if ($email !== '' && is_email($email)) {
                self::enqueue('ticket_confirmed', $orderId, 'email', ['email'=>$email,'order_id'=>$orderId]);
            }
            $patternId = (int) get_option('pesm_sms_ticket_confirmed_pattern', 0);
            if ($patternId > 0 && defined('PESM_SMS_USERNAME') && defined('PESM_SMS_PASSWORD')) {
                try {
                    $normalized = NotificationPolicy::normalizeIranMobile($phone);
                    self::enqueue('ticket_confirmed', $orderId, 'sms', ['phone'=>$normalized,'order_id'=>$orderId,'pattern_id'=>$patternId]);
                } catch (\InvalidArgumentException $e) { /* Unsupported phone: email remains available. */ }
            }
        } catch (Throwable $e) {
            // Failed enqueue is reported by reconciliation/health checks, never rolls back paid tickets.
            do_action('pesm_notification_queue_error', 'enqueue_failed', $orderId);
        }
    }

    /** @param array<string,string|int> $payload */
    public static function enqueue(string $event, int $orderId, string $channel, array $payload): bool
    {
        $hash = NotificationPolicy::dedupeKey($event, $orderId, $channel);
        $encrypted = PayloadCipher::seal($payload);
        global $wpdb;
        $table = $wpdb->prefix . 'pesm_' . self::TABLE;
        $now = gmdate('Y-m-d H:i:s');
        // INSERT IGNORE is safe only for the unique dedupe key; never overwrite another worker's state.
        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (event_key,order_id,channel,dedupe_hash,payload,status,attempts,available_at,created_at,updated_at)
             VALUES (%s,%d,%s,%s,%s,'queued',0,%s,%s,%s)",
            $event, $orderId, $channel, $hash, $encrypted, $now, $now, $now
        ));
        return $result !== false;
    }

    public static function process(int $limit = 10): int
    {
        if ((int) get_option('pesm_schema_version', 0) < 11 || get_option('pesm_notifications_enabled', 'no') !== 'yes') {
            return 0;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'pesm_' . self::TABLE;
        $limit = max(1, min(20, $limit));
        // Sending could have happened before a crash; do NOT automatically replay abandoned claims.
        $wpdb->query("UPDATE {$table} SET status='review',last_error_code='abandoned_send',updated_at=UTC_TIMESTAMP()
            WHERE status='sending' AND locked_until < UTC_TIMESTAMP()");
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE status='queued' AND available_at <= UTC_TIMESTAMP()
             ORDER BY available_at,id LIMIT %d", $limit
        ));
        $processed = 0;
        foreach ($ids ?: [] as $id) {
            $id = (int) $id;
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status='sending',locked_until=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 120 SECOND),
                 attempts=attempts+1,updated_at=UTC_TIMESTAMP()
                 WHERE id=%d AND status='queued' AND available_at <= UTC_TIMESTAMP()", $id
            ));
            if ($claimed !== 1) { continue; }
            ++$processed;
            $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id));
            $status = 'review'; $code = 'send_unknown';
            try {
                if (!$job) { throw new \RuntimeException('Missing claimed job.'); }
                $payload = PayloadCipher::open((string) $job->payload);
                if (!self::stillDeliverable($job, $payload)) {
                    $status = 'failed'; $code = 'order_no_longer_eligible';
                } elseif ($job->event_key !== 'ticket_confirmed' || (int) ($payload['order_id'] ?? 0) !== (int) $job->order_id) {
                    $status = 'failed'; $code = 'payload_invalid';
                } elseif ($job->channel === 'email') {
                    $email = (string) ($payload['email'] ?? '');
                    if (!is_email($email)) { $status = 'failed'; $code = 'invalid_email'; }
                    else {
                        $accepted = wp_mail($email, \PESM\Support\I18n::text('تأیید صدور بلیت'), NotificationPolicy::orderText((int) $job->order_id));
                        $status = $accepted ? 'sent' : 'retry';
                        $code = $accepted ? 'wp_mail_accepted' : 'wp_mail_failed';
                    }
                } elseif ($job->channel === 'sms') {
                    $result = (new MelipayamakClient())->sendPattern(
                        (string) ($payload['phone'] ?? ''), (int) ($payload['pattern_id'] ?? 0),
                        NotificationPolicy::patternVariables((int) $job->order_id)
                    );
                    $status = match ($result['status']) {
                        'sent' => 'sent', 'rejected' => 'failed', 'unavailable' => 'retry', default => 'review',
                    };
                    $code = $result['code'];
                } else { $status = 'failed'; $code = 'channel_invalid'; }
            } catch (Throwable $e) {
                // Unknown whether a previous network call was accepted: conservative manual review.
                $status = 'review'; $code = 'processing_exception';
            }
            $attempts = (int) ($job->attempts ?? 1);
            if ($status === 'retry') {
                if ($attempts >= NotificationPolicy::MAX_ATTEMPTS) {
                    $status = 'failed'; $code = 'retry_exhausted';
                } else {
                    $delay = NotificationPolicy::retryDelay($attempts);
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$table} SET status='queued',locked_until=NULL,available_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND),
                         last_error_code=%s,updated_at=UTC_TIMESTAMP() WHERE id=%d AND status='sending'", $delay, $code, $id
                    ));
                    continue;
                }
            }
            // Only predefined, non-sensitive machine codes go into the log.
            $wpdb->update($table, [
                'status'=>$status, 'last_error_code'=>$code, 'locked_until'=>null,
                'updated_at'=>gmdate('Y-m-d H:i:s'),
                // Once delivered, remove encrypted contact data from the database.
                'payload'=>$status === 'sent' ? '' : (string) ($job->payload ?? ''),
            ], ['id'=>$id, 'status'=>'sending']);
        }
        return $processed;
    }

    /** Check again immediately before delivery; a cancellation after enqueue must not generate confirmation. */
    private static function stillDeliverable(object $job, array $payload): bool
    {
        if (!function_exists('wc_get_order')) { return false; }
        $order = wc_get_order((int) $job->order_id);
        if (!$order || $order->get_meta('_pesm_is_ticket_order') !== 'yes' ||
            in_array((string) $order->get_status(), ['cancelled','failed','refunded','trash'], true)) { return false; }
        $recipient = $job->channel === 'email' ? trim((string) $order->get_billing_email()) :
            trim((string) $order->get_billing_phone());
        if ($job->channel === 'email') {
            if (strtolower($recipient) !== strtolower((string) ($payload['email'] ?? ''))) { return false; }
        } else {
            try { if (NotificationPolicy::normalizeIranMobile($recipient) !== ($payload['phone'] ?? '')) { return false; } }
            catch (\InvalidArgumentException $e) { return false; }
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $sql = $order->get_meta('_pesm_mode') === 'general'
            ? "SELECT COUNT(*) FROM {$p}capacity_tickets WHERE order_id=%d AND status='active'"
            : "SELECT COUNT(*) FROM {$p}tickets WHERE order_id=%d AND status='active'";
        return (int) $wpdb->get_var($wpdb->prepare($sql, (int) $job->order_id)) > 0;
    }

    /** Dead letters stay visible for review, but no contact data survives the retention period. */
    public static function cleanup(): void
    {
        if ((int) get_option('pesm_schema_version', 0) < 11) { return; }
        global $wpdb;
        $table = $wpdb->prefix . 'pesm_' . self::TABLE;
        $wpdb->query("DELETE FROM {$table} WHERE status IN ('sent','failed','review')
            AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)");
    }

    /** Summary only, no contact/secret values. */
    public static function summary(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pesm_' . self::TABLE;
        $rows = $wpdb->get_results("SELECT status,COUNT(*) AS total FROM {$table} GROUP BY status");
        $summary = [];
        foreach ($rows ?: [] as $row) { $summary[(string) $row->status] = (int) $row->total; }
        return $summary;
    }
}
