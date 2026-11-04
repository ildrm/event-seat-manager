<?php

declare(strict_types=1);

namespace PESM\Support;

use InvalidArgumentException;

/** Bounded, self-contained physical geometry printout. Never renders sales or personal data. */
final class VenuePrint
{
    public const MAX_SEATS = 1000;
    private const MAX_SECTIONS = 200;
    private const MAX_GROUPS = 1000;
    private const MAX_OBJECTS = 1000;
    private const TABLE_ROWS = 32;
    private const COLORS = ['#dbeafe', '#dcfce7', '#fef3c7', '#fce7f3', '#e0e7ff', '#ffedd5', '#ccfbf1', '#f3e8ff'];

    public static function render(array $overview, array $seats = [], array $groups = [], array $objects = [], string $title = 'Venue'): string
    {
        $locale = self::text($overview['locale'] ?? (class_exists(I18n::class) ? I18n::locale() : 'en'));
        $fa = preg_match('/^fa(?:[_-]|$)/i', $locale) === 1;
        $t = static fn(string $en, string $persian): string => $fa ? $persian : $en;
        $sections = $overview['sections'] ?? [];
        if (!is_array($sections) || count($sections) > self::MAX_SECTIONS) {
            throw new InvalidArgumentException('Printable venue must contain at most 200 sections.');
        }
        $sectionMap = [];
        $levels = [];
        foreach ($sections as $section) {
            self::record($section);
            $code = self::text($section['code'] ?? $section['section_code'] ?? '');
            $level = self::level($section['level'] ?? $section['level_number'] ?? 0);
            $section['code'] = $code;
            $section['label'] = self::text($section['label'] ?? $code);
            $section['level'] = $level;
            $section['capacity'] = self::integer($section['capacity'] ?? 0, 0, 150000);
            $section['elevation'] = self::coordinate($section['elevation'] ?? 0);
            if (isset($section['bounds'])) { $section['bounds'] = self::rectangle($section['bounds']); }
            $sectionMap[$code] = $section;
            $levels[$level][] = $section;
        }

        $inputCount = count($seats);
        $seats = array_slice($seats, 0, self::MAX_SEATS);
        $detailByLevel = [];
        $categoryNames = [];
        foreach ($seats as $index => &$seat) {
            self::record($seat);
            $seat['x'] = self::coordinate($seat['x'] ?? $seat['pos_x'] ?? 0);
            $seat['y'] = self::coordinate($seat['y'] ?? $seat['pos_y'] ?? 0);
            $seat['rotation'] = self::rotation($seat['rotation'] ?? 0);
            $seat['elevation'] = self::coordinate($seat['elevation'] ?? 0);
            $seat['section_code'] = self::text($seat['section_code'] ?? '');
            $seat['code'] = self::text($seat['code'] ?? $seat['seat_code'] ?? $seat['id'] ?? $index + 1);
            $seat['category_code'] = self::text($seat['category_code'] ?? $seat['zone'] ?? $seat['zone_label'] ?? '');
            $seat['row_label'] = self::text($seat['row_label'] ?? '');
            $seat['group_code'] = self::text($seat['group_code'] ?? '');
            $seat['number'] = self::text($seat['number'] ?? $seat['seat_number'] ?? '');
            $seat['ordinal'] = $index + 1;
            $seat['level'] = self::level($sectionMap[$seat['section_code']]['level'] ?? $seat['level'] ?? 0);
            $detailByLevel[$seat['level']][] = $seat;
            $categoryNames[$seat['category_code']] = true;
        }
        unset($seat);
        $shapesOmitted = count($groups) > self::MAX_GROUPS || count($objects) > self::MAX_OBJECTS;
        $groups = self::shapes(array_slice($groups, 0, self::MAX_GROUPS), true, $sectionMap);
        $objects = self::shapes(array_slice($objects, 0, self::MAX_OBJECTS), false, $sectionMap);
        if (!$levels) { $levels[0] = []; }
        foreach (array_keys($detailByLevel) as $level) { $levels[$level] ??= []; }
        ksort($levels, SORT_NUMERIC);

        $capacity = self::integer($overview['capacity'] ?? $overview['seat_count'] ?? array_sum(array_column($sectionMap, 'capacity')), 0, 150000);
        $selected = self::text($overview['selected_section'] ?? $overview['section_code'] ?? '');
        $expectedDetail = $selected !== '' && isset($sectionMap[$selected]) ? $sectionMap[$selected]['capacity'] : 0;
        if (!$expectedDetail && $seats) {
            $seen = array_fill_keys(array_column($seats, 'section_code'), true);
            foreach ($seen as $code => $_) { $expectedDetail += (int) ($sectionMap[$code]['capacity'] ?? 0); }
        }
        $partial = $inputCount > self::MAX_SEATS || ($expectedDetail > count($seats)) || !empty($overview['partial_detail']);
        $title = self::e($title);
        $direction = $fa ? 'rtl' : 'ltr';
        $html = '<!doctype html><html lang="' . ($fa ? 'fa' : 'en') . '" dir="' . $direction . '"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; script-src &#39;none&#39;; style-src &#39;unsafe-inline&#39;; img-src &#39;none&#39;; font-src &#39;none&#39;; connect-src &#39;none&#39;; object-src &#39;none&#39;; base-uri &#39;none&#39;; form-action &#39;none&#39;">'
            . '<title>' . $title . ' — ' . $t('Venue layout', 'چیدمان سالن') . '</title><style>' . self::css($direction) . '</style></head><body>';
        $notice = '';
        if ($partial) {
            $notice .= '<p class="notice">' . $t('Partial detail: ', 'جزئیات بخشی از نقشه: ') . count($seats) . $t(' places shown', ' جایگاه نمایش داده شده')
                . ($expectedDetail ? $t(' out of ', ' از ') . $expectedDetail : '') . '. '
                . $t('This printout includes at most 1,000 places. The level overview keeps the section context; export the layout or choose another section for the rest.',
                    'این چاپ حداکثر ۱۰۰۰ جایگاه را در بر می‌گیرد. نمای طبقه، موقعیت بخش را حفظ می‌کند؛ برای بقیه از خروجی چیدمان یا چاپ بخش دیگر استفاده کنید.') . '</p>';
        }
        if ($shapesOmitted) {
            $notice .= '<p class="notice">' . $t('Some venue objects or group footprints were omitted to keep this printout bounded.', 'برای محدود نگه داشتن حجم چاپ، بخشی از عناصر یا محدوده گروه‌ها نمایش داده نشده‌اند.') . '</p>';
        }
        $footer = '<footer>' . $t('Physical layout only; this document does not show live availability, payment or ticket status. Coordinates and elevations are in millimetres.',
            'این سند فقط چیدمان فیزیکی را نشان می‌دهد، نه موجودی لحظه‌ای، پرداخت یا وضعیت بلیت. مختصات و ارتفاع‌ها بر حسب میلی‌متر هستند.') . '</footer>';

        foreach ($levels as $level => $floorSections) {
            $floorObjects = self::onLevel($objects, $level);
            $rectangles = array_values(array_filter(array_column($floorSections, 'bounds')));
            $bounds = self::bounds(array_merge($rectangles, $floorObjects));
            $floorCapacity = array_sum(array_column($floorSections, 'capacity'));
            $html .= '<section class="page overview-page" data-level="' . $level . '"><header><h1>' . $title . '</h1><h2>'
                . $t('Level ', 'طبقه ') . ($level + 1) . ' — ' . $t('Overview', 'نمای کلی') . '</h2><p>'
                . $t('Venue capacity: ', 'ظرفیت سالن: ') . $capacity . ' · ' . $t('Level capacity: ', 'ظرفیت طبقه: ') . $floorCapacity . '</p></header>' . $notice;
            $html .= self::svgStart($bounds, $t('Venue level overview', 'نمای کلی طبقه سالن'), 'overview');
            foreach ($floorObjects as $object) { $html .= self::shape($object, $bounds, false); }
            foreach ($floorSections as $section) {
                if (!isset($section['bounds'])) { continue; }
                $rect = $section['bounds'];
                $html .= '<g class="section" data-section="' . self::e($section['code']) . '"><rect x="' . self::n($rect['x']) . '" y="' . self::n($rect['y'])
                    . '" width="' . self::n($rect['width']) . '" height="' . self::n($rect['height']) . '" fill="#dbeafe" fill-opacity="0.65" stroke="#315d97" vector-effect="non-scaling-stroke"/>';
                $font = max(200, $bounds['width'] * .012);
                $html .= self::svgText($section['label'], $rect['x'] + $rect['width'] / 2, $rect['y'] + $rect['height'] / 2 - $font * .4, $font)
                    . self::svgText($section['code'] . ' · ' . $section['capacity'], $rect['x'] + $rect['width'] / 2, $rect['y'] + $rect['height'] / 2 + $font * .9, $font * .8)
                    . '</g>';
            }
            $html .= '</svg><ul class="section-list">';
            foreach ($floorSections as $section) {
                $html .= '<li><bdi>' . self::e($section['code']) . '</bdi> · ' . self::e($section['label']) . ' · '
                    . $section['capacity'] . $t(' places', ' جایگاه') . ' · ' . $t('Elevation ', 'ارتفاع ') . self::n($section['elevation']) . ' mm</li>';
            }
            $html .= '</ul>' . $footer . '</section>';
        }

        foreach ($detailByLevel as $level => $floorSeats) {
            $sectionCodes = array_fill_keys(array_column($floorSeats, 'section_code'), true);
            $floorGroups = array_values(array_filter(self::onLevel($groups, $level), static fn(array $shape): bool => isset($sectionCodes[$shape['section_code']])));
            $floorObjects = array_values(array_filter(self::onLevel($objects, $level), static fn(array $shape): bool => $shape['section_code'] === '' || isset($sectionCodes[$shape['section_code']])));
            $seatShapes = array_map(static fn(array $seat): array => ['x' => $seat['x'], 'y' => $seat['y'], 'width' => 400, 'height' => 400, 'rotation' => $seat['rotation']], $floorSeats);
            $bounds = self::bounds(array_merge($seatShapes, $floorGroups, $floorObjects));
            $html .= '<section class="page detail-page" data-level="' . $level . '"><h1>' . $title . '</h1><h2>'
                . $t('Level ', 'طبقه ') . ($level + 1) . ' — ' . $t('Place detail', 'جزئیات جایگاه‌ها') . '</h2>' . $notice
                . '<p>' . $t('Map numbers correspond to the readable place-code tables on the following pages.', 'شماره‌های روی نقشه با جدول خوانای کد جایگاه‌ها در صفحات بعد مطابقت دارند.') . '</p>'
                . self::legend(array_keys($categoryNames), $t) . self::svgStart($bounds, $t('Detailed place geometry', 'هندسه جزئی جایگاه‌ها'), 'detail');
            foreach ($floorObjects as $object) { $html .= self::shape($object, $bounds, false); }
            foreach ($floorGroups as $group) { $html .= self::shape($group, $bounds, true); }
            foreach ($floorSeats as $seat) {
                $cx = $seat['x'] + 200; $cy = $seat['y'] + 200;
                $html .= '<g class="place" data-code="' . self::e($seat['code']) . '" data-elevation="' . self::n($seat['elevation'])
                    . '" transform="rotate(' . self::n($seat['rotation'] / 1000) . ' ' . self::n($cx) . ' ' . self::n($cy) . ')"><title>' . self::e($seat['code'])
                    . '</title><rect x="' . self::n($seat['x']) . '" y="' . self::n($seat['y']) . '" width="400" height="400" rx="65" fill="'
                    . self::color($seat['category_code']) . '" stroke="#334155" vector-effect="non-scaling-stroke"/>';
                if (!empty($seat['accessible'])) {
                    $html .= '<rect x="' . self::n($seat['x'] + 100) . '" y="' . self::n($seat['y'] + 100) . '" width="200" height="200" fill="none" stroke="#0f766e" vector-effect="non-scaling-stroke"/>';
                }
                $html .= '</g>';
                if ($bounds['width'] <= 100000) { $html .= self::svgText((string) $seat['ordinal'], $cx, $cy, min(230, max(100, $bounds['width'] * .004))); }
            }
            $html .= '</svg>' . $footer . '</section>';
            foreach (array_chunk($floorSeats, self::TABLE_ROWS) as $page => $chunk) {
                $html .= '<section class="page code-page" data-level="' . $level . '"><h1>' . $title . '</h1><h2>' . $t('Place codes — Level ', 'کد جایگاه‌ها — طبقه ')
                    . ($level + 1) . ' · ' . $t('Part ', 'بخش ') . ($page + 1) . '</h2><table><thead><tr>';
                foreach ([$t('Map #', 'شماره نقشه'), $t('Place code', 'کد جایگاه'), $t('Section / row', 'بخش / ردیف'), $t('Category / group', 'دسته / گروه'), $t('X / Y / elevation (mm)', 'ایکس / وای / ارتفاع (میلی‌متر)')] as $column) {
                    $html .= '<th scope="col">' . $column . '</th>';
                }
                $html .= '</tr></thead><tbody>';
                foreach ($chunk as $seat) {
                    $html .= '<tr><td>' . $seat['ordinal'] . '</td><th scope="row"><bdi>' . self::e($seat['code']) . '</bdi>'
                        . (!empty($seat['accessible']) ? '<small>' . $t('Accessible', 'دسترس‌پذیر') . '</small>' : '') . '</th><td>'
                        . self::e($seat['section_code']) . '<br>' . self::e($seat['row_label']) . '</td><td>'
                        . self::e($seat['category_code']) . '<br><bdi>' . self::e($seat['group_code']) . '</bdi></td><td class="coordinates" dir="ltr">'
                        . self::n($seat['x']) . ' / ' . self::n($seat['y']) . ' / ' . self::n($seat['elevation']) . '</td></tr>';
                }
                $html .= '</tbody></table>' . $footer . '</section>';
            }
        }
        return $html . '</body></html>';
    }

