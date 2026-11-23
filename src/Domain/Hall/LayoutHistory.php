<?php

declare(strict_types=1);

namespace PESM\Domain\Hall;

final class LayoutHistory
{
    /** @return array<int, list<object>> Latest 20 versions per requested hall. */
    public static function forHalls(array $hallIds, ?string $format = null): array
    {
        if (!$hallIds) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pesm_layout_versions';
        // Each branch uses the (hall_id, version_no) index and stops at 20 rows.
        $formatClause = $format === null ? '' : $wpdb->prepare(' AND format=%s', $format);
        $select = "SELECT hall_id,id,version_no,status,seat_count,revision,format
                   FROM {$table} WHERE hall_id=%d{$formatClause} ORDER BY version_no DESC LIMIT 20";
        $parts = array_fill(0, count($hallIds), "({$select})");
        $query = count($parts) === 1 ? $select : implode(' UNION ALL ', $parts) . ' ORDER BY hall_id,version_no DESC';
        $versions = $wpdb->get_results($wpdb->prepare($query, array_values($hallIds)));

        $history = [];
        foreach ($versions ?? [] as $version) {
            $history[(int) $version->hall_id][] = $version;
        }
        return $history;
    }

    public static function publishedId(int $hallId, int $currentLayoutId, string $format): int
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'pesm_';
        // The hall default may now point to the other format. Follow publication order
        // since drafts can be published out of version order.
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT l.id FROM {$prefix}layout_versions l WHERE l.hall_id=%d AND l.status='published' AND l.format=%s
             ORDER BY (l.id=%d) DESC,
                 (SELECT a.id FROM {$prefix}audit_logs a WHERE a.entity_type='layout' AND a.entity_id=l.id
                  AND a.action IN ('layout_published','venue_published') ORDER BY a.id DESC LIMIT 1) DESC,
                 l.version_no DESC LIMIT 1",
            $hallId, $format, $currentLayoutId
        ));
    }
}
