<?php

declare(strict_types=1);

namespace PESM\Domain\Event;

use InvalidArgumentException;
use PESM\Domain\Allocation\SessionService;
use PESM\Domain\Booking\CapacityPolicy;
use PESM\Domain\GeneralAdmission\AdmissionPolicy;
use PESM\Domain\Hall\HallService;
use PESM\Domain\Hall\LayoutDocument;
use PESM\Domain\Venue\Acceptance;
use PESM\Domain\Venue\Document;
use PESM\Domain\Venue\JobService;
use PESM\Security\Capabilities;
use RuntimeException;
use Throwable;

final class SetupService
{
    private const EDIT_CAPS = ['pesm_manage_events', 'pesm_manage_sessions'];

    public static function allowed(int $actor, int $center = 0): bool
    {
        return ($center === 0 || Capabilities::centerAllowed($center, $actor))
            && (bool) array_filter(self::EDIT_CAPS, static fn(string $cap): bool => user_can($actor, $cap));
    }

    public static function centers(int $actor): array
    {
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $scope = user_can($actor, 'pesm_manage_centers') ? '1=1'
            : $wpdb->prepare("EXISTS (SELECT 1 FROM {$p}center_users u WHERE u.center_id=c.id AND u.user_id=%d)", $actor);
        return $wpdb->get_results("SELECT c.id,c.name FROM {$p}centers c WHERE {$scope} ORDER BY c.name,c.id") ?: [];
    }

    public static function event(int $id): ?object
    {
        global $wpdb;
        return $id > 0 ? ($wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pesm_events WHERE id=%d", $id)) ?: null) : null;
    }

    public static function halls(int $center): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT id,name,current_layout_id FROM {$wpdb->prefix}pesm_halls WHERE center_id=%d ORDER BY name,id", $center)) ?: [];
    }

