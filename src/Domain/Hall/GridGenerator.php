<?php

declare(strict_types=1);

namespace PESM\Domain\Hall;

use InvalidArgumentException;

/** Pure, deterministic geometry generator. No WordPress dependencies. */
final class GridGenerator
{
    /** @return list<array{code:string,row_label:string,number:int,x:int,y:int,zone:string}> */
    public static function generate(int $rows, int $columns): array
    {
        if ($rows < 1 || $rows > 100 || $columns < 1 || $columns > 100 || $rows * $columns > 10000) {
            throw new InvalidArgumentException(\PESM\Support\I18n::text('ابعاد نقشه نامعتبر است.'));
        }
        $items = [];
        for ($r = 0; $r < $rows; ++$r) {
            $row = self::rowLabel($r);
            for ($c = 0; $c < $columns; ++$c) {
                $num = $c + 1;
                $items[] = [
                    'code' => $row . '-' . $num,
                    'row_label' => $row,
                    'number' => $num,
                    'x' => $c * 36 + 20 + intdiv($c, 15) * 22,
                    'y' => $r * 40 + 20,
                    'zone' => $r < intdiv($rows, 2) ? \PESM\Support\I18n::text('جلو') : \PESM\Support\I18n::text('عقب'),
                ];
            }
        }
        return $items;
    }

    public static function rowLabel(int $zeroBasedIndex): string
    {
        if ($zeroBasedIndex < 0 || $zeroBasedIndex > 99) {
            throw new InvalidArgumentException(\PESM\Support\I18n::text('شماره ردیف خارج از محدوده است.'));
        }
        $result = '';
        do {
            $result = chr(65 + $zeroBasedIndex % 26) . $result;
            $zeroBasedIndex = intdiv($zeroBasedIndex, 26) - 1;
        } while ($zeroBasedIndex >= 0);
        return $result;
    }
}
