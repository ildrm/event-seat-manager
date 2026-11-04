<?php

declare(strict_types=1);

namespace PESM\Domain\Booking;

/** Read-only hints: suggestions NEVER reserve inventory or promise availability. */
final class SeatSuggestions
{
    /**
     * @param list<array<string,mixed>> $rows
     * @return list<list<int>>
     */
    public static function recommend(array $rows, int $count, string $now, bool $preventSingleGap = false, int $limit = 5): array
    {
        if ($count < 1 || $count > HoldPolicy::MAX_SEATS || $limit < 1 || $limit > 10) {
            throw new HoldException('invalid_selection', \PESM\Support\I18n::text('تعداد صندلی درخواستی معتبر نیست.'), 422);
        }
        if (!$rows) { return []; }
        $graph = SeatingRules::graph($rows);
        $geometry = [];
        $available = [];
        foreach ($rows as $row) {
            $id = (int) $row['seat_id'];
            $geometry[$id] = $row;
            $available[$id] = HoldPolicy::canAcquire(
                (string) $row['status'], isset($row['expires_at']) ? (string) $row['expires_at'] : null, $now
            );
        }
        $candidates = [];
        $seen = [];
        $leftMost = min(array_map(static fn(array $r): int => (int) $r['pos_x'], $rows));
        $rightMost = max(array_map(static fn(array $r): int => (int) $r['pos_x'], $rows));
        // Each connected row block is a simple path (at most two neighbors).
        foreach ($graph as $id => $neighbors) {
            if (!$available[$id]) { continue; }
            $run = [$id];
            $cursor = $id;
            while (count($run) < $count) {
                $next = null;
                foreach ($graph[$cursor] as $neighbor) {
                    if ((int) $geometry[$neighbor]['pos_x'] > (int) $geometry[$cursor]['pos_x'] && $available[$neighbor]) {
                        $next = $neighbor; break;
                    }
                }
                if ($next === null) { break; }
                $run[] = $next;
                $cursor = $next;
            }
            if (count($run) !== $count) { continue; }
            try { SeatingRules::validate($rows, $run, $now, true, $preventSingleGap, 0, $graph); }
            catch (HoldException $e) { continue; }
            $ids = $run;
            sort($ids, SORT_NUMERIC);
            $key = implode(',', $ids);
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $xs = array_map(static fn(int $v): int => (int) $geometry[$v]['pos_x'], $run);
            $ys = array_map(static fn(int $v): int => (int) $geometry[$v]['pos_y'], $run);
            // Prefer front rows, then central groups (in the geometry's own units).
            $score = min($ys) * 10000 + abs((min($xs) + max($xs)) - ($leftMost + $rightMost));
            $candidates[] = ['score' => $score, 'ids' => $ids];
        }
        usort($candidates, static fn(array $a, array $b): int => ($a['score'] <=> $b['score']) ?: ($a['ids'] <=> $b['ids']));
        return array_column(array_slice($candidates, 0, $limit), 'ids');
    }
}
