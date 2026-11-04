<?php

declare(strict_types=1);

namespace PESM\Security;

final class Capabilities
{
    public const CAPABILITIES = [
        'pesm_access_dashboard', 'pesm_manage_centers', 'pesm_manage_halls', 'pesm_edit_layouts', 'pesm_manage_events',
        'pesm_manage_sessions', 'pesm_manage_pricing', 'pesm_issue_tickets', 'pesm_view_orders',
        'pesm_manage_refunds', 'pesm_scan_tickets', 'pesm_view_reports', 'pesm_export_private_data',
        'pesm_manage_sms', 'pesm_manage_settings', 'pesm_view_audit_log',
    ];

    /** All names are reserved for future modules; assigning a capability does not implement its feature. */
    public static function install(): void
    {
        $roles = [
            'pesm_super_manager' => self::CAPABILITIES,
            'pesm_center_manager' => ['pesm_manage_halls', 'pesm_edit_layouts', 'pesm_manage_events', 'pesm_manage_sessions', 'pesm_view_reports'],
            'pesm_event_manager' => ['pesm_manage_events', 'pesm_manage_sessions', 'pesm_manage_pricing', 'pesm_view_reports'],
            'pesm_hall_designer' => ['pesm_manage_halls', 'pesm_edit_layouts'],
            'pesm_box_office' => ['pesm_issue_tickets', 'pesm_view_orders'],
            'pesm_finance_manager' => ['pesm_view_orders', 'pesm_manage_refunds', 'pesm_view_reports', 'pesm_export_private_data'],
            'pesm_gate_operator' => ['pesm_scan_tickets'],
            'pesm_analyst' => ['pesm_view_reports'],
        ];
        foreach ($roles as $slug => $caps) {
            // Dashboard capability is shared; feature capabilities still control each action.
            $caps[] = 'pesm_access_dashboard';
            $role = get_role($slug);
            if (!$role) {
                add_role($slug, self::roleTitle($slug), array_fill_keys(array_merge(['read'], $caps), true));
                $role = get_role($slug);
            }
            if ($role) {
                foreach ($caps as $cap) {
                    $role->add_cap($cap);
                }
            }
        }
        $admin = get_role('administrator');
        if ($admin) {
            foreach (self::CAPABILITIES as $cap) {
                $admin->add_cap($cap);
            }
        }
    }

    private static function roleTitle(string $slug): string
    {
        return [
            'pesm_super_manager' => \PESM\Support\I18n::text('مدیر کل رویدادها'), 'pesm_center_manager' => \PESM\Support\I18n::text('مدیر مرکز'),
            'pesm_event_manager' => \PESM\Support\I18n::text('مدیر رویداد'), 'pesm_hall_designer' => \PESM\Support\I18n::text('طراح سالن'),
            'pesm_box_office' => \PESM\Support\I18n::text('اپراتور فروش'), 'pesm_finance_manager' => \PESM\Support\I18n::text('مدیر مالی'),
            'pesm_gate_operator' => \PESM\Support\I18n::text('اپراتور ورودی'), 'pesm_analyst' => \PESM\Support\I18n::text('تحلیلگر'),
        ][$slug] ?? $slug;
    }

    /** Translate role labels per request without rewriting saved roles or capabilities. */
    public static function localizeRoleNames(): void
    {
        if (!function_exists('wp_roles')) { return; }
        $roles = wp_roles();
        foreach (array_keys($roles->roles) as $slug) {
            $title = self::roleTitle($slug);
            if ($title === $slug) { continue; }
            $roles->roles[$slug]['name'] = $title;
            $roles->role_names[$slug] = $title;
        }
    }

    /** Global managers may access every center, others must be explicitly assigned. */
    public static function centerAllowed(int $centerId, int $userId): bool
    {
        if ($centerId < 1 || $userId < 1) {
            return false;
        }
        if (user_can($userId, 'pesm_manage_centers')) {
            return true;
        }
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}pesm_center_users WHERE center_id = %d AND user_id = %d LIMIT 1",
            $centerId, $userId
        ));
    }
}
