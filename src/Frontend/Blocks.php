<?php

declare(strict_types=1);

namespace PESM\Frontend;

/** Dynamic Gutenberg blocks: no client-side trusted HTML and no build pipeline. */
final class Blocks
{
    public static function register(): void
    {
        if (!function_exists('register_block_type')) { return; }
        wp_register_script('pesm-blocks-editor', PESM_URL . 'assets/js/blocks-editor.js',
            ['pesm-i18n', 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor'], PESM_VERSION, true);
        $titles = ['events'=>'فهرست رویدادها', 'event'=>'جزئیات رویداد', 'seat-map'=>'نقشه تعاملی صندلی‌ها',
            'my-tickets'=>'پنل بلیت‌های من', 'gate-scanner'=>'کنترل ورود با QR'];
        $mapping = [
            'events' => 'events', 'event' => 'event', 'seat-map' => 'map',
            'my-tickets' => 'tickets', 'gate-scanner' => 'gate',
        ];
        foreach ($mapping as $slug => $method) {
            register_block_type(PESM_DIR . 'blocks/' . $slug, [
                'title' => \PESM\Support\I18n::text($titles[$slug]),
                'description' => \PESM\Support\I18n::text('نمایش پویا؛ اطلاعات از سمت سرور و با کنترل دسترسی دریافت می‌شود.'),
                'render_callback' => static function (array $attributes) use ($method): string {
                    if ($method === 'event') {
                        return Shortcodes::event(['id'=>(int) ($attributes['eventId'] ?? 0)]);
                    }
                    if ($method === 'map') {
                        return Shortcodes::map(['session'=>(int) ($attributes['sessionId'] ?? 0)]);
                    }
                    return Shortcodes::$method([]);
                },
            ]);
        }
    }
}
