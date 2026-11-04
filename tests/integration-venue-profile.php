<?php

declare(strict_types=1);

if (!defined('ABSPATH')||getenv('PESM_RUN_VENUE_LOAD')!=='1') { throw new RuntimeException('Run only in the disposable venue acceptance site.'); }

use PESM\Domain\Allocation\ReservationService;
use PESM\Security\RateLimiter;

global $wpdb;
$fixture=json_decode((string)file_get_contents(getenv('PESM_VENUE_LOAD_FIXTURE')?:'/private/tmp/esm-integration/venue-load-fixture.json'),true,32,JSON_THROW_ON_ERROR);
$session=(int)$fixture['session_id']; $seat=(int)$fixture['contention_seat_id']; $tile=$fixture['tile_codes'][0];
function venueProfile(callable $action,int $samples=30,?callable $cleanup=null): array {
    global $wpdb; $values=[]; $queries=[];
    for ($i=0;$i<$samples;$i++) { $before=$wpdb->num_queries; $start=hrtime(true); $result=$action($i); $values[]=(hrtime(true)-$start)/1000000; $queries[]=$wpdb->num_queries-$before; if ($cleanup) { $cleanup($result); } }
    sort($values); sort($queries);
    return ['samples'=>$samples,'p50_ms'=>round($values[(int)floor(($samples-1)/2)],3),'p95_ms'=>round($values[(int)ceil($samples*.95)-1],3),
        'min_ms'=>round($values[0],3),'max_ms'=>round(end($values),3),'queries_per_call'=>$queries[(int)floor(($samples-1)/2)]];
}
$profile=[];
$profile['quote']=venueProfile(static fn()=>ReservationService::quote($session,[['type'=>'seat','id'=>$seat]]));
$profile['availability_2000']=venueProfile(static fn()=>ReservationService::availability($session,[$tile]));
$profile['hold']=venueProfile(static fn()=>ReservationService::acquire($session,[['type'=>'seat','id'=>$seat]],bin2hex(random_bytes(32))),30,
    static fn($hold)=>ReservationService::mutate($session,$hold['hold_token'],'release',[],bin2hex(random_bytes(32))));
$profile['ip_rate_counter']=venueProfile(static fn()=>RateLimiter::allow('venue_profile_'.substr($fixture['run_id'],0,10),100000,60));
$variables=$wpdb->get_results("SHOW GLOBAL VARIABLES WHERE Variable_name IN ('innodb_buffer_pool_size','innodb_flush_log_at_trx_commit','sync_binlog','max_connections','innodb_autoinc_lock_mode')",ARRAY_A);
$result=['profile'=>$profile,'database_variables'=>array_column($variables,'Value','Variable_name'),
    'peak_php_memory_mib'=>round(memory_get_peak_usage(true)/1048576,2),'wp_bootstrapped_queries'=>$wpdb->num_queries,
    'scope'=>'Thirty sequential warm service calls in one WordPress/WooCommerce CLI process. Excludes HTTP/worker queue and repeated WordPress bootstrap.'];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
