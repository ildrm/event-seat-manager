<?php

declare(strict_types=1);

namespace PESM\Domain\Ticket;

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Notification\PayloadCipher;
use PESM\Support\Audit;
use Throwable;

/**
 * Two independent confirmations: current owner's order/ownership bearer token
 * authorizes an invitation; the recipient proves email control with a short-lived OTP.
 * QR rotates in the same transaction as ownership. No WooCommerce billing data changes.
 * The original buyer retains their INVOICE, but cannot view the transferred ticket.
 */
final class OwnershipTransferService
{
    /** @return array{transfer_id:int,status:string,replayed:bool} */
    public function request(string $kind, int $ticketId, string $ownerToken, string $recipientEmail,
        string $recipientName, string $requestKey): array
    {
        self::assertSecureChannel(true);
        OwnershipTransferPolicy::validateKind($kind);
        OwnershipTransferPolicy::validateKey($requestKey);
        OwnershipTransferPolicy::validateBearer($ownerToken);
        $recipientEmail = strtolower(trim($recipientEmail));
        if ($ticketId < 1 || !is_email($recipientEmail) || strlen($recipientEmail) > 190) {
            throw new HoldException('invalid_recipient', \PESM\Support\I18n::text('اطلاعات گیرنده نامعتبر است.'), 422);
        }
        $recipientName = AttendeeChangePolicy::normalizedName($recipientName);
        $hash = hash_hmac('sha256', "owner-transfer:$kind:$ticketId:$requestKey", wp_salt('auth'));
        $emailHash = hash_hmac('sha256', $recipientEmail, wp_salt('secure_auth'));
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $table = $kind === 'seated' ? 'tickets' : 'capacity_tickets';
        if ((int) get_option('pesm_schema_version', 0) < 17 || !function_exists('wc_get_order')) {
            throw new HoldException('schema_not_ready', \PESM\Support\I18n::text('زیرساخت انتقال مالکیت آماده نیست.'), 503);
        }
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('پایگاه داده در دسترس نیست.'), 503);
        }
        $code = '';
        $invitationId = 0;
        try {
            // Ticket-first lock order matches gate and attendee edit paths.
            $ticket = $wpdb->get_row($wpdb->prepare(
                "SELECT id,session_id,order_id,public_id,status,qr_version FROM {$p}{$table} WHERE id=%d FOR UPDATE", $ticketId
            ));
            if (!$ticket || !$this->ownerMayTransfer($p, $kind, $ticketId, (int) $ticket->order_id, $ownerToken)) {
                throw new HoldException('permission_denied', \PESM\Support\I18n::text('مجوز انتقال این بلیت وجود ندارد.'), 403);
            }
            $order = wc_get_order((int) $ticket->order_id);
            $this->verifyOrder($order);
            $now = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
            $this->verifyTicket($p, $kind, $ticket, $now);
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}ticket_ownership_transfers WHERE request_hash=%s FOR UPDATE", $hash
            ));
            if ($existing) {
                if ((string) $existing->recipient_email_hash !== $emailHash ||
                    (string) $existing->recipient_name !== $recipientName ||
                    (int) $existing->ticket_id !== $ticketId || (string) $existing->ticket_type !== $kind) {
                    throw new HoldException('idempotency_conflict', \PESM\Support\I18n::text('این شناسه درخواست قبلاً برای گیرنده دیگری استفاده شده است.'), 409);
                }
                self::commit($wpdb);
                return ['transfer_id'=>(int) $existing->id, 'status'=>(string) $existing->status, 'replayed'=>true];
            }
            // One pending invitation per ticket. The ticket lock serializes creators.
            $pending = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$p}ticket_ownership_transfers WHERE ticket_type=%s AND ticket_id=%d
                 AND status='pending' AND expires_at>%s LIMIT 1 FOR UPDATE", $kind, $ticketId, $now
            ));
            if ($pending) { throw new HoldException('transfer_pending', \PESM\Support\I18n::text('درخواست انتقال دیگری برای این بلیت فعال است.'), 409); }
            $code = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            $expires = gmdate('Y-m-d H:i:s', (int) strtotime($now . ' UTC') + OwnershipTransferPolicy::OTP_TTL);
            $encrypted = PayloadCipher::seal(['email'=>$recipientEmail]);
            // OTP hash includes the invitation identifier; we create first with a
            // non-verifying placeholder, then write the final hash before commit.
            if ($wpdb->insert($p . 'ticket_ownership_transfers', [
                'ticket_type'=>$kind, 'ticket_id'=>$ticketId, 'order_id'=>(int) $ticket->order_id,
                'request_hash'=>$hash, 'recipient_name'=>$recipientName,
                'recipient_email_hash'=>$emailHash, 'recipient_email_encrypted'=>$encrypted,
                'otp_hash'=>str_repeat('0', 64), 'attempts'=>0, 'status'=>'pending',
                'expires_at'=>$expires, 'created_at'=>$now,
            ]) !== 1) { throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت درخواست انتقال ناموفق بود.'), 503); }
            $invitationId = (int) $wpdb->insert_id;
            $codeHash = hash_hmac('sha256', "ownership-transfer:$invitationId:$code", wp_salt('secure_auth'));
            if ($wpdb->update($p . 'ticket_ownership_transfers', ['otp_hash'=>$codeHash],
                ['id'=>$invitationId, 'status'=>'pending']) !== 1) {
                throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت تأیید گیرنده ناموفق بود.'), 503);
            }
            self::commit($wpdb);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($e instanceof HoldException) { throw $e; }
            throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت درخواست انتقال ناموفق بود.'), 503);
        }
        // Do not include OTP in HTTP response or URL. Failed email cancels invite.
        $sent = wp_mail($recipientEmail, \PESM\Support\I18n::text('تأیید دریافت بلیت رویداد'),
            sprintf(\PESM\Support\I18n::text("کد تأیید انتقال بلیت: %s\nاعتبار: ۱۰ دقیقه. اگر این انتقال را نمی‌شناسید، آن را نادیده بگیرید."), $code));
        if (!$sent) {
            $wpdb->update($p . 'ticket_ownership_transfers', ['status'=>'delivery_failed'],
                ['id'=>$invitationId,'status'=>'pending']);
            throw new HoldException('delivery_failed', \PESM\Support\I18n::text('ارسال کد تأیید انجام نشد.'), 503);
        }
        return ['transfer_id'=>$invitationId,'status'=>'pending','replayed'=>false];
    }

    /** @return array<string,mixed> */
    public function accept(int $invitationId, string $otp): array
    {
        self::assertSecureChannel(true);
        if ($invitationId < 1 || !preg_match('/^[0-9]{8}$/D', $otp)) {
            throw new HoldException('invalid_code', \PESM\Support\I18n::text('کد تأیید معتبر نیست.'), 422);
        }
        if ((int) get_option('pesm_schema_version', 0) < 17 || !function_exists('wc_get_order')) {
            throw new HoldException('schema_not_ready', \PESM\Support\I18n::text('انتقال مالکیت در دسترس نیست.'), 503);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        // First query only locates ticket, not a proof of authorization.
        $locator = $wpdb->get_row($wpdb->prepare(
            "SELECT ticket_type,ticket_id FROM {$p}ticket_ownership_transfers WHERE id=%d", $invitationId
        ));
        if (!$locator) { throw new HoldException('invalid_code', \PESM\Support\I18n::text('درخواست انتقال معتبر نیست.'), 403); }
        $kind = (string) $locator->ticket_type;
        OwnershipTransferPolicy::validateKind($kind);
        $table = $kind === 'seated' ? 'tickets' : 'capacity_tickets';
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('پایگاه داده در دسترس نیست.'), 503);
        }
        try {
            // Lock ticket before invitation (same order as initiation) and scanner.
            $ticket = $wpdb->get_row($wpdb->prepare(
                "SELECT id,session_id,order_id,public_id,status,qr_version FROM {$p}{$table} WHERE id=%d FOR UPDATE",
                (int) $locator->ticket_id
            ));
            $invitation = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}ticket_ownership_transfers WHERE id=%d FOR UPDATE", $invitationId
            ));
            $now = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
            if (!$ticket || !$invitation || (int) $invitation->ticket_id !== (int) $ticket->id ||
                (string) $invitation->ticket_type !== $kind ||
                (int) $invitation->order_id !== (int) $ticket->order_id ||
                (string) $invitation->status !== 'pending') {
                throw new HoldException('transfer_unavailable', \PESM\Support\I18n::text('انتقال قبلاً مصرف یا باطل شده است.'), 409);
            }
            if (!OwnershipTransferPolicy::verifyOtp($otp, (string) $invitation->otp_hash,
                wp_salt('secure_auth'), $invitationId, (int) $invitation->attempts,
                (string) $invitation->expires_at, $now)) {
                // Commit the failed-attempt counter instead of rolling it back.
                if ($wpdb->query($wpdb->prepare(
                    "UPDATE {$p}ticket_ownership_transfers SET attempts=attempts+1 WHERE id=%d", $invitationId
                )) !== 1) {
                    throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت تلاش تأیید ناموفق بود.'), 503);
                }
                self::commit($wpdb);
                throw new HoldException('invalid_code', \PESM\Support\I18n::text('کد تأیید منقضی یا نادرست است.'), 403);
            }
            $order = wc_get_order((int) $ticket->order_id);
            $this->verifyOrder($order);
            $this->verifyTicket($p, $kind, $ticket, $now);
            if ((int) $ticket->qr_version >= 2147483646) {
                throw new HoldException('qr_version_limit', \PESM\Support\I18n::text('نسخه QR قابل افزایش نیست.'), 409);
            }
            $ownerToken = bin2hex(random_bytes(32));
            $nextVersion = (int) $ticket->qr_version + 1;
            $qr = TicketToken::create((string) $ticket->public_id, $nextVersion, wp_salt('secure_auth'));
            $existingOwner = $wpdb->get_row($wpdb->prepare(
                "SELECT ticket_id FROM {$p}ticket_owners WHERE ticket_type=%s AND ticket_id=%d FOR UPDATE",
                $kind, (int) $ticket->id
            ));
            $emailCipher = (string) $invitation->recipient_email_encrypted;
            $ownerData = [
                'owner_token_hash'=>hash('sha256', $ownerToken),
                'email_encrypted'=>$emailCipher, 'updated_at'=>$now,
            ];
            $written = $existingOwner
                ? $wpdb->update($p . 'ticket_owners', $ownerData,
                    ['ticket_type'=>$kind,'ticket_id'=>(int) $ticket->id])
                : $wpdb->insert($p . 'ticket_owners', array_merge($ownerData,
                    ['ticket_type'=>$kind,'ticket_id'=>(int) $ticket->id]));
            if ($written !== 1 || $wpdb->update($p . $table, [
                    'attendee_name'=>(string) $invitation->recipient_name,
                    'qr_version'=>$nextVersion,'qr_token_hash'=>hash('sha256', $qr),
                ], ['id'=>(int) $ticket->id,'status'=>'active','qr_version'=>(int) $ticket->qr_version]) !== 1 ||
                $wpdb->update($p . 'ticket_ownership_transfers', [
                    'status'=>'accepted', 'accepted_at'=>$now,
                ], ['id'=>$invitationId,'status'=>'pending']) !== 1) {
                throw new HoldException('write_failed', \PESM\Support\I18n::text('ثبت انتقال نهایی موفق نبود.'), 503);
            }
            Audit::record('ticket_ownership_transferred', $kind === 'seated' ? 'ticket' : 'capacity_ticket', (int) $ticket->id);
            self::commit($wpdb);
            // Recipient controls the verified inbox. Token is also in the response
            // exactly once; retries never return the previous bearer token.
            // Delivery is best-effort after COMMIT; failure never rolls back or
            // falsely reports a successfully committed transfer as unsuccessful.
            try {
                $message = sprintf(\PESM\Support\I18n::text("توکن مشاهده بلیت شما: %s\nبرای محافظت از بلیت این توکن را منتشر نکنید."), $ownerToken);
                $decoded = PayloadCipher::open($emailCipher);
                if (isset($decoded['email']) && is_string($decoded['email'])) {
                    wp_mail($decoded['email'], \PESM\Support\I18n::text('بلیت شما منتقل شد'), $message);
                }
            } catch (Throwable $deliveryError) {
                // The accepting recipient still obtains the one-time token in
                // their authenticated OTP confirmation response.
            }
            return [
                'accepted'=>true, 'ticket_type'=>$kind, 'ticket_id'=>(int) $ticket->id,
                'ownership_token'=>$ownerToken, 'qr_version'=>$nextVersion,
            ];
        } catch (Throwable $e) {
            if (!($e instanceof HoldException && $e->errorCode === 'invalid_code')) { $wpdb->query('ROLLBACK'); }
            if ($e instanceof HoldException) { throw $e; }
            throw new HoldException('transfer_failed', \PESM\Support\I18n::text('انتقال بلیت نیازمند بررسی است.'), 503);
        }
    }

    /** @return array<string,mixed> */
    public function view(string $token): array
    {
        self::assertSecureChannel(false);
        OwnershipTransferPolicy::validateBearer($token);
        if ((int) get_option('pesm_schema_version', 0) < 17) {
            throw new HoldException('schema_not_ready', \PESM\Support\I18n::text('دسترسی بلیت در دسترس نیست.'), 503);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $owner = $wpdb->get_row($wpdb->prepare(
            "SELECT ticket_type,ticket_id FROM {$p}ticket_owners WHERE owner_token_hash=%s", hash('sha256', $token)
        ));
        if (!$owner) { throw new HoldException('permission_denied', \PESM\Support\I18n::text('توکن دسترسی معتبر نیست.'), 403); }
        $kind = (string) $owner->ticket_type;
        $table = $kind === 'seated' ? 'tickets' : 'capacity_tickets';
        $ticket = $wpdb->get_row($wpdb->prepare(
            $kind === 'seated'
                ? "SELECT t.public_id,t.ticket_number,t.attendee_name,t.status,t.qr_version,t.session_id,
                    s.seat_code,s.row_label,s.zone_label FROM {$p}tickets t
                    JOIN {$p}seats s ON s.id=t.seat_id WHERE t.id=%d"
                : "SELECT public_id,ticket_number,attendee_name,status,qr_version,session_id,
                    'ورود آزاد' AS seat_code FROM {$p}capacity_tickets WHERE id=%d",
            (int) $owner->ticket_id
        ), ARRAY_A);
        if ($ticket && $kind === 'general') { $ticket['seat_code'] = \PESM\Support\I18n::text('ورود آزاد'); }
        if (!$ticket) { throw new HoldException('invalid_ticket', \PESM\Support\I18n::text('بلیت وجود ندارد.'), 404); }
        $ticket['qr_payload'] = in_array($ticket['status'], ['active','checked_in'], true)
            ? TicketToken::create((string) $ticket['public_id'], (int) $ticket['qr_version'], wp_salt('secure_auth')) : null;
        return ['ticket_type'=>$kind,'ticket'=>$ticket];
    }

    private static function assertSecureChannel(bool $newTransfer): void
    {
        if (!function_exists('is_ssl') || !is_ssl()) {
            throw new HoldException('https_required', \PESM\Support\I18n::text('انتقال و مشاهده بلیت فقط با HTTPS ممکن است.'), 403);
        }
        if ($newTransfer && get_option('pesm_ownership_transfer_preview_enabled', 'no') !== 'yes') {
            throw new HoldException('feature_disabled', \PESM\Support\I18n::text('انتقال مالکیت هنوز در این سایت فعال نیست.'), 503);
        }
    }

    private function ownerMayTransfer(string $p, string $kind, int $ticketId, int $orderId, string $token): bool
    {
        global $wpdb;
        $owner = $wpdb->get_row($wpdb->prepare(
            "SELECT owner_token_hash FROM {$p}ticket_owners WHERE ticket_type=%s AND ticket_id=%d",
            $kind, $ticketId
        ));
        $tokenHash = hash('sha256', $token);
        if ($owner) { return hash_equals((string) $owner->owner_token_hash, $tokenHash); }
        // An original buyer may hold either the initial order token or a verified
        // recovery token. Subsequent owners cannot be impersonated by that buyer.
        $links = $kind === 'seated' ? 'order_links' : 'capacity_order_links';
        $link = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$p}{$links} WHERE order_id=%d AND guest_token_hash=%s LIMIT 1", $orderId, $tokenHash
        ));
        if ($link) { return true; }
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$p}guest_access_tokens WHERE order_id=%d AND token_hash=%s
             AND expires_at>UTC_TIMESTAMP() AND revoked_at IS NULL LIMIT 1", $orderId, $tokenHash
        ));
    }

    private function verifyTicket(string $p, string $kind, object $ticket, string $now): void
    {
        global $wpdb;
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT s.starts_at,s.status,e.status AS event_status FROM {$p}sessions s
             JOIN {$p}events e ON e.id=s.event_id WHERE s.id=%d", (int) $ticket->session_id
        ));
        $checkins = $kind === 'seated' ? 'checkins' : 'capacity_checkins';
        $hasCheckin = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$p}{$checkins} WHERE ticket_id=%d LIMIT 1", (int) $ticket->id
        ));
        if (!$session) { throw new HoldException('ticket_unavailable', \PESM\Support\I18n::text('جلسه یافت نشد.'), 409); }
        OwnershipTransferPolicy::transferable((string) $ticket->status, $hasCheckin,
            (string) $session->starts_at, $now, (string) $session->event_status, (string) $session->status);
    }

    private function verifyOrder(mixed $order): void
    {
        if (!$order || $order->get_meta('_pesm_is_ticket_order') !== 'yes' ||
            !in_array((string) $order->get_status(), ['processing','completed'], true) ||
            (float) $order->get_total_refunded() > 0.0) {
            throw new HoldException('order_unavailable', \PESM\Support\I18n::text('سفارش قابل انتقال نیست.'), 409);
        }
    }

    private static function commit(object $wpdb): void
    {
        if ($wpdb->query('COMMIT') === false) { throw new HoldException('db_unavailable', \PESM\Support\I18n::text('ثبت تراکنش ناموفق بود.'), 503); }
    }
}
