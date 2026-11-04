<?php

declare(strict_types=1);

namespace PESM\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Iranian civil date conversion for supported 1800..2199 AD range. No external dependency. */
final class Jalali
{
    /** @return array{0:int,1:int,2:int} */
    public static function fromGregorian(int $year, int $month, int $day): array
    {
        if ($year < 1800 || $year > 2199 || !checkdate($month, $day, $year)) {
            throw new InvalidArgumentException(\PESM\Support\I18n::text('تاریخ میلادی پشتیبانی نمی‌شود.'));
        }
        $offsets = [0,31,59,90,120,151,181,212,243,273,304,334];
        $leapYear = $month > 2 ? $year + 1 : $year;
        $days = 355666 + 365*$year + intdiv($leapYear+3,4)
            - intdiv($leapYear+99,100) + intdiv($leapYear+399,400) + $day + $offsets[$month-1];
        $jy = -1595 + 33*intdiv($days,12053);
        $days %= 12053;
        $jy += 4*intdiv($days,1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days-1,365);
            $days = ($days-1)%365;
        }
        if ($days < 186) {
            return [$jy,1+intdiv($days,31),1+$days%31];
        }
        return [$jy,7+intdiv($days-186,30),1+($days-186)%30];
    }

    public static function formatTehran(string $utc): string
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));
        if (!$dt || $dt->format('Y-m-d H:i:s') !== $utc) {
            throw new InvalidArgumentException(\PESM\Support\I18n::text('زمان UTC نامعتبر است.'));
        }
        $local = $dt->setTimezone(new DateTimeZone('Asia/Tehran'));
        [$y,$m,$d] = self::fromGregorian((int) $local->format('Y'), (int) $local->format('n'), (int) $local->format('j'));
        return sprintf('%04d/%02d/%02d — %s', $y, $m, $d, $local->format('H:i'));
    }
}