    public static function layouts(int $center): array
    {
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT l.id,l.hall_id,l.format,l.version_no,l.seat_count,h.name,h.current_layout_id
             FROM {$p}layout_versions l JOIN {$p}halls h ON h.id=l.hall_id
             WHERE h.center_id=%d AND l.status='published' ORDER BY h.name,h.id,(h.current_layout_id=l.id) DESC,
                 (SELECT a.id FROM {$p}audit_logs a WHERE a.entity_type='layout' AND a.entity_id=l.id
                  AND a.action IN ('layout_published','venue_published') ORDER BY a.id DESC LIMIT 1) DESC,l.version_no DESC", $center
        )) ?: [];
    }

    public static function drafts(int $actor): array
    {
        global $wpdb;
        $centers = array_map(static fn(object $center): int => (int) $center->id, self::centers($actor));
        if (!$centers) { return []; }
        $ids = implode(',', $centers);
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pesm_event_setups WHERE center_id IN ({$ids}) AND (finished=0 OR page_pending=1) ORDER BY updated_at DESC,id DESC LIMIT 30");
        return array_map([self::class, 'decode'], $rows ?: []);
    }

    private static function decode(object $row): object
    {
        $row->data = json_decode($row->payload, true, 32, JSON_THROW_ON_ERROR);
        return $row;
    }

    public static function load(int $id, int $actor, bool $lock = false): object
    {
        global $wpdb;
        $suffix = $lock ? ' FOR UPDATE' : '';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pesm_event_setups WHERE id=%d{$suffix}", $id));
        if (!$row || !self::allowed($actor, (int) $row->center_id)) { throw new RuntimeException('access'); }
        return self::decode($row);
    }

    public static function transaction(callable $operation): mixed
    {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('write_failed'); }
        try {
            $result = $operation();
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('write_failed'); }
            return $result;
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

    private static function requireCap(int $actor, string $cap): void
    {
        if (!user_can($actor, $cap)) { throw new RuntimeException('access'); }
    }

    public static function start(int $actor, int $eventId, int $center, string $newCenter, array $details, string $key): int
    {
        if (!wp_is_uuid($key, 4)) { throw new RuntimeException('invalid_request'); }
        return self::transaction(static function () use ($actor, $eventId, $center, $newCenter, $details, $key): int {
            global $wpdb;
            $table = $wpdb->prefix . 'pesm_event_setups';
            $prior = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE actor_id=%d AND request_key=%s FOR UPDATE", $actor, $key));
            if ($prior) { return (int) self::load((int) $prior, $actor)->id; }
            if ($eventId) {
                self::requireCap($actor, 'pesm_manage_sessions');
                $event = self::event($eventId);
                if (!$event || $event->status === 'cancelled' || !self::allowed($actor, (int) $event->center_id)) {
                    throw new RuntimeException('access');
                }
                $center = (int) $event->center_id;
                $details = ['title'=>(string) $event->title];
            } else {
                self::requireCap($actor, 'pesm_manage_events');
                if (!preg_match('/^.{1,190}$/usD', $details['title'])) { throw new InvalidArgumentException('invalid_title'); }
                if (!$center && $newCenter !== '') {
                    self::requireCap($actor, 'pesm_manage_centers');
                    if (!preg_match('/^.{1,190}$/usD', $newCenter)) { throw new InvalidArgumentException('invalid_center'); }
                    $center = HallService::createCenter($newCenter, true);
                }
                $available = array_map(static fn(object $entry): int => (int) $entry->id, self::centers($actor));
                if (!in_array($center, $available, true)) { throw new InvalidArgumentException('invalid_center'); }
            }
            $data = ['details'=>$details, 'existing'=>(bool) $eventId, 'rows'=>[], 'prepared'=>[], 'index'=>0,
                'published'=>[], 'make_page'=>false, 'page_id'=>0];
            if ($wpdb->insert($table, [
                'center_id'=>$center, 'event_id'=>$eventId, 'actor_id'=>$actor, 'request_key'=>$key,
                'payload'=>self::encode($data), 'updated_at'=>gmdate('Y-m-d H:i:s'),
            ]) !== 1) { throw new RuntimeException('write_failed'); }
            return (int) $wpdb->insert_id;
        });
    }

    public static function currency(): array
    {
        return [function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
            function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 0];
    }

    public static function location(int $center, string $mode, int $hall, int $layout, int $capacity): array
    {
        if (!in_array($mode, SetupInput::MODES, true)) { throw new InvalidArgumentException('invalid_mode'); }
        if ($mode === 'general') {
            if ($hall && !in_array($hall, array_map(static fn(object $row): int => (int) $row->id, self::halls($center)), true)) {
                throw new InvalidArgumentException('invalid_hall');
            }
            if ($capacity < 1 || $capacity > CapacityPolicy::MAX_CAPACITY) { throw new InvalidArgumentException('invalid_capacity'); }
            return ['mode'=>$mode, 'hall'=>$hall, 'layout'=>0, 'capacity'=>$capacity];
        }

        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $format = $mode === 'seated' ? LayoutDocument::FORMAT : Document::FORMAT;
        $version = $wpdb->get_row($wpdb->prepare(
            "SELECT l.hall_id,l.seat_count FROM {$p}layout_versions l JOIN {$p}halls h ON h.id=l.hall_id
             WHERE l.id=%d AND h.center_id=%d AND l.status='published' AND l.format=%s", $layout, $center, $format
        ));
        if (!$version || (int) $version->seat_count < 1) { throw new InvalidArgumentException('invalid_layout'); }
        return ['mode'=>$mode, 'hall'=>(int) $version->hall_id, 'layout'=>$layout, 'capacity'=>(int) $version->seat_count];
    }

    public static function revise(int $id, int $actor, int $revision, callable $operation): object
    {
        return self::transaction(static function () use ($id, $actor, $revision, $operation): object {
            $setup = self::load($id, $actor, true);
            if ((int) $setup->revision !== $revision) { throw new RuntimeException('revision'); }
            if ($setup->finished) { throw new RuntimeException('finished'); }
            $operation($setup);
            self::save($setup);
            return $setup;
        });
    }

    public static function save(object $setup): void
    {
        global $wpdb;
        $updated = $wpdb->update($wpdb->prefix . 'pesm_event_setups', [
            'event_id'=>(int) $setup->event_id, 'step'=>(int) $setup->step, 'finished'=>(int) $setup->finished,
            'page_pending'=>(int) ($setup->data['make_page'] && !$setup->data['page_id']),
            'payload'=>self::encode($setup->data), 'revision'=>(int) $setup->revision + 1,
            'updated_at'=>gmdate('Y-m-d H:i:s'),
        ], ['id'=>(int) $setup->id, 'revision'=>(int) $setup->revision]);
        if ($updated !== 1) { throw new RuntimeException('write_failed'); }
        $setup->revision = (int) $setup->revision + 1;
    }

    private static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function createHall(object $setup, int $actor, string $name, int $rows, int $columns, bool $advanced): int
    {
        self::requireCap($actor, 'pesm_manage_halls');
        self::requireCap($actor, 'pesm_edit_layouts');
        if (!preg_match('/^.{1,190}$/usD', $name)) { throw new InvalidArgumentException('invalid_hall_name'); }
        if (!$advanced && (min($rows, $columns) < 1 || max($rows, $columns) > 100)) {
            throw new InvalidArgumentException('invalid_grid');
        }
        return HallService::createHall((int) $setup->center_id, $name, $rows, $columns, true, $advanced);
    }

    public static function now(): string
    {
        global $wpdb;
        $now = $wpdb->get_var('SELECT UTC_TIMESTAMP()');
        if (!is_string($now) || $now === '') { throw new RuntimeException('write_failed'); }
        return $now;
    }

    public static function validateRow(object $setup, array $row, string $now): array
    {
        $loc = $row['location'];
        $location = self::location((int) $setup->center_id, $loc['mode'], $loc['hall'], $loc['layout'], $loc['capacity']);
        if (!in_array($row['kind'], ['free', 'paid'], true)) { throw new InvalidArgumentException('invalid_kind'); }
        $price = $row['kind'] === 'free' ? 0 : SetupInput::price($row['amount'], (int) self::currency()[1]);
        return [$location, SetupInput::window($row, $now), $price];
    }

    public static function readiness(object $setup, int $index, string $now): string
    {
        if (!empty($setup->data['published'][$index])) { return 'published'; }
        $row = $setup->data['rows'][$index];
        try {
            [$location, , $price] = self::validateRow($setup, $row, $now);
            if ($setup->data['currency'] !== self::currency()) { return 'currency_changed'; }
            if (!function_exists('wc_create_order') || !function_exists('wc_get_order')) { return 'woocommerce'; }
            if ($location['mode'] === 'general') {
                AdmissionPolicy::assertCheckoutAllowed($price, 'general');
            } elseif ($location['mode'] === 'allocated') {
                if (!Acceptance::creationEnabled()) { return 'acceptance'; }
                if ($price > 0 && function_exists('wc_tax_enabled') && wc_tax_enabled()) { return 'paid_tax_unsupported'; }
                $prepared = $setup->data['prepared'][$index] ?? [];
                if (($prepared['fingerprint'] ?? '') !== self::fingerprint($row, $setup->data['currency'])) { return 'unprepared'; }
                if (JobService::status((int) $prepared['job'])['status'] === 'failed') { return 'job_failed'; }
                if (SessionService::settings((int) $prepared['id'])['inventory_status'] !== 'ready') { return 'preparing'; }
                SessionService::assertPublishable((int) $prepared['id']);
            }
            return 'ready';
        } catch (\PESM\Domain\Booking\HoldException $error) {
            return $error->errorCode;
        } catch (InvalidArgumentException $error) {
            return $error->getMessage();
        } catch (Throwable) {
            return 'write_failed';
        }
    }

    private static function fingerprint(array $row, array $currency): string
    {
        return hash('sha256', self::encode([$row, $currency]));
    }

    private static function ensureEvent(object $setup, int $actor): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pesm_events';
        if (!$setup->event_id) {
            self::requireCap($actor, 'pesm_manage_events');
            $details = $setup->data['details'];
            $setup->event_id = HallService::createEvent((int) $setup->center_id, $details['title'], $details, true);
        }
        $event = $wpdb->get_row($wpdb->prepare("SELECT id,center_id,status FROM {$table} WHERE id=%d FOR UPDATE", $setup->event_id));
        if (!$event || (int) $event->center_id !== (int) $setup->center_id || $event->status === 'cancelled') {
            throw new RuntimeException('access');
        }
        return (int) $event->id;
    }

    public static function confirmDetails(object $setup, int $actor, array $details): void
    {
        self::requireCap($actor, 'pesm_manage_events');
        if ($setup->data['existing'] || $setup->data['published']) { throw new RuntimeException('access'); }
        if (!preg_match('/^.{1,190}$/usD', $details['title'])) { throw new InvalidArgumentException('invalid_title'); }
        if ($setup->event_id) {
            EventEditor::update((int) $setup->event_id, $details['title'], $details, $actor, true);
        }
        $setup->data['details'] = $details;
    }

    private static function createSession(int $event, array $loc, array $window, int $price, array $row): int
    {
        return HallService::createSession($event, $loc['hall'], $window[0], $window[1], $window[2], $price,
            $loc['mode'], $loc['mode'] === 'general' ? $loc['capacity'] : 0, $loc['layout'], true,
            ['adjacent'=>$row['adjacent'], 'gap'=>$row['gap']]);
    }

    public static function prepare(object $setup, int $actor, int $index): int
    {
        self::requireCap($actor, 'pesm_manage_sessions');
        $now = self::now();
        $status = self::readiness($setup, $index, $now);
        if (!in_array($status, ['unprepared', 'preparing', 'job_failed', 'ready'], true)) { throw new RuntimeException($status); }
        $row = $setup->data['rows'][$index];
        $prior = $setup->data['prepared'][$index] ?? [];
        $fingerprint = self::fingerprint($row, $setup->data['currency']);
        if (($prior['fingerprint'] ?? '') === $fingerprint) { return (int) $prior['job']; }
        [$loc, $window, $price] = self::validateRow($setup, $row, $now);
        if ($loc['mode'] !== 'allocated') { throw new InvalidArgumentException('invalid_mode'); }
        $event = self::ensureEvent($setup, $actor);
        $settings = [];
        if (($prior['layout'] ?? 0) === $loc['layout']) {
            // These settings refer to codes in the pinned layout. Prices in
            // minor units can only be reused while currency and precision match.
            $previous = SessionService::settings((int) $prior['id']);
            $settings = array_intersect_key($previous, array_flip(['ticket_mode','group_entry_policy','group_modes']));
            if (($prior['currency'] ?? []) === $setup->data['currency']) {
                $settings['pricing'] = $previous['pricing'] ?? [];
            }
        }
        self::retire($setup, $index, $actor);
        $session = self::createSession($event, $loc, $window, $price, $row);
        SessionService::configure($session, $settings, true);
        $job = JobService::create((int) $setup->center_id, $loc['hall'], 'provision', ['session_id'=>$session], $actor, false);
        $setup->data['prepared'][$index] = ['id'=>$session, 'job'=>(int) $job['id'], 'fingerprint'=>$fingerprint,
            'layout'=>$loc['layout'], 'currency'=>$setup->data['currency']];
        return (int) $job['id'];
    }

    private static function retire(object $setup, int $index, int $actor): void
    {
        global $wpdb;
        $prior = $setup->data['prepared'][$index] ?? null;
        if (!$prior) { return; }
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}pesm_sessions WHERE id=%d AND event_id=%d FOR UPDATE", $prior['id'], $setup->event_id
        ));
        if ($status !== 'draft') { throw new RuntimeException('revision'); }
        LifecycleService::change('session', (int) $prior['id'], 'cancelled', $actor, true);
        if ($wpdb->update($wpdb->prefix . 'pesm_venue_jobs', [
            'status'=>'cancelled','lease_token'=>'','lease_until'=>null,
        ], ['id'=>$prior['job'],'session_id'=>$prior['id']]) === false) { throw new RuntimeException('write_failed'); }
        unset($setup->data['prepared'][$index]);
    }

    public static function publish(object $setup, int $actor, array $indices, bool $makePage): void
    {
        self::requireCap($actor, 'pesm_manage_sessions');
        if (!$indices) { throw new InvalidArgumentException('select_sessions'); }
        $now = self::now();
        $eventId = self::ensureEvent($setup, $actor);
        foreach ($indices as $index) {
            $status = self::readiness($setup, $index, $now);
            if ($status !== 'ready') { throw new RuntimeException($status . ':' . ($index + 1)); }
        }
        $event = self::event($eventId);
        if ($event->status !== 'published') {
            self::requireCap($actor, 'pesm_manage_events');
            LifecycleService::change('event', $eventId, 'published', $actor, true);
        }
        global $wpdb;
        foreach ($indices as $index) {
            $row = $setup->data['rows'][$index];
            [$loc, $window, $price] = self::validateRow($setup, $row, $now);
            if ($loc['mode'] === 'allocated') {
                $session = (int) $setup->data['prepared'][$index]['id'];
                if (!$row['custom'] && $wpdb->update($wpdb->prefix . 'pesm_sessions',
                    ['sale_starts_at'=>$window[1]], ['id'=>$session, 'status'=>'draft']) === false) {
                    throw new RuntimeException('write_failed');
                }
            } else {
                self::retire($setup, $index, $actor);
                $session = self::createSession($eventId, $loc, $window, $price, $row);
            }
            LifecycleService::change('session', $session, 'published', $actor, true);
            $setup->data['published'][$index] = $session;
        }
        $setup->data['make_page'] = $setup->data['make_page'] || (!$setup->data['existing'] && $makePage);
        $setup->finished = (int) (count($setup->data['published']) === count($setup->data['rows']));
    }
}
