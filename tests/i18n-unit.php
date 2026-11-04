<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';
require __DIR__ . '/../src/Support/Jalali.php';
require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Domain/Booking/HoldPolicy.php';
require __DIR__ . '/../src/Domain/Notification/NotificationPolicy.php';
require __DIR__ . '/../src/Domain/Hall/LayoutDocument.php';
require __DIR__ . '/../src/Domain/Hall/LayoutPrintRenderer.php';
require __DIR__ . '/../src/Domain/Reporting/XlsxExporter.php';
require __DIR__ . '/../src/Security/Capabilities.php';

use PESM\Support\I18n;
use PESM\Domain\Booking\HoldException;
use PESM\Domain\Booking\HoldPolicy;
use PESM\Domain\Notification\NotificationPolicy;
use PESM\Domain\Hall\LayoutDocument;
use PESM\Domain\Hall\LayoutPrintRenderer;
use PESM\Domain\Reporting\XlsxExporter;
use PESM\Security\Capabilities;

$GLOBALS['locale'] = 'fa_IR';
$GLOBALS['override'] = [];
function determine_locale(): string { return $GLOBALS['locale']; }
function __(string $message, string $domain): string { return $GLOBALS['override'][$message] ?? $message; }
function wp_roles(): object { return $GLOBALS['roles']; }

$checks = 0;
function expectLocale(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
}

expectLocale(I18n::text('ظرفیت') === 'ظرفیت' && I18n::direction() === 'rtl', 'Persian source and direction');
expectLocale(I18n::status('published') === 'منتشرشده', 'Persian administrative status label');
expectLocale(I18n::formatDate('2026-11-02 12:00:00') === '1405/08/11 — 15:30', 'Persian calendar with Tehran time');
$GLOBALS['locale'] = 'en_US';
expectLocale(I18n::text('ظرفیت') === 'Capacity' && I18n::direction() === 'ltr', 'English catalog and direction');
expectLocale(I18n::status('awaiting_woocommerce') === 'Awaiting WooCommerce refund', 'English refund workflow status label');
expectLocale(I18n::status('custom_gateway_status') === 'custom_gateway_status', 'Unknown custom status preserved');
expectLocale(I18n::formatDate('2026-11-02 12:00:00') === '2026/11/02 — 15:30', 'Gregorian calendar with Tehran time');
expectLocale(I18n::text('<p>ظرفیت</p>') === '<p>Capacity</p>', 'Static HTML text span');
expectLocale(I18n::text('<svg aria-label="نمای کلی چیدمان سالن">') === '<svg aria-label="Hall layout overview">', 'Static accessible attribute');
expectLocale(I18n::text('خریدار اختصاصی 123') === 'خریدار اختصاصی 123', 'Unknown messages and user names are never rewritten');
expectLocale(str_contains(NotificationPolicy::orderText(57), '57') && str_starts_with(NotificationPolicy::orderText(57), 'Tickets for order '), 'English notification with preserved order ID');
try { HoldPolicy::normalizeSeats([]); throw new RuntimeException('Expected validation failure'); }
catch (HoldException $e) {
    expectLocale($e->errorCode === 'invalid_selection' && $e->getMessage() === 'Choose between 1 and 20 seats.', 'Translated API validation preserves machine code');
}
$GLOBALS['locale'] = 'en_GB';
expectLocale(I18n::text('ظرفیت') === 'Capacity', 'English regional locale fallback');
$GLOBALS['override']['ظرفیت'] = 'Custom capacity';
expectLocale(I18n::text('ظرفیت') === 'Custom capacity', 'WordPress gettext override takes priority');
expectLocale(I18n::text('<p>ظرفیت</p>') === '<p>Custom capacity</p>', 'Gettext override for static HTML span');
$GLOBALS['override'] = [];
$json = LayoutDocument::encode([['code'=>'X-1','row_label'=>'X','number'=>1,'zone'=>'جلو','x'=>0,'y'=>0]]);
$html = LayoutPrintRenderer::render($json, 'نام سالن');
expectLocale(str_contains($html, '<html lang="en" dir="ltr">'), 'Printable language and direction');
expectLocale(str_contains($html, 'نام سالن') && str_contains($html, 'جلو'), 'User hall and zone names preserved');
expectLocale(str_contains($html, 'Physical capacity: 1') && !str_contains($html, 'ظرفیت فیزیکی'), 'Printable fixed labels translated');
$GLOBALS['locale'] = 'fa_IR';
expectLocale(I18n::text('ساختار پایگاه داده آماده نیست.') === 'ساختار پایگاه داده آماده نیست.', 'Switching locale within request restores Persian');
$GLOBALS['roles'] = (object) [
    'roles' => ['pesm_gate_operator'=>['name'=>'Old stored role name','capabilities'=>['pesm_scan_tickets'=>true]],
        'administrator'=>['name'=>'Site administrator','capabilities'=>['manage_options'=>true]]],
    'role_names' => ['pesm_gate_operator'=>'Old stored role name','administrator'=>'Site administrator'],
];
Capabilities::localizeRoleNames();
expectLocale(wp_roles()->role_names['pesm_gate_operator'] === 'اپراتور ورودی', 'Persian role label independent of installation language');
$GLOBALS['locale'] = 'en_US';
Capabilities::localizeRoleNames();
expectLocale(wp_roles()->role_names['pesm_gate_operator'] === 'Gate operator', 'English role label after switching language');
expectLocale(wp_roles()->roles['pesm_gate_operator']['capabilities'] === ['pesm_scan_tickets'=>true] &&
    wp_roles()->role_names['administrator'] === 'Site administrator', 'Role capabilities and unrelated names preserved');
foreach (['en_US'=>'0','fa_IR'=>'1'] as $locale=>$rtl) {
    $GLOBALS['locale'] = $locale;
    $path = XlsxExporter::toFile(['center_id'=>1,'sessions'=>[]]);
    try {
        $archive = new PharData($path);
        $sheet = $archive['xl/worksheets/sheet1.xml']->getContent();
        $styles = $archive['xl/styles.xml']->getContent();
        expectLocale(str_contains($sheet, 'rightToLeft="' . $rtl . '"'), 'XLSX direction: ' . $locale);
        expectLocale(str_contains($sheet, $locale === 'en_US' ? '>Capacity<' : '>ظرفیت<'), 'XLSX header language: ' . $locale);
        expectLocale(str_contains($styles, 'horizontal="' . ($locale === 'en_US' ? 'left' : 'right') . '"'), 'XLSX text alignment: ' . $locale);
        unset($archive);
    } finally {
        unlink($path);
    }
}
echo "PASS: $checks bilingual runtime assertions.\n";
