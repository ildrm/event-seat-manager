<?php

declare(strict_types=1);

namespace PESM\Domain\Booking;

/** Optional housekeeping. The acquisition transaction remains authoritative. */
final class CapacityMaintenance
{
    public static function cleanup(): int
    {
        if ((int) get_option('pesm_schema_version', 0) < 9) { return 0; }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $sessions = $wpdb->get_col(
            "SELECT DISTINCT session_id FROM {$p}capacity_holds
             WHERE status='active' AND expires_at<=UTC_TIMESTAMP() ORDER BY session_id LIMIT 25"
        );
        if (!is_array($sessions)) { return 0; }
        $total = 0;
        foreach ($sessions as $sessionId) {
            try {
                $total += (new CapacityService())->cleanup((int) $sessionId, 100);
            } catch (\Throwable $e) {
                // A later acquisition can reclaim expired units; cron is advisory.
            }
        }
        return $total;
    }
}
