<?php

declare(strict_types=1);

namespace PESM\Domain\Hall;

use RuntimeException;

final class HallService
{
    public static function createCenter(string $name): int
    {
        global $wpdb;
        if (trim($name) === '') {
            throw new RuntimeException(\PESM\Support\I18n::text('نام مرکز لازم است.'));
        }
        self::begin();
        try {
            if ($wpdb->insert($wpdb->prefix . 'pesm_centers', [
                'name' => $name, 'created_at' => gmdate('Y-m-d H:i:s'),
            ]) !== 1) {
                throw new RuntimeException(\PESM\Support\I18n::text('ثبت مرکز انجام نشد.'));
            }
            $id = (int) $wpdb->insert_id;
            \PESM\Support\Audit::record('center_created', 'center', $id);
            self::commit();
            return $id;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    public static function createHall(int $centerId, string $name, int $rows, int $columns): int
    {
        global $wpdb;
        if ($centerId < 1 || $name === '') {
            throw new RuntimeException(\PESM\Support\I18n::text('مرکز یا نام سالن معتبر نیست.'));
        }
        $seats = GridGenerator::generate($rows, $columns);
        $p = $wpdb->prefix . 'pesm_';
        self::begin();
        try {
            if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}centers WHERE id=%d", $centerId))) {
                throw new RuntimeException(\PESM\Support\I18n::text('مرکز وجود ندارد.'));
            }
            if (!$wpdb->insert($p . 'halls', [
                'center_id' => $centerId, 'name' => $name, 'created_at' => gmdate('Y-m-d H:i:s'),
            ])) {
                throw new RuntimeException(\PESM\Support\I18n::text('امکان ایجاد سالن نیست.'));
            }
            $hallId = (int) $wpdb->insert_id;
            if (!$wpdb->insert($p . 'layout_versions', [
                'hall_id' => $hallId, 'version_no' => 1, 'status' => 'published',
                'seat_count' => count($seats), 'created_at' => gmdate('Y-m-d H:i:s'),
            ])) {
                throw new RuntimeException(\PESM\Support\I18n::text('ثبت نسخه چیدمان انجام نشد.'));
            }
            $layoutId = (int) $wpdb->insert_id;
            foreach (array_chunk($seats, 100) as $batch) {
                $values = [];
                $params = [];
                foreach ($batch as $s) {
                    $values[] = '(%d,%s,%s,%d,%s,%d,%d)';
                    array_push($params, $layoutId, $s['code'], $s['row_label'], $s['number'], $s['zone'], $s['x'], $s['y']);
                }
                $sql = "INSERT INTO {$p}seats (layout_id,seat_code,row_label,seat_number,zone_label,pos_x,pos_y) VALUES " . implode(',', $values);
                if ($wpdb->query($wpdb->prepare($sql, ...$params)) !== count($batch)) {
                    throw new RuntimeException(\PESM\Support\I18n::text('ثبت گروهی صندلی‌ها کامل نشد.'));
                }
            }
            if ($wpdb->update($p . 'halls', ['current_layout_id' => $layoutId], ['id' => $hallId]) !== 1) {
                throw new RuntimeException(\PESM\Support\I18n::text('اتصال نسخه نقشه به سالن انجام نشد.'));
            }
            \PESM\Support\Audit::record('hall_created', 'hall', $hallId);
            self::commit();
            return $hallId;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    public static function createEvent(int $centerId, string $title, array $details = []): int
    {
        global $wpdb;
        if ($centerId < 1 || trim($title) === '') {
            throw new RuntimeException(\PESM\Support\I18n::text('شناسه مرکز و عنوان رویداد لازم است.'));
        }
        $p = $wpdb->prefix . 'pesm_';
        self::begin();
        try {
            if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}centers WHERE id=%d", $centerId))) {
                throw new RuntimeException(\PESM\Support\I18n::text('مرکز انتخاب‌شده وجود ندارد.'));
            }
            $fields = \PESM\Domain\Event\EventDetails::normalize($details);
            if ($wpdb->insert($p . 'events', array_merge($fields, [
                'center_id' => $centerId, 'title' => $title,
                'status' => 'draft', 'created_at' => gmdate('Y-m-d H:i:s'),
            ])) !== 1) {
                throw new RuntimeException(\PESM\Support\I18n::text('ثبت رویداد انجام نشد.'));
            }
            $id = (int) $wpdb->insert_id;
            \PESM\Support\Audit::record('event_created', 'event', $id);
            self::commit();
            return $id;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    public static function createSession(int $eventId, int $hallId, string $startUtc, string $saleStartUtc, string $saleEndUtc, int $unitPriceMinor = 0, string $mode = 'seated', int $capacity = 0): int
    {
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        if ($unitPriceMinor < 0 || $unitPriceMinor > 1000000000) { throw new RuntimeException(\PESM\Support\I18n::text('قیمت بلیت نامعتبر است.')); }
        if (!in_array($mode, ['seated', 'general'], true)) { throw new RuntimeException(\PESM\Support\I18n::text('نوع جلسه نامعتبر است.')); }
        if ($mode === 'general') { \PESM\Domain\Booking\CapacityPolicy::capacity($capacity); }
        if ($mode === 'seated' && $capacity !== 0) { throw new RuntimeException(\PESM\Support\I18n::text('برای جلسه شماره‌دار، ظرفیت از نقشه محاسبه می‌شود.')); }
        self::begin();
        try {
            $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}events WHERE id = %d FOR UPDATE", $eventId));
            $hall = $hallId > 0 ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}halls WHERE id = %d", $hallId)) : null;
            if (!$event || ($mode === 'seated' && (!$hall || !$hall->current_layout_id)) ||
                ($hall && (int) $event->center_id !== (int) $hall->center_id) ||
                ($mode === 'seated' && $hallId < 1) || ($mode === 'general' && $hallId > 0 && !$hall)) {
                throw new RuntimeException(\PESM\Support\I18n::text('مرکز رویداد و سالن یکسان نیست یا نقشه‌ای وجود ندارد.'));
            }
            if ($saleEndUtc <= $saleStartUtc || $startUtc <= $saleEndUtc || $startUtc <= gmdate('Y-m-d H:i:s')) {
                throw new RuntimeException(\PESM\Support\I18n::text('بازه فروش و تاریخ اجرا نامعتبر است.'));
            }
            $layoutId = $hall ? (int) $hall->current_layout_id : 0;
            if (!$wpdb->insert($p . 'sessions', [
                'event_id' => $eventId, 'hall_id' => $hallId, 'layout_id' => $layoutId,
                'starts_at' => $startUtc, 'sale_starts_at' => $saleStartUtc, 'sale_ends_at' => $saleEndUtc,
                'status' => 'draft', 'unit_price_minor' => $unitPriceMinor, 'mode' => $mode,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ])) {
                throw new RuntimeException(\PESM\Support\I18n::text('ثبت جلسه انجام نشد.'));
            }
            $sessionId = (int) $wpdb->insert_id;
            if ($mode === 'general') {
                if ($wpdb->insert($p . 'session_capacity', [
                    'session_id' => $sessionId, 'total_capacity' => $capacity,
                ]) !== 1) {
                    throw new RuntimeException(\PESM\Support\I18n::text('ایجاد موجودی ظرفیت آزاد ناموفق بود.'));
                }
            } else {
                $count = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$p}session_seats (session_id,seat_id,status,updated_at)
                 SELECT %d,id,'available',UTC_TIMESTAMP() FROM {$p}seats WHERE layout_id = %d",
                $sessionId, $layoutId
            ));
            $expected = (int) $wpdb->get_var($wpdb->prepare("SELECT seat_count FROM {$p}layout_versions WHERE id = %d", $layoutId));
            if ($count === false || $count !== $expected) {
                throw new RuntimeException(\PESM\Support\I18n::text('موجودی جلسه با نقشه مطابقت ندارد.'));
            }
            }
            \PESM\Support\Audit::record('session_created', 'session', $sessionId);
            self::commit();
            return $sessionId;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    private static function begin(): void
    {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new RuntimeException(\PESM\Support\I18n::text('آغاز تراکنش پایگاه داده ممکن نشد.'));
        }
    }

    private static function commit(): void
    {
        global $wpdb;
        if ($wpdb->query('COMMIT') === false) {
            throw new RuntimeException(\PESM\Support\I18n::text('تراکنش قابل ثبت نیست.'));
        }
    }
}
