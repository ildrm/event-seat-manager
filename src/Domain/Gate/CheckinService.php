<?php

declare(strict_types=1);

namespace PESM\Domain\Gate;

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Ticket\TicketToken;
use PESM\Domain\GeneralAdmission\AdmissionGateService;
use PESM\Security\Capabilities;
use PESM\Support\Audit;
use Throwable;

/** Online first-entry scanner; duplicate entry is rejected across concurrent workers. */
final class CheckinService
{
    /**
     * Restricted manual lookup; exposes no customer telephone/name/QR to client.
     * @return array<string,mixed>
     */
    public function lookupNumber(string $ticketNumber, int $operatorId): array
    {
        if ($operatorId < 1 || !user_can($operatorId, 'pesm_scan_tickets')) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('مجوز کنترل ورود ندارید.'), 403);
        }
        if (!preg_match('/^P-[A-F0-9]{16}$/D', $ticketNumber)) {
            throw new HoldException('invalid_ticket_number', \PESM\Support\I18n::text('شماره بلیت معتبر نیست.'), 422);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT t.id,t.public_id,t.qr_version,t.ticket_number,t.status,t.session_id,e.center_id
             FROM {$p}tickets t JOIN {$p}sessions s ON s.id=t.session_id
             JOIN {$p}events e ON e.id=s.event_id WHERE t.ticket_number=%s", $ticketNumber
        ));
        if (!$row) {
            return (new AdmissionGateService())->lookupNumber($ticketNumber, $operatorId);
        }
        if (!Capabilities::centerAllowed((int) $row->center_id, $operatorId)) {
            throw new HoldException('ticket_not_found', \PESM\Support\I18n::text('بلیت در محدوده دسترسی شما پیدا نشد.'), 404);
        }
        return [
            'ticket_number' => (string) $row->ticket_number,
            'session_id' => (int) $row->session_id,
            'status' => (string) $row->status,
        ];
    }

    /** Controlled entry by ticket number. Final state is revalidated under row lock by scan(). */
    public function manualCheckin(string $ticketNumber, int $operatorId, string $gate = ''): array
    {
        $this->lookupNumber($ticketNumber, $operatorId);
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT public_id,qr_version FROM {$p}tickets WHERE ticket_number=%s", $ticketNumber
        ));
        if (!$ticket) {
            return (new AdmissionGateService())->manualCheckin($ticketNumber, $operatorId, $gate);
        }
        return $this->scan(TicketToken::create(
            (string) $ticket->public_id, (int) $ticket->qr_version, wp_salt('secure_auth')
        ), $operatorId, $gate);
    }

    /** @return array<string,mixed> */
    public function scan(string $payload, int $operatorId, string $gate = ''): array
    {
        if ((int) get_option('pesm_schema_version', 0) < 3) {
            throw new HoldException('schema_not_ready', \PESM\Support\I18n::text('ساختار پایگاه داده آماده نیست.'), 503);
        }
        if ($operatorId < 1 || !user_can($operatorId, 'pesm_scan_tickets')) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('مجوز اسکن بلیت ندارید.'), 403);
        }
        if (!preg_match('/^[a-f0-9]{32}\.[a-f0-9]{64}$/D', $payload)) {
            throw new HoldException('invalid_qr', \PESM\Support\I18n::text('محتوای QR معتبر نیست.'), 422);
        }
        $publicId = substr($payload, 0, 32);
        $gate = function_exists('mb_substr') ? mb_substr(trim($gate), 0, 100, 'UTF-8') : substr(trim($gate), 0, 100);
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        // Read center before transaction; it is checked AGAIN after the row lock.
        $centerId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT e.center_id FROM {$p}tickets t JOIN {$p}sessions s ON s.id=t.session_id
             JOIN {$p}events e ON e.id=s.event_id WHERE t.public_id=%s", $publicId
        ));
        if (!$centerId) {
            return (new AdmissionGateService())->scan($payload, $operatorId, $gate);
        }
        if (!Capabilities::centerAllowed($centerId, $operatorId)) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('این بلیت به مرکز مجاز شما تعلق ندارد.'), 403);
        }
        if ($wpdb->query('START TRANSACTION') === false) { throw new HoldException('db_unavailable', \PESM\Support\I18n::text('ارتباط پایگاه داده برقرار نشد.'), 503); }
        try {
            $now = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
            // Lock ONLY ticket, not joined session/event: avoids inverse lock order
            // against refund/cancel (session -> hold -> inventory -> ticket).
            $ticket = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}tickets WHERE public_id=%s FOR UPDATE", $publicId
            ));
            $session = $ticket ? $wpdb->get_row($wpdb->prepare(
                "SELECT s.starts_at,s.status,e.center_id,e.status AS event_status FROM {$p}sessions s
                 JOIN {$p}events e ON e.id=s.event_id WHERE s.id=%d", (int) $ticket->session_id
            )) : null;
            if (!$ticket || !$session || (int) $session->center_id !== $centerId ||
                !TicketToken::valid($payload, (string) $ticket->public_id, (int) $ticket->qr_version, wp_salt('secure_auth')) ||
                !hash_equals((string) $ticket->qr_token_hash, hash('sha256', $payload))) {
                throw new HoldException('invalid_qr', \PESM\Support\I18n::text('بلیت قابل اعتبارسنجی نیست.'), 422);
            }
            if ($session->status === 'cancelled' || $session->event_status === 'cancelled') {
                throw new HoldException('event_cancelled', \PESM\Support\I18n::text('این جلسه یا رویداد لغو شده است.'), 409);
            }
            // No browser clock: UTC database clock only; the check-in window is configurable.
            $beforeSeconds = (int) get_option('pesm_entry_before_seconds', 7200);
            $afterSeconds = (int) get_option('pesm_entry_after_seconds', 14400);
            $start = strtotime((string) $session->starts_at . ' UTC');
            $when = strtotime($now . ' UTC');
            if ($when < $start - $beforeSeconds || $when > $start + $afterSeconds) {
                throw new HoldException('invalid_entry_time', \PESM\Support\I18n::text('زمان ورود این بلیت هنوز نرسیده یا پایان یافته است.'), 409);
            }
            if ($ticket->status === 'checked_in') {
                throw new HoldException('already_checked_in', \PESM\Support\I18n::text('ورود این بلیت قبلاً ثبت شده است.'), 409);
            }
            if ($ticket->status !== 'active') {
                throw new HoldException('ticket_inactive', \PESM\Support\I18n::text('این بلیت فعال نیست.'), 409);
            }
            if ($wpdb->insert($p . 'checkins', [
                'ticket_id' => (int) $ticket->id, 'operator_id' => $operatorId,
                'checked_in_at' => $now, 'gate_label' => $gate,
            ]) !== 1 || $wpdb->update($p . 'tickets', ['status' => 'checked_in'], ['id' => (int) $ticket->id, 'status' => 'active']) !== 1) {
                throw new HoldException('checkin_failed', \PESM\Support\I18n::text('ثبت ورود انجام نشد.'), 503);
            }
            Audit::record('ticket_checked_in', 'ticket', (int) $ticket->id, $operatorId);
            if ($wpdb->query('COMMIT') === false) { throw new HoldException('checkin_failed', \PESM\Support\I18n::text('ثبت ورود ناموفق بود.'), 503); }
            return ['checked_in' => true, 'ticket_number' => $ticket->ticket_number, 'checked_in_at_utc' => $now];
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($e instanceof HoldException) { throw $e; }
            throw new HoldException('checkin_failed', \PESM\Support\I18n::text('خطای کنترل ورود رخ داد.'), 503);
        }
    }
}
