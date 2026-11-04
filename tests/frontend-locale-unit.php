<?php

declare(strict_types=1);

/** Rendering boundary tests; no WordPress, browser or database integration is implied. */
require_once __DIR__ . '/../src/Support/I18n.php';
require_once __DIR__ . '/../src/Support/Ui.php';
require_once __DIR__ . '/../src/Support/Jalali.php';
require_once __DIR__ . '/../src/Frontend/Shortcodes.php';
require_once __DIR__ . '/../src/Frontend/Blocks.php';

define('PESM_URL', 'https://example.test/wp-content/plugins/event-seat-manager/');
define('PESM_DIR', dirname(__DIR__) . '/');
define('PESM_VERSION', '2.0.0');
$GLOBALS['test_locale'] = 'en_US';
$GLOBALS['scripts'] = [];
$GLOBALS['styles'] = [];
$GLOBALS['blocks'] = [];

function determine_locale(): string { return $GLOBALS['test_locale']; }
function __(string $message, string $domain): string { return $message; }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
function esc_url(string $value): string { return $value; }
function esc_url_raw(string $value): string { return $value; }
function rest_url(string $path): string { return 'https://example.test/wp-json/' . $path; }
function wp_json_encode(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
function wp_unique_id(): string { static $id = 0; return (string) ++$id; }
function wp_enqueue_style(string $handle, string $source, array $dependencies = [], mixed $version = false): void { $GLOBALS['styles'][$handle] = $dependencies; }
function wp_enqueue_script(string $handle, string $src, array $deps, string $version, bool $footer): void {
    $GLOBALS['scripts'][$handle] = $deps;
}
function wp_register_script(string $handle, string $src, array $deps, string $version, bool $footer): void {
    $GLOBALS['scripts'][$handle] = $deps;
}
function wp_add_inline_script(...$arguments): void {}
function shortcode_atts(array $defaults, array $attributes, string $tag): array { return array_merge($defaults, $attributes); }
function absint(mixed $value): int { return abs((int) $value); }
function get_option(string $key, mixed $default = false): mixed { return $default; }
function is_user_logged_in(): bool { return false; }
function current_user_can(string $capability): bool { return false; }
function get_permalink(): string { return 'https://example.test/events/'; }
function add_query_arg(string $key, int $value, string $url): string { return $url . '?' . $key . '=' . $value; }
function register_block_type(string $path, array $args): void { $GLOBALS['blocks'][basename($path)] = $args; }

final class LocaleFakeDatabase
{
    public string $prefix = 'wp_';
    public function get_var(string $query): string { return 'seated'; }
    public function prepare(string $query, mixed ...$values): string { return $query; }
    public function get_row(string $query): object {
        return (object) ['id'=>9,'title'=>'<script>رویدادها</script>','description'=>'','organizer'=>'','venue'=>'','contact'=>'','admission_terms'=>'','poster_attachment_id'=>0];
    }
    public function get_results(string $query): array {
        if (str_contains($query, 'FROM wp_pesm_sessions WHERE event_id')) { return []; }
        return [(object) ['id'=>9, 'title'=>'<script>رویدادها</script>']];
    }
}
$GLOBALS['wpdb'] = new LocaleFakeDatabase();

function verify(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

use PESM\Frontend\Shortcodes;
use PESM\Frontend\Blocks;

foreach (['en_US'=>'ltr', 'fa_IR'=>'rtl'] as $locale=>$direction) {
    $GLOBALS['test_locale'] = $locale;
    $capacityView = Shortcodes::capacity(['session'=>1]);
    $seatedView = Shortcodes::map(['session'=>1]);
    foreach ([$capacityView, $seatedView] as $bookingView) {
        verify(preg_match('/<input[^>]*name="phone"[^>]*required[^>]*>/u', $bookingView) === 1, 'A rendered guest checkout must require the phone');
        verify(str_contains($bookingView, 'autocomplete="given-name"') && str_contains($bookingView, 'autocomplete="family-name"'), 'Buyer names must support accessible autofill');
    }
    verify(substr_count($seatedView, 'class="pesm-receipt"') === 1, 'One authoritative order receipt');
    verify(str_contains($seatedView, 'pesm-booking-summary') && str_contains($seatedView, 'pesm-map-panel'), 'Map and reservation summary must remain separate');
    $ticketView = Shortcodes::tickets();
    verify(preg_match('/<form class="pesm-recovery-verify" hidden>.*?<input name="order_id" type="hidden">/s', $ticketView) === 1, 'Recovery must reveal code entry after requesting it without asking the order number twice');
    $views = [$ticketView, $capacityView, $seatedView,
        Shortcodes::gate(), Shortcodes::manager(), Shortcodes::ownershipTransfer()];
    foreach ($views as $view) {
        verify(str_contains($view, 'dir="' . $direction . '"'), 'Wrong direction for ' . $locale);
        if ($locale === 'en_US') {
            verify(!preg_match('/[\x{0600}-\x{06ff}]/u', $view), 'Untranslated English view: ' . $view);
        } else {
            verify((bool) preg_match('/[\x{0600}-\x{06ff}]/u', $view), 'Persian text is missing');
        }
    }
    $GLOBALS['styles'] = [];
    $events = Shortcodes::events();
    verify(($GLOBALS['styles']['pesm-map'] ?? null) === ['pesm-ui'], 'The event directory must load its card styles without relying on a seat map');
    $GLOBALS['styles'] = [];
    $eventWithoutSessions = Shortcodes::event(['id'=>9]);
    verify(($GLOBALS['styles']['pesm-map'] ?? null) === ['pesm-ui'], 'An event without sessions must load its hero and empty-state styles');
    verify(str_contains($events, '&lt;script&gt;رویدادها&lt;/script&gt;'), 'User content must remain escaped and untranslated');
    verify(!str_contains($events, '<script>'), 'User content was inserted as HTML');
    Blocks::register();
    verify($GLOBALS['blocks']['events']['title'] === ($locale === 'en_US' ? 'Event list' : 'فهرست رویدادها'), 'Block title locale mismatch');
}

foreach ($GLOBALS['scripts'] as $handle=>$deps) {
    if ($handle !== 'pesm-qr') {
        verify(in_array('pesm-i18n', $deps, true), 'Missing translation dependency: ' . $handle);
    }
}

echo "PASS: English/Persian views, direction, block metadata, escaping and script dependencies.\n";
