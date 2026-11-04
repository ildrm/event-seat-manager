<?php

declare(strict_types=1);

namespace PESM\Domain\Checkout;

use PESM\Domain\Booking\HoldException;

/**
 * Deterministic, server-validated attendee snapshots. No new WP user is created.
 * The WooCommerce order meta is an immutable-in-our-code snapshot; ticket issue
 * reads that snapshot, not a later browser payload. The buyer remains separate.
 */
final class AttendeeManifest
{
    /** @param mixed $submitted @param list<int> $seatIds @return list<array{seat_id:int,first_name:string,last_name:string}> */
    public static function seats(mixed $submitted, array $seatIds, string $buyerFirst, string $buyerLast): array
    {
        $seatIds = array_map('intval', $seatIds);
        sort($seatIds, SORT_NUMERIC);
        if (!$seatIds || count($seatIds) > 20 || count(array_unique($seatIds)) !== count($seatIds)) {
            throw self::invalid();
        }
        if ($submitted === null || $submitted === '') {
            return array_map(static fn(int $id): array => [
                'seat_id' => $id, 'first_name' => $buyerFirst, 'last_name' => $buyerLast,
            ], $seatIds);
        }
        self::checkList($submitted, count($seatIds));
        $bySeat = [];
        foreach ($submitted as $item) {
            if (!is_array($item) || !isset($item['seat_id']) || !is_int($item['seat_id'])) {
                throw self::invalid();
            }
            $seatId = $item['seat_id'];
            if ($seatId < 1 || isset($bySeat[$seatId]) || !in_array($seatId, $seatIds, true)) {
                throw self::invalid();
            }
            $bySeat[$seatId] = self::names($item);
        }
        $result = [];
        foreach ($seatIds as $id) {
            if (!isset($bySeat[$id])) { throw self::invalid(); }
            $result[] = ['seat_id' => $id] + $bySeat[$id];
        }
        return $result;
    }

    /** @param mixed $submitted @return list<array{admission_index:int,first_name:string,last_name:string}> */
    public static function general(mixed $submitted, int $quantity, string $buyerFirst, string $buyerLast): array
    {
        if ($quantity < 1 || $quantity > 20) { throw self::invalid(); }
        if ($submitted === null || $submitted === '') {
            $submitted = array_fill(0, $quantity, ['first_name'=>$buyerFirst, 'last_name'=>$buyerLast]);
        }
        self::checkList($submitted, $quantity);
        $result = [];
        foreach ($submitted as $i => $item) {
            if (!is_array($item) || isset($item['seat_id']) ||
                (isset($item['admission_index']) && $item['admission_index'] !== $i + 1)) {
                throw self::invalid();
            }
            $result[] = ['admission_index' => $i + 1] + self::names($item);
        }
        return $result;
    }

    /** @return list<array{seat_id:int,first_name:string,last_name:string}> */
    public static function storedSeats(mixed $stored, array $ids, string $first, string $last): array
    {
        return self::seats($stored, $ids, $first, $last);
    }

    /** @return list<array{admission_index:int,first_name:string,last_name:string}> */
    public static function storedGeneral(mixed $stored, int $quantity, string $first, string $last): array
    {
        return self::general($stored, $quantity, $first, $last);
    }

    /** @param array<string,mixed> $person */
    public static function fullName(array $person): string
    {
        return $person['first_name'] . ' ' . $person['last_name'];
    }

    private static function checkList(mixed $list, int $count): void
    {
        if (!is_array($list) || !array_is_list($list) || count($list) !== $count) {
            throw self::invalid();
        }
    }

    /** @param array<string,mixed> $item @return array{first_name:string,last_name:string} */
    private static function names(array $item): array
    {
        foreach (['first_name','last_name'] as $field) {
            if (!isset($item[$field]) || !is_string($item[$field]) ||
                preg_match('//u', $item[$field]) !== 1 ||
                preg_match('/[\x00-\x1F\x7F]/u', $item[$field])) {
                throw self::invalid();
            }
        }
        return [
            'first_name' => CheckoutPolicy::normalizeName($item['first_name']),
            'last_name' => CheckoutPolicy::normalizeName($item['last_name']),
        ];
    }

    private static function invalid(): HoldException
    {
        return new HoldException('invalid_attendees', \PESM\Support\I18n::text('اطلاعات شرکت‌کنندگان با تعداد و شناسه بلیت‌ها مطابقت ندارد.'), 422);
    }
}
