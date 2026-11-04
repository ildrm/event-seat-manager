<?php

declare(strict_types=1);

namespace PESM\Domain\Checkout;

use PESM\Domain\Booking\HoldException;

/** Immutable currency/precision and integer arithmetic shared by all issuance paths. */
final class FinancialSnapshot
{
    public static function integer(mixed $value, int $maximum = 20000000000000): int
    {
        if ((!is_int($value) && !is_string($value)) ||
            !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value) ||
            strlen((string) $value) > strlen((string) $maximum) ||
            (int) $value > $maximum) {
            self::mismatch();
        }
        return (int) $value;
    }

    /** Parse Woo's decimal string without floating-point rounding or current shop settings. */
    public static function minor(mixed $amount, int $decimals): int
    {
        if ($decimals < 0 || $decimals > 6 || !is_scalar($amount) ||
            !preg_match('/^([0-9]+)(?:\.([0-9]+))?$/D', (string) $amount, $match)) {
            self::mismatch();
        }
        $fraction = rtrim($match[2] ?? '', '0');
        if (strlen($fraction) > $decimals) { self::mismatch(); }
        $digits = ltrim($match[1] . str_pad($fraction, $decimals, '0'), '0');
        return self::integer($digits === '' ? '0' : $digits);
    }

    public static function validate(object $order, int $total, string $currency, int $decimals, bool $free): void
    {
        if ($currency === '' || !preg_match('/^[A-Z0-9_-]{2,12}$/D', $currency) ||
            !method_exists($order, 'get_currency') ||
            !hash_equals($currency, (string) $order->get_currency()) ||
            $decimals < 0 || $decimals > 6 ||
            self::minor($order->get_total(), $decimals) !== $total ||
            ($free ? $total !== 0 : $total <= 0)) {
            self::mismatch();
        }
    }

    public static function uniform(object $order, int $quantity, bool $free): array
    {
        $unit = self::integer($order->get_meta('_pesm_unit_price_minor'), 100000000000);
        $total = self::integer($order->get_meta('_pesm_total_minor'));
        $precision = self::integer($order->get_meta('_pesm_price_decimals'), 6);
        $rawDiscount = $order->get_meta('_pesm_discount_minor');
        $discount = self::integer($rawDiscount === '' || $rawDiscount === null ? 0 : $rawDiscount);
        if ($quantity < 1 || $quantity > 20 || $discount > $unit * $quantity ||
            $total !== $unit * $quantity - $discount) { self::mismatch(); }
        self::validate($order, $total, (string) $order->get_meta('_pesm_currency'), $precision, $free);
        return ['unit_minor' => $unit, 'total_minor' => $total, 'discount_minor' => $discount,
            'price_decimals' => $precision, 'currency' => (string) $order->get_meta('_pesm_currency')];
    }

    private static function mismatch(): never
    {
        throw new HoldException('price_mismatch', \PESM\Support\I18n::text('مبلغ تأییدشده با سفارش ثبت‌شده تطابق ندارد.'), 409);
    }
}
