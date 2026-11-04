<?php

declare(strict_types=1);

namespace PESM\Domain\Booking;

use PESM\Support\Audit;
use Throwable;

/**
 * Best-effort bounded cleanup. Stock correctness is enforced by transactional
 * hold acquisition and DB timestamps, never by this scheduled task.
 */
final class HoldMaintenance
{
    public static function cleanup(): int
    {
        if ((int) get_option('pesm_schema_version', 0) < 5) {
            return 0;
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $sessions = $wpdb->get_col(
            "SELECT DISTINCT session_id FROM {$p}holds WHERE status='active' AND expires_at<=UTC_TIMESTAMP()
             ORDER BY session_id LIMIT 25"
        );
        if (!is_array($sessions)) {
            return 0;
        }
        $cleaned = 0;
        foreach ($sessions as $sessionId) {
            if ($wpdb->query('START TRANSACTION') === false) {
                continue;
            }
            try {
                $sessionId = (int) $sessionId;
                if (!$wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$p}sessions WHERE id=%d FOR UPDATE", $sessionId
                ))) {
                    $wpdb->query('ROLLBACK');
                    continue;
                }
                $now = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
                $holds = $wpdb->get_results($wpdb->prepare(
                    "SELECT id FROM {$p}holds WHERE session_id=%d AND status='active'
                     AND expires_at<=%s ORDER BY id LIMIT 100 FOR UPDATE", $sessionId, $now
                ));
                if (!is_array($holds)) {
                    throw new \RuntimeException('Cannot fetch expired holds.');
                }
                foreach ($holds as $hold) {
                    $holdId = (int) $hold->id;
                    // Lock seat rows in a stable order, do not touch reassigned/sold seats.
                    $rows = $wpdb->get_results($wpdb->prepare(
                        "SELECT seat_id FROM {$p}session_seats WHERE session_id=%d AND hold_id=%d
                         AND status='held' ORDER BY seat_id FOR UPDATE", $sessionId, $holdId
                    ));
                    if (!is_array($rows)) { throw new \RuntimeException('Cannot fetch expired seats.'); }
                    foreach ($rows as $row) {
                        if ($wpdb->update($p . 'session_seats', [
                            'status'=>'available', 'hold_id'=>null, 'expires_at'=>null, 'updated_at'=>$now,
                        ], ['session_id'=>$sessionId, 'seat_id'=>(int) $row->seat_id,
                            'hold_id'=>$holdId, 'status'=>'held']) !== 1) {
                            throw new \RuntimeException('Cannot release expired seat.');
                        }
                    }
                    if ($wpdb->update($p . 'holds', ['status'=>'expired'],
                        ['id'=>$holdId, 'status'=>'active']) !== 1) {
                        throw new \RuntimeException('Cannot expire hold.');
                    }
                    Audit::record('hold_expired', 'hold', $holdId);
                    ++$cleaned;
                }
                if ($wpdb->query('COMMIT') === false) {
                    throw new \RuntimeException('Commit failed.');
                }
            } catch (Throwable $error) {
                $wpdb->query('ROLLBACK');
                // Leave untouched for the next run; lazy acquisition remains authoritative.
            }
        }
        return $cleaned;
    }
}
