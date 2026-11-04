<?php
/**
 * Deliberately retain all PESM data on uninstall.
 * Destructive uninstall requires a future, separately confirmed data-removal workflow.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
// No destructive action here.
