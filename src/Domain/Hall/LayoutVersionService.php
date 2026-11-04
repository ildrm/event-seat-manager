<?php

declare(strict_types=1);

namespace PESM\Domain\Hall;

use PESM\Security\Capabilities;
use PESM\Support\Audit;
use RuntimeException;
use Throwable;

/**
 * Published layouts are immutable. Imports create new drafts; activating a draft
 * only changes the default layout for NEW sessions. Existing sessions keep layout_id.
 */
final class LayoutVersionService
{
    private static function assertHall(int $hallId, int $actorId): object
    {
        global $wpdb;
        if ($hallId < 1 || $actorId < 1 || !user_can($actorId, 'pesm_edit_layouts')) {
            throw new RuntimeException(\PESM\Support\I18n::text('مجوز مدیریت چیدمان وجود ندارد.'));
        }
        $p = $wpdb->prefix . 'pesm_';
        $hall = $wpdb->get_row($wpdb->prepare("SELECT id,center_id,current_layout_id FROM {$p}halls WHERE id=%d", $hallId));
        if (!$hall || !Capabilities::centerAllowed((int) $hall->center_id, $actorId)) {
            throw new RuntimeException(\PESM\Support\I18n::text('سالن موردنظر در محدوده دسترسی شما نیست.'));
        }
        return $hall;
    }

    public function export(int $hallId, int $layoutId, int $actorId): string
    {
        global $wpdb;
        self::assertHall($hallId, $actorId);
        $p = $wpdb->prefix . 'pesm_';
        $layout = $wpdb->get_row($wpdb->prepare(
            "SELECT id,seat_count FROM {$p}layout_versions WHERE hall_id=%d AND id=%d", $hallId, $layoutId
        ));
        if (!$layout) { throw new RuntimeException(\PESM\Support\I18n::text('نسخه چیدمان در این سالن وجود ندارد.')); }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT seat_code AS code,row_label,seat_number AS number,zone_label AS zone,pos_x AS x,pos_y AS y
             FROM {$p}seats WHERE layout_id=%d ORDER BY id", $layoutId
        ), ARRAY_A);
        if (!is_array($rows) || count($rows) !== (int) $layout->seat_count) {
            throw new RuntimeException(\PESM\Support\I18n::text('شمارش صندلی‌های چیدمان با بانک اطلاعاتی مطابقت ندارد.'));
        }
        return LayoutDocument::encodeFull($rows, LayoutObjectStorage::forLayout($layoutId));
    }

    public function importDraft(int $hallId, int $actorId, string $json): int
    {
        // Parse fully before starting DB transaction. No existing record can be lost on invalid input.
        $content = LayoutDocument::parseFull($json);
        $seats = $content['seats'];
        global $wpdb;
        self::assertHall($hallId, $actorId);
        $p = $wpdb->prefix . 'pesm_';
        if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException(\PESM\Support\I18n::text('تراکنش آغاز نشد.')); }
        try {
            $hall = $wpdb->get_row($wpdb->prepare("SELECT center_id FROM {$p}halls WHERE id=%d FOR UPDATE", $hallId));
            if (!$hall || !Capabilities::centerAllowed((int) $hall->center_id, $actorId)) {
                throw new RuntimeException(\PESM\Support\I18n::text('مجوز سالن معتبر نیست.'));
            }
            $nextVersion = 1 + (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(MAX(version_no),0) FROM {$p}layout_versions WHERE hall_id=%d", $hallId
            ));
            if ($wpdb->insert($p . 'layout_versions', [
                'hall_id' => $hallId, 'version_no' => $nextVersion, 'status' => 'draft',
                'seat_count' => count($seats), 'created_at' => gmdate('Y-m-d H:i:s'),
            ]) !== 1) { throw new RuntimeException(\PESM\Support\I18n::text('ثبت نسخه جدید ممکن نشد.')); }
            $layoutId = (int) $wpdb->insert_id;
            foreach (array_chunk($seats, 100) as $chunk) {
                $patterns = [];
                $params = [];
                foreach ($chunk as $seat) {
                    $patterns[] = '(%d,%s,%s,%d,%s,%d,%d)';
                    array_push($params, $layoutId, $seat['code'], $seat['row_label'], $seat['number'],
                        $seat['zone'], $seat['x'], $seat['y']);
                }
                $sql = "INSERT INTO {$p}seats (layout_id,seat_code,row_label,seat_number,zone_label,pos_x,pos_y) VALUES " . implode(',', $patterns);
                if ($wpdb->query($wpdb->prepare($sql, ...$params)) !== count($chunk)) {
                    throw new RuntimeException(\PESM\Support\I18n::text('ثبت گروه صندلی‌ها ناقص ماند.'));
                }
            }
            LayoutObjectStorage::replace($layoutId, $content['objects']);
            Audit::record('layout_imported', 'layout', $layoutId, $actorId);
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException(\PESM\Support\I18n::text('ثبت نهایی نسخه ناموفق بود.')); }
            return $layoutId;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    public function publish(int $hallId, int $layoutId, int $actorId): void
    {
        global $wpdb;
        self::assertHall($hallId, $actorId);
        $p = $wpdb->prefix . 'pesm_';
        if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException(\PESM\Support\I18n::text('تراکنش آغاز نشد.')); }
        try {
            $hall = $wpdb->get_row($wpdb->prepare("SELECT center_id,current_layout_id FROM {$p}halls WHERE id=%d FOR UPDATE", $hallId));
            if (!$hall || !Capabilities::centerAllowed((int) $hall->center_id, $actorId)) {
                throw new RuntimeException(\PESM\Support\I18n::text('دسترسی به سالن وجود ندارد.'));
            }
            $layout = $wpdb->get_row($wpdb->prepare(
                "SELECT id,status,seat_count FROM {$p}layout_versions WHERE hall_id=%d AND id=%d FOR UPDATE", $hallId, $layoutId
            ));
            if (!$layout || $layout->status !== 'draft') {
                throw new RuntimeException(\PESM\Support\I18n::text('تنها نسخه پیش‌نویسِ متعلق به همین سالن قابل انتشار است.'));
            }
            $actual = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}seats WHERE layout_id=%d", $layoutId));
            if ($actual < 1 || $actual !== (int) $layout->seat_count) {
                throw new RuntimeException(\PESM\Support\I18n::text('تعداد صندلی‌های نسخه با مقدار ثبت‌شده مطابقت ندارد.'));
            }
            if ($wpdb->update($p . 'layout_versions', ['status' => 'published'], ['id' => $layoutId, 'status' => 'draft']) !== 1 ||
                $wpdb->update($p . 'halls', ['current_layout_id' => $layoutId], ['id' => $hallId]) !== 1) {
                throw new RuntimeException(\PESM\Support\I18n::text('انتشار نسخه جدید کامل نشد.'));
            }
            Audit::record('layout_published', 'layout', $layoutId, $actorId);
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException(\PESM\Support\I18n::text('تراکنش انتشار تکمیل نشد.')); }
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }
}
