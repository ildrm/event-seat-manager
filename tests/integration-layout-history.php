<?php

declare(strict_types=1);

use PESM\Domain\Hall\LayoutHistory;

// Run with wp eval-file on a disposable installation. The temporary table is connection-local.
if (!defined('ABSPATH') || wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Requires a local WordPress fixture.');
}

global $wpdb;
$table = $wpdb->prefix . 'pesm_layout_versions';
if (false === $wpdb->query("CREATE TEMPORARY TABLE {$table} (
    id int AUTO_INCREMENT PRIMARY KEY, hall_id int, version_no int, status varchar(20),
    seat_count int, revision int, format varchar(30), UNIQUE KEY hall_version (hall_id, version_no)
)")) {
    throw new RuntimeException('Could not create the temporary history fixture.');
}

try {
    foreach ([11, 22] as $hall) {
        for ($version = 1; $version <= 23; ++$version) {
            if (false === $wpdb->insert($table, ['hall_id' => $hall, 'version_no' => $version])) {
                throw new RuntimeException('Could not seed layout versions.');
            }
        }
    }
    foreach ([[], [11], [22, 11, 33]] as $halls) {
        $before = $wpdb->num_queries;
        $history = LayoutHistory::forHalls($halls);
        if ($wpdb->num_queries - $before !== (int) (bool) $halls || isset($history[33])) {
            throw new RuntimeException('Expected one bounded read for nonempty input and none for empty input.');
        }
        foreach (array_intersect($halls, [11, 22]) as $hall) {
            $numbers = array_map('intval', array_column($history[$hall], 'version_no'));
            if ($numbers !== range(23, 4)) {
                throw new RuntimeException('Expected the latest 20 versions of each hall in descending order.');
            }
        }
    }
    echo "Layout history query count, order and per-hall limits passed.\n";
} finally {
    $wpdb->query("DROP TEMPORARY TABLE {$table}");
}