    private static function shapes(array $items, bool $group, array $sections): array
    {
        $out = [];
        foreach ($items as $item) {
            self::record($item);
            $rectangle = self::rectangle($item);
            $section = self::text($item['section_code'] ?? '');
            $out[] = $rectangle + ['code' => self::text($item['code'] ?? $item['group_code'] ?? $item['id'] ?? ''),
                'label' => self::text($item['label'] ?? $item['code'] ?? $item['group_code'] ?? $item['type'] ?? ''),
                'type' => self::text($item['type'] ?? $item['group_type'] ?? ($group ? 'table' : 'label')),
                'shape' => self::text($item['shape'] ?? ''), 'section_code' => $section,
                'level' => $section !== '' && isset($sections[$section]) ? $sections[$section]['level'] : null,
                'rotation' => self::rotation($item['rotation'] ?? 0), 'elevation' => self::coordinate($item['elevation'] ?? 0)];
        }
        return $out;
    }

    private static function onLevel(array $shapes, int $level): array
    {
        return array_values(array_filter($shapes, static fn(array $shape): bool => $shape['level'] === null || $shape['level'] === $level));
    }

    /** Axis-aligned bounds include every rotated corner; negative positions retain their real location. */
    private static function bounds(array $items): array
    {
        if (!$items) { return ['x' => -1000.0, 'y' => -1000.0, 'width' => 2000.0, 'height' => 2000.0]; }
        $minX = $minY = INF; $maxX = $maxY = -INF;
        foreach ($items as $item) {
            $cx = $item['x'] + $item['width'] / 2; $cy = $item['y'] + $item['height'] / 2;
            $angle = (($item['rotation'] ?? 0) / 1000) * M_PI / 180;
            foreach ([[-1,-1], [-1,1], [1,-1], [1,1]] as [$sx,$sy]) {
                $dx = $sx * $item['width'] / 2; $dy = $sy * $item['height'] / 2;
                $x = $cx + $dx * cos($angle) - $dy * sin($angle); $y = $cy + $dx * sin($angle) + $dy * cos($angle);
                $minX = min($minX, $x); $maxX = max($maxX, $x); $minY = min($minY, $y); $maxY = max($maxY, $y);
            }
        }
        $padding = max(250, max($maxX - $minX, $maxY - $minY) * .025);
        return ['x' => $minX - $padding, 'y' => $minY - $padding, 'width' => max(1000, $maxX - $minX + 2 * $padding), 'height' => max(1000, $maxY - $minY + 2 * $padding)];
    }

