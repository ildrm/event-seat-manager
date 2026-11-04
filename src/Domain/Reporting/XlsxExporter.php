<?php

declare(strict_types=1);

namespace PESM\Domain\Reporting;

/**
 * Small, dependency-free OOXML XLSX exporter for *aggregate-only* center reports.
 * Numeric cells have numeric OOXML types; every text value is an inline string,
 * never a formula, hyperlink, or external relationship.
 *
 * PHP's Phar extension is needed for writing the ZIP package. Fail closed when
 * unavailable, instead of silently returning CSV with an .xlsx extension.
 */
final class XlsxExporter
{
    private const HEADERS = [
        'جلسه', 'رویداد', 'شروع (UTC)', 'ظرفیت', 'آزاد', 'نگهداری موقت',
        'در انتظار پرداخت', 'فروخته‌شده', 'مسدود', 'بلیت فعال',
        'ورود ثبت‌شده', 'عدم حضور پس از مهلت ورود',
    ];
    private const FIELDS = [
        'session_id', 'title', 'starts_at', 'capacity', 'available', 'held',
        'payment_pending', 'sold', 'blocked', 'active_tickets', 'checked_in', 'no_show',
    ];
    private const TEXT_FIELDS = ['title' => true, 'starts_at' => true];

    /** @param array{center_id:int,sessions:list<array<string,mixed>>} $report */
    public static function toFile(array $report): string
    {
        if (!class_exists(\PharData::class) || !extension_loaded('phar')) {
            throw new \RuntimeException(\PESM\Support\I18n::text('ماژول PHP Phar برای تولید XLSX لازم است.'));
        }
        if (!isset($report['sessions']) || !is_array($report['sessions']) || count($report['sessions']) > 5000) {
            throw new \InvalidArgumentException(\PESM\Support\I18n::text('حجم گزارش از سقف مجاز فراتر رفته است.'));
        }
        $temp = tempnam(sys_get_temp_dir(), 'pesm_xlsx_');
        if ($temp === false) { throw new \RuntimeException(\PESM\Support\I18n::text('ساخت فایل موقت گزارش ممکن نیست.')); }
        // PharData requires a .zip filename. Keep both files in the OS temp dir.
        unlink($temp);
        $path = $temp . '.zip';
        try {
            $archive = new \PharData($path, 0, null, \Phar::ZIP);
            $archive['[Content_Types].xml'] = self::contentTypes();
            $archive['_rels/.rels'] = self::rootRelations();
            $archive['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . \PESM\Support\I18n::text('<sheets><sheet name="گزارش حضور" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $archive['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>';
            $archive['xl/styles.xml'] = self::styles();
            $archive['xl/worksheets/sheet1.xml'] = self::sheet($report['sessions']);
            unset($archive);
            return $path;
        } catch (\Throwable $e) {
            if (file_exists($path)) { unlink($path); }
            throw $e;
        }
    }

    private static function sheet(array $sessions): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView rightToLeft="' . (\PESM\Support\I18n::isPersian() ? '1' : '0') . '" workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols><col min="1" max="1" width="13" customWidth="1"/>'
            . '<col min="2" max="2" width="35" customWidth="1"/>'
            . '<col min="3" max="3" width="25" customWidth="1"/>'
            . '<col min="4" max="12" width="19" customWidth="1"/></cols><sheetData>';
        $xml .= '<row r="1" ht="28" customHeight="1">';
        foreach (self::HEADERS as $index => $header) {
            $xml .= self::textCell(self::column($index) . '1', \PESM\Support\I18n::text($header), 1);
        }
        $xml .= '</row>';
        foreach ($sessions as $rowIndex => $row) {
            if (!is_array($row)) { throw new \InvalidArgumentException('Invalid report row.'); }
            $number = $rowIndex + 2;
            $xml .= '<row r="' . $number . '">';
            foreach (self::FIELDS as $col => $field) {
                $cell = self::column($col) . $number;
                $value = $row[$field] ?? null;
                if (isset(self::TEXT_FIELDS[$field])) {
                    $xml .= self::textCell($cell, (string) $value, 0);
                } else {
                    if (!is_numeric($value) || !is_finite((float) $value)) {
                        throw new \InvalidArgumentException('Invalid numeric report value.');
                    }
                    $xml .= '<c r="' . $cell . '" s="2"><v>' . (int) $value . '</v></c>';
                }
            }
            $xml .= '</row>';
        }
        return $xml . '</sheetData><autoFilter ref="A1:L' . (count($sessions) + 1) . '"/>'
            . '<pageMargins left="0.4" right="0.4" top="0.6" bottom="0.6" header="0.2" footer="0.2"/>'
            . '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1"/></worksheet>';
    }

    private static function textCell(string $cell, string $value, int $style): string
    {
        // Remove characters forbidden by XML 1.0 and replace broken UTF-8.
        $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
        $safe = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<c r="' . $cell . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
            . $safe . '</t></is></c>';
    }

    private static function column(int $i): string
    {
        return chr(65 + $i); // This export is intentionally bounded to 12 columns.
    }

    private static function styles(): string
    {
        $alignment = \PESM\Support\I18n::isPersian() ? 'right' : 'left';
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF244462"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0">'
            . '<alignment horizontal="' . $alignment . '"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFill="1" applyFont="1">'
            . '<alignment horizontal="' . $alignment . '"/></xf><xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0">'
            . '<alignment horizontal="' . $alignment . '"/></xf></cellXfs></styleSheet>';
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function rootRelations(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }
}
