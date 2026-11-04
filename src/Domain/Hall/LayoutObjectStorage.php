<?php

declare(strict_types=1);

namespace PESM\Domain\Hall;

use RuntimeException;

/** Store annotations separately from sellable seats. Caller owns the DB transaction. */
final class LayoutObjectStorage
{
    /** @param list<array<string,mixed>> $objects */
    public static function replace(int $layoutId, array $objects): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pesm_layout_objects';
        if ($wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE layout_id=%d", $layoutId)) === false) {
            throw new RuntimeException(\PESM\Support\I18n::text('پاکسازی اشیای پیش‌نویس انجام نشد.'));
        }
        foreach ($objects as $object) {
            if ($wpdb->insert($table, [
                'layout_id' => $layoutId, 'object_code' => $object['id'],
                'object_type' => $object['type'], 'pos_x' => $object['x'], 'pos_y' => $object['y'],
                'width' => $object['width'], 'height' => $object['height'], 'label' => $object['label'],
            ]) !== 1) {
                throw new RuntimeException(\PESM\Support\I18n::text('ذخیره اشیای نقشه ناموفق بود.'));
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public static function forLayout(int $layoutId): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pesm_layout_objects';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT object_code AS id,object_type AS type,pos_x AS x,pos_y AS y,width,height,label
              FROM {$table} WHERE layout_id=%d ORDER BY id", $layoutId
        ), ARRAY_A);
        if (!is_array($rows)) { throw new RuntimeException(\PESM\Support\I18n::text('خواندن اشیای سالن ناموفق بود.')); }
        foreach ($rows as &$row) {
            foreach (['x','y','width','height'] as $key) { $row[$key] = (int) $row[$key]; }
        }
        unset($row);
        return $rows;
    }
}
