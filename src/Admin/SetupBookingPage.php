<?php

declare(strict_types=1);

namespace PESM\Admin;

use PESM\Domain\Event\SetupService;
use RuntimeException;

final class SetupBookingPage
{
    public static function allowed(): bool
    {
        return current_user_can('edit_pages') && current_user_can('publish_pages');
    }

    private static function marker(int $eventId): string
    {
        return 'pesm_booking_event_' . $eventId;
    }

    public static function find(int $eventId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_content_filtered=%s ORDER BY ID LIMIT 1",
            self::marker($eventId)
        ));
    }

    public static function publish(int $setupId, int $actor): int
    {
        global $wpdb;
        $setup = SetupService::load($setupId, $actor);
        if (!$setup->data['make_page'] || !$setup->data['published'] || !self::allowed()) {
            throw new RuntimeException('access');
        }
        $eventId = (int) $setup->event_id;
        $event = SetupService::event($eventId);
        if (!$event || $event->status !== 'published' || (int) $event->center_id !== (int) $setup->center_id) {
            throw new RuntimeException('access');
        }
        $lock = 'pesm_page_' . substr(hash('sha256', $wpdb->posts . '|' . get_current_blog_id() . '|' . $eventId), 0, 48);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $lock)) !== '1') {
            throw new RuntimeException('page_failed');
        }
        try {
            // The marker is stored in the post row, so a retry can find it even
            // if WordPress stopped before returning the post ID to this caller.
            $page = self::find($eventId);
            if ($page) {
                if (get_post_status($page) !== 'publish') { throw new RuntimeException('page_failed'); }
            } else {
                if ((int) $setup->data['page_id'] > 0) { throw new RuntimeException('page_failed'); }
                $page = wp_insert_post([
                    'post_title'=>wp_slash((string) $event->title), 'post_type'=>'page', 'post_status'=>'publish',
                    'post_content'=>'[pesm_event id="' . $eventId . '"]', 'post_content_filtered'=>self::marker($eventId),
                    'post_author'=>$actor,
                ], true);
                if (is_wp_error($page) || !$page || get_post_status($page) !== 'publish') { throw new RuntimeException('page_failed'); }
            }
            return SetupService::transaction(static function () use ($setupId, $actor, $page): int {
                $setup = SetupService::load($setupId, $actor, true);
                if ((int) $setup->data['page_id'] !== $page) {
                    $setup->data['page_id'] = $page;
                    SetupService::save($setup);
                }
                return $page;
            });
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
}
