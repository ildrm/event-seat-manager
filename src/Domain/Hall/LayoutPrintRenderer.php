<?php

declare(strict_types=1);

namespace PESM\Domain\Hall;

use InvalidArgumentException;

/** Self-contained printable HTML. No external fonts/CDNs; browser Save as PDF works. */
final class LayoutPrintRenderer
{
    private const COLORS = ['#dbeafe', '#dcfce7', '#fef3c7', '#fce7f3', '#e0e7ff', '#ffedd5', '#ccfbf1', '#f3e8ff'];

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function render(string $json, string $hallTitle, string $paper = 'A4'): string
    {
        if (!in_array($paper, ['A4', 'A3'], true)) { throw new InvalidArgumentException(\PESM\Support\I18n::text('اندازه کاغذ معتبر نیست.')); }
        $document = LayoutDocument::parseFull($json);
        $seats = $document['seats'];
        $objects = $document['objects'];
        $zones = [];
        foreach ($seats as $seat) {
            $zones[$seat['zone']][$seat['row_label']][] = $seat;
        }
        $zoneNames = array_keys($zones);
        $colors = [];
        foreach ($zoneNames as $i => $name) { $colors[$name] = self::COLORS[$i % count(self::COLORS)]; }
        $title = self::e($hallTitle);
        $css = '@page{size:' . $paper . ' landscape;margin:10mm}' .
            '*,*:before,*:after{box-sizing:border-box}body{font-family:Tahoma,Arial,sans-serif;color:#111827;direction:' . \PESM\Support\I18n::direction() . ';margin:0}' .
            '.page{break-after:page;page-break-after:always;min-height:170mm;padding:4mm}.page:last-child{break-after:auto;page-break-after:auto}' .
            'h1{font-size:20pt;margin:0 0 9mm}h2{font-size:15pt;margin:0 0 6mm}p{font-size:10pt}' .
            '.legend{display:flex;flex-wrap:wrap;gap:4mm;font-size:10pt;margin:5mm 0}.key{padding:2mm 4mm;border:1px solid #94a3b8;border-radius:4px}' .
            '.overview{width:100%;height:135mm;display:block;border:1px solid #cbd5e1;border-radius:4px}' .
            '.grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:3mm;direction:ltr}' .
            '.seat{border:1px solid #64748b;min-height:17mm;display:flex;justify-content:center;align-items:center;flex-direction:column;' .
            'font:10pt Tahoma,Arial,sans-serif;text-align:center;border-radius:5px;overflow-wrap:anywhere;padding:2mm}' .
            '.seat small{font-size:8pt}footer{font-size:9pt;color:#475569;margin-top:7mm}' .
            '@media screen{body{background:#f1f5f9}.page{background:white;margin:12px auto;max-width:1250px;padding:20px;box-shadow:0 1px 5px #94a3b8}}';
        $out = '<!doctype html><html lang="' . (\PESM\Support\I18n::isPersian() ? 'fa' : 'en') . '" dir="' . \PESM\Support\I18n::direction() . '"><head><meta charset="UTF-8"><title>' . $title . \PESM\Support\I18n::text(' - نقشه قابل چاپ</title>') .
            '<style>' . $css . '</style></head><body>';
        $minX = min(array_column($seats, 'x')); $maxX = max(array_column($seats, 'x'));
        $minY = min(array_column($seats, 'y')); $maxY = max(array_column($seats, 'y'));
        foreach ($objects as $object) {
            $minX = min($minX, $object['x']);
            $minY = min($minY, $object['y']);
            $maxX = max($maxX, $object['x'] + $object['width']);
            $maxY = max($maxY, $object['y'] + $object['height']);
        }
        $x = $minX - 30; $y = $minY - 30;
        $w = max(100, $maxX - $minX + 60); $h = max(100, $maxY - $minY + 60);
        $out .= \PESM\Support\I18n::text('<section class="page"><h1>نقشه سالن: ') . $title . \PESM\Support\I18n::text('</h1><p>ظرفیت فیزیکی: ') . count($seats) .
            \PESM\Support\I18n::text(' صندلی — اندازه چاپ: ') . $paper . \PESM\Support\I18n::text(' — صفحه اول: نمای کلی (برای خواندن شماره هر صندلی، صفحات جزئیات را چاپ کنید).</p>');
        $out .= '<div class="legend">';
        foreach ($colors as $zone => $color) {
            $out .= '<span class="key" style="background:' . $color . '">' . self::e($zone) . '</span>';
        }
        $out .= '</div><svg class="overview" viewBox="' . $x . ' ' . $y . ' ' . $w . ' ' . $h . \PESM\Support\I18n::text('" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="نمای کلی چیدمان سالن">');
        foreach ($objects as $object) {
            $isRound = $object['type'] === 'round_table';
            $isSeparator = $object['type'] === 'separator';
            $fill = $object['type'] === 'accessible_area' ? '#dcfce7' : '#cbd5e1';
            $out .= '<g aria-label="' . self::e($object['label'] ?: $object['type']) . '">';
            if ($isRound) {
                $out .= '<ellipse cx="' . ($object['x'] + (int) ($object['width']/2)) . '" cy="' . ($object['y'] + (int) ($object['height']/2)) . '" rx="' . (int) ($object['width']/2) . '" ry="' . (int) ($object['height']/2) . '" fill="' . $fill . '" stroke="#64748b"/>';
            } elseif ($isSeparator) {
                $out .= '<line x1="' . $object['x'] . '" y1="' . $object['y'] . '" x2="' . ($object['x']+$object['width']) . '" y2="' . ($object['y']+$object['height']) . '" stroke="#64748b" stroke-width="4"/>';
            } else {
                $out .= '<rect x="' . $object['x'] . '" y="' . $object['y'] . '" width="' . $object['width'] . '" height="' . $object['height'] . '" fill="' . $fill . '" stroke="#64748b" opacity="0.85"/>';
            }
            if ($object['label'] !== '') {
                $out .= '<text x="' . ($object['x'] + 4) . '" y="' . ($object['y'] + 15) . '" font-size="13" fill="#111827">' . self::e($object['label']) . '</text>';
            }
            $out .= '</g>';
        }
        foreach ($seats as $seat) {
            $out .= '<circle cx="' . $seat['x'] . '" cy="' . $seat['y'] . '" r="10" fill="' . $colors[$seat['zone']] . '" stroke="#334155" stroke-width="1"/>';
        }
        $out .= \PESM\Support\I18n::text('</svg><footer>این نقشه تنها چیدمان فیزیکی سالن را نشان می‌دهد، نه موجودی یا وضعیت فروش صندلی‌ها.</footer></section>');
        $page = 2;
        foreach ($zones as $zone => $rows) {
            foreach ($rows as $label => $rowSeats) {
                usort($rowSeats, static fn(array $a, array $b): int => ($a['x'] <=> $b['x']) ?: ($a['number'] <=> $b['number']));
                // 12 seats per line and no more than 36 per detail page: 60-wide rows stay legible.
                foreach (array_chunk($rowSeats, 36) as $chunkIndex => $group) {
                    $out .= '<section class="page"><h2>' . $title . \PESM\Support\I18n::text(' — زون ') . self::e((string) $zone) .
                        \PESM\Support\I18n::text(' — ردیف ') . self::e((string) $label) . \PESM\Support\I18n::text('</h2><p>بخش ') . ($chunkIndex + 1) .
                        \PESM\Support\I18n::text(' — صفحه ') . $page++ . ' — ' . count($group) . \PESM\Support\I18n::text(' صندلی</p><div class="grid">');
                    foreach ($group as $seat) {
                        $out .= '<div class="seat" style="background:' . $colors[$zone] . '"><b>' . self::e($seat['code']) .
                            \PESM\Support\I18n::text('</b><small>شماره ') . $seat['number'] . '</small></div>';
                    }
                    $out .= \PESM\Support\I18n::text('</div><footer>ترتیب بر اساس مختصات افقی (چپ به راست) است؛ بررسی مسیرها و راهروها بر عهده مدیر سالن است.</footer></section>');
                }
            }
        }
        return $out . '</body></html>';
    }
}
