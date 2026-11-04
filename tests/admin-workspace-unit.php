<?php
declare(strict_types=1);

/** Render and permission regression for the grouped admin workspace; mocked WordPress. */
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'PESM\\')) {
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
        if (is_file($path)) { require_once $path; }
    }
});
define('PESM_VERSION', '1.0.0');
define('PESM_URL', 'https://example.test/plugins/event-seat-manager/');
$GLOBALS['test_locale'] = 'fa_IR';
$GLOBALS['test_manager'] = true;
function determine_locale(): string { return $GLOBALS['test_locale']; }
function __(string $message, string $domain): string { return $message; }
function esc_html__(string $message, string $domain): string { return esc_html($message); }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_attr(string $value): string { return esc_html($value); }
function esc_url(string $value): string { return esc_html($value); }
function esc_textarea(string $value): string { return esc_html($value); }
function current_user_can(string $cap): bool { return $GLOBALS['test_manager'] || in_array($cap, ['pesm_access_dashboard', 'pesm_view_reports'], true); }
function get_current_user_id(): int { return 7; }
function get_option(string $key, mixed $default = null): mixed { return $key === 'pesm_schema_version' ? 18 : $default; }
function admin_url(string $path): string { return 'https://example.test/wp-admin/' . $path; }
function rest_url(string $path): string { return 'https://example.test/wp-json/' . $path; }
function is_admin(): bool { return true; }
function add_query_arg(mixed ...$args): string {
    if (is_array($args[0])) { return $args[1] . '?' . http_build_query($args[0]); }
    return $args[2] . '?' . http_build_query([$args[0]=>$args[1]]);
}
function wp_nonce_url(string $url, string $action): string { return $url . '&nonce=test'; }
function wp_nonce_field(string $action): void { echo '<input type="hidden" name="_wpnonce" value="test">'; }
function wp_create_nonce(string $action): string { return 'test'; }
function wp_generate_uuid4(): string { return '12345678-1234-4234-8234-123456789abc'; }
function wp_json_encode(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
function wp_enqueue_script(mixed ...$args): void {}
function submit_button(string $text, string $type, string $name, bool $wrap): void { echo '<button type="submit" class="button">' . esc_html($text) . '</button>'; }
function checked(mixed $value, mixed $expected, bool $echo): string { return $value === $expected ? 'checked' : ''; }
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_-]/', '', $value); }
function wp_unslash(string $value): string { return $value; }

final class AdminWorkspaceDatabase {
    public string $prefix = 'wp_';
    public function prepare(string $sql, mixed ...$args): string { return $sql; }
    public function get_results(string $sql): array {
        if (str_contains($sql, 'SELECT * FROM wp_pesm_centers') || str_contains($sql, 'SELECT c.* FROM wp_pesm_centers')) {
            return [(object) ['id'=>1, 'name'=>'Center']];
        }
        if (str_contains($sql, 'SELECT * FROM wp_pesm_halls')) {
            return [(object) ['id'=>2, 'center_id'=>1, 'name'=>'Hall', 'current_layout_id'=>3]];
        }
        if (str_contains($sql, 'SELECT * FROM wp_pesm_events')) {
            return [(object) ['id'=>4, 'center_id'=>1, 'title'=>'User <em>title</em>', 'status'=>'published']];
        }
        if (str_contains($sql, 'SELECT * FROM wp_pesm_sessions')) {
            return [(object) ['id'=>5, 'event_id'=>4, 'hall_id'=>2, 'mode'=>'seated', 'status'=>'published', 'unit_price_minor'=>0]];
        }
        return [];
    }
}
$GLOBALS['wpdb'] = new AdminWorkspaceDatabase();
$checks = 0;
function verify(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
    $GLOBALS['checks']++;
}

ob_start();
\PESM\Admin\Pages::render();
$html = (string) ob_get_clean();
$document = new DOMDocument();
libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="UTF-8">' . $html);
$xpath = new DOMXPath($document);
verify($xpath->query('//*[@data-pesm-panel]')->length === 6, 'All six areas must be accessible without enhancement JavaScript');
verify($xpath->query('//*[@data-pesm-panel and @hidden]')->length === 0, 'Server must not hide navigation panels');
verify(str_contains($html, 'User &lt;em&gt;title&lt;/em&gt;') && !str_contains($html, 'User <em>title</em>'), 'Stored titles must remain escaped');
verify($xpath->query('//*[@id="pesm-workspace-content" and @tabindex="-1"]')->length === 1, 'Skip link target must accept focus');
foreach ($xpath->query('//*[@data-pesm-panel]') as $panel) {
    foreach ($xpath->query('.//form', $panel) as $form) {
        $fields = $xpath->query('.//input[@name="pesm_view"]', $form);
        verify($fields->length === 1 && $fields->item(0)->getAttribute('value') === $panel->getAttribute('data-pesm-panel'), 'Each form must return to its actual area');
        verify($xpath->query('.//input[@name="_wpnonce"]', $form)->length === 1, 'Existing nonce fields must remain inside their forms');
    }
}
foreach (['pesm_create_center', 'pesm_create_hall', 'pesm_layout_preset', 'pesm_layout_import', 'pesm_change_attendee',
    'pesm_create_event', 'pesm_create_session', 'pesm_seat_block', 'pesm_transfer_ticket_seat', 'pesm_create_ticket_bundle',
    'pesm_cancel_free_ticket', 'pesm_refund_request', 'pesm_refund_reconcile', 'pesm_assign_center', 'pesm_edit_event',
    'pesm_postpone_session', 'pesm_seating_rules', 'pesm_export_capacity', 'pesm_save_notification_settings'] as $action) {
    verify($xpath->query('//input[@name="action" and @value="' . $action . '"]')->length > 0, 'Existing action disappeared: ' . $action);
}
// This integration contract catches startup failures when a required editor button is absent.
// It reads the actual JS selectors rather than maintaining a second hand-copied control list.
$editor = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " pesm-layout-editor ")]')->item(0);
verify($editor !== null, 'An authorized layout designer must receive the editor');
$editorScript = (string) file_get_contents(__DIR__ . '/../assets/js/layout-editor.js');
preg_match_all("/panel\\.querySelector\\('([^']+)'\\)/", $editorScript, $requiredSelectors);
foreach (array_unique($requiredSelectors[1]) as $selector) {
    $query = str_starts_with($selector, '.')
        ? './/*[contains(concat(" ", normalize-space(@class), " "), " ' . substr($selector, 1) . ' ")]'
        : './/' . $selector;
    verify($xpath->query($query, $editor)->length > 0, 'Missing required editor control: ' . $selector);
}
preg_match_all("/input\\('([^']+)'\\)/", $editorScript, $requiredInputs);
foreach (array_unique($requiredInputs[1]) as $name) {
    verify($xpath->query('.//input[@name="' . $name . '"]', $editor)->length === 1, 'Missing required editor field: ' . $name);
}
$GLOBALS['test_manager'] = false;
ob_start();
\PESM\Admin\Pages::render();
$analyst = (string) ob_get_clean();
foreach (['pesm_create_center', 'pesm_create_event', 'pesm_create_session', 'pesm_refund_request', 'pesm_assign_center'] as $action) {
    verify(!str_contains($analyst, 'value="' . $action . '"'), 'Analyst must not receive a privileged form: ' . $action);
}
verify(str_contains($analyst, 'data-pesm-panel="reports"'), 'An authorized analyst must retain reports');
echo "PASS: {$checks} grouped workspace, form integrity, escaping and permission checks.\n";
