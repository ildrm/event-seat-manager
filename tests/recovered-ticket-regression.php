<?php
/** Regression: a recovered guest token must load the order link after token validation. */
declare(strict_types=1);
namespace PESM\Support {
require_once __DIR__ . '/../src/Support/I18n.php'; class Audit { public static function record(...$args): void {} } }
namespace {
const ARRAY_A = "ARRAY_A";
require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Domain/Ticket/TicketToken.php';
require __DIR__ . '/../src/Domain/Ticket/TicketService.php';
function get_option(string $key, mixed $fallback=null): mixed { return $key === 'pesm_schema_version' ? 4 : $fallback; }
function wp_salt(string $purpose): string { return 'unit-test-secret'; }
function wc_get_order(int $id): object { return new class {
    public function get_meta(string $key): string { return 'yes'; }
    public function get_status(): string { return 'completed'; }
    public function get_currency(): string { return 'IRR'; }
    public function get_total(): string { return '0'; }
    public function get_discount_total(): string { return '0'; }
    public function get_total_tax(): string { return '0'; }
    public function get_total_refunded(): string { return '0'; }
    public function get_payment_method_title(): string { return ''; }
    public function get_transaction_id(): string { return ''; }
    public function get_date_created(): mixed { return null; }
}; }
class FakeDb {
    public string $prefix = 'wp_';
    public int $linkQueries = 0;
    public bool $tokenValid = true;
    public function prepare(string $sql, mixed ...$args): string { return $sql; }
    public function get_row(string $sql): ?object {
        if (str_contains($sql, 'guest_token_hash')) { return null; }
        if (str_contains($sql, 'order_links')) { $this->linkQueries++; return (object)['status'=>'issued','id'=>8]; }
        return null;
    }
    public function get_var(string $sql): mixed { return $this->tokenValid ? 1 : null; }
    public function get_results(string $sql, mixed $format): array { return []; }
}
$wpdb = new FakeDb();
$result = (new \PESM\Domain\Ticket\TicketService())->forGuest(52, str_repeat('a',64));
if ($result['fulfillment_status'] !== 'issued' || $wpdb->linkQueries !== 1) {
    throw new \RuntimeException('Recovered token failed to load linked order.');
}
$wpdb->tokenValid = false;
try { (new \PESM\Domain\Ticket\TicketService())->forGuest(52, str_repeat('b',64)); }
catch (\PESM\Domain\Booking\HoldException $e) {
    if ($e->errorCode === 'permission_denied' && $wpdb->linkQueries === 1) {
        echo "PASS: recovered guest token and rejected-token isolation.\n";
        exit(0);
    }
}
throw new \RuntimeException('Invalid token was not denied.');
}