    private static function shape(array $item, array $bounds, bool $group): string
    {
        $cx = $item['x'] + $item['width'] / 2; $cy = $item['y'] + $item['height'] / 2;
        $fill = $group ? '#e8e0d3' : ($item['type'] === 'field' ? '#d3eadc' : '#dce3ed');
        $out = '<g class="' . ($group ? 'group-footprint' : 'venue-object') . '" data-code="' . self::e($item['code']) . '" data-elevation="'
            . self::n($item['elevation']) . '" transform="rotate(' . self::n($item['rotation'] / 1000) . ' ' . self::n($cx) . ' ' . self::n($cy) . ')"><title>' . self::e($item['label']) . '</title>';
        if ($item['shape'] === 'ellipse' || $item['type'] === 'round_table') {
            $out .= '<ellipse cx="' . self::n($cx) . '" cy="' . self::n($cy) . '" rx="' . self::n($item['width'] / 2) . '" ry="' . self::n($item['height'] / 2) . '" fill="' . $fill . '" stroke="#64748b" vector-effect="non-scaling-stroke"/>';
        } elseif ($item['type'] === 'separator') {
            $out .= '<line x1="' . self::n($item['x']) . '" y1="' . self::n($item['y']) . '" x2="' . self::n($item['x'] + $item['width']) . '" y2="' . self::n($item['y'] + $item['height']) . '" stroke="#64748b" vector-effect="non-scaling-stroke"/>';
        } else {
            $out .= '<rect x="' . self::n($item['x']) . '" y="' . self::n($item['y']) . '" width="' . self::n($item['width']) . '" height="' . self::n($item['height']) . '" fill="' . $fill . '" stroke="#64748b" vector-effect="non-scaling-stroke"/>';
        }
        $out .= '</g>' . self::svgText($item['label'], $cx, $cy, max(180, $bounds['width'] * .008));
        return $out;
    }

