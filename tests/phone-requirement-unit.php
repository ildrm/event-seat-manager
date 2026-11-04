<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Domain/Checkout/CheckoutPolicy.php';

use PESM\Domain\Checkout\CheckoutPolicy;
use PESM\Domain\Booking\HoldException;

$checks=0;
foreach (['09123456789','9123456789','+989123456789','00989123456789','۹۱۲۳۴۵۶۷۸۹','٠٩١٢٣٤٥٦٧٨٩'] as $phone) {
    if (CheckoutPolicy::normalizePhone($phone)!=='09123456789') throw new RuntimeException('Normalizing '.$phone);
    ++$checks;
}
foreach (['','not-a-phone','user@example.com','1234'] as $invalid) {
    try { CheckoutPolicy::normalizePhone($invalid);throw new RuntimeException('Missing/invalid phone accepted'); }
    catch (HoldException $e) { if ($e->errorCode!=='invalid_phone') throw $e; ++$checks; }
}
if (!CheckoutPolicy::phoneLimitAllows(1,1,2)) throw new RuntimeException('Limit allows false negative');++$checks;
if (CheckoutPolicy::phoneLimitAllows(2,1,2)) throw new RuntimeException('Phone limit bypass');++$checks;
// Actual required phone inputs in both booking variants are checked by frontend-locale-unit.php.
echo "PASS: {$checks} guest phone requirement checks (pure PHP/static, not browser).\n";
