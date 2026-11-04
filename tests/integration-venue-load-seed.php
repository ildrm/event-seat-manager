<?php

declare(strict_types=1);

/** Explicitly destructive fixture creation for an isolated acceptance site only. */
if (!defined('ABSPATH')||getenv('PESM_RUN_VENUE_LOAD')!=='1') { throw new RuntimeException('Enable only in a disposable WordPress acceptance installation.'); }
if (!defined('PESM_VENUE_ACCEPTANCE_TEST')) { define('PESM_VENUE_ACCEPTANCE_TEST',true); }

use PESM\Domain\Allocation\SessionService;
use PESM\Domain\Event\LifecycleService;
use PESM\Domain\Hall\HallService;
use PESM\Domain\Venue\JobService;
use PESM\Domain\Venue\LayoutRepository;

global $wpdb;
$p=$wpdb->prefix.'pesm_';
$path=getenv('PESM_VENUE_LOAD_FIXTURE')?:'/private/tmp/esm-integration/venue-load-fixture.json';
if (!str_starts_with($path,'/private/tmp/')&&!str_starts_with($path,'/tmp/')) { throw new RuntimeException('Keep private acceptance fixtures outside the web root.'); }
if (getenv('PESM_VENUE_LOAD_VERIFY')==='1') {
    $fixture=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR); $session=(int)$fixture['session_id'];
    $duplicate=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM (SELECT seat_id FROM {$p}allocation_tickets WHERE session_id=%d AND status IN ('active','checked_in') GROUP BY seat_id HAVING COUNT(*)>1) d",$session));
    $soldWithoutTicket=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}session_seats i WHERE i.session_id=%d AND i.status='sold' AND NOT EXISTS (SELECT 1 FROM {$p}allocation_tickets t WHERE t.session_id=i.session_id AND t.seat_id=i.seat_id AND t.status IN ('active','checked_in'))",$session));
    $ticketWithoutSale=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}allocation_tickets t LEFT JOIN {$p}session_seats i ON i.session_id=t.session_id AND i.seat_id=t.seat_id WHERE t.session_id=%d AND t.status IN ('active','checked_in') AND (i.status<>'sold' OR i.seat_id IS NULL)",$session));
    $duplicateOrder=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM (SELECT a.hold_id FROM {$p}allocation_orders a JOIN {$p}allocation_holds h ON h.id=a.hold_id WHERE h.session_id=%d GROUP BY a.hold_id HAVING COUNT(*)>1) d",$session));
    $brokenLine=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}allocation_lines l JOIN {$p}allocation_holds h ON h.id=l.hold_id WHERE h.session_id=%d AND l.status='issued' AND l.admission_count<>(SELECT COUNT(*) FROM {$p}allocation_tickets t WHERE t.line_id=l.id)",$session));
    $result=['oversells'=>$duplicate,'duplicate_issuance'=>$duplicateOrder,'sold_without_ticket'=>$soldWithoutTicket,
        'ticket_without_sale'=>$ticketWithoutSale,'broken_admission_lines'=>$brokenLine,
        'issued_tickets'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}allocation_tickets WHERE session_id=%d",$session)),
        'orders'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}allocation_orders a JOIN {$p}allocation_holds h ON h.id=a.hold_id WHERE h.session_id=%d",$session)),
        'checkins'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}allocation_checkins c JOIN {$p}allocation_tickets t ON t.id=c.ticket_id WHERE t.session_id=%d",$session))];
    $result['passed']=array_sum(array_intersect_key($result,array_flip(['oversells','duplicate_issuance','sold_without_ticket','ticket_without_sale','broken_admission_lines'])))===0;
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n"; return;
}

wp_set_current_user(1);
$layout=(int)(getenv('PESM_VENUE_LOAD_LAYOUT')?:12);
$info=$wpdb->get_row($wpdb->prepare("SELECT h.id,h.center_id FROM {$p}halls h JOIN {$p}layout_versions l ON l.hall_id=h.id WHERE l.id=%d",$layout));
if (!$info) { throw new RuntimeException('Generate the 150,000-seat fixture before preparing load acceptance.'); }
$overview=LayoutRepository::overview($layout);
if ($overview['capacity']!==150000) { throw new RuntimeException('Load acceptance requires an actual 150,000-seat layout.'); }
LayoutRepository::publish($layout);
$run=bin2hex(random_bytes(5));
$event=HallService::createEvent((int)$info->center_id,'Venue load '.$run);
LifecycleService::change('event',$event,'published',1);
$session=HallService::createSession($event,(int)$info->id,gmdate('Y-m-d H:i:s',time()+3600),
    gmdate('Y-m-d H:i:s',time()-300),gmdate('Y-m-d H:i:s',time()+1800),0,'allocated');
