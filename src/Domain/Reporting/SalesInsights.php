<?php

declare(strict_types=1);

namespace PESM\Domain\Reporting;

use DateTimeImmutable;
use DateTimeZone;
use PESM\Domain\Booking\HoldException;
use PESM\Security\Capabilities;

/**
 * Center-scoped ticket issuance and seat-distribution analytics.
 * Not a finance ledger: ticket created_at is NOT WooCommerce payment time.
 * No order/customer names, identifiers, addresses, or phone numbers leave this service.
 */
final class SalesInsights
{
    public function summary(int $actorId, int $centerId, int $days = 30, int $sessionId = 0): array
    {
        if ($actorId < 1 || $centerId < 1 || !user_can($actorId, 'pesm_view_reports') ||
            !Capabilities::centerAllowed($centerId, $actorId)) {
            throw new HoldException('permission_denied', \PESM\Support\I18n::text('اجازه مشاهده گزارش این مرکز را ندارید.'), 403);
        }
        if ($days < 7 || $days > 90 || $sessionId < 0) {
            throw new HoldException('invalid_report_filter', \PESM\Support\I18n::text('بازه گزارش یا جلسه نامعتبر است.'), 422);
        }
        if ((int)get_option('pesm_schema_version', 0) < 13) {
            throw new HoldException('schema_not_ready', \PESM\Support\I18n::text('ساختار گزارش‌گیری آماده نیست.'), 503);
        }
        // This query also checks that a filtered session belongs to the requested center.
        // Avoid applying a session filter to unrelated centers in subsequent queries.
        $attendance = (new AttendanceReport())->summary($actorId, $centerId, $sessionId);
        if ($sessionId > 0 && !$attendance['sessions']) {
            throw new HoldException('invalid_session', \PESM\Support\I18n::text('جلسه‌ای در مرکز مجاز یافت نشد.'), 404);
        }

        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $start = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify('-' . ($days - 1) . ' days');
        $end = $start->modify('+' . $days . ' days');
        $from = $start->format('Y-m-d H:i:s');
        $until = $end->format('Y-m-d H:i:s');
        $daily = [];
        for ($d = 0; $d < $days; $d++) {
            $daily[$start->modify('+' . $d . ' days')->format('Y-m-d')] = 0;
        }
        $conditions = 'e.center_id=%d';
        $params = [$centerId];
        if ($sessionId > 0) {
            $conditions .= ' AND s.id=%d';
            $params[] = $sessionId;
        }
        // Both ticket tables represent the same metric. Never count holds or pending orders.
        $ticketTables = ['tickets', 'capacity_tickets'];
        if ((int) get_option('pesm_schema_version', 0) >= 19) { $ticketTables[] = 'allocation_tickets'; }
        foreach ($ticketTables as $table) {
            $activeLine = $table === 'allocation_tickets' ? "AND (t.status='checked_in' OR EXISTS (SELECT 1 FROM {$p}allocation_lines al WHERE al.id=t.line_id AND al.status='issued'))" : '';
            $sql = "SELECT DATE(t.created_at) AS issued_day, COUNT(*) AS total
                    FROM {$p}{$table} t
                    JOIN {$p}sessions s ON s.id=t.session_id
                    JOIN {$p}events e ON e.id=s.event_id
                    WHERE {$conditions} AND t.created_at >= %s AND t.created_at < %s
                      AND t.status IN ('active','checked_in')
                      {$activeLine}
                    GROUP BY DATE(t.created_at) ORDER BY issued_day";
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [$from, $until])), ARRAY_A);
            if (!is_array($rows)) {
                throw new HoldException('db_unavailable', \PESM\Support\I18n::text('دریافت روند بلیت ممکن نشد.'), 503);
            }
            foreach ($rows as $row) {
                $day = (string)($row['issued_day'] ?? '');
                if (array_key_exists($day, $daily)) {
                    $daily[$day] += max(0, (int)$row['total']);
                }
            }
        }
        $ticketSource = (int) get_option('pesm_schema_version', 0) >= 19
            ? "(SELECT session_id,seat_id,status FROM {$p}tickets UNION ALL SELECT a.session_id,a.seat_id,a.status FROM {$p}allocation_tickets a JOIN {$p}allocation_lines al ON al.id=a.line_id WHERE al.status='issued' OR a.status='checked_in')" : "{$p}tickets";
        $sql = "SELECT seat.zone_label AS zone,seat.row_label AS row_label,COUNT(*) AS total
                FROM {$ticketSource} t
                JOIN {$p}seats seat ON seat.id=t.seat_id
                JOIN {$p}sessions s ON s.id=t.session_id AND s.layout_id=seat.layout_id
                JOIN {$p}events e ON e.id=s.event_id
                WHERE {$conditions} AND t.status IN ('active','checked_in')
                GROUP BY seat.zone_label,seat.row_label
                ORDER BY total DESC,zone ASC,row_label ASC LIMIT 200";
        $zones = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        if (!is_array($zones)) {
            throw new HoldException('db_unavailable', \PESM\Support\I18n::text('دریافت آمار ردیف‌ها ممکن نشد.'), 503);
        }
        $groups = [];
        foreach ($zones as $row) {
            $groups[] = [
                'zone' => (string)$row['zone'], 'row' => (string)$row['row_label'],
                'tickets' => max(0, (int)$row['total']),
            ];
        }
        $sessions = [];
        $events = [];
        foreach ($attendance['sessions'] as $row) {
            $cap = max(0, (int)$row['capacity']);
            $sold = max(0, (int)$row['sold']);
            $eventId = (int)$row['event_id'];
            $sessions[] = [
                'session_id'=>(int)$row['session_id'], 'event_id'=>$eventId,
                'event_title'=>(string)$row['title'], 'capacity'=>$cap, 'sold'=>$sold,
                'occupancy_percent'=>$cap > 0 ? round(100 * $sold / $cap, 1) : 0.0,
            ];
            if (!isset($events[$eventId])) {
                $events[$eventId] = ['event_id'=>$eventId, 'title'=>(string)$row['title'],
                    'sessions'=>0,'capacity'=>0,'sold'=>0,'occupancy_percent'=>0.0];
            }
            ++$events[$eventId]['sessions'];
            $events[$eventId]['capacity'] += $cap;
            $events[$eventId]['sold'] += $sold;
        }
        foreach ($events as &$row) {
            $row['occupancy_percent'] = $row['capacity'] > 0
                ? round(100 * $row['sold'] / $row['capacity'], 1) : 0.0;
        }
        unset($row);
        $trend = [];
        foreach ($daily as $date => $count) {
            $trend[] = ['date'=>$date, 'issued_active_tickets'=>$count];
        }
        return [
            'center_id'=>$centerId, 'session_id'=>$sessionId,
            'days'=>$days, 'time_zone'=>'UTC', 'measure'=>'currently_valid_tickets_by_issuance_date',
            'not_financial_revenue'=>true, 'sessions_limit'=>200, 'zone_rows_limit'=>200,
            'trend'=>$trend, 'sessions'=>$sessions, 'events'=>array_values($events), 'zone_rows'=>$groups,
        ];
    }
}
