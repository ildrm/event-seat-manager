<?php

declare(strict_types=1);

namespace PESM\Domain\Event;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class SetupInput
{
    public const MODES = ['seated', 'general', 'allocated'];
    public const MAX_SESSIONS = 50;

    public static function time(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('Asia/Tehran'));
        if (!$time || $time->format('Y-m-d\TH:i') !== $value) {
            throw new InvalidArgumentException('invalid_time');
        }
        return $time->setTimezone(new DateTimeZone('UTC'));
    }

    public static function window(array $row, string $now): array
    {
        $start = self::time($row['start']);
        if ($row['custom']) {
            $opens = self::time($row['open'])->format('Y-m-d H:i:s');
            $closes = self::time($row['close'])->format('Y-m-d H:i:s');
        } else {
            $opens = $now;
            $closes = $start->modify('-1 minute')->format('Y-m-d H:i:s');
        }
        $starts = $start->format('Y-m-d H:i:s');
        if ($opens >= $closes || $closes >= $starts || $closes <= $now) {
            throw new InvalidArgumentException('invalid_window');
        }
        return [$starts, $opens, $closes];
    }

    public static function number(string $value): string
    {
        return strtr(trim($value), [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',"\u{066B}"=>'.',
        ]);
    }

    public static function price(string $value, int $decimals): int
    {
        $value = self::number($value);
        if ($decimals < 0 || $decimals > 6 || !preg_match('/^([0-9]{1,10})(?:\.([0-9]+))?$/D', $value, $parts)
            || strlen($parts[2] ?? '') > $decimals) {
            throw new InvalidArgumentException('invalid_price');
        }
        $minor = (int) ($parts[1] . str_pad($parts[2] ?? '', $decimals, '0'));
        if ($minor < 1 || $minor > 1000000000) {
            throw new InvalidArgumentException('invalid_price');
        }
        return $minor;
    }

    public static function blank(array $location): array
    {
        return ['location'=>$location, 'start'=>'', 'open'=>'', 'close'=>'', 'kind'=>'', 'amount'=>'',
            'custom'=>false, 'adjacent'=>false, 'gap'=>false];
    }
}
