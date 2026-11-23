<?php

declare(strict_types=1);

use PESM\Admin\SetupBookingPage;
use PESM\Database\Migrator;
use PESM\Domain\Event\SetupInput;
use PESM\Domain\Event\SetupService;
use PESM\Domain\Hall\GridGenerator;
use PESM\Domain\Hall\HallService;
use PESM\Domain\Hall\LayoutDocument;
use PESM\Domain\Hall\LayoutVersionService;
use PESM\Domain\Venue\JobService;

if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI || getenv('PESM_SETUP_INTEGRATION') !== '1') {
    throw new RuntimeException('Run only in a disposable WordPress installation through WP-CLI.');
}
$actor = get_current_user_id();
if (!$actor || !user_can($actor, 'manage_options') || !function_exists('wc_create_order')) {
    throw new RuntimeException('A test administrator and WooCommerce are required.');
}
global $wpdb;
$p = $wpdb->prefix . 'pesm_';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
};
$phase = getenv('PESM_SETUP_PHASE') ?: 'verify';
if ($phase === 'baseline') {
    $assert((int) get_option('pesm_schema_version') === 19, 'Baseline must use schema 19');
    $center = HallService::createCenter('ESM before upgrade');
    $hall = HallService::createHall($center, 'ESM historic hall', 3, 4);
    $event = HallService::createEvent($center, 'ESM historic event');
    $session = HallService::createSession($event, $hall, gmdate('Y-m-d H:i:s', time() + 172800),
        gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s', time() + 86400));
    \PESM\Domain\Event\LifecycleService::change('event', $event, 'published', $actor);
    \PESM\Domain\Event\LifecycleService::change('session', $session, 'published', $actor);
    $seat = (int) $wpdb->get_var($wpdb->prepare("SELECT MIN(seat_id) FROM {$p}session_seats WHERE session_id=%d", $session));
    $hold = (new \PESM\Domain\Booking\HoldService())->acquire($session, [$seat], bin2hex(random_bytes(24)));
    $checkout = (new \PESM\Domain\Checkout\CheckoutService())->prepare($session, $hold['token'], [
        'first_name'=>'Avery','last_name'=>'Test','phone'=>'09123456789','email'=>'historical@example.invalid',
    ]);
    $order = wc_get_order($checkout['order_id']);
    $order->set_customer_note('historic order');
    $order->save();
    update_option('pesm_setup_test_baseline', [
        'session'=>$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}sessions WHERE id=%d", $session), ARRAY_A),
        'inventory'=>$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}session_seats WHERE session_id=%d ORDER BY id", $session), ARRAY_A),
        'order'=>$order->get_id(),
        'tickets'=>$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}tickets WHERE session_id=%d ORDER BY id", $session), ARRAY_A),
    ]);
    WP_CLI::success('Prepared schema 19 upgrade fixture.');
    return;
}
$assert((int) get_option('pesm_schema_version') === Migrator::VERSION, 'Current schema');
if ($baseline = get_option('pesm_setup_test_baseline')) {
    $session = (int) $baseline['session']['id'];
    $assert($baseline['session'] === $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}sessions WHERE id=%d", $session), ARRAY_A), 'Historic session is byte-for-byte equivalent');
    $assert($baseline['inventory'] === $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}session_seats WHERE session_id=%d ORDER BY id", $session), ARRAY_A), 'Historic inventory was not rebound');
    $assert(wc_get_order((int) $baseline['order'])->get_customer_note() === 'historic order', 'WooCommerce data survived');
    $assert(count($baseline['tickets']) === 1 && $baseline['tickets'] ===
        $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}tickets WHERE session_id=%d ORDER BY id", $session), ARRAY_A),
        'The issued ticket and its QR data survived');
}

