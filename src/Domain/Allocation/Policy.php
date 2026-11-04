<?php
declare(strict_types=1);
namespace PESM\Domain\Allocation;

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Checkout\FinancialSnapshot;

/** Pure normalized allocation, pricing and admission rules. */
final class Policy
{
    public const MAX_UNITS = 20;
    public const MAX_ADMISSIONS = 200;

    public static function units(array $units): array
    {
        if (!$units || count($units) > self::MAX_UNITS) { self::fail('invalid_selection'); }
        $result = [];
        foreach ($units as $unit) {
            if (!is_array($unit) || !in_array($unit['type'] ?? '', ['seat','group','table','box'], true)) { self::fail('invalid_selection'); }
            $type = $unit['type'] === 'seat' ? 'seat' : 'group';
            $id = $unit['id'] ?? null;
            if ($type === 'seat') {
                if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,18}$/D', (string) $id) || (int) $id < 1) { self::fail('invalid_selection'); }
                $id = (int) $id;
            } elseif (!is_string($id) || !preg_match('/^[A-Za-z0-9._-]{1,60}$/D', $id)) { self::fail('invalid_selection'); }
            $key = $type . ':' . $id;
            if (isset($result[$key])) { self::fail('duplicate_resource'); }
            $result[$key] = ['type'=>$type,'id'=>$id];
        }
        ksort($result, SORT_STRING);
        return array_values($result);
    }

    public static function settings(array $settings): array
    {
        $mode = $settings['ticket_mode'] ?? 'individual';
        $entry = $settings['group_entry_policy'] ?? 'together';
        if (!in_array($mode, ['individual','group','buyer_choice'], true) || !in_array($entry, ['together','incremental'], true)) { self::fail('invalid_settings'); }
        $pricing = array_key_exists('pricing',$settings) ? $settings['pricing'] : [];
        if (!is_array($pricing)) { self::fail('invalid_settings'); }
        $normalized = [];
        foreach (['sections','categories','groups'] as $kind) {
            $normalized[$kind] = self::prices($pricing[$kind] ?? []);
        }
        $normalized['section_categories'] = [];
        $sectionCategories=$pricing['section_categories']??[];
        if(!is_array($sectionCategories)||count($sectionCategories)>10000){self::fail('invalid_settings');}
        foreach ($sectionCategories as $section=>$prices) {
            self::code((string) $section);
            $normalized['section_categories'][(string) $section] = self::prices($prices);
        }
        $groupModes=$settings['group_modes']??[];
        if(!is_array($groupModes)||count($groupModes)>10000){self::fail('invalid_settings');}
        foreach($groupModes as$code=>$sale){self::code((string)$code);if(!in_array($sale,['individual','whole','both'],true)){self::fail('invalid_settings');}}
        return ['pricing'=>$normalized,'ticket_mode'=>$mode,'group_entry_policy'=>$entry,'group_modes'=>$groupModes];
    }

    private static function prices(mixed $prices): array
    {
        if (!is_array($prices) || count($prices) > 10000) { self::fail('invalid_settings'); }
        $result = [];
        foreach ($prices as $key=>$value) {
            self::code((string) $key);
            $result[(string) $key] = FinancialSnapshot::integer($value, 100000000000);
        }
        ksort($result);
        return $result;
    }

    public static function price(int $default, string $section, string $category, array $settings): int
    {
        $p = $settings['pricing'];
        return $p['section_categories'][$section][$category] ?? $p['categories'][$category] ?? $p['sections'][$section] ?? $default;
    }

    public static function allocate(int $total, array $seatIds): array
    {
        if (!$seatIds || count($seatIds) > self::MAX_ADMISSIONS || $total < 0) { self::fail('invalid_selection'); }
        sort($seatIds, SORT_NUMERIC);
        $base = intdiv($total, count($seatIds)); $remainder = $total % count($seatIds); $result = [];
        foreach ($seatIds as $index=>$id) { $result[(int) $id] = $base + ($index < $remainder ? 1 : 0); }
        return $result;
    }

    public static function entry(string $policy, int $capacity, int $used, int $requested): int
    {
        if (!in_array($policy, ['together','incremental'], true) || $capacity < 1 || $used < 0 || $used > $capacity) { self::fail('invalid_admission'); }
        if ($used === $capacity) { self::fail('already_checked_in'); }
        if ($policy === 'together') {
            if ($used !== 0) { self::fail('admission_conflict'); }
            return $capacity;
        }
        if ($requested < 1 || $requested > $capacity - $used) { self::fail('admission_quantity'); }
        return $requested;
    }

    public static function code(string $code): void
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,60}$/D', $code)) { self::fail('invalid_identifier'); }
    }

    public static function fail(string $code, int $http = 409): never
    {
        throw new HoldException($code, Messages::text($code), $http);
    }
}
