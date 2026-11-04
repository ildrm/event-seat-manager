<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

require_once __DIR__ . '/../src/Domain/Booking/HoldException.php';
require_once __DIR__ . '/../src/Domain/Ticket/RefundPolicy.php';
require_once __DIR__ . '/../src/Domain/Ticket/RefundReviewService.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Ticket\RefundPolicy as Policy;
use PESM\Domain\Ticket\RefundReviewService;

$assertions = 0;
function expect(bool $ok, string $name): void {
    global $assertions;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $name); }
    ++$assertions;
}
function reject(callable $callback, string $code): void {
    try { $callback(); }
    catch (HoldException $e) { expect($e->errorCode === $code, $code); return; }
    throw new RuntimeException('Expected: ' . $code);
}
$k = str_repeat('a', 32);
Policy::requestKey($k); expect(true, 'valid key');
reject(fn() => Policy::requestKey('abc'), 'invalid_request_key');
reject(fn() => Policy::requestKey(str_repeat('g',32)), 'invalid_request_key');
reject(fn() => Policy::requestKey(str_repeat('0',129)), 'invalid_request_key');
expect(Policy::reason('  دلیل    استرداد  بلیت  ') === 'دلیل استرداد بلیت', 'normalize reason');
reject(fn() => Policy::reason('short'), 'invalid_reason');
reject(fn() => Policy::reason(str_repeat('a', 501)), 'invalid_reason');
Policy::ticketEligible('active', false, '2026-11-04 00:00:00', '2026-11-03 23:00:00'); expect(true, 'valid ticket');
reject(fn() => Policy::ticketEligible('refund_pending',false,'2026-11-04 00:00:00','2026-11-03 23:00:00'), 'ticket_not_refundable');
reject(fn() => Policy::ticketEligible('checked_in',true,'2026-11-04 00:00:00','2026-11-03 23:00:00'), 'ticket_not_refundable');
reject(fn() => Policy::ticketEligible('active',true,'2026-11-04 00:00:00','2026-11-03 23:00:00'), 'ticket_not_refundable');
reject(fn() => Policy::ticketEligible('active',false,'2026-11-03 23:00:00','2026-11-03 23:00:00'), 'event_started');
expect(Policy::ticketAmount('1500.35','0.00',1,2,0) === 150035, 'exact cents');
expect(Policy::ticketAmount('1000','0',1,0,0) === 1000, 'zero-decimal currency');
expect(Policy::ticketAmount('0.01','0',1,2,0) === 1, 'minimum paid price');
reject(fn() => Policy::ticketAmount('0.00','0',1,2,0), 'refund_manual_review');
reject(fn() => Policy::ticketAmount('1.00','0.05',1,2,0), 'refund_tax_unsupported');
reject(fn() => Policy::ticketAmount('1.00','0',2,2,0), 'refund_manual_review');
reject(fn() => Policy::ticketAmount('1.00','0',1,2,1), 'refund_manual_review');
reject(fn() => Policy::ticketAmount('1.009','0',1,2,0), 'refund_manual_review');
reject(fn() => Policy::ticketAmount('-1','0',1,2,0), 'refund_manual_review');
expect(Policy::matchingRefund([['item_id'=>11,'quantity'=>1,'total_minor'=>4200]], 11, 4200), 'exact item refund');
expect(!Policy::matchingRefund([], 11, 4200), 'no refund');
expect(!Policy::matchingRefund([['item_id'=>12,'quantity'=>1,'total_minor'=>4200]],11,4200), 'wrong item');
expect(!Policy::matchingRefund([['item_id'=>11,'quantity'=>1,'total_minor'=>4100]],11,4200), 'partial amount');
expect(!Policy::matchingRefund([['item_id'=>11,'quantity'=>2,'total_minor'=>4200]],11,4200), 'wrong count');
expect(!Policy::matchingRefund([['item_id'=>11,'quantity'=>1,'total_minor'=>4200],['item_id'=>11,'quantity'=>1,'total_minor'=>4200]],11,4200), 'duplicate refund line');
expect(Policy::matchingRefund([['item_id'=>12,'quantity'=>1,'total_minor'=>100],['item_id'=>11,'quantity'=>1,'total_minor'=>4200]],11,4200), 'other lines allowed');

function wc_get_price_decimals(): int { return 2; }
final class RefundLineStub {
    public function __construct(private int $itemId, private int $qty, private string $total) {}
    public function get_meta(string $key): int { return $this->itemId; }
    public function get_quantity(): int { return $this->qty; }
    public function get_total(): string { return $this->total; }
}
final class RefundStub {
    public function __construct(private int $id, private array $items) {}
    public function get_items(string $type): array { return $this->items; }
    public function get_id(): int { return $this->id; }
}
final class RefundOrderStub {
    public function __construct(private string $currency, private array $refunds) {}
    public function get_currency(): string { return $this->currency; }
    public function get_refunds(): array { return $this->refunds; }
}
$method = new ReflectionMethod(RefundReviewService::class, 'confirmedRefundId');
$service = new RefundReviewService();
$order = new RefundOrderStub('USD',[new RefundStub(19, [new RefundLineStub(11, -1, '-42.00')])]);
expect($method->invoke($service, $order, 11, 4200, 'USD') === 19, 'Woo refund references exact item');
expect($method->invoke($service, $order, 11, 4100, 'USD') === 0, 'wrong amount not verified');
expect($method->invoke($service, $order, 11, 4200, 'EUR') === 0, 'wrong currency not verified');
expect($method->invoke($service, new RefundOrderStub('USD', []), 11, 4200, 'USD') === 0, 'missing Woo refund not verified');
echo "PASS: {$assertions} refund policy and Woo refund matching checks (no gateway or InnoDB).\n";
