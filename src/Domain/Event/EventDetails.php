<?php

declare(strict_types=1);

namespace PESM\Domain\Event;

use InvalidArgumentException;

/** Keep fields explicit, so arbitrary request properties never become SQL columns. */
final class EventDetails
{
    public const FIELDS = ['description', 'organizer', 'venue', 'contact', 'admission_terms'];

    /** @param array<string,mixed> $values @return array<string,mixed> */
    public static function normalize(array $values): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $value = $values[$field] ?? '';
            if (!is_string($value) || strlen($value) > (in_array($field,['description','admission_terms'],true) ? 10000 : 700)) {
                throw new InvalidArgumentException(\PESM\Support\I18n::text('طول یا نوع مشخصات رویداد معتبر نیست.'));
            }
            $out[$field] = trim($value);
        }
        $poster = $values['poster_attachment_id'] ?? 0;
        if (!is_int($poster) || $poster < 0) {
            throw new InvalidArgumentException(\PESM\Support\I18n::text('شناسه پوستر نامعتبر است.'));
        }
        $out['poster_attachment_id'] = $poster ?: null;
        return $out;
    }
}
