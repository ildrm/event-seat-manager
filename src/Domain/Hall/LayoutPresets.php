<?php

declare(strict_types=1);

namespace PESM\Domain\Hall;

use InvalidArgumentException;

/** Pure parametric seat arrangements. A generated preset is a DRAFT, never a published replacement. */
final class LayoutPresets
{
    public const PRESETS = [
        'cinema' => 'سینما', 'theatre' => 'تئاتر', 'conference' => 'همایش',
        'classroom' => 'کلاس', 'u_shape' => 'U شکل', 'roundtable' => 'میزگرد',
        'curved' => 'ردیف منحنی', 'multi_section' => 'چندبخشی', 'vip' => 'ویژه/VIP',
    ];

    /** @return list<array{code:string,row_label:string,number:int,x:int,y:int,zone:string}> */
    public static function generate(string $preset, int $rows, int $columns): array
    {
        if (!isset(self::PRESETS[$preset]) || $rows < 1 || $rows > 100 || $columns < 1 ||
            $columns > 100 || $rows * $columns > LayoutDocument::MAX_SEATS) {
            throw new InvalidArgumentException(\PESM\Support\I18n::text('نوع الگو یا ابعاد نقشه معتبر نیست.'));
        }
        $seats = GridGenerator::generate($rows, $columns);
        foreach ($seats as $i => &$seat) {
            $r = intdiv($i, $columns);
            $c = $i % $columns;
            $mid = ($columns - 1) / 2;
            switch ($preset) {
                case 'cinema':
                    $seat['x'] = 30 + $c * 38 + intdiv($c, 15) * 30;
                    $seat['y'] = 30 + $r * 43;
                    break;
                case 'theatre':
                case 'curved':
                    $seat['x'] = 30 + $c * 39 + intdiv($c, 12) * 18;
                    $seat['y'] = 35 + $r * 43 + (int) round(pow(($c - $mid) / max(1, $columns), 2) * ($preset === 'theatre' ? 210 : 360));
                    break;
                case 'conference':
                    $seat['x'] = 30 + $c * 40 + intdiv($c, 10) * 64;
                    $seat['y'] = 30 + $r * 45;
                    break;
                case 'classroom':
                    $seat['x'] = 30 + $c * 45 + intdiv($c, 2) * 45;
                    $seat['y'] = 30 + $r * 75;
                    break;
                case 'u_shape':
                    // Perimeter of U; inner floor stays empty. Sequential codes remain stable.
                    $width = max(200, ($columns - 1) * 40);
                    $height = max(200, ($rows - 1) * 43);
                    if ($r === $rows - 1) {
                        $seat['x'] = 30 + (int) round($c * $width / max(1, $columns - 1));
                        $seat['y'] = 30 + $height;
                    } elseif ($c < intdiv($columns, 2)) {
                        $seat['x'] = 30 + $c * 28;
                        $seat['y'] = 30 + $r * 43;
                    } else {
                        $seat['x'] = 30 + $width - ($columns - $c - 1) * 28;
                        $seat['y'] = 30 + $r * 43;
                    }
                    break;
                case 'roundtable':
                    $perTable = min(10, max(4, $columns));
                    $tableIndex = intdiv($c, $perTable);
                    $inTable = $c % $perTable;
                    $actual = min($perTable, $columns - $tableIndex * $perTable);
                    $angle = 2 * M_PI * $inTable / $actual;
                    $seat['x'] = (int) round(75 + $tableIndex * 150 + 52 * cos($angle));
                    $seat['y'] = (int) round(75 + $r * 160 + 52 * sin($angle));
                    break;
                case 'multi_section':
                    $block = intdiv($c, 10);
                    $seat['x'] = 30 + $c * 38 + $block * 85;
                    $seat['y'] = 30 + $r * 45;
                    $seat['zone'] = \PESM\Support\I18n::text('بخش ') . ($block + 1);
                    break;
                case 'vip':
                    $seat['x'] = 30 + $c * 55;
                    $seat['y'] = 30 + $r * 75;
                    $seat['zone'] = $r < max(1, intdiv($rows, 3)) ? \PESM\Support\I18n::text('ویژه') : \PESM\Support\I18n::text('عمومی');
                    break;
            }
        }
        unset($seat);
        // Enforce the same interchange contract as user-uploaded JSON.
        LayoutDocument::parse(LayoutDocument::encode($seats));
        return $seats;
    }
}
