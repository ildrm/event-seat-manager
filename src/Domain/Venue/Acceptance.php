<?php

declare(strict_types=1);

namespace PESM\Domain\Venue;

use RuntimeException;

/** Creation is gated; access to already issued reservations never depends on this flag. */
final class Acceptance
{
    public static function creationEnabled(): bool
    {
        if (defined('WP_CLI') && WP_CLI && defined('PESM_VENUE_ACCEPTANCE_TEST') && PESM_VENUE_ACCEPTANCE_TEST) { return true; }
        $report = get_option('pesm_venue_acceptance', []);
        return is_array($report) && self::passes($report);
    }

    public static function passes(array $r): bool
    {
        return ($r['passed'] ?? false) === true && ($r['schema'] ?? 0) === 19
            && ($r['capacity'] ?? 0) >= 150000 && ($r['concurrent_purchase_attempts'] ?? 0) >= 1000
            && ($r['gate_operators'] ?? 0) >= 20 && ($r['oversells'] ?? -1) === 0
            && ($r['duplicate_issuance'] ?? -1) === 0 && ($r['integration_passed'] ?? false) === true
            && isset($r['hold_p95_ms'], $r['availability_p95_ms'], $r['gate_p95_ms'], $r['environment'])
            && is_array($r['environment']) && $r['environment'] !== []
            && is_numeric($r['hold_p95_ms']) && $r['hold_p95_ms'] >= 0 && $r['hold_p95_ms'] <= 2000
            && is_numeric($r['availability_p95_ms']) && $r['availability_p95_ms'] >= 0 && $r['availability_p95_ms'] <= 500
            && is_numeric($r['gate_p95_ms']) && $r['gate_p95_ms'] >= 0 && $r['gate_p95_ms'] <= 1000;
    }

    public static function record(array $report): void
    {
        if (!self::passes($report)) { throw new RuntimeException('Venue acceptance requirements have not passed.'); }
        $report['recorded_at'] = gmdate('c');
        update_option('pesm_venue_acceptance', $report, false);
    }

    public static function register(): void
    {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('pesm venue-accept', static function (array $args, array $options): void {
                $path = $options['report'] ?? '';
                if (!is_string($path) || !is_readable($path) || filesize($path) > 1048576) { \WP_CLI::error('Provide --report with a readable acceptance JSON report.'); }
                try {
                    self::record(json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR));
                    \WP_CLI::success('Acceptance recorded. New allocated sessions may now be created.');
                } catch (\Throwable $e) { \WP_CLI::error($e->getMessage()); }
            });
        }
    }
}
