<?php
/**
 * Plugin Name: event-seat-manager
 * Description: Bilingual event, hall, seat reservation, WooCommerce ticketing and online admission management.
 * Version: 1.0.0
 * Author: Shahin Ilderemi
 * Author URI:  https://ildrm.com
 * Requires PHP: 8.1
 * Text Domain: event-seat-manager
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Renaming a plugin changes its activation path. Avoid loading two PESM copies.
if (defined('PESM_FILE')) {
    register_activation_hook(__FILE__, static function (): void {
        load_plugin_textdomain('event-seat-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');
        wp_die(esc_html__('Deactivate the previous Event Seat Manager copy before activating this package. Existing booking data will be retained.', 'event-seat-manager'));
    });
    add_action('admin_notices', static function (): void {
        if (current_user_can('activate_plugins')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Deactivate the previous Event Seat Manager copy before activating this package. Existing booking data will be retained.', 'event-seat-manager') . '</p></div>';
        }
    });
    return;
}

define('PESM_VERSION', '1.0.0');
define('PESM_FILE', __FILE__);
define('PESM_DIR', plugin_dir_path(__FILE__));
define('PESM_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'PESM\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = PESM_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        // Order creation, issuance and replay were verified with both WC data stores.
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', PESM_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', PESM_FILE, false);
    }
});

register_activation_hook(__FILE__, static function (bool $networkWide = false): void {
    load_plugin_textdomain('event-seat-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');
    if ($networkWide) {
        wp_die(esc_html__('Activate Event Seat Manager on individual sites; network activation is not supported.', 'event-seat-manager'));
    }
    \PESM\Database\Migrator::run();
    if (get_option('pesm_schema_error') || (int) get_option('pesm_schema_version', 0) !== \PESM\Database\Migrator::VERSION) {
        wp_die(esc_html__('The booking database could not be prepared. Check database permissions and InnoDB support, then activate again.', 'event-seat-manager'));
    }
    \PESM\Security\Capabilities::install();
    update_option('pesm_capabilities_version', 3, false);
});

add_action('plugins_loaded', static function (): void {
    if (version_compare(PHP_VERSION, '8.1.0', '<')) {
        return;
    }
    add_action('init', static function (): void {
        load_plugin_textdomain('event-seat-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');
        \PESM\Support\I18n::register();
    }, 0);
    add_action('init', static function (): void {
        if ((int) get_option('pesm_capabilities_version', 0) < 3) {
            \PESM\Security\Capabilities::install();
            update_option('pesm_capabilities_version', 3, false);
        }
    }, 1);
    add_action('pesm_hourly_maintenance', [\PESM\Security\RateLimiter::class, 'cleanup']);
    add_action('pesm_hourly_maintenance', [\PESM\Domain\Guest\GuestAccessService::class, 'cleanup']);
    add_action('pesm_hourly_maintenance', [\PESM\Domain\Booking\HoldMaintenance::class, 'cleanup']);
    add_action('pesm_hourly_maintenance', [\PESM\Domain\Booking\CapacityMaintenance::class, 'cleanup']);
    add_action('pesm_hourly_maintenance', [\PESM\Domain\Notification\NotificationQueue::class, 'cleanup']);
    add_action('pesm_process_notifications', [\PESM\Domain\Notification\NotificationQueue::class, 'process']);
    if ((int) get_option('pesm_schema_version', 0) < \PESM\Database\Migrator::VERSION) {
        \PESM\Database\Migrator::run();
    }
    // A partially applied migration must never expose booking or ticket mutation routes.
    if (get_option('pesm_schema_error') || (int) get_option('pesm_schema_version', 0) !== \PESM\Database\Migrator::VERSION) {
        add_action('admin_notices', static function (): void {
            if (current_user_can('activate_plugins')) {
                echo '<div class="notice notice-error"><p>' . esc_html__('Event Seat Manager is paused because its database is not ready. Check InnoDB and database permissions, then reactivate the plugin.', 'event-seat-manager') . '</p></div>';
            }
        });
        return;
    }
    add_action('init', static function (): void {
        add_filter('cron_schedules', static function (array $schedules): array {
            $schedules['pesm_five_minutes'] = ['interval'=>300,'display'=>__('Event Seat Manager: 5 minutes', 'event-seat-manager')];
            return $schedules;
        });
        if (!wp_next_scheduled('pesm_hourly_maintenance')) {
            wp_schedule_event(time() + 300, 'hourly', 'pesm_hourly_maintenance');
        }
        if (!wp_next_scheduled('pesm_process_notifications')) {
            wp_schedule_event(time() + 60, 'pesm_five_minutes', 'pesm_process_notifications');
        }
    }, 2);
    // Safe without Elementor: the hook simply never fires. Avoid plugin-load ordering races.
    \PESM\Integrations\ElementorWidgets::register();
    if (class_exists('WooCommerce')) {
        \PESM\Integrations\WooCommerceBridge::register();
        \PESM\Integrations\CartSeatManager::register();
    }
    add_action('rest_api_init', [\PESM\Http\RestController::class, 'register']);
    add_action('init', [\PESM\Frontend\Shortcodes::class, 'register']);
    add_action('init', [\PESM\Frontend\Blocks::class, 'register']);
    add_action('admin_post_pesm_session_calendar', [\PESM\Domain\Calendar\CalendarFeed::class, 'download']);
    add_action('admin_post_nopriv_pesm_session_calendar', [\PESM\Domain\Calendar\CalendarFeed::class, 'download']);
    add_action('admin_post_pesm_attendance_csv', [\PESM\Domain\Reporting\AttendanceReport::class, 'downloadCsv']);
    add_action('admin_post_pesm_attendance_xlsx', [\PESM\Domain\Reporting\AttendanceReport::class, 'downloadXlsx']);
    if (is_admin()) {
        \PESM\Admin\Pages::register();
    }
});

register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('pesm_hourly_maintenance');
    wp_clear_scheduled_hook('pesm_process_notifications');
});
