<?php

declare(strict_types=1);

/** Run with `wp eval-file tests/integration-locale.php` on an installed test WordPress. */
if (!defined('ABSPATH') || !class_exists(\PESM\Support\I18n::class)) {
    throw new RuntimeException('Load WordPress with Event Seat Manager active before running this test.');
}

use PESM\Support\I18n;
use PESM\Domain\Notification\NotificationPolicy;
use PESM\Domain\Reporting\AttendanceReport;
use PESM\Domain\Reporting\XlsxExporter;
use PESM\Frontend\Shortcodes;

$checks = 0;
$initialLocale = determine_locale();
$initialLanguageOption = get_option('WPLANG', null);
$outcomes = [];
$expect = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
};

foreach (['fa_IR', 'en_US'] as $testLocale) {
    $switched = switch_to_locale($testLocale);
    try {
        $expect(determine_locale() === $testLocale, 'Install the official Persian WordPress language pack before this test.');
        $expect(I18n::locale() === $testLocale, 'Plugin request locale does not match WordPress.');
        $fa = $testLocale === 'fa_IR';
        $expect(I18n::text('ظرفیت') === ($fa ? 'ظرفیت' : 'Capacity'), 'Bundled exact translation failed.');
        $bootstrap = __('Deactivate the previous Event Seat Manager copy before activating this package. Existing booking data will be retained.', 'event-seat-manager');
        $expect($fa ? str_contains($bootstrap, 'نسخهٔ قبلی افزونه') : str_starts_with($bootstrap, 'Deactivate'), 'Standard WordPress gettext bootstrap translation failed.');
        $roleName = wp_roles()->role_names['pesm_gate_operator'] ?? '';
        $expect($roleName === ($fa ? 'اپراتور ورودی' : 'Gate operator'), 'Role name did not follow WordPress locale.');
        $view = Shortcodes::tickets();
        $expect(str_contains($view, 'dir="' . ($fa ? 'rtl' : 'ltr') . '"'), 'Ticket view direction failed.');
        $expect($fa ? str_contains($view, 'بلیت') : !preg_match('/[\x{0600}-\x{06ff}]/u', $view), 'Ticket form language failed.');
        $notification = NotificationPolicy::orderText(123);
        $expect($fa ? str_contains($notification, 'بلیت') : str_starts_with($notification, 'Tickets for order '), 'Queued notification source language failed.');
        $csv = AttendanceReport::asCsv(['center_id'=>0, 'sessions'=>[]]);
        $expect(str_contains($csv, $fa ? 'ظرفیت' : 'Capacity'), 'CSV header language failed.');
        $expect(I18n::formatDate('2026-11-02 12:00:00') === ($fa ? '1405/08/11 — 15:30' : '2026/11/02 — 15:30'), 'Calendar or Tehran time failed.');
        $path = XlsxExporter::toFile(['center_id'=>0, 'sessions'=>[]]);
        try {
            $zip = new PharData($path);
            $sheet = $zip['xl/worksheets/sheet1.xml']->getContent();
            $expect(str_contains($sheet, $fa ? '>ظرفیت<' : '>Capacity<'), 'XLSX header language failed.');
            $expect(str_contains($sheet, 'rightToLeft="' . ($fa ? '1' : '0') . '"'), 'XLSX direction failed.');
            unset($zip);
        } finally {
            unlink($path);
        }
        $outcomes[] = ['locale'=>$testLocale, 'direction'=>I18n::direction(), 'gettext'=>true, 'views'=>true,
            'role_labels'=>true, 'notification'=>true, 'csv'=>true, 'xlsx'=>true];
    } finally {
        if ($switched) { restore_previous_locale(); }
    }
}
$expect(determine_locale() === $initialLocale, 'Request locale was not restored.');
$expect(get_option('WPLANG', null) === $initialLanguageOption, 'The saved site language was changed.');
echo wp_json_encode(['passed'=>true, 'checks'=>$checks, 'initial_locale'=>$initialLocale,
    'site_language_unchanged'=>true, 'outcomes'=>$outcomes], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
