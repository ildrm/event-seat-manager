<?php

declare(strict_types=1);

/** Exercises issuance behavior; this fake does not establish InnoDB isolation. */
namespace PESM\Support {
require_once __DIR__ . '/../src/Support/I18n.php';
    final class Audit { public static function record(mixed ...$args): void {} }
}

namespace {
require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Domain/Checkout/CheckoutPolicy.php';
require __DIR__ . '/../src/Domain/Checkout/AttendeeManifest.php';
require __DIR__ . '/../src/Domain/Ticket/TicketToken.php';
require __DIR__ . '/../src/Domain/Ticket/TicketService.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Ticket\TicketService;

function get_option(string $key, mixed $default = null): mixed { return $key === 'pesm_schema_version' ? 18 : $default; }
function wp_salt(string $key): string { return 'test-only-unusable-secret-for-qr-signing'; }
function wc_get_order(int $id): object|false { return $id === 99 ? $GLOBALS['order'] : false; }

final class SeatedOrder {
    public bool $paid = true;
    public bool $datePaid = true;
    public string $status = 'processing';
    public string $total = '2.00';
    public string $currency = 'USD';
    public array $meta = [
        '_pesm_is_ticket_order' => 'yes', '_pesm_hold_id' => 12, '_pesm_session_id' => 7,
        '_pesm_unit_price_minor' => 100, '_pesm_total_minor' => 200,
        '_pesm_price_decimals' => 2, '_pesm_currency' => 'USD',
    ];
    public function get_meta(string $key): mixed { return $this->meta[$key] ?? ''; }
    public function is_paid(): bool { return $this->paid; }
    public function get_date_paid(): ?DateTimeImmutable { return $this->datePaid ? new DateTimeImmutable() : null; }
    public function get_status(): string { return $this->status; }
    public function get_total(): string { return $this->total; }
    public function get_currency(): string { return $this->currency; }
    public function get_billing_first_name(): string { return 'Test'; }
    public function get_billing_last_name(): string { return 'Attendee'; }
}

final class SeatedPaymentDb {
    public string $prefix = 'wp_';
    public string $now = '2026-11-04 10:00:00';
    public bool $waitPastExpiry = false;
    public array $queries = [];
    public array $session = ['id' => 7, 'mode' => 'seated', 'unit_price_minor' => 100,
        'status' => 'published', 'event_status' => 'published'];
    public array $hold = ['id' => 12, 'session_id' => 7, 'order_id' => 99,
        'status' => 'payment_pending', 'expires_at' => '2026-11-04 10:01:00'];
    public array $link = ['id' => 9, 'status' => 'pending'];
    public array $seats = [
        ['seat_id' => 1, 'hold_id' => 12, 'status' => 'payment_pending', 'expires_at' => '2026-11-04 10:01:00'],
        ['seat_id' => 2, 'hold_id' => 12, 'status' => 'payment_pending', 'expires_at' => '2026-11-04 10:01:00'],
    ];
    public array $tickets = [];
    public int $failTicket = 0;
    private ?string $snapshot = null;

