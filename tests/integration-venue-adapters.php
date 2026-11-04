<?php
declare(strict_types=1);

/** Exercise actual registered REST adapters, permissions, reports and browser mount contracts. */
if (!defined('ABSPATH')) { echo "SKIP: run inside disposable local WordPress.\n"; return; }
if (getenv('PESM_RUN_INTEGRATION') !== '1' || wp_get_environment_type() !== 'local') { throw new RuntimeException('Disposable local integration opt-in required.'); }
if (!defined('PESM_VENUE_ACCEPTANCE_TEST')) { define('PESM_VENUE_ACCEPTANCE_TEST', true); }
wp_set_current_user(1);
$fixture = get_option('pesm_allocation_integration_fixture', []);
if (!$fixture) { throw new RuntimeException('Run allocation integration first.'); }
global $wpdb;
$p = $wpdb->prefix . 'pesm_'; $checks = [];
$ok = static function (bool $value, string $label) use (&$checks): void { if (!$value) { throw new RuntimeException($label); } $checks[] = $label; };
$request = static function (string $method, string $path, array $body = []): WP_REST_Response {
    $r = new WP_REST_Request($method, $path);
    if ($method === 'GET') { $r->set_query_params($body); }
    else { $r->set_header('Content-Type','application/json'); $r->set_body(wp_json_encode($body)); }
    return rest_do_request($r);
};
$session = (int) $fixture['session_id']; $layout = (int) $fixture['layout_id']; $center = (int) $fixture['center_id'];
$catalog = $request('GET','/pesm/v2/presets');
$ok($catalog->get_status()===200 && count($catalog->get_data()['presets'])===50,'registered public catalog exposes fifty recipes');
$overview = $request('GET','/pesm/v2/sessions/'.$session.'/layout'); $data = $overview->get_data();
$ok($overview->get_status()===200 && !array_key_exists('seats',$data),'public overview has no unbounded seat array');
$ok(isset($data['sections'][0]['price_min_minor'],$data['unit_price_minor'],$data['purchase_limit']),'overview includes canonical server prices and purchase limit');
$tileCode = $data['tiles'][0]['tile_code'];
$tile = $request('GET','/pesm/v2/sessions/'.$session.'/tiles/'.$tileCode)->get_data();
$ok(count($tile['seats'])<=2000,'geometry tile remains bounded');
$row = (string) $wpdb->get_var($wpdb->prepare("SELECT row_label FROM {$p}seats WHERE layout_id=%d ORDER BY id LIMIT 1",$layout));
$page = $request('GET','/pesm/v2/sessions/'.$session.'/units',['type'=>'seat','row'=>$row,'limit'=>3])->get_data();
$ok(count($page['items'])<=3 && count($page['items'])>0,'public inventory list applies its bound');
foreach ($page['items'] as $item) { $ok($item['row_label']===$row && isset($item['available'],$item['total_minor']),'row filtering and availability/price precede pagination'); }
$empty = $request('GET','/pesm/v2/sessions/'.$session.'/units',['type'=>'table','row'=>'NO_SUCH_ROW'])->get_data();
$ok($empty['items']===[],'group row filter cannot silently return unrelated groups');
$groups = $request('GET','/pesm/v2/sessions/'.$session.'/units',['type'=>'table','limit'=>1])->get_data();
$ok(isset($groups['items'][0]['tile_code']) && $groups['items'][0]['tile_code']!=='','public groups include their canonical map locator');
$config = $request('GET','/pesm/v2/admin/sessions/'.$session.'/config')->get_data();
$ok(isset($config['settings'],$config['session_id'],$config['layout_id'],$config['hall_id']),'admin configuration supplies its exact venue identities');

$report = (new PESM\Domain\Reporting\AttendanceReport())->summary(1,$center,$session);
$expectedUsed = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}allocation_tickets WHERE session_id=%d AND status='checked_in'",$session));
$ok($report['sessions'][0]['checked_in']===$expectedUsed,'legacy attendance dashboard counts allocated admission slots');
$insights = (new PESM\Domain\Reporting\SalesInsights())->summary(1,$center,30,$session);
$ok($insights['sessions'][0]['session_id']===$session,'existing analytics accepts allocated sessions without SQL errors');
$anomalies = (new PESM\Domain\Reconciliation\ReconciliationService())->anomalies(1,$center,100);
$ok(isset($anomalies['next_cursor']['allocated']),'combined reconciliation provides an independent allocated cursor');
$exhausted = (new PESM\Domain\Reconciliation\ReconciliationService())->anomalies(1,$center,100,['seated'=>-1,'general'=>-1,'allocated'=>-1]);
$ok($exhausted['items']===[],'exhausted inventory streams never restart during composite pagination');

