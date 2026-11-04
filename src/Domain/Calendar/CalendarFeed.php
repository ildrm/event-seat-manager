<?php

declare(strict_types=1);

namespace PESM\Domain\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** RFC 5545 text/calendar (.ics), without a guessed event duration. */
final class CalendarFeed
{
    /** @param array{session_id:int,starts_at:string,title:string,venue:string,description:string} $data */
    public static function build(array $data, string $host, string $dtstamp): string
    {
        $when = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $data['starts_at'], new DateTimeZone('UTC'));
        if (!$when || $when->format('Y-m-d H:i:s') !== $data['starts_at'] ||
            $data['session_id'] < 1 || !preg_match('/^[a-z0-9.-]{1,253}$/Di', $host) ||
            !preg_match('/^\d{8}T\d{6}Z$/D', $dtstamp)) {
            throw new InvalidArgumentException(\PESM\Support\I18n::text('اطلاعات تقویم جلسه معتبر نیست.'));
        }
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//PESM//Event Calendar//FA',
            'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'BEGIN:VEVENT',
            'UID:pesm-session-' . $data['session_id'] . '@' . $host,
            'DTSTAMP:' . $dtstamp,
            'DTSTART:' . $when->format('Ymd\THis\Z'),
            'SUMMARY:' . self::escape($data['title']),
            'LOCATION:' . self::escape($data['venue']),
            'DESCRIPTION:' . self::escape($data['description']),
            'END:VEVENT', 'END:VCALENDAR',
        ];
        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    /** Escape text fields, strip control chars and prevent raw property injection. */
    public static function escape(string $text): string
    {
        $text = preg_replace('/\r\n?|\n/u', "\n", $text) ?? '';
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '';
        if (strlen($text) > 8000) {
            $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
            if ($chars === false) { throw new InvalidArgumentException(\PESM\Support\I18n::text('رشته UTF-8 نامعتبر است.')); }
            $limited = ''; $bytes = 0;
            foreach ($chars as $char) {
                if ($bytes + strlen($char) > 8000) { break; }
                $limited .= $char; $bytes += strlen($char);
            }
            $text = $limited;
        }
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
    }

    /** Fold at no more than 75 octets without splitting a UTF-8 codepoint. */
    public static function fold(string $line): string
    {
        $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) { throw new InvalidArgumentException(\PESM\Support\I18n::text('رشته UTF-8 نامعتبر است.')); }
        $out = '';
        $length = 0;
        foreach ($chars as $char) {
            $bytes = strlen($char);
            if ($length + $bytes > 75) {
                $out .= "\r\n ";
                $length = 1;
            }
            $out .= $char;
            $length += $bytes;
        }
        return $out;
    }

    /** Public download is available only for a published event and session. */
    public static function download(): void
    {
        $sessionId = isset($_GET['session_id']) ? absint(wp_unslash($_GET['session_id'])) : 0;
        if ($sessionId < 1) { wp_die(\PESM\Support\I18n::text('شناسه جلسه نامعتبر است.'), '', ['response' => 404]); }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT s.id AS session_id,s.starts_at,e.title,e.venue,e.description FROM {$p}sessions s
             JOIN {$p}events e ON e.id=s.event_id WHERE s.id=%d AND s.status='published' AND e.status='published'",
            $sessionId
        ), ARRAY_A);
        if (!$row) { wp_die(\PESM\Support\I18n::text('جلسه عمومی پیدا نشد.'), '', ['response' => 404]); }
        try {
            $host = (string) parse_url(home_url('/'), PHP_URL_HOST);
            $payload = self::build([
                'session_id' => (int) $row['session_id'], 'starts_at' => (string) $row['starts_at'],
                'title' => (string) $row['title'], 'venue' => (string) ($row['venue'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
            ], $host, gmdate('Ymd\THis\Z'));
        } catch (\Throwable $e) {
            wp_die(\PESM\Support\I18n::text('تولید تقویم ممکن نیست.'), '', ['response' => 503]);
        }
        nocache_headers();
        header('Content-Type: text/calendar; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="pesm-session-' . $sessionId . '.ics"');
        echo $payload;
        exit;
    }
}
