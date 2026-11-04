<?php

declare(strict_types=1);

namespace PESM\Domain\Reporting;

use PESM\Domain\Booking\HoldException;
use PESM\Security\Capabilities;

/** Scoped, aggregate-only attendance and inventory reporting. No buyer PII. */
final class AttendanceReport
{
    /** @return array{center_id:int,sessions:list<array<string,mixed>>} */
    public function summary(int $userId, int $centerId, int $sessionId = 0): array
    {
        if ($userId < 1 || !user_can($userId, 'pesm_view_reports') || !Capabilities::centerAllowed($centerId, $userId)) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('اجازه مشاهده گزارش این مرکز را ندارید.'), 403);
        }
        if ((int) get_option('pesm_schema_version', 0) < 4) {
            throw new HoldException('schema_not_ready', \PESM\Support\I18n::text('ساختار پایگاه داده آماده نیست.'), 503);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $conditions = 'WHERE e.center_id=%d';
        $args = [$centerId];
        if ($sessionId > 0) {
            $conditions .= ' AND s.id=%d';
            $args[] = $sessionId;
        }
        // Aggregate in SQL: avoids 900 individual seat queries or leaking attendee names.
        $sql = "SELECT s.id AS session_id,e.id AS event_id,e.title,s.starts_at,
                CASE WHEN s.mode='general' THEN COALESCE(c.total_capacity,0) ELSE COALESCE(i.capacity,0) END AS capacity,
                CASE WHEN s.mode='general' THEN COALESCE(c.total_capacity-c.blocked_units-c.held_units-c.pending_units-c.sold_units+COALESCE(x.expired_units,0),0) ELSE COALESCE(i.available,0) END AS available,
                CASE WHEN s.mode='general' THEN COALESCE(c.held_units-COALESCE(x.expired_units,0),0) ELSE COALESCE(i.held,0) END AS held,
                CASE WHEN s.mode='general' THEN COALESCE(c.pending_units,0) ELSE COALESCE(i.payment_pending,0) END AS payment_pending,
                CASE WHEN s.mode='general' THEN COALESCE(c.sold_units,0) ELSE COALESCE(i.sold,0) END AS sold,
                CASE WHEN s.mode='general' THEN COALESCE(c.blocked_units,0) ELSE COALESCE(i.blocked,0) END AS blocked,
                CASE WHEN s.mode='general' THEN COALESCE(gt.active,0) ELSE COALESCE(t.active,0) END AS active_tickets,
                CASE WHEN s.mode='general' THEN COALESCE(gt.checked_in,0) ELSE COALESCE(t.checked_in,0) END AS checked_in,
                CASE WHEN s.mode='general' THEN COALESCE(gt.cancelled,0) ELSE COALESCE(t.cancelled,0) END AS cancelled_tickets
              FROM {$p}sessions s JOIN {$p}events e ON e.id=s.event_id
              LEFT JOIN {$p}session_capacity c ON c.session_id=s.id
              LEFT JOIN (
                 SELECT session_id,SUM(quantity) AS expired_units FROM {$p}capacity_holds
                 WHERE status='active' AND expires_at<=UTC_TIMESTAMP() GROUP BY session_id
              ) x ON x.session_id=s.id
              LEFT JOIN (
                 SELECT session_id,COUNT(*) AS capacity,
                    SUM(status='available' OR (status IN ('held','payment_pending') AND expires_at<=UTC_TIMESTAMP())) AS available,
                    SUM(status='held' AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())) AS held,
                    SUM(status='payment_pending' AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())) AS payment_pending,
                    SUM(status='sold') AS sold,SUM(status='blocked') AS blocked
                 FROM {$p}session_seats GROUP BY session_id
              ) i ON i.session_id=s.id
              LEFT JOIN (
                 SELECT session_id,SUM(status='active') AS active,
                    SUM(status='checked_in') AS checked_in,
                    SUM(status IN ('cancelled','refunded','transferred')) AS cancelled
                 FROM {$p}tickets GROUP BY session_id
              ) t ON t.session_id=s.id
              LEFT JOIN (
                 SELECT session_id,SUM(status='active') AS active,
                    SUM(status='checked_in') AS checked_in,
                    SUM(status IN ('cancelled','refunded','transferred')) AS cancelled
                 FROM {$p}capacity_tickets GROUP BY session_id
              ) gt ON gt.session_id=s.id
              {$conditions} ORDER BY s.starts_at DESC,s.id DESC LIMIT 200";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
        if (!is_array($rows)) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('گزارش حضور قابل دریافت نیست.'), 503);
        }
        $result = [];
        $dbNow = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
        if (!$dbNow) { throw new HoldException('db_unavailable', \PESM\Support\I18n::text('ساعت پایگاه داده قابل دریافت نیست.'), 503); }
        $entryWindow = max(0, (int) get_option('pesm_entry_after_seconds', 14400));
        foreach ($rows as $row) {
            foreach (['session_id', 'event_id', 'capacity', 'available', 'held', 'payment_pending', 'sold', 'blocked', 'active_tickets', 'checked_in', 'cancelled_tickets'] as $key) {
                $row[$key] = (int) ($row[$key] ?? 0);
            }
            // No-show is only meaningful after the configured entry window has elapsed.
            $closed = strtotime((string) $row['starts_at'] . ' UTC') + $entryWindow < strtotime($dbNow . ' UTC');
            $row['no_show'] = $closed ? $row['active_tickets'] : 0;
            $row['awaiting_entry'] = $row['active_tickets'];
            $result[] = $row;
        }
        return ['center_id'=>$centerId, 'sessions'=>$result];
    }

    /** CSV for aggregate reports, intentionally excluding emails, phones and attendee names. */
    public static function asCsv(array $report): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) { throw new \RuntimeException('CSV stream unavailable.'); }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [\PESM\Support\I18n::text('جلسه'),\PESM\Support\I18n::text('رویداد'),\PESM\Support\I18n::text('شروع (UTC)'),\PESM\Support\I18n::text('ظرفیت'),\PESM\Support\I18n::text('آزاد'),\PESM\Support\I18n::text('نگهداری موقت'),\PESM\Support\I18n::text('در انتظار پرداخت'),\PESM\Support\I18n::text('فروخته‌شده'),\PESM\Support\I18n::text('مسدود'),\PESM\Support\I18n::text('بلیت فعال'),\PESM\Support\I18n::text('ورود ثبت‌شده'),\PESM\Support\I18n::text('عدم حضور پس از پایان مهلت')], ',', '"', '');
        foreach ($report['sessions'] as $row) {
            fputcsv($stream, [
                (int) $row['session_id'], CsvSafety::safeCell((string) $row['title']), (string) $row['starts_at'],
                (int) $row['capacity'], (int) $row['available'], (int) $row['held'],
                (int) $row['payment_pending'], (int) $row['sold'], (int) $row['blocked'],
                (int) $row['active_tickets'], (int) $row['checked_in'], (int) $row['no_show'],
            ], ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return (string) $csv;
    }

    /** Explicit nonce plus server-side capability + center ownership on every export. */
    public static function downloadCsv(): void
    {
        $centerId = isset($_GET['center_id']) ? absint(wp_unslash($_GET['center_id'])) : 0;
        check_admin_referer('pesm_attendance_csv_' . $centerId);
        try {
            $data = (new self())->summary(get_current_user_id(), $centerId);
        } catch (HoldException $e) {
            wp_die(esc_html($e->getMessage()), '', ['response'=>$e->httpStatus]);
        }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="pesm-attendance-' . $centerId . '.csv"');
        echo self::asCsv($data); // Generated CSV; every user-controlled cell is formula-escaped.
        exit;
    }

    /** Export aggregate-only rows with the same scoped query as CSV. */
    public static function downloadXlsx(): void
    {
        $centerId = isset($_GET['center_id']) ? absint(wp_unslash($_GET['center_id'])) : 0;
        check_admin_referer('pesm_attendance_xlsx_' . $centerId);
        try {
            $data = (new self())->summary(get_current_user_id(), $centerId);
            $path = XlsxExporter::toFile($data);
        } catch (HoldException $e) {
            wp_die(esc_html($e->getMessage()), '', ['response' => $e->httpStatus]);
        } catch (\Throwable $e) {
            // Never expose server paths or exception details in the public response.
            wp_die(\PESM\Support\I18n::text('تولید گزارش Excel ممکن نشد؛ ماژول Phar و فضای موقت سرور را بررسی کنید.'), '', ['response' => 503]);
        }
        if (headers_sent()) {
            @unlink($path);
            wp_die(\PESM\Support\I18n::text('سرآیندهای خروجی قبلاً ارسال شده‌اند.'), '', ['response' => 500]);
        }
        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="pesm-attendance-' . $centerId . '.xlsx"');
        header('Content-Length: ' . filesize($path));
        try {
            readfile($path);
        } finally {
            @unlink($path);
        }
        exit;
    }

}
