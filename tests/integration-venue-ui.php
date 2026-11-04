<?php
declare(strict_types=1);

if (!defined('ABSPATH')) { echo "SKIP: run inside disposable local WordPress.\n"; return; }
if (getenv('PESM_RUN_INTEGRATION') !== '1' || wp_get_environment_type() !== 'local') { throw new RuntimeException('Local integration opt-in is required.'); }
wp_set_current_user(1);
$fixture = get_option('pesm_allocation_integration_fixture', []);
if (!$fixture) { throw new RuntimeException('Run integration-allocation.php first.'); }
$pages = [];
foreach (['booking' => '[pesm_seat_map session="' . $fixture['session_id'] . '"]', 'tickets' => '[pesm_venue_tickets]', 'gate' => '[pesm_venue_gate]'] as $kind => $content) {
    $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Venue UI acceptance ' . $kind, 'post_content' => $content]);
    if (is_wp_error($id)) { throw new RuntimeException('Unable to create browser fixture.'); }
    $pages[$kind] = get_permalink($id);
}
$filename = 'venue-qa-' . bin2hex(random_bytes(12)) . '.php';
$redirect = admin_url('admin.php?page=pesm-venue-designer&hall_id=' . (int) $fixture['hall_id'] . '&layout_id=' . (int) $fixture['layout_id'] . '&session_id=' . (int) $fixture['session_id']);
$source = '<?php if (!in_array($_SERVER["REMOTE_ADDR"] ?? "", ["127.0.0.1", "::1"], true) || time() > ' . (time() + 600) . ') { http_response_code(404); exit; } unlink(__FILE__); require __DIR__ . "/wp-load.php"; wp_set_current_user(1); wp_set_auth_cookie(1, false); wp_safe_redirect(' . var_export($redirect, true) . '); exit;';
if (file_put_contents(ABSPATH . $filename, $source) === false) { throw new RuntimeException('Unable to create one-use local browser login.'); }
chmod(ABSPATH . $filename, 0600);
$pages['login'] = home_url('/' . $filename);
file_put_contents('/private/tmp/esm-integration/venue-ui-fixture.json', wp_json_encode($pages));
chmod('/private/tmp/esm-integration/venue-ui-fixture.json', 0600);
echo wp_json_encode($pages) . "\n";
