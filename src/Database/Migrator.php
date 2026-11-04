<?php

declare(strict_types=1);

namespace PESM\Database;

use RuntimeException;

final class Migrator
{
    public const VERSION = 19;

    public static function run(): void
    {
        global $wpdb;
        $before = (int) get_option('pesm_schema_version', 0);
        // Activation and the first requests after an update can overlap across
        // PHP workers. Serialize DDL on the database, not a process-local lock.
        $database = (string) $wpdb->get_var('SELECT DATABASE()');
        $lock = 'esm_migrate_' . substr(hash('sha256', $database . '|' . $wpdb->prefix), 0, 48);
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)', $lock));
        if ((string) $acquired !== '1') {
            update_option('pesm_schema_error', 'Database migration lock unavailable; retry the upgrade.', false);
            return;
        }
        try {
            // Bypass this worker's pre-lock option cache. A preceding worker
            // may have completed the upgrade while this connection waited.
            $current = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", 'pesm_schema_version'
            ));
            $error = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", 'pesm_schema_error'
            ));
            wp_cache_delete('pesm_schema_version', 'options');
            wp_cache_delete('pesm_schema_error', 'options');
            if ($before < self::VERSION && $current >= self::VERSION && !$error) {
                return;
            }
            self::migrate();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function migrate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $p = $wpdb->prefix . 'pesm_';
        $charset = $wpdb->get_charset_collate();
        $defs = [
            "CREATE TABLE {$p}centers (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                name varchar(190) NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}center_users (
                center_id bigint(20) unsigned NOT NULL,
                user_id bigint(20) unsigned NOT NULL,
                PRIMARY KEY  (center_id,user_id),
                KEY user_id (user_id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}halls (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                center_id bigint(20) unsigned NOT NULL,
                name varchar(190) NOT NULL,
                current_layout_id bigint(20) unsigned DEFAULT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY center_id (center_id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}layout_versions (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                hall_id bigint(20) unsigned NOT NULL,
                version_no int(11) unsigned NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'published',
                format varchar(32) NOT NULL DEFAULT 'pesm.layout.v1',
                revision int(11) unsigned NOT NULL DEFAULT 0,
                editor_request_hash char(64) DEFAULT NULL,
                editor_payload_hash char(64) DEFAULT NULL,
                seat_count int(11) unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY hall_version (hall_id,version_no),
                UNIQUE KEY editor_request_hash (editor_request_hash)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}seats (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                layout_id bigint(20) unsigned NOT NULL,
                seat_code varchar(60) NOT NULL,
                row_label varchar(20) NOT NULL,
                seat_number int(11) unsigned NOT NULL,
                zone_label varchar(60) NOT NULL,
                pos_x int(11) NOT NULL,
                pos_y int(11) NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY layout_seat (layout_id,seat_code),
                KEY layout_row (layout_id,row_label)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}layout_objects (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                layout_id bigint(20) unsigned NOT NULL,
                object_code varchar(60) NOT NULL,
                object_type varchar(32) NOT NULL,
                pos_x int(11) NOT NULL,
                pos_y int(11) NOT NULL,
                width int(11) unsigned NOT NULL,
                height int(11) unsigned NOT NULL,
                label varchar(120) NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                UNIQUE KEY layout_object (layout_id,object_code),
                KEY layout_type (layout_id,object_type)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}events (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                center_id bigint(20) unsigned NOT NULL,
                title varchar(190) NOT NULL,
                description text NULL,
                organizer varchar(190) NULL,
                venue varchar(190) NULL,
                contact varchar(190) NULL,
                admission_terms text NULL,
                poster_attachment_id bigint(20) unsigned DEFAULT NULL,
                status varchar(24) NOT NULL DEFAULT 'draft',
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY center_id (center_id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}sessions (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_id bigint(20) unsigned NOT NULL,
                hall_id bigint(20) unsigned NOT NULL,
                layout_id bigint(20) unsigned NOT NULL,
                starts_at datetime NOT NULL,
                sale_starts_at datetime NOT NULL,
                sale_ends_at datetime NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'draft',
                unit_price_minor bigint(20) unsigned NOT NULL DEFAULT 0,
                mode varchar(16) NOT NULL DEFAULT 'seated',
                require_adjacent tinyint(1) unsigned NOT NULL DEFAULT 0,
                avoid_single_gap tinyint(1) unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY event_id (event_id),
                KEY layout_id (layout_id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}session_capacity (
                session_id bigint(20) unsigned NOT NULL,
                total_capacity int(11) unsigned NOT NULL,
                blocked_units int(11) unsigned NOT NULL DEFAULT 0,
                held_units int(11) unsigned NOT NULL DEFAULT 0,
                pending_units int(11) unsigned NOT NULL DEFAULT 0,
                sold_units int(11) unsigned NOT NULL DEFAULT 0,
                version bigint(20) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (session_id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}capacity_holds (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                session_id bigint(20) unsigned NOT NULL,
                request_hash char(64) NOT NULL,
                token_hash char(64) NOT NULL,
                quantity int(11) unsigned NOT NULL,
                order_id bigint(20) unsigned NOT NULL DEFAULT 0,
                phone_hash char(64) NOT NULL DEFAULT '',
                status varchar(24) NOT NULL DEFAULT 'active',
                expires_at datetime NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY request_hash (request_hash),
                UNIQUE KEY token_hash (token_hash),
                KEY session_expiry (session_id,status,expires_at)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}capacity_order_links (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                hold_id bigint(20) unsigned NOT NULL,
                order_id bigint(20) unsigned NOT NULL,
                guest_token_hash char(64) NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'pending',
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY hold_id (hold_id),
                UNIQUE KEY order_id (order_id),
                UNIQUE KEY guest_token_hash (guest_token_hash)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}capacity_tickets (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id char(32) NOT NULL,
                ticket_number varchar(40) NOT NULL,
                hold_id bigint(20) unsigned NOT NULL,
                admission_index int(11) unsigned NOT NULL,
                session_id bigint(20) unsigned NOT NULL,
                order_id bigint(20) unsigned NOT NULL,
                attendee_name varchar(190) NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'active',
                qr_version int(11) unsigned NOT NULL DEFAULT 1,
                qr_token_hash char(64) NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                UNIQUE KEY ticket_number (ticket_number),
                UNIQUE KEY qr_token_hash (qr_token_hash),
                UNIQUE KEY hold_index (hold_id,admission_index),
                KEY session_status (session_id,status),
                KEY order_id (order_id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}capacity_checkins (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                ticket_id bigint(20) unsigned NOT NULL,
                operator_id bigint(20) unsigned NOT NULL,
                checked_in_at datetime NOT NULL,
                gate_label varchar(100) NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                UNIQUE KEY ticket_id (ticket_id),
                KEY operator_time (operator_id,checked_in_at)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}session_seats (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                session_id bigint(20) unsigned NOT NULL,
                seat_id bigint(20) unsigned NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'available',
                hold_id bigint(20) unsigned DEFAULT NULL,
                expires_at datetime DEFAULT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY session_seat (session_id,seat_id),
                KEY session_status (session_id,status),
                KEY hold_id (hold_id),
                KEY session_expiry (session_id,expires_at)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}holds (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                session_id bigint(20) unsigned NOT NULL,
                request_hash char(64) NOT NULL,
                token_hash char(64) NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'active',
                order_id bigint(20) unsigned NOT NULL DEFAULT 0,
                phone_hash char(64) NOT NULL DEFAULT '',
                renewal_count int(11) unsigned NOT NULL DEFAULT 0,
                expires_at datetime NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY request_hash (request_hash),
                UNIQUE KEY token_hash (token_hash),
                KEY session_expiry (session_id,expires_at),
                KEY phone_status (phone_hash,status)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}hold_mutations (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                hold_id bigint(20) unsigned NOT NULL,
                request_hash char(64) NOT NULL,
                action varchar(16) NOT NULL,
                selection_hash char(64) NOT NULL,
                expires_at datetime NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY request_hash (request_hash),
                KEY hold_id (hold_id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}hold_items (
                hold_id bigint(20) unsigned NOT NULL,
                seat_id bigint(20) unsigned NOT NULL,
                PRIMARY KEY  (hold_id,seat_id),
                KEY seat_id (seat_id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}phone_locks (
                event_id bigint(20) unsigned NOT NULL,
                phone_hash char(64) NOT NULL,
                PRIMARY KEY  (event_id,phone_hash)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}order_links (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                hold_id bigint(20) unsigned NOT NULL,
                order_id bigint(20) unsigned NOT NULL,
                guest_token_hash char(64) NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'pending',
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY hold_id (hold_id),
                UNIQUE KEY order_id (order_id),
                UNIQUE KEY guest_token_hash (guest_token_hash)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}tickets (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id char(32) NOT NULL,
                ticket_number varchar(40) NOT NULL,
                hold_id bigint(20) unsigned NOT NULL,
                session_id bigint(20) unsigned NOT NULL,
                seat_id bigint(20) unsigned NOT NULL,
                order_id bigint(20) unsigned NOT NULL,
                attendee_name varchar(190) NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'active',
                qr_version int(11) unsigned NOT NULL DEFAULT 1,
                qr_token_hash char(64) NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                UNIQUE KEY ticket_number (ticket_number),
                UNIQUE KEY qr_token_hash (qr_token_hash),
                UNIQUE KEY hold_seat (hold_id,seat_id),
                KEY order_id (order_id),
                KEY session_status (session_id,status)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}ticket_attendee_changes (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                ticket_type varchar(12) NOT NULL,
                ticket_id bigint(20) unsigned NOT NULL,
                request_hash char(64) NOT NULL,
                old_name varchar(190) NOT NULL,
                new_name varchar(190) NOT NULL,
                actor_user_id bigint(20) unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY request_hash (request_hash),
                KEY ticket_history (ticket_type,ticket_id,created_at)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}ticket_seat_transfers (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                ticket_id bigint(20) unsigned NOT NULL,
                request_hash char(64) NOT NULL,
                from_seat_id bigint(20) unsigned NOT NULL,
                to_seat_id bigint(20) unsigned NOT NULL,
                old_qr_version int(11) unsigned NOT NULL,
                new_qr_version int(11) unsigned NOT NULL,
                actor_user_id bigint(20) unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY request_hash (request_hash),
                KEY ticket_time (ticket_id,created_at)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}ticket_owners (
                ticket_type varchar(12) NOT NULL,
                ticket_id bigint(20) unsigned NOT NULL,
                owner_token_hash char(64) NOT NULL,
                email_encrypted text NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (ticket_type,ticket_id),
                UNIQUE KEY owner_token_hash (owner_token_hash)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}ticket_ownership_transfers (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                ticket_type varchar(12) NOT NULL,
                ticket_id bigint(20) unsigned NOT NULL,
                order_id bigint(20) unsigned NOT NULL,
                request_hash char(64) NOT NULL,
                recipient_name varchar(190) NOT NULL,
                recipient_email_hash char(64) NOT NULL,
                recipient_email_encrypted text NOT NULL,
                otp_hash char(64) NOT NULL,
                attempts int(11) unsigned NOT NULL DEFAULT 0,
                status varchar(24) NOT NULL DEFAULT 'pending',
                expires_at datetime NOT NULL,
                created_at datetime NOT NULL,
                accepted_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY request_hash (request_hash),
                KEY pending_ticket (ticket_type,ticket_id,status,expires_at)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}ticket_bundles (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_id bigint(20) unsigned NOT NULL,
                center_id bigint(20) unsigned NOT NULL,
                request_hash char(64) NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'linked',
                actor_user_id bigint(20) unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY request_hash (request_hash),
                KEY center_event (center_id,event_id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}ticket_bundle_items (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                bundle_id bigint(20) unsigned NOT NULL,
                ticket_id bigint(20) unsigned NOT NULL,
                session_id bigint(20) unsigned NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY ticket_id (ticket_id),
                UNIQUE KEY bundle_session (bundle_id,session_id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}checkins (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                ticket_id bigint(20) unsigned NOT NULL,
                operator_id bigint(20) unsigned NOT NULL,
                checked_in_at datetime NOT NULL,
                gate_label varchar(100) NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                UNIQUE KEY ticket_id (ticket_id),
                KEY operator_time (operator_id,checked_in_at)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}guest_recovery_requests (
                order_id bigint(20) unsigned NOT NULL,
                code_hash char(64) NOT NULL,
                attempts int(11) unsigned NOT NULL DEFAULT 0,
                expires_at datetime NOT NULL,
                requested_at datetime NOT NULL,
                PRIMARY KEY  (order_id),
                KEY expires_at (expires_at)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}guest_access_tokens (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                order_id bigint(20) unsigned NOT NULL,
                token_hash char(64) NOT NULL,
                expires_at datetime NOT NULL,
                created_at datetime NOT NULL,
                revoked_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY token_hash (token_hash),
                KEY order_expiry (order_id,expires_at)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}notification_jobs (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_key varchar(64) NOT NULL,
                order_id bigint(20) unsigned NOT NULL,
                channel varchar(12) NOT NULL,
                dedupe_hash char(64) NOT NULL,
                payload mediumtext NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'queued',
                attempts int(11) unsigned NOT NULL DEFAULT 0,
                available_at datetime NOT NULL,
                locked_until datetime DEFAULT NULL,
                last_error_code varchar(60) NOT NULL DEFAULT '',
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY dedupe_hash (dedupe_hash),
                KEY queue_due (status,available_at),
                KEY order_id (order_id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}rate_limits (
                bucket char(64) NOT NULL,
                hits int(11) unsigned NOT NULL DEFAULT 0,
                expires_at datetime NOT NULL,
                PRIMARY KEY  (bucket),
                KEY expires_at (expires_at)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}event_changes (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_id bigint(20) unsigned NOT NULL,
                session_id bigint(20) unsigned NOT NULL DEFAULT 0,
                action varchar(16) NOT NULL,
                request_hash char(64) NOT NULL,
                payload_hash char(64) NOT NULL,
                old_starts_at datetime DEFAULT NULL,
                new_starts_at datetime DEFAULT NULL,
                reason varchar(500) NOT NULL,
                actor_user_id bigint(20) unsigned NOT NULL,
                affected_orders int(11) unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY request_hash (request_hash),
                KEY event_time (event_id,created_at)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}event_change_cases (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                change_id bigint(20) unsigned NOT NULL,
                order_id bigint(20) unsigned NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'review_required',
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY change_order (change_id,order_id),
                KEY order_id (order_id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}refund_cases (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                ticket_id bigint(20) unsigned NOT NULL,
                order_id bigint(20) unsigned NOT NULL,
                order_item_id bigint(20) unsigned NOT NULL,
                request_hash char(64) NOT NULL,
                amount_minor bigint(20) unsigned NOT NULL,
                currency varchar(12) NOT NULL,
                status varchar(32) NOT NULL DEFAULT 'awaiting_woocommerce',
                reason varchar(500) NOT NULL,
                wc_refund_id bigint(20) unsigned NOT NULL DEFAULT 0,
                actor_user_id bigint(20) unsigned NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY ticket_id (ticket_id),
                UNIQUE KEY request_hash (request_hash),
                KEY order_status (order_id,status)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}discount_rules (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_id bigint(20) unsigned NOT NULL,
                session_id bigint(20) unsigned NOT NULL DEFAULT 0,
                code varchar(32) NOT NULL,
                zone_label varchar(60) NOT NULL DEFAULT '',
                kind varchar(12) NOT NULL,
                discount_value bigint(20) unsigned NOT NULL,
                min_order_minor bigint(20) unsigned NOT NULL DEFAULT 0,
                max_uses int(11) unsigned NOT NULL DEFAULT 0,
                reserved_uses int(11) unsigned NOT NULL DEFAULT 0,
                redeemed_uses int(11) unsigned NOT NULL DEFAULT 0,
                starts_at datetime NOT NULL,
                ends_at datetime NOT NULL,
                enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
                PRIMARY KEY  (id),
                UNIQUE KEY event_coupon (event_id,code),
                KEY session_active (session_id,enabled)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}discount_claims (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                rule_id bigint(20) unsigned NOT NULL,
                hold_id bigint(20) unsigned NOT NULL,
                amount_minor bigint(20) unsigned NOT NULL,
                status varchar(16) NOT NULL DEFAULT 'reserved',
                order_id bigint(20) unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY hold_id (hold_id),
                KEY rule_status (rule_id,status),
                KEY order_id (order_id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}audit_logs (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                action varchar(64) NOT NULL,
                entity_type varchar(64) NOT NULL,
                entity_id bigint(20) unsigned NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY entity (entity_type,entity_id)
            ) ENGINE=InnoDB $charset;",
        ];
        $defs[] = "CREATE TABLE {$p}layout_save_receipts (
            request_hash char(64) NOT NULL,
            hall_id bigint(20) unsigned NOT NULL,
            layout_id bigint(20) unsigned NOT NULL,
            base_revision int(11) unsigned NOT NULL,
            revision int(11) unsigned NOT NULL,
            payload_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (request_hash),
            KEY layout_id (layout_id)
        ) ENGINE=InnoDB $charset;";
        $extensions = [VenueSchema::class, AllocationSchema::class];
        foreach ($extensions as $schema) {
            array_push($defs, ...$schema::definitions($p, $charset));
        }
        foreach ($defs as $sql) {
            dbDelta($sql);
        }
        $names = ['centers', 'center_users', 'halls', 'layout_versions', 'seats', 'layout_objects', 'events', 'sessions', 'session_seats', 'session_capacity', 'capacity_holds', 'capacity_order_links', 'capacity_tickets', 'capacity_checkins', 'holds', 'hold_mutations', 'hold_items', 'phone_locks', 'order_links', 'tickets', 'ticket_attendee_changes', 'ticket_seat_transfers', 'ticket_owners', 'ticket_ownership_transfers', 'ticket_bundles', 'ticket_bundle_items', 'checkins', 'guest_recovery_requests', 'guest_access_tokens', 'notification_jobs', 'rate_limits', 'audit_logs', 'event_changes', 'event_change_cases', 'refund_cases', 'discount_rules', 'discount_claims'];
        $names[] = 'layout_save_receipts';
        foreach ($extensions as $schema) { array_push($names, ...$schema::tables()); }
        foreach ($names as $name) {
            $table = $p . $name;
            $actual = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($actual !== $table) {
                update_option('pesm_schema_error', 'Missing table: ' . $name, false);
                return;
            }
            $engine = $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            ));
            if (strtoupper((string) $engine) !== 'INNODB') {
                update_option('pesm_schema_error', 'InnoDB required: ' . $name, false);
                return;
            }
        }
        $required = [
            'session_seats' => 'session_seat',
            'session_capacity' => 'PRIMARY',
            'capacity_holds' => 'request_hash',
            'capacity_order_links' => 'hold_id',
            'capacity_tickets' => 'hold_index',
            'capacity_checkins' => 'ticket_id',
            'holds' => 'request_hash',
            'hold_mutations' => 'request_hash',
            'hold_items' => 'PRIMARY',
            'rate_limits' => 'PRIMARY',
            'notification_jobs' => 'dedupe_hash',
            'phone_locks' => 'PRIMARY',
            'order_links' => 'hold_id',
            'tickets' => 'hold_seat',
            'ticket_seat_transfers' => 'request_hash',
            'ticket_attendee_changes' => 'request_hash',
            'ticket_owners' => 'owner_token_hash',
            'ticket_ownership_transfers' => 'request_hash',
            'ticket_bundles' => 'request_hash',
            'ticket_bundle_items' => 'ticket_id',
            'checkins' => 'ticket_id',
            'guest_recovery_requests' => 'PRIMARY',
            'guest_access_tokens' => 'token_hash',
            'layout_versions' => 'editor_request_hash',
            'layout_objects' => 'layout_object',
            'event_changes' => 'request_hash',
            'event_change_cases' => 'change_order',
            'refund_cases' => 'request_hash',
            'discount_rules' => 'event_coupon',
            'discount_claims' => 'hold_id',
        ];
        $required['layout_save_receipts'] = 'PRIMARY';
        foreach ($extensions as $schema) { $required = array_merge($required, $schema::uniqueIndexes()); }
        foreach ($required as $suffix => $index) {
            $table = $p . $suffix;
            $present = $wpdb->get_var($wpdb->prepare(
                'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s AND NON_UNIQUE = 0 LIMIT 1',
                $table, $index
            ));
            if (!$present) {
                update_option('pesm_schema_error', 'Missing unique index: ' . $suffix . '/' . $index, false);
                return;
            }
        }
        foreach (['revision', 'editor_request_hash', 'editor_payload_hash', 'format'] as $column) {
            $present = $wpdb->get_var($wpdb->prepare(
                'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
                $p . 'layout_versions', $column
            ));
            if (!$present) {
                update_option('pesm_schema_error', 'Missing layout column: ' . $column, false);
                return;
            }
        }
        foreach (['description', 'organizer', 'venue', 'contact', 'admission_terms', 'poster_attachment_id'] as $column) {
            $present = $wpdb->get_var($wpdb->prepare(
                'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
                $p . 'events', $column
            ));
            if (!$present) {
                update_option('pesm_schema_error', 'Missing event column: ' . $column, false);
                return;
            }
        }
        foreach (['sessions' => ['unit_price_minor', 'require_adjacent', 'avoid_single_gap', 'mode'], 'holds' => ['order_id', 'phone_hash', 'renewal_count'], 'capacity_holds' => ['order_id', 'phone_hash']] as $suffix => $columns) {
            foreach ($columns as $column) {
                $present = $wpdb->get_var($wpdb->prepare(
                    'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
                    $p . $suffix, $column
                ));
                if (!$present) {
                    update_option('pesm_schema_error', 'Missing column: ' . $suffix . '/' . $column, false);
                    return;
                }
            }
        }
        delete_option('pesm_schema_error');
        update_option('pesm_schema_version', self::VERSION, false);
    }
}
