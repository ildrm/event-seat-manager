<?php

declare(strict_types=1);

namespace PESM\Security;

/** An InnoDB atomic conditional UPDATE, not a racy WordPress transient counter. */
final class RateLimiter
{
    public static function allow(string $scope, int $max = 80, int $windowSeconds = 60): bool
    {
        global $wpdb;
        if ((int) get_option('pesm_schema_version', 0) < 2) {
            return false; // Fail closed when migration is incomplete.
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? $_SERVER['REMOTE_ADDR'] : 'unavailable';
        $bucket = hash_hmac('sha256', $scope . '|' . $ip, wp_salt('auth'));
        $table = $wpdb->prefix . 'pesm_rate_limits';
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (bucket,hits,expires_at) VALUES (%s,0,UTC_TIMESTAMP())", $bucket
        ));
        if ($inserted === false) {
            return false;
        }
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET hits = IF(expires_at <= UTC_TIMESTAMP(),1,hits + 1),
             expires_at = IF(expires_at <= UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND),expires_at)
             WHERE bucket = %s AND (expires_at <= UTC_TIMESTAMP() OR hits < %d)",
            $windowSeconds, $bucket, $max
        ));
        return $updated === 1;
    }

    public static function cleanup(): void
    {
        global $wpdb;
        if ((int) get_option('pesm_schema_version', 0) < 2) {
            return;
        }
        $wpdb->query("DELETE FROM {$wpdb->prefix}pesm_rate_limits WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)");
    }
}
