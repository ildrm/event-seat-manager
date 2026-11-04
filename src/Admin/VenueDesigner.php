<?php

declare(strict_types=1);

namespace PESM\Admin;

use PESM\Security\Capabilities;
use PESM\Support\I18n;
use PESM\Support\VenueUi;

final class VenueDesigner
{
    public static function register(): void
    {
        add_action('admin_post_pesm_venue_limits', [self::class, 'saveLimits']);
        add_action('admin_menu', static function (): void {
            add_submenu_page('pesm-dashboard', self::title(), self::title(), 'pesm_edit_layouts', 'pesm-venue-designer', [self::class, 'render']);
        });
    }

    private static function title(): string
    {
        return I18n::isPersian() ? 'طراح سالن' : 'Venue designer';
    }

    public static function render(): void
    {
        if (!current_user_can('pesm_edit_layouts')) { wp_die('Access denied.', '', ['response' => 403]); }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $all = current_user_can('pesm_manage_centers');
        $scope = $all ? '1=1' : $wpdb->prepare("EXISTS (SELECT 1 FROM {$p}center_users cu WHERE cu.center_id=h.center_id AND cu.user_id=%d)", get_current_user_id());
        $halls = $wpdb->get_results("SELECT h.id,h.name,h.center_id,h.current_layout_id,v.layout_id AS venue_layout_id FROM {$p}halls h LEFT JOIN {$p}venue_layouts v ON v.layout_id=h.current_layout_id WHERE {$scope} ORDER BY h.id DESC LIMIT 200", ARRAY_A) ?: [];
        $eventScope = $all ? '1=1' : $wpdb->prepare("EXISTS (SELECT 1 FROM {$p}center_users cu WHERE cu.center_id=e.center_id AND cu.user_id=%d)", get_current_user_id());
        $events = $wpdb->get_results("SELECT e.id,e.title,e.center_id,e.status FROM {$p}events e WHERE {$eventScope} ORDER BY e.id DESC LIMIT 200", ARRAY_A) ?: [];
        $sessions = $wpdb->get_results("SELECT s.id,s.event_id,s.hall_id,s.layout_id,s.status,s.starts_at,e.title FROM {$p}sessions s JOIN {$p}events e ON e.id=s.event_id WHERE s.mode='allocated' AND {$eventScope} ORDER BY s.id DESC LIMIT 200", ARRAY_A) ?: [];
        $hallId = isset($_GET['hall_id']) ? absint($_GET['hall_id']) : (int) ($halls[0]['id'] ?? 0);
        $hall = null;
        foreach ($halls as $candidate) { if ((int) $candidate['id'] === $hallId) { $hall = $candidate; break; } }
        if ($hallId > 0 && !$hall) { wp_die('Hall not accessible.', '', ['response' => 403]); }
        $layoutId = isset($_GET['layout_id']) ? absint($_GET['layout_id']) : (int) ($hall['venue_layout_id'] ?? 0);
        if ($layoutId > 0 && (int) $wpdb->get_var($wpdb->prepare("SELECT hall_id FROM {$p}layout_versions WHERE id=%d", $layoutId)) !== $hallId) { wp_die('Layout not accessible.', '', ['response' => 403]); }
        $versions = $hallId > 0 ? ($wpdb->get_results($wpdb->prepare("SELECT id,version_no,status,seat_count FROM {$p}layout_versions WHERE hall_id=%d AND format='pesm.layout.v2' ORDER BY id DESC LIMIT 30", $hallId), ARRAY_A) ?: []) : [];
        echo '<div class="wrap"><h1>' . esc_html(self::title()) . '</h1>';
        if (current_user_can('manage_options')) {
            echo '<details><summary>' . esc_html(I18n::isPersian() ? 'سقف خرید هر شماره موبایل' : 'Purchase limit per phone number') . '</summary><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="pesm_venue_limits">';
            wp_nonce_field('pesm_venue_limits');
            echo '<label>' . esc_html(I18n::isPersian() ? 'حداکثر جایگاه برای هر شماره در یک رویداد' : 'Maximum places per phone number in an event') . ' <input type="number" name="phone_limit" min="1" max="200" value="' . esc_attr((string) get_option('pesm_phone_event_limit', 20)) . '" required></label> ';
            submit_button(I18n::text('ذخیره'), 'secondary', 'submit', false);
            echo '</form></details>';
        }
        if (!$halls) {
            echo '<p>' . esc_html(I18n::text('ابتدا مرکز و سالن بسازید.')) . '</p></div>';
            return;
        }
        echo VenueUi::render('admin', [
            'halls' => $halls, 'events' => $events, 'sessions' => $sessions, 'versions' => $versions,
            'hallId' => $hallId, 'centerId' => (int) ($hall['center_id'] ?? 0), 'layoutId' => $layoutId,
            'sessionId' => isset($_GET['session_id']) ? absint($_GET['session_id']) : 0,
            'canManageRefunds' => current_user_can('pesm_manage_refunds'),
            'canBlockSeats' => current_user_can('pesm_manage_halls'),
            'canManagePricing' => current_user_can('pesm_manage_pricing'),
            'canManageSessions' => current_user_can('pesm_manage_sessions'),
        ]);
        echo '</div>';
    }

    public static function saveLimits(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Access denied.', '', ['response' => 403]); }
        check_admin_referer('pesm_venue_limits');
        $value = isset($_POST['phone_limit']) && is_scalar($_POST['phone_limit']) ? filter_var(wp_unslash($_POST['phone_limit']), FILTER_VALIDATE_INT) : false;
        if ($value === false || $value < 1 || $value > 200) { wp_die('Choose a limit from 1 to 200.', '', ['response' => 422]); }
        update_option('pesm_phone_event_limit', $value, false);
        wp_safe_redirect(admin_url('admin.php?page=pesm-venue-designer'));
        exit;
    }
}
