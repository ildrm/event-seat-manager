<?php

declare(strict_types=1);

namespace PESM\Domain\Hall;

use InvalidArgumentException;
use JsonException;

require_once __DIR__ . '/SeatCode.php';

/** A versioned, data-only format. Never accepts SQL, HTML or serialized PHP. */
final class LayoutDocument
{
    public const FORMAT = 'pesm.layout.v1';
    public const MAX_BYTES = 2_097_152;
    public const MAX_SEATS = 10000;
    public const MAX_OBJECTS = 500;
    public const OBJECT_TYPES = ['stage','round_table','rect_table','aisle','door','emergency_exit','label','separator','accessible_area'];

    /** @return list<array{code:string,row_label:string,number:int,zone:string,x:int,y:int}> */
    public static function parse(string $json): array
    {
        if ($json === '' || strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException(\PESM\Support\I18n::text('حجم فایل چیدمان نامعتبر است (حداکثر ۲ مگابایت).'));
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(\PESM\Support\I18n::text('فایل چیدمان JSON معتبر نیست.'), 0, $e);
        }
        if (!is_array($data) || array_is_list($data) || ($data['format'] ?? null) !== self::FORMAT ||
            !isset($data['seats']) || !is_array($data['seats']) || !array_is_list($data['seats'])) {
            throw new InvalidArgumentException(\PESM\Support\I18n::text('قالب فایل چیدمان پشتیبانی نمی‌شود.'));
        }
        if (count($data['seats']) < 1 || count($data['seats']) > self::MAX_SEATS) {
            throw new InvalidArgumentException(\PESM\Support\I18n::text('تعداد صندلی باید بین ۱ و ۱۰۰۰۰ باشد.'));
        }
        $seats = [];
        $seen = [];
        foreach ($data['seats'] as $index => $seat) {
            if (!is_array($seat) || array_is_list($seat)) {
                throw new InvalidArgumentException(\PESM\Support\I18n::text('ساختار یک صندلی نامعتبر است.'));
            }
            // An exact allowlist also prevents accidentally importing private or executable fields.
            $expected = ['code', 'row_label', 'number', 'zone', 'x', 'y'];
            $keys = array_keys($seat);
            sort($keys);
            $sortedExpected = $expected;
            sort($sortedExpected);
            if ($keys !== $sortedExpected) {
                throw new InvalidArgumentException(\PESM\Support\I18n::text('فیلدهای صندلی ناقص یا اضافی است.'));
            }
            $code = $seat['code'];
            $row = $seat['row_label'];
            $zone = $seat['zone'];
            if (!SeatCode::valid($code) ||
                !is_string($row) || !preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $row) ||
                !is_string($zone) || trim($zone) === '' || strlen($zone) > 240 ||
                preg_match('/\A.{1,60}\z/usD', $zone) !== 1 ||
                preg_match('/[\x00-\x1F\x7F]/', $zone) ||
                !self::integer($seat['number'], 1, 10000) ||
                !self::integer($seat['x'], -1000000, 1000000) ||
                !self::integer($seat['y'], -1000000, 1000000)) {
                throw new InvalidArgumentException(\PESM\Support\I18n::text('اطلاعات صندلی شماره ') . ($index + 1) . \PESM\Support\I18n::text(' نامعتبر است.'));
            }
            if (isset($seen[$code])) {
                throw new InvalidArgumentException(\PESM\Support\I18n::text('کد صندلی تکراری است: ') . $code);
            }
            $seen[$code] = true;
            $seats[] = ['code' => $code, 'row_label' => $row, 'number' => $seat['number'],
                'zone' => $zone, 'x' => $seat['x'], 'y' => $seat['y']];
        }
        return $seats;
    }

    /**
     * @return array{seats:list<array<string,mixed>>,objects:list<array<string,mixed>>}
     * Older v1 layouts without an objects array are valid and remain importable.
     */
    public static function parseFull(string $json): array
    {
        $seats = self::parse($json);
        $decoded = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        if (!is_object($decoded) || (property_exists($decoded, 'objects') && !is_array($decoded->objects))) {
            throw new InvalidArgumentException(\PESM\Support\I18n::text('فهرست اشیای نقشه باید آرایه باشد.'));
        }
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        $objects = $data['objects'] ?? [];
        if (!is_array($objects) || !array_is_list($objects) || count($objects) > self::MAX_OBJECTS) {
            throw new InvalidArgumentException(\PESM\Support\I18n::text('فهرست اشیای نقشه نامعتبر است.'));
        }
        $seen = [];
        $parsed = [];
        foreach ($objects as $index => $object) {
            if (!is_array($object) || array_is_list($object)) {
                throw new InvalidArgumentException(\PESM\Support\I18n::text('ساختار شیء نقشه نامعتبر است.'));
            }
            $keys = array_keys($object);
            sort($keys);
            $expected = ['id','type','x','y','width','height','label'];
            sort($expected);
            if ($keys !== $expected || !is_string($object['id']) ||
                !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,59}$/D', $object['id']) ||
                isset($seen[$object['id']]) || !is_string($object['type']) ||
                !in_array($object['type'], self::OBJECT_TYPES, true) ||
                !self::integer($object['x'], -1000000, 1000000) ||
                !self::integer($object['y'], -1000000, 1000000) ||
                !self::integer($object['width'], 1, 10000) ||
                !self::integer($object['height'], 1, 10000) ||
                !is_string($object['label']) || strlen($object['label']) > 360 ||
                preg_match('/\A.{0,120}\z/usD', $object['label']) !== 1 ||
                preg_match('/[\x00-\x1F\x7F]/', $object['label'])) {
                throw new InvalidArgumentException(\PESM\Support\I18n::text('اطلاعات شیء شماره ') . ($index + 1) . \PESM\Support\I18n::text(' نامعتبر است.'));
            }
            $seen[$object['id']] = true;
            $parsed[] = $object;
        }
        return ['seats' => $seats, 'objects' => $parsed];
    }

    /** @param list<array<string,mixed>> $seats @param list<array<string,mixed>> $objects */
    public static function encodeFull(array $seats, array $objects): string
    {
        // Retain the v1 interchange format; seats retain their own immutable codes.
        $legacy = json_decode(self::encode($seats), true, 32, JSON_THROW_ON_ERROR);
        $legacy['objects'] = array_values($objects);
        $json = json_encode($legacy, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        self::parseFull($json); // Never export invalid stored geometry or unsafe text.
        return $json . "\n";
    }

    private static function integer(mixed $value, int $min, int $max): bool
    {
        return is_int($value) && $value >= $min && $value <= $max;
    }

    /** @param list<array<string,mixed>> $seats */
    public static function encode(array $seats): string
    {
        $out = [];
        foreach ($seats as $seat) {
            $out[] = ['code' => (string) $seat['code'], 'row_label' => (string) $seat['row_label'],
                'number' => (int) $seat['number'], 'zone' => (string) $seat['zone'],
                'x' => (int) $seat['x'], 'y' => (int) $seat['y']];
        }
        $json = json_encode(['format' => self::FORMAT, 'seats' => $out],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        // Revalidate exported documents so a corrupt DB layout cannot become an unsafe interchange format.
        self::parse($json);
        return $json . "\n";
    }
}
