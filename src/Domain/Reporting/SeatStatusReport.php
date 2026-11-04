<?php

declare(strict_types=1);

namespace PESM\Domain\Reporting;

use PESM\Domain\Booking\HoldException;
use PESM\Security\Capabilities;

/** Admin-only current snapshot. Never joins a customer or attendee table. */
final class SeatStatusReport
{
    public function snapshot(int $actorId, int $centerId, int $sessionId): array
    {
        if ($actorId < 1 || $centerId < 1 || $sessionId < 1 ||
            !user_can($actorId, 'pesm_view_reports') || !Capabilities::centerAllowed($centerId, $actorId)) {
            throw new HoldException('permission_denied',\PESM\Support\I18n::text('مجوز گزارش این مرکز وجود ندارد.'),403);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT s.id,s.layout_id,s.mode FROM {$p}sessions s
             JOIN {$p}events e ON e.id=s.event_id
             WHERE s.id=%d AND e.center_id=%d", $sessionId, $centerId
        ), ARRAY_A);
        if (!$session || $session['mode'] !== 'seated') {
            throw new HoldException('invalid_session',\PESM\Support\I18n::text('این جلسه در مرکز شما نقشه صندلی ندارد.'),404);
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT seat.id,seat.seat_code,seat.row_label,seat.zone_label,
                    seat.pos_x,seat.pos_y,
                    CASE WHEN inv.status IN ('held','payment_pending') AND inv.expires_at<=UTC_TIMESTAMP()
                         THEN 'available' ELSE COALESCE(inv.status,'missing') END AS status
             FROM {$p}seats seat
             LEFT JOIN {$p}session_seats inv ON inv.seat_id=seat.id AND inv.session_id=%d
             WHERE seat.layout_id=%d ORDER BY seat.id LIMIT 10001",
            $sessionId, (int)$session['layout_id']
        ), ARRAY_A);
        if (!is_array($rows) || count($rows) > 10000) {
            throw new HoldException('db_unavailable',\PESM\Support\I18n::text('دریافت نقشه مدیریتی ممکن نشد.'),503);
        }
        $allowed=['available','held','payment_pending','sold','blocked','missing'];
        $totals=array_fill_keys($allowed,0);
        $groups=[];
        foreach ($rows as &$row) {
            $row['id']=(int)$row['id'];
            $row['pos_x']=(int)$row['pos_x'];
            $row['pos_y']=(int)$row['pos_y'];
            $row['status']=in_array($row['status'],$allowed,true) ? $row['status'] : 'missing';
            ++$totals[$row['status']];
            $key=(string)$row['zone_label'].' / '.(string)$row['row_label'];
            if (!isset($groups[$key])) $groups[$key]=['zone'=>(string)$row['zone_label'],
                'row'=>(string)$row['row_label'],'total'=>0,'sold'=>0,'available'=>0];
            ++$groups[$key]['total'];
            if ($row['status']==='sold') ++$groups[$key]['sold'];
            if ($row['status']==='available') ++$groups[$key]['available'];
        }
        unset($row);
        return ['center_id'=>$centerId,'session_id'=>$sessionId,'totals'=>$totals,
            'groups'=>array_values($groups),'seats'=>$rows];
    }
}
