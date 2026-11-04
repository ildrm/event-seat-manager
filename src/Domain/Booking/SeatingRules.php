<?php

declare(strict_types=1);

namespace PESM\Domain\Booking;

/**
 * Pure seating constraints. Adjacency is based on geometric row segments, not
 * consecutive display numbers. In the generated layouts, a 22-unit aisle
 * separates every 15 seats; a 42-unit threshold does not cross that aisle.
 * Custom layouts need an explicit topology editor before they can be certified.
 */
final class SeatingRules
{
    public const MAX_ADJACENT_DISTANCE = 42;

    /**
     * @param list<array<string,mixed>> $rows Joined seat and session inventory rows.
     * @return array<int,list<int>> An undirected graph keyed by seat id.
     */
    public static function graph(array $rows): array
    {
        $byRow = [];
        $graph = [];
        foreach ($rows as $seat) {
            $id = (int) $seat['seat_id'];
            if ($id < 1) { throw new HoldException('invalid_seat', \PESM\Support\I18n::text('شناسه صندلی در نقشه معتبر نیست.'), 503); }
            $graph[$id] = [];
            $rowKey = (string) $seat['row_label'] . "\0" . (string) $seat['zone_label'] . "\0" . (int) $seat['pos_y'];
            $byRow[$rowKey][] = $seat;
        }
        foreach ($byRow as $seats) {
            usort($seats, static fn(array $a, array $b): int =>
                ((int) $a['pos_x'] <=> (int) $b['pos_x']) ?: ((int) $a['seat_id'] <=> (int) $b['seat_id']));
            for ($i = 1, $n = count($seats); $i < $n; ++$i) {
                $before = $seats[$i - 1];
                $after = $seats[$i];
                $distance = (int) $after['pos_x'] - (int) $before['pos_x'];
                if ($distance < 1 || $distance > self::MAX_ADJACENT_DISTANCE) { continue; }
                $a = (int) $before['seat_id']; $b = (int) $after['seat_id'];
                $graph[$a][] = $b;
                $graph[$b][] = $a;
            }
        }
        return $graph;
    }

    /**
     * Validate final selection while the caller holds the session row lock.
     * @param list<array<string,mixed>> $rows Full current session inventory.
     * @param list<int> $selected The complete desired hold selection.
     * @param int $ownerHoldId Existing hold being modified; 0 for new holds.
     */
    public static function validate(
        array $rows, array $selected, string $now, bool $adjacent, bool $singleGap,
        int $ownerHoldId = 0, ?array $precomputedGraph = null
    ): void {
        if (!$adjacent && !$singleGap) { return; }
        $graph = $precomputedGraph ?? self::graph($rows);
        $wanted = array_fill_keys($selected, true);
        if (count(array_intersect_key($wanted, $graph)) !== count($wanted)) {
            throw new HoldException('invalid_seat', \PESM\Support\I18n::text('صندلی در چیدمان جلسه وجود ندارد.'), 422);
        }
        if ($adjacent && count($wanted) > 1) {
            $found = [];
            $queue = [$selected[0]];
            while ($queue) {
                $current = array_pop($queue);
                if (isset($found[$current])) { continue; }
                $found[$current] = true;
                foreach ($graph[$current] as $neighbor) {
                    if (isset($wanted[$neighbor]) && !isset($found[$neighbor])) { $queue[] = $neighbor; }
                }
            }
            if (count($found) !== count($wanted)) {
                throw new HoldException('seats_not_adjacent', \PESM\Support\I18n::text('صندلی‌های انتخابی باید واقعاً کنار یکدیگر و در یک بلوک باشند.'), 409);
            }
        }
        if (!$singleGap) { return; }
        $availableBefore = [];
        foreach ($rows as $row) {
            $id = (int) $row['seat_id'];
            $owned = $ownerHoldId > 0 && (int) ($row['hold_id'] ?? 0) === $ownerHoldId &&
                (string) $row['status'] === 'held';
            $availableBefore[$id] = $owned || HoldPolicy::canAcquire(
                (string) $row['status'], isset($row['expires_at']) ? (string) $row['expires_at'] : null, $now
            );
        }
        $availableAfter = $availableBefore;
        foreach ($wanted as $seatId => $_) { $availableAfter[$seatId] = false; }
        // Only *new* singleton gaps are rejected; pre-existing gaps elsewhere
        // do not make unrelated reservations impossible.
        foreach ($graph as $id => $neighbors) {
            if (!$neighbors || !$availableAfter[$id]) { continue; }
            $afterIsSingle = true;
            foreach ($neighbors as $neighbor) { if ($availableAfter[$neighbor]) { $afterIsSingle = false; break; } }
            if (!$afterIsSingle) { continue; }
            $beforeIsSingle = $availableBefore[$id];
            foreach ($neighbors as $neighbor) { if ($availableBefore[$neighbor]) { $beforeIsSingle = false; break; } }
            if (!$beforeIsSingle) {
                throw new HoldException('single_seat_gap', \PESM\Support\I18n::text('این انتخاب یک صندلی خالیِ تک در بلوک ایجاد می‌کند.'), 409);
            }
        }
    }

    /** @param list<object> $objects @return list<array<string,mixed>> */
    public static function fromObjects(array $objects): array
    {
        return array_map(static fn(object $o): array => (array) $o, $objects);
    }
}
