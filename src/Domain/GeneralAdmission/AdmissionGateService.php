<?php

declare(strict_types=1);

namespace PESM\Domain\GeneralAdmission;

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Ticket\TicketToken;
use PESM\Security\Capabilities;
use PESM\Support\Audit;
use Throwable;

/** Atomic online check-in for unnumbered admission; separate unique checkin key. */
final class AdmissionGateService
{
    /** @return array<string,mixed> */
    public function lookupNumber(string $ticketNumber, int $operatorId): array
    {
        $this->assertOperator($operatorId);
        if (!preg_match('/^P-[A-F0-9]{16}$/D', $ticketNumber)) {
            throw new HoldException('invalid_ticket_number', \PESM\Support\I18n::text('شماره بلیت معتبر نیست.'), 422);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT t.ticket_number,t.session_id,t.status,e.center_id FROM {$p}capacity_tickets t
             JOIN {$p}sessions s ON s.id=t.session_id JOIN {$p}events e ON e.id=s.event_id
             WHERE t.ticket_number=%s", $ticketNumber
        ));
        if (!$row || !Capabilities::centerAllowed((int) $row->center_id, $operatorId)) {
            throw new HoldException('ticket_not_found', \PESM\Support\I18n::text('بلیت در محدوده مجاز یافت نشد.'), 404);
        }
        return ['ticket_number'=>(string) $row->ticket_number, 'session_id'=>(int) $row->session_id,
            'status'=>(string) $row->status, 'admission_type'=>'general'];
    }

    /** @return array<string,mixed> */
    public function manualCheckin(string $ticketNumber, int $operatorId, string $gate = ''): array
    {
        $this->lookupNumber($ticketNumber, $operatorId);
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT public_id,qr_version FROM {$p}capacity_tickets WHERE ticket_number=%s", $ticketNumber
        ));
        if (!$ticket) { throw new HoldException('ticket_not_found', \PESM\Support\I18n::text('بلیت یافت نشد.'), 404); }
        return $this->scan(TicketToken::create((string) $ticket->public_id,
            (int) $ticket->qr_version, wp_salt('secure_auth')), $operatorId, $gate);
    }

    /** @return array<string,mixed> */
    public function scan(string $payload, int $operatorId, string $gate = ''): array
    {
        $this->assertOperator($operatorId);
        if (!preg_match('/^[a-f0-9]{32}\.[a-f0-9]{64}$/D', $payload)) {
            throw new HoldException('invalid_qr', \PESM\Support\I18n::text('کد QR معتبر نیست.'), 422);
        }
        $publicId = substr($payload, 0, 32);
        $gate = function_exists('mb_substr') ? mb_substr(trim($gate), 0, 100, 'UTF-8') : substr(trim($gate), 0, 100);
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $centerId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT e.center_id FROM {$p}capacity_tickets t JOIN {$p}sessions s ON s.id=t.session_id
             JOIN {$p}events e ON e.id=s.event_id WHERE t.public_id=%s", $publicId
        ));
        if (!$centerId || !Capabilities::centerAllowed($centerId, $operatorId)) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('این بلیت به مرکز مجاز شما تعلق ندارد.'), 403);
        }
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('پایگاه داده در دسترس نیست.'), 503);
        }
        try {
            $ticket = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}capacity_tickets WHERE public_id=%s FOR UPDATE", $publicId
            ));
            $session = $ticket ? $wpdb->get_row($wpdb->prepare(
                "SELECT s.starts_at,s.status,e.center_id,e.status AS event_status FROM {$p}sessions s
                 JOIN {$p}events e ON e.id=s.event_id WHERE s.id=%d", (int) $ticket->session_id
            )) : null;
            if (!$ticket || !$session || (int) $session->center_id !== $centerId ||
                !TicketToken::valid($payload, (string) $ticket->public_id, (int) $ticket->qr_version, wp_salt('secure_auth')) ||
                !hash_equals((string) $ticket->qr_token_hash, hash('sha256', $payload))) {
                throw new HoldException('invalid_qr', \PESM\Support\I18n::text('این کد QR قابل اعتبارسنجی نیست.'), 422);
            }
            if ($session->status === 'cancelled' || $session->event_status === 'cancelled') {
                throw new HoldException('event_cancelled', \PESM\Support\I18n::text('جلسه یا رویداد لغو شده است.'), 409);
            }
            $now = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
            $start = strtotime((string) $session->starts_at . ' UTC');
            $current = strtotime($now . ' UTC');
            $before = max(0, (int) get_option('pesm_entry_before_seconds', 7200));
            $after = max(0, (int) get_option('pesm_entry_after_seconds', 14400));
            if ($current < $start - $before || $current > $start + $after) {
                throw new HoldException('invalid_entry_time', \PESM\Support\I18n::text('زمان ورود هنوز آغاز نشده یا پایان یافته است.'), 409);
            }
            if ($ticket->status === 'checked_in') {
                throw new HoldException('already_checked_in', \PESM\Support\I18n::text('ورود این بلیت قبلاً ثبت شده است.'), 409);
            }
            if ($ticket->status !== 'active') {
                throw new HoldException('ticket_inactive', \PESM\Support\I18n::text('این بلیت فعال نیست.'), 409);
            }
            if ($wpdb->insert($p . 'capacity_checkins', [
                'ticket_id'=>(int) $ticket->id, 'operator_id'=>$operatorId, 'checked_in_at'=>$now, 'gate_label'=>$gate,
            ]) !== 1 || $wpdb->update($p . 'capacity_tickets', ['status'=>'checked_in'],
                ['id'=>(int) $ticket->id,'status'=>'active']) !== 1) {
                throw new HoldException('checkin_failed', \PESM\Support\I18n::text('ثبت ورود ناموفق بود.'), 503);
            }
            Audit::record('general_ticket_checked_in', 'capacity_ticket', (int) $ticket->id, $operatorId);
            if ($wpdb->query('COMMIT') === false) { throw new HoldException('checkin_failed', \PESM\Support\I18n::text('تراکنش ثبت نشد.'), 503); }
            return ['checked_in'=>true, 'ticket_number'=>(string) $ticket->ticket_number, 'checked_in_at_utc'=>$now];
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($e instanceof HoldException) { throw $e; }
            throw new HoldException('checkin_failed', \PESM\Support\I18n::text('خطای کنترل ورود رخ داد.'), 503);
        }
    }

    private function assertOperator(int $operatorId): void
    {
        if ($operatorId < 1 || !user_can($operatorId, 'pesm_scan_tickets')) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('مجوز کنترل ورود ندارید.'), 403);
        }
    }
}
