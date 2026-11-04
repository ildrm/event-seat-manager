<?php

declare(strict_types=1);
namespace PESM\Security {
require_once __DIR__ . '/../src/Support/I18n.php';
require_once __DIR__ . '/../src/Support/Ui.php';
    class Capabilities {
        public static function centerAllowed(int $center, int $user): bool { return $center === 7 && $user === 20; }
    }
}
namespace {
    require __DIR__ . '/../src/Domain/Booking/HoldException.php';
    require __DIR__ . '/../src/Domain/Reporting/AttendanceReport.php';
    require __DIR__ . '/../src/Frontend/Shortcodes.php';
    use PESM\Frontend\Shortcodes;
    $GLOBALS['allow'] = true;
    $GLOBALS['script_enqueued'] = [];
    if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
    if (!defined('PESM_DIR')) define('PESM_DIR', dirname(__DIR__) . '/');
    if (!defined('PESM_VERSION')) define('PESM_VERSION','1.0.0');
    if (!defined('PESM_URL')) define('PESM_URL','https://example.test/wp-content/plugins/pesm/');
    function is_user_logged_in(): bool { return $GLOBALS['allow']; }
    function current_user_can(string $cap): bool { return $GLOBALS['allow'] && $cap === 'pesm_view_reports'; }
    function user_can(int $user, string $cap): bool { return $user === 20 && $cap === 'pesm_view_reports'; }
    function get_current_user_id(): int { return 20; }
    function get_option(string $key, mixed $default=null): mixed { return $key==='pesm_schema_version' ? 11 : $default; }
    function shortcode_atts(array $defaults,array $attributes,string $tag): array { return array_merge($defaults,$attributes); }
    function absint(mixed $value): int { return abs((int) $value); }
    function wp_enqueue_style(string $handle,mixed ...$args): void { }
    function wp_enqueue_script(string $handle,mixed ...$args): void { $GLOBALS['script_enqueued'][]=$handle; }
    function wp_nonce_url(string $url,string $action): string { return $url.'&_wpnonce=test'; }
    function wp_create_nonce(string $action): string { return 'rest-test-token'; }
    function admin_url(string $path): string { return 'https://example.test/wp-admin/'.$path; }
    function rest_url(string $path): string { return 'https://example.test/wp-json/'.$path; }
    function esc_url(string $value): string { return htmlspecialchars($value,ENT_QUOTES,'UTF-8'); }
    function esc_html(string $value): string { return htmlspecialchars($value,ENT_QUOTES,'UTF-8'); }
    function esc_attr(string $value): string { return htmlspecialchars($value,ENT_QUOTES,'UTF-8'); }
    final class DbMock {
        public string $prefix='wp_'; public int $reads=0;
        public function prepare(string $sql, mixed ...$args): string { foreach ($args as $arg) {$sql=preg_replace('/%d/',(string)$arg,$sql,1);} return $sql; }
        public function get_results(string $sql,string $mode): array {
            if (!str_contains($sql,'WHERE e.center_id=7')) throw new RuntimeException('Unscoped SQL');
            $this->reads++;
            return [['session_id'=>11,'title'=>'جلسه <unsafe>', 'starts_at'=>'2026-11-02 14:00:00',
                'capacity'=>900,'available'=>900,'held'=>0,'payment_pending'=>0,'sold'=>0,'blocked'=>0,
                'active_tickets'=>0,'checked_in'=>0,'cancelled_tickets'=>0]];
        }
        public function get_var(string $sql): string { return '2026-11-02 15:00:00'; }
    }
    $GLOBALS['wpdb']=new DbMock();
    $count=0;
    $html=Shortcodes::manager(['center'=>7]);
    foreach (['pesm_attendance_xlsx','pesm_attendance_csv','pesm-attendance-rows',
              'pesm-attendance-session','pesm-attendance-refresh','pesm-attendance-print',
              'data-center-id="7"','data-nonce="rest-test-token"'] as $fragment) {
        if (!str_contains($html,$fragment)) throw new RuntimeException("Missing dashboard integration: $fragment");
        $count++;
    }
    if (str_contains($html,'<unsafe>') || !str_contains($html,'&lt;unsafe&gt;')) throw new RuntimeException('Unsafe title rendering');
    $count++;
    if (!in_array('pesm-attendance',$GLOBALS['script_enqueued'],true)) throw new RuntimeException('JS not enqueued');
    if (!in_array('pesm-sales-insights',$GLOBALS['script_enqueued'],true)) throw new RuntimeException('Insights JS not enqueued');
    if (!str_contains($html,'pesm-insights-events') || !str_contains($html,'pesm-insights-zones')) throw new RuntimeException('Insights tables missing');
    if (str_contains($html,'<unsafe>')) throw new RuntimeException('Unsafe event title in insight filter');
    $count++;
    $GLOBALS['allow']=false;
    if (!str_contains(Shortcodes::manager(['center'=>7]),'ورود مدیر')) throw new RuntimeException('Guest dashboard access');
    $count++;
    echo "PASS: {$count} dashboard integration/access/escaping assertions (mocked WP).\n";
}