$details = ['title'=>'Setup integration'];
$key = wp_generate_uuid4();
$id = SetupService::start($actor, 0, 0, 'Setup center', $details, $key);
$assert(SetupService::start($actor, 0, 0, 'Setup center', $details, $key) === $id, 'Idempotent start');
$draft = SetupService::load($id, $actor);
$center = (int) $draft->center_id;
$assert((int) $draft->event_id === 0, 'Saving a wizard draft creates no event');
$draft = SetupService::revise($id, $actor, (int) $draft->revision, static function (object $draft) use ($actor): void {
    $hall = SetupService::createHall($draft, $actor, 'Setup 240', 12, 20, false);
    $layout = (int) SetupService::halls((int) $draft->center_id)[0]->current_layout_id;
    $location = SetupService::location((int) $draft->center_id, 'seated', $hall, $layout, 0);
    $draft->data['rows'] = [SetupInput::blank($location), SetupInput::blank(SetupService::location((int) $draft->center_id, 'general', 0, 0, 77))];
    $draft->data['rows'][0] = array_replace($draft->data['rows'][0], ['start'=>'2099-02-15T18:00','kind'=>'paid','amount'=>'45','adjacent'=>true]);
    $draft->data['rows'][1] = array_replace($draft->data['rows'][1], ['start'=>'2099-02-15T19:00','kind'=>'free','custom'=>true,'open'=>'2099-02-10T13:00','close'=>'2099-02-15T19:00']);
    $draft->data['currency'] = SetupService::currency();
    $draft->step = 4;
});
$loc = $draft->data['rows'][0]['location'];
$assert($loc['capacity'] === 240, 'Grid has exactly 240 seats');
$alt = (new LayoutVersionService())->importDraft($loc['hall'], $actor, LayoutDocument::encode(GridGenerator::generate(4, 5)));
(new LayoutVersionService())->publish($loc['hall'], $alt, $actor);
$assert($alt !== $loc['layout'], 'Alternate layout is now the default');
$other = HallService::createCenter('Foreign center');
try {
    SetupService::location($other, 'seated', 0, $loc['layout'], 0);
    throw new RuntimeException('Allowing a layout from another center');
} catch (InvalidArgumentException $error) {
    $assert($error->getMessage() === 'invalid_layout', 'Reject an out-of-center layout');
}
try {
    SetupService::revise($id, $actor, 1, static function (): void {});
    throw new RuntimeException('Accepted stale draft');
} catch (RuntimeException $error) {
    $assert($error->getMessage() === 'revision', 'Two-tab conflict');
}
try {
    SetupService::revise($id, $actor, (int) $draft->revision, static fn(object $setup) => SetupService::publish($setup, $actor, [0, 1], true));
    throw new RuntimeException('Published part of a failed batch');
} catch (RuntimeException $error) {
    $assert($error->getMessage() === 'invalid_window:2', 'Identify failing session');
}
$assert((int) SetupService::load($id, $actor)->event_id === 0, 'Failing the batch rolled back event creation');

$draft = SetupService::revise($id, $actor, (int) $draft->revision, static fn(object $setup) => SetupService::publish($setup, $actor, [0], true));
$first = (int) $draft->data['published'][0];
$session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}sessions WHERE id=%d", $first));
$assert($session->status === 'published' && (int) $session->layout_id === $loc['layout'], 'Published the selected layout version');
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}session_seats WHERE session_id=%d", $first)) === 240, 'Only the exact 240 seats are provisioned');
$assert((int) $session->require_adjacent === 1 && (int) $session->unit_price_minor === 45 * (10 ** SetupService::currency()[1]), 'Saved rules and major-unit price');
$assert($session->starts_at === '2099-02-15 14:30:00', 'Tehran time stored as UTC');
$assert(!$draft->finished && !isset($draft->data['published'][1]), 'Unchecked session remains draft');
add_filter('wp_insert_post_empty_content', '__return_true');
try {
    SetupBookingPage::publish($id, $actor);
    throw new RuntimeException('Ignored a WordPress page error');
} catch (RuntimeException $error) {
    $assert($error->getMessage() === 'page_failed', 'Page failure is isolated');
} finally {
    remove_filter('wp_insert_post_empty_content', '__return_true');
}
$assert(SetupService::event((int) $draft->event_id)->status === 'published', 'Page failure preserves published event');
$page = SetupBookingPage::publish($id, $actor);
$assert($page === SetupBookingPage::publish($id, $actor), 'Page retry returns the same ID');
$assert(get_post($page)->post_content === '[pesm_event id="' . $draft->event_id . '"]', 'Public page uses the event shortcode');
$assert(str_contains(do_shortcode(get_post($page)->post_content), 'Setup integration'), 'Public event renders with the existing shortcode');
$draft = SetupService::load($id, $actor);
$draft = SetupService::revise($id, $actor, (int) $draft->revision, static function (object $setup) use ($actor): void {
    $setup->data['rows'][1]['close'] = '2099-02-15T18:45';
    SetupService::publish($setup, $actor, [1], false);
});
$assert((bool) $draft->finished, 'Can publish remaining sessions later');
$assert((int) $wpdb->get_var($wpdb->prepare("SELECT total_capacity FROM {$p}session_capacity WHERE session_id=%d",
    $draft->data['published'][1])) === 77, 'General admission provisions the requested capacity');
