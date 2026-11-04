<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

/** PHP-only assertions: cannot establish WooCommerce or InnoDB compatibility. */
require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Domain/Checkout/CheckoutPolicy.php';
require __DIR__ . '/../src/Domain/Ticket/TicketToken.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Checkout\CheckoutPolicy as C;
use PESM\Domain\Ticket\TicketToken as T;

$n = 0;
function eq(mixed $a, mixed $b, string $label): void {
    global $n;
    if ($a !== $b) { throw new RuntimeException($label . ': ' . json_encode([$a, $b])); }
    ++$n;
}
function error(callable $fn, string $expected): void {
    global $n;
    try { $fn(); }
    catch (HoldException $e) { eq($e->errorCode, $expected, 'expected error'); return; }
    throw new RuntimeException('Expected error ' . $expected);
}
foreach (['09123456789', '+989123456789', '00989123456789', '989123456789', '9123456789',
    '۰۹۱۲۳۴۵۶۷۸۹', '٠٩١٢٣٤٥٦٧٨٩', '09 123 456 789', '(0912) 345-6789'] as $phone) {
    eq(C::normalizePhone($phone), '09123456789', 'phone canonical');
}
foreach (['', '123', '09123456', '+449123456789', 'not-a-phone', '0912345678901'] as $phone) {
    error(fn() => C::normalizePhone($phone), 'invalid_phone');
}
eq(C::normalizeName("  علی   رضا  "), 'علی رضا', 'name normalization');
error(fn() => C::normalizeName(''), 'invalid_name');
error(fn() => C::normalizeName(str_repeat('A',91)), 'invalid_name');
eq(C::amount(0, 0), '0', 'zero rial');
eq(C::amount(123456, 0), '123456', 'rial');
eq(C::amount(99, 2), '0.99', 'subunit');
eq(C::amount(123456, 2), '1234.56', 'dollars');
eq(C::amount(1, 3), '0.001', 'three decimals');
error(fn() => C::amount(-1, 2), 'invalid_price');
error(fn() => C::amount(0, 7), 'invalid_price');
eq(C::phoneLimitAllows(19, 1, 20), true, 'limit allows last ticket');
eq(C::phoneLimitAllows(20, 1, 20), false, 'limit blocks extra');
eq(C::phoneLimitAllows(0, 20, 20), true, 'full single order');
eq(C::phoneLimitAllows(0, 21, 20), false, 'too many');
eq(C::payable(0, 1, '2026-11-02 10:00:00', '2026-11-02 10:01:00'), true, 'free allowed');
eq(C::payable(100, 20, '2026-11-02 10:00:00', '2026-11-02 10:01:00'), true, 'max qty');
eq(C::payable(100, 21, '2026-11-02 10:00:00', '2026-11-02 10:01:00'), false, 'over max qty');
eq(C::payable(100, 1, '2026-11-02 10:00:00', '2026-11-02 10:00:00'), false, 'expiry boundary');
$secret = str_repeat('S', 64);
$public = bin2hex(random_bytes(16));
$qr = T::create($public, 1, $secret);
eq(strlen($qr), 97, 'QR payload size');
eq(T::valid($qr, $public, 1, $secret), true, 'valid token');
eq(T::valid($qr, $public, 2, $secret), false, 'rotation invalidates');
eq(T::valid($qr, $public, 1, str_repeat('X',64)), false, 'wrong secret');
eq(T::valid(str_replace('a','b',$qr), $public, 1, $secret), false, 'tampering rejected');
try { T::create('unsafe-id', 1, $secret); throw new RuntimeException('Accepted bad public id'); }
catch (InvalidArgumentException $e) { ++$n; }
try { T::create($public, 0, $secret); throw new RuntimeException('Accepted zero QR version'); }
catch (InvalidArgumentException $e) { ++$n; }
echo "PASS: {$n} checkout/ticket pure-PHP assertions.\n";
