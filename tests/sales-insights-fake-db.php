<?php

declare(strict_types=1);

namespace PESM\Security {
require_once __DIR__ . '/../src/Support/I18n.php';
    final class Capabilities {
        public static function centerAllowed(int $center, int $user): bool { return $center===7 && $user===20; }
    }
}
namespace {
    require __DIR__ . '/../src/Domain/Booking/HoldException.php';
    require __DIR__ . '/../src/Domain/Reporting/AttendanceReport.php';
    require __DIR__ . '/../src/Domain/Reporting/SalesInsights.php';

    use PESM\Domain\Booking\HoldException;
    use PESM\Domain\Reporting\SalesInsights;
    if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
    $GLOBALS['authorized']=true;
    function user_can(int $user,string $cap): bool {
        return $GLOBALS['authorized'] && $user===20 && $cap==='pesm_view_reports';
    }
    function get_option(string $name,mixed $fallback=null): mixed { return $name==='pesm_schema_version' ? 13 : $fallback; }

    final class SalesDbMock {
        public string $prefix='wp_';
        public array $queries=[];
        public bool $failure=false;
        public function prepare(string $query,mixed ...$args): string {
            foreach ($args as $arg) {
                $value = is_int($arg) ? (string)$arg : "'" . str_replace("'","''",(string)$arg) . "'";
                $query=preg_replace('/%[ds]/', $value, $query, 1);
            }
            return $query;
        }
        public function get_var(string $query): string { return '2026-11-03 00:00:00'; }
        public function get_results(string $query,string $outputType): array|false {
            $this->queries[]=$query;
            if ($this->failure) return false;
            if (!str_contains($query,'e.center_id=7') && !str_contains($query,'e.center_id = 7')) {
                throw new \RuntimeException('Unscoped query');
            }
            if (str_contains($query,'COALESCE(c.total_capacity')) {
                if (str_contains($query,'s.id=999')) return [];
                return [
                    ['session_id'=>11,'event_id'=>15,'title'=>'نمایش <img src=x onerror=alert(1)>',
                     'starts_at'=>'2026-11-01 13:00:00','capacity'=>100,'available'=>55,'held'=>5,
                     'payment_pending'=>0,'sold'=>40,'blocked'=>0,'active_tickets'=>35,'checked_in'=>5,'cancelled_tickets'=>3],
                    ['session_id'=>12,'event_id'=>15,'title'=>'نمایش <img src=x onerror=alert(1)>',
                     'starts_at'=>'2026-11-02 13:00:00','capacity'=>100,'available'=>70,'held'=>0,
                     'payment_pending'=>0,'sold'=>30,'blocked'=>0,'active_tickets'=>25,'checked_in'=>5,'cancelled_tickets'=>0],
                    ['session_id'=>13,'event_id'=>16,'title'=>'دوم',
                     'starts_at'=>'2026-11-02 13:00:00','capacity'=>0,'available'=>0,'held'=>0,
                     'payment_pending'=>0,'sold'=>0,'blocked'=>0,'active_tickets'=>0,'checked_in'=>0,'cancelled_tickets'=>0],
                ];
            }
            if (str_contains($query,'DATE(t.created_at)')) {
                if (str_contains($query,'pesm_capacity_tickets')) {
                    return [['issued_day'=>gmdate('Y-m-d'), 'total'=>2]];
                }
                return [['issued_day'=>gmdate('Y-m-d'),'total'=>3], ['issued_day'=>'1999-01-01','total'=>1000]];
            }
            if (str_contains($query,'seat.zone_label')) {
                return [['zone'=>'VIP <script>','row_label'=>'A','total'=>'6'],
                        ['zone'=>'عمومی','row_label'=>'B','total'=>'4']];
            }
            throw new \RuntimeException('Unexpected query: ' . $query);
        }
    }
    $GLOBALS['wpdb'] = new SalesDbMock();
    $service=new SalesInsights();
    $count=0;
    $result=$service->summary(20,7,7);
    if (count($result['trend'])!==7) throw new \RuntimeException('7 days expected'); $count++;
    if (end($result['trend'])['issued_active_tickets']!==5) throw new \RuntimeException('Both ticket types'); $count++;
    if (count($result['events'])!==2 || $result['events'][0]['sold']!==70) throw new \RuntimeException('Event aggregation'); $count++;
    if ($result['events'][0]['occupancy_percent']!==35.0) throw new \RuntimeException('Occupancy'); $count++;
    if ($result['events'][1]['occupancy_percent']!==0.0) throw new \RuntimeException('Zero denominator'); $count++;
    if ($result['zone_rows'][0]['tickets']!==6 || $result['not_financial_revenue']!==true) throw new \RuntimeException('Zone counts'); $count++;
    if ($result['sessions'][0]['event_title'] !== 'نمایش <img src=x onerror=alert(1)>') throw new \RuntimeException('Unexpected title'); $count++;
    foreach ($GLOBALS['wpdb']->queries as $sql) {
        if (!str_contains($sql,'center_id=7') && !str_contains($sql,'center_id = 7')) throw new \RuntimeException('missing scope');
        if (str_contains($sql,'FROM wp_tickets t') || str_contains($sql,'FROM pesm_capacity_tickets t')) {
            if (!str_contains($sql,"t.status IN ('active','checked_in')")) throw new \RuntimeException('Invalid tickets counted');
            if (!str_contains($sql,'t.created_at < ')) throw new \RuntimeException('Missing exclusive window bound');
        }
        $count++;
    }
    foreach ([0,6,91] as $days) {
        try { $service->summary(20,7,$days); throw new \RuntimeException('invalid days accepted'); }
        catch (HoldException $e) { if ($e->errorCode!=='invalid_report_filter') throw $e; $count++; }
    }
    foreach ([[0,7],[20,8]] as [$user,$center]) {
        try { $service->summary($user,$center,30); throw new \RuntimeException('unauthorized'); }
        catch (HoldException $e) { if ($e->httpStatus!==403) throw $e; $count++; }
    }
    $GLOBALS['authorized']=false;
    try { $service->summary(20,7,30); throw new \RuntimeException('Revoked permission'); }
    catch (HoldException $e) { if ($e->httpStatus!==403) throw $e; $count++; }
    $GLOBALS['authorized']=true;
    try { $service->summary(20,7,30,999); throw new \RuntimeException('Cross-scope session accepted'); }
    catch (HoldException $e) { if ($e->httpStatus!==404) throw $e; $count++; }
    $GLOBALS['wpdb']->failure=true;
    try { $service->summary(20,7,30); throw new \RuntimeException('DB failure ignored'); }
    catch (HoldException $e) { if ($e->httpStatus!==503) throw $e; $count++; }
    echo "PASS: {$count} analytics scope/count/range/security assertions (fake DB).\n";
}
