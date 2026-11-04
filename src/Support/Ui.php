<?php

declare(strict_types=1);

namespace PESM\Support;

/** Shared, scoped presentation assets. No data or permission logic belongs here. */
final class Ui
{
    public static function enqueue(): void
    {
        wp_enqueue_style('pesm-ui', PESM_URL . 'assets/css/ui.css', [], self::assetVersion('assets/css/ui.css'));
    }

    /** Refresh revised assets while preserving the public product version. */
    public static function assetVersion(string $relativePath): string
    {
        static $versions = [];
        if (isset($versions[$relativePath])) { return $versions[$relativePath]; }
        $path = PESM_DIR . ltrim($relativePath, '/');
        $hash = is_file($path) ? hash_file('sha256', $path) : false;
        return $versions[$relativePath] = PESM_VERSION . ($hash !== false ? '.' . substr($hash, 0, 12) : '');
    }

    /** Decorative icons must be paired with visible, localized text. */
    public static function icon(string $name): string
    {
        $paths = [
            'calendar' => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 11h18m-13 5h2m4 0h2"/>',
            'seat' => '<path d="M6 12V6a3 3 0 0 1 3-3h6a3 3 0 0 1 3 3v6M4 11a2 2 0 0 1 2 2v2h12v-2a2 2 0 1 1 4 0v5H2v-5a2 2 0 0 1 2-2Zm2 7v3m12-3v3"/>',
            'ticket' => '<path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3a2 2 0 0 0 0-4V7Z"/><path d="M15 5v2m0 4v2m0 4v2"/>',
            'chart' => '<path d="M4 3v17h17M8 15v-4m5 4V7m5 8v-6"/>',
            'settings' => '<path d="M4 7h16M4 17h16"/><circle cx="8" cy="7" r="3"/><circle cx="16" cy="17" r="3"/>',
            'grid' => '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
            'arrow' => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
            'chevron' => '<path d="m9 5 7 7-7 7"/>',
            'check' => '<path d="m5 12 4 4L19 6"/>',
            'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'location' => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
            'users' => '<circle cx="9" cy="7" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3m1-17a3 3 0 0 1 0 6m3 11v-3a6 6 0 0 0-3-5"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
        ];
        return '<svg class="pesm-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . ($paths[$name] ?? $paths['grid']) . '</svg>';
    }
}
