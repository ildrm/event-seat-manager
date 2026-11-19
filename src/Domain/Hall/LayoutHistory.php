<?php

declare(strict_types=1);

namespace PESM\Domain\Hall;

final class LayoutHistory
{
    /** @return array<int, list<object>> Latest 20 versions per requested hall. */
    public static function forHalls(array $hallIds): array
    {
        if (!$hallIds) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pesm_layout_versions';
        // Each branch uses the (hall_id, version_no) index and stops at 20 rows.
        $select = "SELECT hall_id,id,version_no,status,seat_count,revision,format
                   FROM {$table} WHERE hall_id=%d ORDER BY version_no DESC LIMIT 20";
        $parts = array_fill(0, count($hallIds), "({$select})");
        $query = count($parts) === 1 ? $select : implode(' UNION ALL ', $parts) . ' ORDER BY hall_id,version_no DESC';
        $versions = $wpdb->get_results($wpdb->prepare($query, array_values($hallIds)));

        $history = [];
        foreach ($versions as $version) {
            $history[(int) $version->hall_id][] = $version;
        }
        return $history;
    }
}