$restricted = wp_insert_user(['user_login'=>'setup-restricted-' . wp_generate_password(8, false), 'user_pass'=>wp_generate_password(), 'role'=>'pesm_event_manager']);
try {
    SetupService::load($id, (int) $restricted);
    throw new RuntimeException('Cross-center draft was readable');
} catch (RuntimeException $error) {
    $assert($error->getMessage() === 'access', 'Cross-center draft is private');
}

$advanced = SetupService::start($actor, 0, $center, '', ['title'=>'Advanced setup integration'], wp_generate_uuid4());
$advanced = SetupService::load($advanced, $actor);
$generated = JobService::create($center, $loc['hall'], 'generate', ['preset_id'=>'cinema_central_aisle','parameters'=>['capacity'=>24]], $actor);
JobService::run((int) $generated['id']);
$generated = JobService::status((int) $generated['id']);
$assert($generated['status'] === 'complete', 'Existing venue generator builds the layout');
$advancedLayout = (int) $generated['layout_id'];
$publication = JobService::create($center, $loc['hall'], 'publish', ['layout_id'=>$advancedLayout], $actor);
JobService::run((int) $publication['id']);
$advancedId = (int) $advanced->id;
$advanced = SetupService::revise($advancedId, $actor, (int) $advanced->revision, static function (object $setup) use ($center, $advancedLayout): void {
    $setup->data['rows'][] = array_replace(SetupInput::blank(SetupService::location($center, 'allocated', 0, $advancedLayout, 0)),
        ['start'=>'2099-02-15T22:00','kind'=>'free']);
    $setup->data['currency'] = SetupService::currency();
    $setup->step = 4;
});
$assert(SetupService::readiness($advanced, 0, SetupService::now()) === 'acceptance', 'Production acceptance still blocks creation');
define('PESM_VENUE_ACCEPTANCE_TEST', true);
$job = 0;
$advanced = SetupService::revise($advancedId, $actor, (int) $advanced->revision, static function (object $setup) use ($actor, &$job): void {
    $job = SetupService::prepare($setup, $actor, 0);
});
$advanced = SetupService::revise($advancedId, $actor, (int) $advanced->revision, static function (object $setup) use ($actor, $job, $assert): void {
    $assert(SetupService::prepare($setup, $actor, 0) === $job, 'Preparing twice returns the same job');
});
$assert(SetupService::readiness($advanced, 0, SetupService::now()) === 'preparing', 'Wizard surfaces pending inventory');
JobService::run($job);
$assert(SetupService::readiness($advanced, 0, SetupService::now()) === 'ready', 'Allocated session is ready after provisioning');
$advanced = SetupService::revise($advancedId, $actor, (int) $advanced->revision,
    static fn(object $setup) => SetupService::publish($setup, $actor, [0], false));
$assert($advanced->data['published'][0] === $advanced->data['prepared'][0]['id'], 'Batch publishes the prepared allocated session');
WP_CLI::success($checks . ' real event setup checks passed.');