wp_set_current_user(0);
$denied = $request('GET','/pesm/v2/admin/layouts/'.$layout);
$ok($denied->get_status()>=400,'anonymous callers cannot read administrator layouts');
$ok($request('GET','/pesm/v1/sessions/'.$session.'/availability')->get_status()===409,'legacy availability cannot expand allocated inventory');
$legacy = $request('POST','/pesm/v1/holds',['session_id'=>$session,'seat_ids'=>[(int)$fixture['member_ids'][0]],'idempotency_key'=>bin2hex(random_bytes(24))]);
$ok($legacy->get_status()>=400,'legacy acquisition cannot bypass allocation group rules');

$limited = wp_create_user('venue_adapter_'.bin2hex(random_bytes(5)),bin2hex(random_bytes(24)));
if (is_wp_error($limited)) { throw new RuntimeException('Unable to create restricted test actor.'); }
$user = get_user_by('id',$limited); $user->add_cap('pesm_manage_sessions'); $user->add_cap('pesm_edit_layouts');
$wpdb->insert($p.'center_users',['center_id'=>$center,'user_id'=>$limited]);
try {
    wp_set_current_user($limited);
    $countBefore = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}sessions WHERE event_id=%d",(int)$fixture['event_id']));
    $creation = ['event_id'=>(int)$fixture['event_id'],'hall_id'=>(int)$fixture['hall_id'],'starts_at'=>gmdate('Y-m-d H:i:s',time()+7200),'sale_starts_at'=>gmdate('Y-m-d H:i:s',time()-60),'sale_ends_at'=>gmdate('Y-m-d H:i:s',time()+3600),'settings'=>['pricing'=>['categories'=>['STANDARD'=>1]]]];
    $badPrice = $request('POST','/pesm/v2/admin/sessions',$creation);
    $ok($badPrice->get_status()===403,'creation cannot bypass dedicated pricing capability');
    $ok((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}sessions WHERE event_id=%d",(int)$fixture['event_id']))===$countBefore,'denied pricing creation leaves no session or provisioning side effects');
    $patch = $request('PATCH','/pesm/v2/admin/sessions/'.$session.'/config',['settings'=>['pricing'=>[]]]);
    $ok($patch->get_status()===403,'configuration PATCH enforces the same pricing capability');
    wp_set_current_user(1);
    $creation['settings']=['ticket_mode'=>'invalid'];
    $invalid = $request('POST','/pesm/v2/admin/sessions',$creation);
    $ok($invalid->get_status()>=400 && (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}sessions WHERE event_id=%d",(int)$fixture['event_id']))===$countBefore,'invalid settings are rejected before committing a session');
} finally {
    wp_set_current_user(1);
    $wpdb->delete($p.'center_users',['user_id'=>$limited]);
    require_once ABSPATH.'wp-admin/includes/user.php';
    wp_delete_user($limited);
}
$mount = PESM\Support\VenueUi::render('cart',['session'=>(int)$fixture['free_session_id'],'cartKey'=>'fixture','cartNonce'=>'fixture-only']);
preg_match('/data-config="([^"]+)"/',$mount,$match);
$mountConfig = json_decode(html_entity_decode($match[1],ENT_QUOTES),true);
$ok($mountConfig['sessionId']===(int)$fixture['free_session_id'],'private cart mount initializes the canonical sessionId');
$scripts = wp_scripts();
$ok(in_array('pesm-qr',$scripts->registered['pesm-venue']->deps,true),'allocated ticket clients load the local QR renderer');
$result = ['passed'=>true,'checks'=>count($checks),'scenarios'=>$checks,'schema'=>(int)get_option('pesm_schema_version')];
file_put_contents('/private/tmp/esm-integration/venue-adapter-results.json',wp_json_encode($result,JSON_PRETTY_PRINT));
echo wp_json_encode($result,JSON_PRETTY_PRINT)."\n";
