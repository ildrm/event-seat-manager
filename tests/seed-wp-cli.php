<?php
/**
 * In a disposable WordPress site, after activating PESM, run:
 * wp eval-file wp-content/plugins/event-seat-manager/tests/seed-wp-cli.php
 */
if (!defined('ABSPATH') || !class_exists(\PESM\Domain\Hall\HallService::class)) {
    throw new RuntimeException('Run from an activated WordPress installation via wp eval-file.');
}
$service = \PESM\Domain\Hall\HallService::class;
$center = $service::createCenter('مرکز همایش — فیکسچر آزمایشی');
$hall = $service::createHall($center, 'سالن اصلی (۱۵×۶۰)', 15, 60);
$event = $service::createEvent($center, 'رویداد نمایشی فاز اول');
$start = gmdate('Y-m-d H:i:s', time() + 48 * HOUR_IN_SECONDS);
$saleStart = gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS);
$saleEnd = gmdate('Y-m-d H:i:s', time() + 24 * HOUR_IN_SECONDS);
$session = $service::createSession($event, $hall, $start, $saleStart, $saleEnd);
global $wpdb;
$seats = $wpdb->get_results($wpdb->prepare(
    "SELECT id,seat_code FROM {$wpdb->prefix}pesm_seats WHERE layout_id = (SELECT layout_id FROM {$wpdb->prefix}pesm_sessions WHERE id=%d) ORDER BY id LIMIT 1", $session
));
WP_CLI::log(wp_json_encode(['center_id' => $center, 'hall_id' => $hall, 'event_id' => $event,
    'session_id' => $session, 'first_seat_id' => (int) $seats[0]->id,
    'shortcode' => '[pesm_seat_map session="' . $session . '"]']));