    private static function svgStart(array $bounds, string $label, string $class): string
    {
        return '<svg class="geometry ' . $class . '" xmlns="http://www.w3.org/2000/svg" viewBox="' . self::n($bounds['x']) . ' ' . self::n($bounds['y']) . ' '
            . self::n($bounds['width']) . ' ' . self::n($bounds['height']) . '" role="img" aria-label="' . self::e($label) . '">';
    }

    private static function svgText(string $text, float $x, float $y, float $size): string
    {
        return '<text x="' . self::n($x) . '" y="' . self::n($y) . '" font-size="' . self::n($size) . '" text-anchor="middle" dominant-baseline="middle" fill="#17243b">' . self::e($text) . '</text>';
    }

    private static function legend(array $categories, callable $t): string
    {
        $out = '<div class="legend">';
        foreach (array_slice($categories, 0, 40) as $category) { $category = (string) $category; $out .= '<span><i style="background:' . self::color($category) . '"></i>' . self::e($category !== '' ? $category : $t('General', 'عمومی')) . '</span>'; }
        $out .= '<span><i class="group-key"></i>' . $t('Table / box footprint', 'محدوده میز / باکس') . '</span><span><i class="access-key"></i>' . $t('Accessible place', 'جایگاه دسترس‌پذیر') . '</span></div>';
        if (count($categories) > 40) { $out .= '<p>' . $t('Additional category codes are included in the detail tables.', 'کد دسته‌های دیگر در جدول جزئیات آمده است.') . '</p>'; }
        return $out;
    }

