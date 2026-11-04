<?php

declare(strict_types=1);

namespace PESM\Security {
require_once __DIR__ . '/../src/Support/I18n.php';
    class Capabilities {
        public static bool $allowed = false;
        public static function centerAllowed(int $center, int $user): bool { return self::$allowed && $center === 7 && $user === 20; }
    }
}
namespace {
    require __DIR__ . '/../src/Domain/Booking/HoldException.php';
    require __DIR__ . '/../src/Domain/Reporting/AttendanceReport.php';
    use PESM\Domain\Booking\HoldException;
    use PESM\Domain\Reporting\AttendanceReport;
    use PESM\Security\Capabilities;
    function user_can(int $user, string $cap): bool { return $user === 20 && $cap === 'pesm_view_reports'; }
    function get_option(string $name, mixed $default=null): mixed { return $name === 'pesm_schema_version' ? 4 : $default; }
    class ReportDb {
        public string $prefix = 'wp_';
        public int $reads = 0;
        public string $sql = '';
        public function prepare(string $sql, int ...$args): string {
            $this->sql = $sql;
            foreach ($args as $arg) { $sql = preg_replace('/%d/',(string)$arg,$sql,1); }
            return $sql;
        }
        public function get_results(string $query, string $mode): array {
            $this->reads++;
            if (!str_contains($query, 'WHERE e.center_id=7')) { throw new \RuntimeException('scope missing'); }
            return [['session_id'=>'40','title'=>'<رویداد>','starts_at'=>'2026-11-02 12:00:00',
                'capacity'=>'900','available'=>'500','held'=>'30','payment_pending'=>'20', 'sold'=>'350',
                'blocked'=>'0','active_tickets'=>'300','checked_in'=>'45','cancelled_tickets'=>'5']];
        }
        public function get_var(string $query): string { return '2026-11-02 18:00:00'; }
    }
    if (!defined('ARRAY_A')) { define('ARRAY_A','ARRAY_A'); }
    $GLOBALS['wpdb'] = new ReportDb();
    $report = new AttendanceReport();
    $count=0;
    foreach ([[0,7],[20,0],[20,8],[19,7]] as [$user,$center]) {
        try { $report->summary($user,$center); throw new \RuntimeException('Unauthorized report succeeded'); }
        catch (HoldException $e) { if ($e->errorCode!=='permission_denied') { throw $e; } $count++; }
    }
    if ($GLOBALS['wpdb']->reads) { throw new \RuntimeException('Unauthenticated query made'); }
    Capabilities::$allowed = true;
    $result=$report->summary(20,7);
    if ($result['center_id']!==7 || $result['sessions'][0]['checked_in']!==45 ||
        $result['sessions'][0]['no_show']!==300 || $GLOBALS['wpdb']->reads!==1) {
        throw new \RuntimeException('Report aggregation incorrect');
    }
    $count+=4;
    if (!str_contains($GLOBALS['wpdb']->sql, 'GROUP BY session_id')) { throw new \RuntimeException('SQL grouping missing'); }
    $count++;
    if (!str_contains($GLOBALS['wpdb']->sql, 'session_capacity') ||
        !str_contains($GLOBALS['wpdb']->sql, 'capacity_holds') ||
        !str_contains($GLOBALS['wpdb']->sql, "s.mode='general'")) {
        throw new \RuntimeException('General-admission totals missing from aggregate query');
    }
    $count += 3;
    echo "PASS: {$count} report scope & aggregation assertions (mocked DB).\n";
}
