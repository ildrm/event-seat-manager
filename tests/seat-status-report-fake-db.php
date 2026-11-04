<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';

require __DIR__ . '/../src/Domain/Booking/HoldException.php';
require __DIR__ . '/../src/Security/Capabilities.php';
require __DIR__ . '/../src/Domain/Reporting/SeatStatusReport.php';

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Reporting\SeatStatusReport;

const ARRAY_A = 'ARRAY_A';
function user_can(int $userId, string $cap): bool {
    return $userId===7 && $cap === 'pesm_view_reports';
}
final class FakeStatusDb {
    public string $prefix='wp_';
    public int $queried=0;
    public function prepare(string $sql,mixed ...$values): string {
        foreach ($values as $v) $sql=preg_replace('/%d/',(string)(int)$v,$sql,1);
        return $sql;
    }
    public function get_var(string $sql): int { return str_contains($sql,'center_id = 3 AND user_id = 7') ? 1 : 0; }
    public function get_row(string $sql,string $format): ?array {
        ++$this->queried;
        return str_contains($sql,'s.id=11 AND e.center_id=3') ?
            ['id'=>11,'layout_id'=>5,'mode'=>'seated'] : null;
    }
    public function get_results(string $sql,string $format): array {
        ++$this->queried;
        if (!str_contains($sql,'inv.session_id=11') || !str_contains($sql,'seat.layout_id=5')) {
            throw new RuntimeException('Unscoped seat inventory query');
        }
        return [
            ['id'=>'1','seat_code'=>'A-1','row_label'=>'A','zone_label'=>'VIP','pos_x'=>'10','pos_y'=>'20','status'=>'sold'],
            ['id'=>'2','seat_code'=>'A-2','row_label'=>'A','zone_label'=>'VIP','pos_x'=>'50','pos_y'=>'20','status'=>'available'],
            ['id'=>'3','seat_code'=>'B-1','row_label'=>'B','zone_label'=>'عمومی','pos_x'=>'10','pos_y'=>'65','status'=>'held'],
            ['id'=>'4','seat_code'=>'B-2','row_label'=>'B','zone_label'=>'عمومی','pos_x'=>'50','pos_y'=>'65','status'=>'invalid'],
        ];
    }
}
$wpdb=new FakeStatusDb();$report=new SeatStatusReport();$checks=0;
function okStatus(bool $passed,string $why):void {global $checks; ++$checks;if(!$passed)throw new RuntimeException($why);}
$result=$report->snapshot(7,3,11);
okStatus($result['session_id']===11,'session id');
okStatus(count($result['seats'])===4,'rows');
okStatus($result['totals']['sold']===1 && $result['totals']['available']===1,'counts');
okStatus($result['totals']['held']===1 && $result['totals']['missing']===1,'unknown status refused');
okStatus(count($result['groups'])===2,'zone-row groups');
okStatus($result['groups'][0]['sold']===1,'VIP sold');
okStatus($result['seats'][0]['pos_x']===10,'numeric coordinate');
okStatus(!array_key_exists('phone',$result['seats'][0])&&!array_key_exists('name',$result['seats'][0]),'no PII');
$before=$wpdb->queried;
foreach ([[8,3,11],[7,4,11],[7,3,-1]] as $args) {
    try{$report->snapshot(...$args);throw new RuntimeException('Unauthorized request succeeded');}
    catch(HoldException $e){okStatus($e->errorCode==='permission_denied' && $e->httpStatus===403,'authorization rejected');}
}
okStatus($wpdb->queried===$before,'permission checked before session query');
try{$report->snapshot(7,3,12);throw new RuntimeException('Other session loaded');}
catch(HoldException $e){okStatus($e->httpStatus===404,'foreign session rejected');}
echo "PASS: {$checks} scoped seat status fake DB assertions (not MySQL integration).\n";