    private static function rectangle(mixed $value): array
    {
        self::record($value);
        $out = ['x' => self::coordinate($value['x'] ?? 0), 'y' => self::coordinate($value['y'] ?? 0),
            'width' => self::coordinate($value['width'] ?? 0, 20000000), 'height' => self::coordinate($value['height'] ?? 0, 20000000)];
        if ($out['width'] <= 0 || $out['height'] <= 0) { throw new InvalidArgumentException('Printable geometry needs positive dimensions.'); }
        return $out;
    }

    private static function coordinate(mixed $value, int $limit = 10000000): float
    {
        if ((!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) || !is_finite((float) $value) || abs((float) $value) > $limit) {
            throw new InvalidArgumentException('Invalid printable geometry coordinate.');
        }
        return (float) $value;
    }

    private static function rotation(mixed $value): int { return self::integer($value, 0, 359999); }
    private static function level(mixed $value): int { return self::integer($value, 0, 100); }
    private static function integer(mixed $value, int $min, int $max): int
    {
        if ((!is_int($value) && !(is_string($value) && preg_match('/^[0-9]+$/D', $value))) || (int) $value < $min || (int) $value > $max) {
            throw new InvalidArgumentException('Invalid printable geometry count.');
        }
        return (int) $value;
    }
    private static function record(mixed $value): void { if (!is_array($value)) { throw new InvalidArgumentException('Invalid printable geometry record.'); } }
    private static function text(mixed $value): string
    {
        if (!is_scalar($value) && $value !== null) { throw new InvalidArgumentException('Invalid printable label.'); }
        return (string) $value;
    }
    private static function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private static function n(float|int $value): string { return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.') ?: '0'; }
    private static function color(string $category): string { return self::COLORS[hexdec(substr(hash('sha256', $category), 0, 2)) % count(self::COLORS)]; }
    private static function css(string $direction): string
    {
        return '@page{size:A4 landscape;margin:10mm}*{box-sizing:border-box}body{margin:0;color:#17243b;font:10pt/1.4 Tahoma,Arial,sans-serif;direction:' . $direction . '}'
            . '.page{padding:3mm;break-after:page;page-break-after:always}.page:last-child{break-after:auto;page-break-after:auto}h1{font-size:18pt;margin:0 0 3mm;overflow-wrap:anywhere}h2{font-size:13pt;margin:0 0 3mm}p{margin:2mm 0 4mm}'
            . '.geometry{display:block;width:100%;height:132mm;border:1px solid #cbd5e1;background:#fff;direction:ltr}.detail{height:140mm}.geometry text{font-family:Tahoma,Arial,sans-serif}.geometry [vector-effect]{stroke-width:1}'
            . '.notice{border:1px solid #b45309;background:#fff7e6;padding:3mm;font-weight:600}.legend{display:flex;gap:3mm;flex-wrap:wrap;margin:3mm 0;font-size:8pt}.legend>span{display:inline-flex;gap:1.5mm;align-items:center}.legend i{display:inline-block;width:3mm;height:3mm;border:1px solid #64748b}.group-key{background:#e8e0d3;border-radius:50%}.access-key{background:#fff;border:2px solid #0f766e!important}'
            . '.section-list{columns:3;margin:3mm 0;padding-inline-start:4mm;font-size:8pt}.section-list li{break-inside:avoid;margin-block:1mm}footer{font-size:8pt;color:#52627a;margin-top:4mm}'
            . 'table{border-collapse:collapse;width:100%;table-layout:fixed;font-size:8.5pt}thead{display:table-header-group}th,td{padding:1.4mm;border:1px solid #b6c2d2;text-align:start;vertical-align:top;overflow-wrap:anywhere}thead th{background:#edf2fa}th:first-child,td:first-child{width:8%}th:nth-child(2){width:27%}th:nth-child(5){width:23%}.coordinates{font-family:monospace;font-size:8pt}tbody tr{break-inside:avoid}small{display:block;font-size:7.5pt;font-weight:normal}bdi{unicode-bidi:isolate}'
            . '@media screen{body{background:#eef2f6}.page{max-width:1250px;background:white;margin:16px auto;padding:20px}}@media print{*{print-color-adjust:exact;-webkit-print-color-adjust:exact}}';
    }
}
