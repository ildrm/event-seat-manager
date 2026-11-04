<?php

declare(strict_types=1);

namespace PESM\Domain\Ticket;

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Booking\HoldPolicy;
use PESM\Domain\Booking\SeatingRules;

/** Domain-only rules for an administrative, same-price post-issuance seat move. */
final class SeatTransferPolicy
{
    public static function validate(int $ticketId, int $targetSeatId, string $requestKey): void
    {
        if ($ticketId < 1 || $targetSeatId < 1) {
            throw new HoldException('invalid_selection', \PESM\Support\I18n::text('شماره بلیت یا صندلی مقصد معتبر نیست.'), 422);
        }
        HoldPolicy::validateKey($requestKey);
    }

    public static function mustBeActive(string $ticketStatus, string $sessionStatus, string $eventStatus,
        string $startsAt, string $now, bool $checkedIn): void
    {
        if ($ticketStatus !== 'active' || $checkedIn) {
            throw new HoldException('ticket_not_transferable', \PESM\Support\I18n::text('بلیت استفاده‌شده یا غیرفعال قابل جابه‌جایی نیست.'), 409);
        }
        if ($sessionStatus !== 'published' || $eventStatus !== 'published' || $startsAt <= $now) {
            throw new HoldException('event_closed', \PESM\Support\I18n::text('مهلت جابه‌جایی صندلی پایان یافته است.'), 409);
        }
    }

    /**
     * Re-evaluate booking-wide adjacency and newly isolated free seats.
     * $rows represent the BEFORE transfer snapshot, including sold allocations.
     * @param list<array<string,mixed>> $rows
     * @param list<int> $otherSeats Other tickets on the same order/hold.
     */
    public static function validateTopology(array $rows, array $otherSeats, int $source, int $target,
        bool $adjacent, bool $avoidGap): void
    {
        if (!$adjacent && !$avoidGap) { return; }
        $graph = SeatingRules::graph($rows);
        $selection = array_values(array_unique(array_merge($otherSeats, [$target])));
        $wanted = array_fill_keys($selection, true);
        if (!isset($graph[$source], $graph[$target]) || count(array_intersect_key($wanted, $graph)) !== count($wanted)) {
            throw new HoldException('invalid_seat', \PESM\Support\I18n::text('چیدمان صندلی‌ها معتبر نیست.'), 422);
        }
        if ($adjacent && count($selection) > 1) {
            $visited = []; $stack = [$selection[0]];
            while ($stack) {
                $id = array_pop($stack);
                if (isset($visited[$id])) { continue; }
                $visited[$id] = true;
                foreach ($graph[$id] as $near) {
                    if (isset($wanted[$near]) && !isset($visited[$near])) { $stack[] = $near; }
                }
            }
            if (count($visited) !== count($wanted)) {
                throw new HoldException('seats_not_adjacent', \PESM\Support\I18n::text('بعد از انتقال، صندلی‌های یک سفارش دیگر مجاور نیستند.'), 409);
            }
        }
        if (!$avoidGap) { return; }
        $before = [];
        foreach ($rows as $row) { $before[(int) $row['seat_id']] = (string) $row['status'] === 'available'; }
        $after = $before;
        $after[$source] = true;
        $after[$target] = false;
        foreach ($after as $id => $free) {
            if (!$free || !$graph[$id]) { continue; }
            $neighborsBefore = 0; $neighborsAfter = 0;
            foreach ($graph[$id] as $neighbor) {
                $neighborsBefore += (int) ($before[$neighbor] ?? false);
                $neighborsAfter += (int) ($after[$neighbor] ?? false);
            }
            if ($neighborsAfter === 0 && (!$before[$id] || $neighborsBefore > 0)) {
                throw new HoldException('single_seat_gap', \PESM\Support\I18n::text('جابه‌جایی یک صندلی آزاد تک باقی می‌گذارد.'), 409);
            }
        }
    }

    public static function assertDestination(string $status): void
    {
        // An expired hold is intentionally NOT taken over by this feature.
        // Cleanup/reconciliation must release the row first.
        if ($status !== 'available') {
            throw new HoldException('seat_unavailable', \PESM\Support\I18n::text('صندلی مقصد در دسترس نیست.'), 409);
        }
    }
}
