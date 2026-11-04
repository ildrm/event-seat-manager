<?php

declare(strict_types=1);

namespace PESM\Support;

use RuntimeException;

/** Insert inside the caller's transaction; log only action and IDs, not bearer tokens or PII. */
final class Audit
{
    public static function record(string $action, string $type, int $id, int $userId = 0): void
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'pesm_audit_logs', [
            'actor_user_id' => $userId, 'action' => $action, 'entity_type' => $type,
            'entity_id' => $id, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if ($ok !== 1) {
            throw new RuntimeException(\PESM\Support\I18n::text('ثبت گزارش تغییرات ممکن نشد.'));
        }
    }
}
