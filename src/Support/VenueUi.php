<?php

declare(strict_types=1);

namespace PESM\Support;

/** One entry point for the editor, booking, cart, ticket and gate clients. */
final class VenueUi
{
    public static function render(string $context, array $config = []): string
    {
        if (!isset($config['sessionId']) && isset($config['session'])) { $config['sessionId'] = (int) $config['session']; unset($config['session']); }
        Ui::enqueue();
        wp_enqueue_style('pesm-venue', PESM_URL . 'assets/build/venue-app.css', ['pesm-ui'], Ui::assetVersion('assets/build/venue-app.css'));
        wp_enqueue_script('pesm-qr', PESM_URL . 'assets/vendor/qrcodegen-bundled.js', [], Ui::assetVersion('assets/vendor/qrcodegen-bundled.js'), true);
        wp_enqueue_script('pesm-venue', PESM_URL . 'assets/build/venue-app.js', ['pesm-i18n', 'pesm-qr'], Ui::assetVersion('assets/build/venue-app.js'), true);
        $config = array_merge([
            'context' => $context, 'rest' => rest_url('pesm/v2/'),
            'nonce' => wp_create_nonce('wp_rest'), 'locale' => I18n::locale(),
            'preview3dUrl' => PESM_URL . 'assets/build/preview3d.mjs?v=' . rawurlencode(Ui::assetVersion('assets/build/preview3d.mjs')),
            'workerUrl' => PESM_URL . 'assets/build/geometry-worker.js',
            'creationEnabled' => \PESM\Domain\Venue\Acceptance::creationEnabled(),
        ], $config);
        return '<section class="pesm-ui pesm-venue-app" dir="' . I18n::direction() . '" data-config="'
            . esc_attr(wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT))
            . '"><noscript>' . esc_html(I18n::isPersian() ? 'برای نقشه تعاملی، جاوااسکریپت را فعال کنید.' : 'Enable JavaScript to use the interactive venue browser.') . '</noscript></section>';
    }
}
