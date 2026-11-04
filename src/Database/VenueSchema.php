<?php

declare(strict_types=1);

namespace PESM\Database;

/** Additive v2 tables. Existing seat IDs and session inventory remain authoritative. */
final class VenueSchema
{
    public static function definitions(string $p, string $charset): array
    {
        return [
            "CREATE TABLE {$p}venue_layouts (
                layout_id bigint(20) unsigned NOT NULL,
                preset_id varchar(60) NOT NULL,
                preset_version int(11) unsigned NOT NULL DEFAULT 1,
                parameters longtext NOT NULL,
                manifest longtext NOT NULL,
                content_hash char(64) NOT NULL DEFAULT '',
                status varchar(24) NOT NULL DEFAULT 'building',
                revision bigint(20) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (layout_id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}venue_sections (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                layout_id bigint(20) unsigned NOT NULL,
                section_code varchar(60) NOT NULL,
                label varchar(120) NOT NULL,
                level_number int(11) unsigned NOT NULL DEFAULT 0,
                elevation int(11) NOT NULL DEFAULT 0,
                bounds_json longtext NOT NULL,
                geometry_json longtext NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY layout_section (layout_id,section_code)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}venue_places (
                seat_id bigint(20) unsigned NOT NULL,
                layout_id bigint(20) unsigned NOT NULL,
                section_code varchar(60) NOT NULL,
                block_code varchar(60) NOT NULL,
                block_position int(11) unsigned NOT NULL,
                closed_loop tinyint(1) unsigned NOT NULL DEFAULT 0,
                group_code varchar(60) NOT NULL DEFAULT '',
                category_code varchar(60) NOT NULL DEFAULT 'STANDARD',
                rotation int(11) unsigned NOT NULL DEFAULT 0,
                elevation int(11) NOT NULL DEFAULT 0,
                accessible tinyint(1) unsigned NOT NULL DEFAULT 0,
                tile_code varchar(80) NOT NULL,
                PRIMARY KEY  (seat_id),
                KEY layout_section (layout_id,section_code,seat_id),
                KEY layout_tile (layout_id,tile_code,seat_id),
                UNIQUE KEY layout_block (layout_id,block_code,block_position),
                KEY layout_group (layout_id,group_code,seat_id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}venue_groups (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                layout_id bigint(20) unsigned NOT NULL,
                group_code varchar(60) NOT NULL,
                section_code varchar(60) NOT NULL,
                group_type varchar(12) NOT NULL,
                category_code varchar(60) NOT NULL DEFAULT 'STANDARD',
                capacity int(11) unsigned NOT NULL,
                sale_mode varchar(16) NOT NULL DEFAULT 'both',
                geometry_json longtext NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY layout_group (layout_id,group_code),
                KEY layout_section (layout_id,section_code,id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}venue_tiles (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                layout_id bigint(20) unsigned NOT NULL,
                section_code varchar(60) NOT NULL,
                tile_code varchar(80) NOT NULL,
                payload longtext NOT NULL,
                content_hash char(64) NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY layout_tile (layout_id,tile_code),
                KEY layout_section (layout_id,section_code,id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}venue_objects (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                layout_id bigint(20) unsigned NOT NULL,
                object_code varchar(60) NOT NULL,
                section_code varchar(60) NOT NULL DEFAULT '',
                payload longtext NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY layout_object (layout_id,object_code),
                KEY layout_section (layout_id,section_code,id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}venue_commands (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                layout_id bigint(20) unsigned NOT NULL,
                request_hash char(64) NOT NULL,
                payload_hash char(64) NOT NULL,
                result_json longtext NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY layout_request (layout_id,request_hash)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}venue_jobs (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                center_id bigint(20) unsigned NOT NULL,
                hall_id bigint(20) unsigned NOT NULL,
                layout_id bigint(20) unsigned NOT NULL DEFAULT 0,
                session_id bigint(20) unsigned NOT NULL DEFAULT 0,
                actor_id bigint(20) unsigned NOT NULL,
                kind varchar(16) NOT NULL,
                status varchar(24) NOT NULL DEFAULT 'pending',
                payload longtext NOT NULL,
                cursor_json longtext NOT NULL,
                result_json longtext NOT NULL,
                lease_token char(32) NOT NULL DEFAULT '',
                lease_until datetime DEFAULT NULL,
                attempts int(11) unsigned NOT NULL DEFAULT 0,
                error_message varchar(500) NOT NULL DEFAULT '',
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY pending_lease (status,lease_until),
                KEY center_jobs (center_id,id)
            ) ENGINE=InnoDB $charset;",
            "CREATE TABLE {$p}venue_import_chunks (
                token_hash char(64) NOT NULL,
                actor_id bigint(20) unsigned NOT NULL,
                part_no int(11) unsigned NOT NULL,
                data longblob NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (token_hash,part_no),
                KEY actor_upload (actor_id,created_at)
            ) ENGINE=InnoDB $charset;",
        ];
    }

    public static function tables(): array
    {
        return ['venue_layouts','venue_sections','venue_places','venue_groups','venue_tiles','venue_objects','venue_commands','venue_jobs','venue_import_chunks'];
    }

    public static function uniqueIndexes(): array
    {
        return ['venue_layouts'=>'PRIMARY','venue_sections'=>'layout_section','venue_places'=>'PRIMARY',
            'venue_groups'=>'layout_group','venue_tiles'=>'layout_tile','venue_objects'=>'layout_object',
            'venue_commands'=>'layout_request','venue_jobs'=>'PRIMARY','venue_import_chunks'=>'PRIMARY'];
    }
}
