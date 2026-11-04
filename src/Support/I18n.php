<?php

declare(strict_types=1);

namespace PESM\Support;

/** Exact-message translations. Never pass user content or assembled markup to text(). */
final class I18n
{
    private static ?array $english = null;

    public static function locale(): string
    {
        if (function_exists('determine_locale')) {
            return (string) determine_locale();
        }
        return function_exists('get_locale') ? (string) get_locale() : 'fa_IR';
    }

    public static function isPersian(): bool
    {
        return preg_match('/^fa(?:[_-]|$)/i', self::locale()) === 1;
    }

    public static function direction(): string
    {
        return self::isPersian() ? 'rtl' : 'ltr';
    }

    /** Controlled internal state labels; unknown third-party states remain unchanged. */
    public static function status(string $status): string
    {
        $labels = [
            'draft'=>'پیش‌نویس', 'published'=>'منتشرشده', 'paused'=>'متوقف',
            'active'=>'فعال', 'checked_in'=>'ورود ثبت‌شده', 'cancelled'=>'لغوشده',
            'refunded'=>'مستردشده', 'failed'=>'ناموفق', 'pending'=>'در انتظار',
            'on-hold'=>'در انتظار بررسی', 'processing'=>'در حال انجام',
            'completed'=>'تکمیل‌شده', 'issued'=>'صادرشده', 'held'=>'نگهداری موقت',
            'payment_pending'=>'در انتظار پرداخت', 'sold'=>'فروخته‌شده',
            'blocked'=>'مسدود', 'available'=>'آزاد', 'refund_pending'=>'در انتظار استرداد',
            'awaiting_woocommerce'=>'در انتظار استرداد ووکامرس', 'finalized'=>'نهایی‌شده',
        ];
        return isset($labels[$status]) ? self::text($labels[$status]) : $status;
    }

    public static function text(string $message): string
    {
        // Site language packs may override either bundled language using normal gettext.
        $translated = function_exists('__') ? __($message, 'event-seat-manager') : $message;
        if ($translated !== $message) {
            return $translated;
        }
        $messages = self::isPersian() ? [] : self::english();
        if (isset($messages[$message])) {
            return $messages[$message];
        }
        // Some legacy views contain HTML in static source literals. Translate only their
        // statically extracted spans. Callers must escape and append dynamic data separately.
        if (str_contains($message, '<')) {
            return preg_replace_callback('/[^<>"\']*[\x{0600}-\x{06ff}][^<>"\']*/u',
                static function (array $match) use ($messages): string {
                    $translation = function_exists('__') ? __($match[0], 'event-seat-manager') : $match[0];
                    return $translation !== $match[0] ? $translation : ($messages[$match[0]] ?? $match[0]);
                }, $message) ?? $message;
        }
        return $message;
    }

    /** @return array<string,string> */
    public static function english(): array
    {
        if (self::$english === null) {
            $path = dirname(__DIR__, 2) . '/languages/en.json';
            $content = is_file($path) ? file_get_contents($path) : false;
            $data = $content !== false ? json_decode($content, true) : null;
            self::$english = is_array($data) ? $data : [];
        }
        return self::$english;
    }

    /** Business dates use Tehran time in both locales, and Gregorian dates in English. */
    public static function formatDate(string $utc): string
    {
        if (self::isPersian()) {
            return Jalali::formatTehran($utc);
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d H:i:s') !== $utc) {
            throw new \InvalidArgumentException(self::text('زمان UTC نامعتبر است.'));
        }
        return $date->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('Y/m/d — H:i');
    }

    public static function register(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'registerAssets'], 5);
        add_action('admin_enqueue_scripts', [self::class, 'registerAssets'], 5);
        add_action('init', [\PESM\Security\Capabilities::class, 'localizeRoleNames'], 2);
        add_action('change_locale', [\PESM\Security\Capabilities::class, 'localizeRoleNames'], 10, 0);
    }

    public static function registerAssets(): void
    {
        wp_register_script('pesm-i18n', PESM_URL . 'assets/js/i18n.js', [], PESM_VERSION, true);
        $settings = [
            'locale' => self::locale(),
            'direction' => self::direction(),
            'messages' => self::isPersian() ? new \stdClass() : self::english(),
        ];
        wp_add_inline_script('pesm-i18n', 'window.PESM_I18N_CONFIG=' . wp_json_encode($settings,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
    }
}
