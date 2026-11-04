<?php
declare(strict_types=1);
namespace PESM\Database;

/** Additive allocation tables; session_seats remains the only seat inventory. */
final class AllocationSchema
{
    public static function tables(): array
    {
        return ['allocation_sessions','allocation_sections','allocation_holds','allocation_lines',
            'allocation_orders','allocation_tickets','allocation_checkins','allocation_requests'];
    }

    public static function uniqueIndexes(): array
    {
        return ['allocation_sessions'=>'PRIMARY','allocation_sections'=>'PRIMARY',
            'allocation_holds'=>'request_hash','allocation_lines'=>'hold_line',
            'allocation_orders'=>'order_id','allocation_tickets'=>'line_seat',
            'allocation_checkins'=>'ticket_id','allocation_requests'=>'request_hash'];
    }

    public static function definitions(string $p, string $charset): array
    {
        return [
            "CREATE TABLE {$p}allocation_sessions (
                session_id bigint(20) unsigned NOT NULL,
                settings_json longtext NOT NULL,
                price_revision bigint(20) unsigned NOT NULL DEFAULT 1,
                status varchar(24) NOT NULL DEFAULT 'provisioning',
                PRIMARY KEY  (session_id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}allocation_sections (
                session_id bigint(20) unsigned NOT NULL,
                section_code varchar(60) NOT NULL,
                revision bigint(20) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (session_id,section_code)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}allocation_holds (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                session_id bigint(20) unsigned NOT NULL,
                request_hash char(64) NOT NULL,
                token_hash char(64) NOT NULL,
                selection_hash char(64) NOT NULL,
                quote_json longtext NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'active',
                renewal_count int(11) unsigned NOT NULL DEFAULT 0,
                order_id bigint(20) unsigned NOT NULL DEFAULT 0,
                phone_hash char(64) NOT NULL DEFAULT '',
                expires_at datetime NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY request_hash (request_hash),
                UNIQUE KEY token_hash (token_hash),
                KEY session_expiry (session_id,status,expires_at),
                KEY due_expiry (status,expires_at),
                KEY phone_status (phone_hash,status)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}allocation_lines (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                hold_id bigint(20) unsigned NOT NULL,
                line_no int(11) unsigned NOT NULL,
                unit_type varchar(16) NOT NULL,
                unit_code varchar(60) NOT NULL,
                section_code varchar(60) NOT NULL,
                seat_ids_json longtext NOT NULL,
                admission_count int(11) unsigned NOT NULL,
                total_minor bigint(20) unsigned NOT NULL,
                ticket_mode varchar(16) NOT NULL DEFAULT 'individual',
                entry_policy varchar(16) NOT NULL DEFAULT 'together',
                public_id char(32) DEFAULT NULL,
                qr_version int(11) unsigned NOT NULL DEFAULT 1,
                status varchar(24) NOT NULL DEFAULT 'held',
                PRIMARY KEY  (id),
                UNIQUE KEY hold_line (hold_id,line_no),
                UNIQUE KEY public_id (public_id),
                KEY hold_status (hold_id,status)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}allocation_orders (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                hold_id bigint(20) unsigned NOT NULL,
                order_id bigint(20) unsigned NOT NULL,
                guest_token_hash char(64) NOT NULL,
                financial_json longtext NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'pending',
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY hold_id (hold_id),
                UNIQUE KEY order_id (order_id),
                UNIQUE KEY guest_token_hash (guest_token_hash)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}allocation_tickets (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                line_id bigint(20) unsigned NOT NULL,
                session_id bigint(20) unsigned NOT NULL,
                order_id bigint(20) unsigned NOT NULL,
                seat_id bigint(20) unsigned NOT NULL,
                public_id char(32) NOT NULL,
                attendee_name varchar(190) NOT NULL,
                amount_minor bigint(20) unsigned NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'active',
                qr_version int(11) unsigned NOT NULL DEFAULT 1,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY line_seat (line_id,seat_id),
                UNIQUE KEY public_id (public_id),
                KEY session_status (session_id,status),
                KEY order_id (order_id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}allocation_checkins (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                ticket_id bigint(20) unsigned NOT NULL,
                operator_id bigint(20) unsigned NOT NULL,
                gate_label varchar(100) NOT NULL DEFAULT '',
                checked_in_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY ticket_id (ticket_id),
                KEY operator_time (operator_id,checked_in_at)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}allocation_requests (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                request_hash char(64) NOT NULL,
                payload_hash char(64) NOT NULL,
                result_json longtext NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY request_hash (request_hash),
                KEY created_at (created_at)
            ) ENGINE=InnoDB $charset;",
        ];
    }
}