    public function prepare(string $sql, mixed ...$args): string { return $sql; }
    public function query(string $sql): int|false {
        $this->queries[] = $sql;
        if ($sql === 'START TRANSACTION') {
            $this->snapshot = serialize([$this->hold, $this->link, $this->seats, $this->tickets]);
        } elseif ($sql === 'ROLLBACK' && $this->snapshot !== null) {
            [$this->hold, $this->link, $this->seats, $this->tickets] = unserialize($this->snapshot);
            $this->snapshot = null;
        } elseif ($sql === 'COMMIT') { $this->snapshot = null; }
        return 1;
    }
    public function get_var(string $sql): mixed {
        $this->queries[] = $sql;
        if ($sql === 'SELECT UTC_TIMESTAMP()') { return $this->now; }
        throw new RuntimeException('Unexpected SQL: ' . $sql);
    }
    public function get_row(string $sql): object {
        $this->queries[] = $sql;
        if (str_contains($sql, 'FROM wp_pesm_sessions')) {
            if ($this->waitPastExpiry) { $this->now = '2026-11-04 10:02:00'; }
            return (object) $this->session;
        }
        if (str_contains($sql, 'FROM wp_pesm_holds')) { return (object) $this->hold; }
        if (str_contains($sql, 'FROM wp_pesm_order_links')) { return (object) $this->link; }
        throw new RuntimeException('Unexpected SQL: ' . $sql);
    }
    public function get_col(string $sql): array { return [1, 2]; }
    public function get_results(string $sql): array { return array_map(static fn(array $seat): object => (object) $seat, $this->seats); }
    public function insert(string $table, array $data): int|false {
        if ($this->failTicket === count($this->tickets) + 1) { return false; }
        $this->tickets[] = $data;
        return 1;
    }
    public function update(string $table, array $data, array $where): int {
        if ($table === 'wp_pesm_holds') { $this->hold = array_replace($this->hold, $data); }
        elseif ($table === 'wp_pesm_order_links') { $this->link = array_replace($this->link, $data); }
        elseif ($table === 'wp_pesm_session_seats') {
            foreach ($this->seats as &$seat) {
                if ($seat['seat_id'] === $where['seat_id']) { $seat = array_replace($seat, $data); }
            }
        } else { throw new RuntimeException('Unexpected update: ' . $table); }
        return 1;
    }
}

$n = 0;
function check(bool $ok, string $message): void {
    global $n;
    if (!$ok) { throw new RuntimeException($message); }
    ++$n;
}
function denied(callable $callback, string $code): void {
    try { $callback(); }
    catch (HoldException $error) { check($error->errorCode === $code, 'Expected ' . $code . ', received ' . $error->errorCode); return; }
    throw new RuntimeException('Expected error: ' . $code);
}
function resetPayment(): void { $GLOBALS['order'] = new SeatedOrder(); $GLOBALS['wpdb'] = new SeatedPaymentDb(); }

$service = new TicketService();
resetPayment();
$order->paid = false;
denied(fn() => $service->confirmOrder(99), 'payment_unverified');
resetPayment();
$order->datePaid = false;
denied(fn() => $service->confirmOrder(99), 'payment_unverified');
resetPayment();
$order->status = 'cancelled';
denied(fn() => $service->confirmOrder(99, true), 'payment_unverified');
$order->total = '0';
denied(fn() => $service->confirmOrder(99, true), 'order_inactive');

foreach (['0.01', '1.99', '2.01'] as $tampered) {
    resetPayment(); $order->total = $tampered;
    denied(fn() => $service->confirmOrder(99), 'price_mismatch');
    check($wpdb->tickets === [] && $wpdb->hold['status'] === 'payment_pending', 'Wrong total cannot consume inventory');
}
resetPayment(); $order->currency = 'EUR';
denied(fn() => $service->confirmOrder(99), 'price_mismatch');
resetPayment(); unset($order->meta['_pesm_total_minor']);
denied(fn() => $service->confirmOrder(99), 'price_mismatch');
resetPayment(); $wpdb->hold['order_id'] = 100;
denied(fn() => $service->confirmOrder(99), 'invalid_order');
resetPayment(); $wpdb->waitPastExpiry = true;
check($service->confirmOrder(99) === 'review_required', 'Lock wait cannot extend payment deadline');
check($wpdb->tickets === [] && $wpdb->link['status'] === 'review_required', 'Expired callback never issues tickets');

resetPayment(); $wpdb->failTicket = 2;
denied(fn() => $service->confirmOrder(99), 'ticket_write_failed');
check($wpdb->tickets === [] && $wpdb->seats[0]['status'] === 'payment_pending', 'Failed partial issuance rolls back');
resetPayment(); $wpdb->session['unit_price_minor'] = 200;
check($service->confirmOrder(99) === 'issued', 'Original price snapshot survives later session price changes');
check(count($wpdb->tickets) === 2 && $wpdb->hold['status'] === 'sold', 'Verified payment issues exactly two seats');
check($service->confirmOrder(99) === 'already_issued' && count($wpdb->tickets) === 2, 'Callback replay is idempotent');

resetPayment();
$order->total = '0';
$order->meta['_pesm_total_minor'] = 0;
$order->meta['_pesm_discount_minor'] = 200;
check($service->confirmOrder(99, true) === 'issued', 'A fully discounted checkout can issue zero-total tickets');
resetPayment();
$order->total = '0'; $order->meta['_pesm_total_minor'] = 0;
denied(fn() => $service->confirmOrder(99, true), 'price_mismatch');
check(!\PESM\Domain\Checkout\CheckoutPolicy::payable(PHP_INT_MAX, 20, '2026-11-04', '2026-11-05'), 'Overflow price rejected before checkout');
echo "PASS: {$n} seated payment/expiry/rollback assertions (fake DB).\n";
}