SessionService::configure($session,['ticket_mode'=>'buyer_choice','group_entry_policy'=>'incremental']);
$cursor=0;
do { $progress=SessionService::provision($session,$cursor,2000); $cursor=$progress['cursor']; } while (!$progress['complete']);
$queued=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}venue_jobs WHERE session_id=%d AND kind='provision' AND status='pending'",$session));
foreach ($queued as $jobId) { JobService::run((int)$jobId); }
LifecycleService::change('session',$session,'published',1);
$bySection=[];
foreach ($overview['sections'] as $section) {
    $bySection[]=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT seat_id FROM {$p}venue_places WHERE layout_id=%d AND section_code=%s ORDER BY seat_id LIMIT 80",$layout,$section['code'])));
}
$seats=[];
for ($n=0;count($seats)<1000;$n++) {
    $found=false;
    foreach ($bySection as $ids) { if (isset($ids[$n])&&count($seats)<1000) { $seats[]=$ids[$n]; $found=true; } }
    if (!$found) { throw new RuntimeException('Not enough distributed fixture places.'); }
}
$contention=(int)$wpdb->get_var($wpdb->prepare("SELECT seat_id FROM {$p}venue_places WHERE layout_id=%d AND section_code=%s ORDER BY seat_id DESC LIMIT 1",$layout,$overview['sections'][0]['code']));
$group=$wpdb->get_row($wpdb->prepare("SELECT group_code,section_code,capacity FROM {$p}venue_groups WHERE layout_id=%d ORDER BY id DESC LIMIT 1",$layout),ARRAY_A);
$group['seat_ids']=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT seat_id FROM {$p}venue_places WHERE layout_id=%d AND group_code=%s ORDER BY seat_id",$layout,$group['group_code'])));
$operators=[];
for ($i=0;$i<20;$i++) {
    $user=wp_create_user('venue_gate_'.$run.'_'.$i,wp_generate_password(48,true,true));
    if (is_wp_error($user)) { throw new RuntimeException('Unable to create acceptance operator.'); }
    $account=new WP_User($user); $account->set_role('pesm_gate_operator');
    $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$p}center_users (center_id,user_id) VALUES (%d,%d)",(int)$info->center_id,$user));
    $expiry=time()+7200; $token=WP_Session_Tokens::get_instance($user)->create($expiry);
    $cookie=wp_generate_auth_cookie($user,$expiry,'logged_in',$token);
    $_COOKIE[LOGGED_IN_COOKIE]=$cookie; wp_set_current_user($user);
    $operators[]=['user_id'=>$user,'cookie'=>LOGGED_IN_COOKIE.'='.$cookie,'nonce'=>wp_create_nonce('wp_rest')];
}
$fixture=['run_id'=>$run,'created_at'=>gmdate('c'),'layout_id'=>$layout,'center_id'=>(int)$info->center_id,
    'event_id'=>$event,'session_id'=>$session,'capacity'=>150000,'seat_ids'=>$seats,'contention_seat_id'=>$contention,
    'group'=>$group,'operators'=>$operators,'tile_codes'=>array_column($overview['tiles'],'code'),
    'environment'=>['php'=>PHP_VERSION,'wordpress'=>get_bloginfo('version'),'woocommerce'=>defined('WC_VERSION')?WC_VERSION:'unknown',
        'database'=>(string)$wpdb->get_var('SELECT VERSION()'),
        'application_profile'=>'Two PHP built-in replicas with 16 workers each; loopback load-balancing proxy; shared InnoDB; dedicated CLI jobs.']];
$json=json_encode($fixture,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
if (file_put_contents($path,$json,LOCK_EX)===false||!chmod($path,0600)) { throw new RuntimeException('Unable to write private acceptance fixture.'); }
echo json_encode(['fixture'=>$path,'session_id'=>$session,'capacity'=>150000,'purchase_clients'=>count($seats),'gate_operators'=>count($operators)],JSON_THROW_ON_ERROR)."\n";
