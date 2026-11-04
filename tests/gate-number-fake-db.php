<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

/** Behavioral test double: no actual DB isolation, WordPress or camera. */
require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Domain/Ticket/TicketToken.php';
require __DIR__ . '/../src/Security/Capabilities.php';
require __DIR__ . '/../src/Support/Audit.php';
require __DIR__ . '/../src/Domain/Gate/CheckinService.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Gate\CheckinService;
use PESM\Domain\Ticket\TicketToken;

function get_option(string $key, mixed $default = false): mixed {
    return match ($key) { 'pesm_schema_version' => 5, 'pesm_entry_before_seconds' => 7200,
        'pesm_entry_after_seconds' => 14400, default => $default };
}
function user_can(int $user, string $cap): bool { return $user === 2 && $cap === 'pesm_scan_tickets'; }
function wp_salt(string $type): string { return str_repeat('S', 40); }
class GateFakeDatabase {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public array $audit = [];
    public array $checkins = [];
    public array $ticket;
    public function __construct() {
        $this->ticket = [
            'id'=>25, 'public_id'=>str_repeat('a',32), 'qr_version'=>1,
            'ticket_number'=>'P-ABCDEF0123456789', 'session_id'=>8,
            'status'=>'active', 'center_id'=>1,
            'qr_token_hash'=>hash('sha256', TicketToken::create(str_repeat('a',32),1,wp_salt('secure_auth'))),
        ];
    }
    public function prepare(string $sql, mixed ...$args): string {
        $args = array_map(fn($a) => is_int($a) ? $a : "'" . str_replace("'","''",(string) $a) . "'", $args);
        return vsprintf($sql, $args);
    }
    public function get_var(string $sql): mixed {
        if ($sql === 'SELECT UTC_TIMESTAMP()') return '2026-11-02 12:00:00';
        if (str_contains($sql, 'FROM wp_pesm_center_users')) return str_contains($sql, 'center_id = 1') ? 1 : null;
        if (str_contains($sql, 'SELECT e.center_id') && str_contains($sql, "'" . $this->ticket['public_id'] . "'")) return $this->ticket['center_id'];
        throw new RuntimeException('Unexpected get_var query: ' . $sql);
    }
    public function get_row(string $sql): ?object {
        if (str_contains($sql, 'FROM wp_pesm_tickets t JOIN wp_pesm_sessions') &&
            str_contains($sql, "'" . $this->ticket['ticket_number'] . "'")) return (object) $this->ticket;
        if (str_contains($sql, 'FROM wp_pesm_tickets WHERE public_id=') &&
            str_contains($sql, "'" . $this->ticket['public_id'] . "'")) return (object) $this->ticket;
        if (str_contains($sql, 'SELECT public_id,qr_version FROM wp_pesm_tickets') &&
            str_contains($sql, "'" . $this->ticket['ticket_number'] . "'")) return (object) $this->ticket;
        if (str_contains($sql, 'SELECT s.starts_at') && str_contains($sql, 'FROM wp_pesm_sessions s')) {
            return (object) ['starts_at'=>'2026-11-02 12:00:00', 'status'=>'published',
                'event_status'=>'published', 'center_id'=>1];
        }
        throw new RuntimeException('Unexpected get_row query: ' . $sql);
    }
    public function insert(string $table, array $data): int {
        if ($table === 'wp_pesm_checkins') { $this->checkins[] = $data; return 1; }
        if ($table === 'wp_pesm_audit_logs') { $this->audit[] = $data; return 1; }
        throw new RuntimeException('Unexpected insert: ' . $table);
    }
    public function update(string $table, array $data, array $where): int {
        if ($table === 'wp_pesm_tickets' && $where['id'] === 25 && $where['status'] === 'active' &&
            $this->ticket['status'] === 'active') {
            $this->ticket['status'] = $data['status']; return 1;
        }
        return 0;
    }
    public function query(string $sql): int|false {
        if (in_array($sql, ['START TRANSACTION','COMMIT','ROLLBACK'],true)) return 1;
        throw new RuntimeException('Unexpected query: ' . $sql);
    }
}
$wpdb = new GateFakeDatabase();
$svc = new CheckinService();
$count = 0;
function verify(bool $condition, string $description): void {
    global $count;
    if (!$condition) throw new RuntimeException('FAIL: ' . $description);
    ++$count;
}
function failWith(callable $fn, string $code): void {
    try { $fn(); } catch (HoldException $e) {
        verify($e->errorCode === $code, 'expected error ' . $code); return;
    }
    throw new RuntimeException('Expected failure: ' . $code);
}
failWith(fn() => $svc->lookupNumber('P-123',2), 'invalid_ticket_number');
failWith(fn() => $svc->lookupNumber('P-ABCDEF0123456789',3), 'permission_denied');
$info = $svc->lookupNumber('P-ABCDEF0123456789',2);
verify(!array_key_exists('public_id', $info) && !array_key_exists('attendee_name', $info), 'lookup excludes PII and QR');
verify($info['session_id'] === 8 && $info['status'] === 'active', 'allowed center lookup');
$wpdb->ticket['center_id'] = 2;
failWith(fn() => $svc->lookupNumber('P-ABCDEF0123456789',2), 'ticket_not_found');
$wpdb->ticket['center_id'] = 1;
$result = $svc->manualCheckin('P-ABCDEF0123456789', 2, 'شمال');
verify($result['checked_in'] === true && $wpdb->ticket['status'] === 'checked_in', 'manual checkin succeeds');
verify(count($wpdb->checkins) === 1 && count($wpdb->audit) === 1, 'unique checkin and audit');
failWith(fn() => $svc->manualCheckin('P-ABCDEF0123456789',2,'شمال'), 'already_checked_in');
verify(count($wpdb->checkins) === 1, 'duplicate manual entry creates no new entry');
echo "PASS: {$count} gate-number fake-DB checks; no real WordPress, InnoDB or multi-scanner tests.\n";
