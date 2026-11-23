<?php

declare(strict_types=1);

namespace PESM\Admin;

use InvalidArgumentException;
use PESM\Domain\Event\EventDetails;
use PESM\Domain\Event\SetupInput;
use PESM\Domain\Event\SetupService;
use PESM\Domain\Venue\JobService;
use PESM\Security\Capabilities;
use PESM\Support\Ui;
use RuntimeException;
use Throwable;

final class EventSetup
{
    private const SLUG = 'pesm-event-setup';
    private const FORM = 'pesm_event_setup';

    public static function register(): void
    {
        add_action('admin_menu', static function (): void {
            $hook = add_submenu_page('pesm-dashboard', SetupText::text('new'), SetupText::text('new'),
                'pesm_access_dashboard', self::SLUG, [self::class, 'render']);
            if ($hook) {
                add_action('admin_enqueue_scripts', static function (string $page) use ($hook): void {
                    if ($page !== $hook) { return; }
                    self::assets();
                });
            }
        });
        add_action('admin_post_' . self::FORM, [self::class, 'submit']);
        add_action('wp_ajax_pesm_setup_layout_preview', [self::class, 'preview']);
    }

    private static function assets(): void
    {
        Ui::enqueue();
        wp_enqueue_style('pesm-event-setup', PESM_URL . 'assets/css/event-setup.css', ['pesm-ui'], Ui::assetVersion('assets/css/event-setup.css'));
        wp_enqueue_script('pesm-event-setup', PESM_URL . 'assets/js/event-setup.js', [], Ui::assetVersion('assets/js/event-setup.js'), true);
        if (current_user_can('upload_files')) { wp_enqueue_media(); }
    }

    public static function url(array $args = []): string
    {
        return add_query_arg(['page'=>self::SLUG] + $args, admin_url('admin.php'));
    }

    public static function backLink(int $center): int
    {
        $id = absint(self::value($_GET, 'pesm_setup'));
        if (!$id) { return 0; }
        try {
            $setup = SetupService::load($id, get_current_user_id());
            if ($center === 0 || (int) $setup->center_id === $center) {
                SetupView::link(SetupText::text('return_setup'), self::url(['setup'=>$id]));
                return $id;
            }
        } catch (Throwable) {
            // This optional navigation link carries no authority.
        }
        return 0;
    }

    public static function render(): void
    {
        self::authorize();
        $actor = get_current_user_id();
        $id = absint(self::value($_GET, 'setup'));
        try {
            $setup = $id ? SetupService::load($id, $actor) : null;
            $event = $setup ? null : self::existingEvent(absint(self::value($_GET, 'event')), $actor);
            if (!$setup && !$event && !current_user_can('pesm_manage_events')) {
                self::redirect(self::dashboard());
            }
            $requested = absint(self::value($_GET, 'stage'));
            $stage = $setup ? (int) $setup->step : 1;
            if ($setup && $requested === 1 && !$setup->data['existing'] && !$setup->data['published']
                && current_user_can('pesm_manage_events')) {
                $stage = 1;
            }
            $flash = self::takeFeedback($id);
            if ($flash) { $stage = (int) $flash['stage']; }
            $notice = sanitize_key(self::value($_GET, 'notice'));
            if (!in_array($notice, ['saved','page_failed','page_ready','revision','finished'], true)) { $notice = ''; }
            SetupView::render($setup, $event, $stage, $notice, $flash['error'] ?? '', $flash['posted'] ?? []);
        } catch (Throwable $error) {
            wp_die(esc_html(SetupText::text(self::error($error))), '', ['response'=>403]);
        }
    }

    public static function submit(): void
    {
        self::authorize();
        check_admin_referer(self::FORM);
        $actor = get_current_user_id();
        $id = absint(self::value($_POST, 'setup'));
        $revision = absint(self::value($_POST, 'revision'));
        $stage = absint(self::value($_POST, 'stage'));
        $action = self::value($_POST, 'intent');
        if ($action === '') { $action = 'next'; }
        $setup = $event = null;
        $destination = '';
        $job = 0;
        $notice = '';
        try {
            if (!$id) {
                if ($stage !== 1) { throw new RuntimeException('invalid_request'); }
                $eventId = absint(self::value($_POST, 'event'));
                $event = self::existingEvent($eventId, $actor);
                $details = $event ? [] : self::details($_POST);
                $center = absint(self::value($_POST, 'center'));
                $newCenter = sanitize_text_field(self::value($_POST, 'new_center'));
                $id = SetupService::start($actor, $eventId, $center, $newCenter, $details, self::value($_POST, 'request_key'));
                self::redirect($action === 'exit' ? self::dashboard() : self::url(['setup'=>$id,'notice'=>'saved']));
            }
            $setup = SetupService::load($id, $actor);
            if ($action === 'retry-page') {
                self::redirect(self::url(['setup'=>$id, 'notice'=>self::publishPage($id, $actor)]));
            }
            if ($setup->finished && $action === 'publish') {
                self::redirect(self::url(['setup'=>$id]));
            }
            if ($stage === 2 && $action === 'preview' && (int) $setup->step === 2 && current_user_can('pesm_manage_sessions')) {
                self::feedback($id, 0, 2, '');
            }
            $setup = SetupService::revise($id, $actor, $revision, static function (object $draft) use (
                $actor, $stage, $action, &$destination, &$job
            ): void {
                if ($stage === 1) {
                    SetupService::confirmDetails($draft, $actor, self::details($_POST));
                    return;
                }
                if ($stage !== (int) $draft->step || !current_user_can('pesm_manage_sessions')) {
                    throw new RuntimeException('access');
                }
                if ($stage === 2) {
                    $destination = self::saveLocation($draft, $actor, $action);
                } elseif ($stage === 3) {
                    self::saveRows($draft, $action);
                } elseif ($stage === 4) {
                    $job = self::review($draft, $actor, $action);
                } else {
                    throw new RuntimeException('invalid_request');
                }
            });
            if ($job > 0) { JobService::schedule($job); }
            if ($job < 0) {
                $job = -$job;
                if (JobService::status($job)['status'] === 'failed') { JobService::retry($job, $actor); }
                JobService::run($job);
            }
            if ($action === 'publish' && $setup->data['make_page'] && !$setup->data['page_id']) {
                $notice = self::publishPage($id, $actor);
            }
            if ($destination !== '') { self::redirect($destination); }
            $url = self::url(['setup'=>$id,'notice'=>$notice ?: 'saved']);
            if (preg_match('/^edit:(\d+)$/D', $action, $focus)) { $url .= '#pesm-session-' . ((int) $focus[1] + 1); }
            self::redirect($action === 'exit' ? self::dashboard() : $url);
        } catch (Throwable $error) {
            $message = self::error($error);
            if ($message === 'access') {
                wp_die(esc_html(SetupText::text('access')), '', ['response'=>403]);
            }
            if ($message === 'revision' || $message === 'finished') {
                self::redirect(self::url(['setup'=>$id, 'notice'=>$message]));
            }
            self::feedback($id, (int) ($event->id ?? 0), $stage ?: 1, $message);
        }
    }

    private static function saveLocation(object $draft, int $actor, string $action): string
    {
        $index = (int) $draft->data['index'];
        if (isset($draft->data['published'][$index])) { throw new RuntimeException('access'); }
        $draft->data['location_input'] = self::locationInput($_POST);
        if ($action === 'exit') { return ''; }
        if ($action === 'back' && $draft->data['rows']) {
            unset($draft->data['location_input']);
            $draft->step = 3;
            return '';
        }
        $input = $draft->data['location_input'];
        $mode = $input['mode'];
        $layout = $mode === 'allocated' ? $input['allocated_layout'] : $input['seated_layout'];
        if ($action === 'design-seated') {
            if ($mode !== 'seated' || !user_can($actor, 'pesm_edit_layouts')) { throw new RuntimeException('access'); }
            $hall = $layout ? SetupService::location((int) $draft->center_id, $mode, 0, $layout, 0)['hall'] : 0;
            return Pages::layoutUrl($hall, ['pesm_setup'=>(int) $draft->id] + ($layout ? ['pesm_layout'=>$layout] : []));
        }
        if ($action === 'design' || $action === 'build-advanced') {
            if ($mode !== 'allocated' || !user_can($actor, 'pesm_edit_layouts')) { throw new RuntimeException('access'); }
            if ($action === 'build-advanced') {
                $draft->data['location_input']['designer_hall'] = SetupService::createHall($draft, $actor, $input['advanced_hall_name'], 0, 0, true);
                $draft->data['location_input']['advanced_hall_name'] = '';
                return self::designerUrl($draft, $draft->data['location_input']['designer_hall']);
            }
            $selected = $layout ? SetupService::location((int) $draft->center_id, 'allocated', 0, $layout, 0) : [];
            $requestedHall = $input['designer_hall'] ?: ($selected['hall'] ?? 0);
            foreach (SetupService::halls((int) $draft->center_id) as $hall) {
                if ((int) $hall->id === $requestedHall) {
                    return self::designerUrl($draft, $requestedHall, ($selected['hall'] ?? 0) === $requestedHall ? $layout : 0);
                }
            }
            throw new InvalidArgumentException('invalid_hall');
        }
        if ($action === 'build') {
            if ($mode !== 'seated') { throw new RuntimeException('invalid_mode'); }
            $hallId = SetupService::createHall($draft, $actor, $input['hall_name'], $input['rows'], $input['columns'], false);
            $hall = array_column(SetupService::halls((int) $draft->center_id), null, 'id')[$hallId];
            $layout = (int) $hall->current_layout_id;
        } elseif ($action !== 'next') {
            throw new RuntimeException('invalid_request');
        }
        $location = SetupService::location((int) $draft->center_id, $mode, $input['general_hall'], $layout, $input['capacity']);
        $row = $draft->data['rows'][$index] ?? SetupInput::blank($location);
        $row['location'] = $location;
        $draft->data['rows'][$index] = $row;
        unset($draft->data['location_input']);
        $draft->step = 3;
        return '';
    }

    private static function designerUrl(object $draft, int $hall, int $layout = 0): string
    {
        $args = ['page'=>'pesm-venue-designer','hall_id'=>$hall,'pesm_setup'=>(int) $draft->id];
        if ($layout) { $args['layout_id'] = $layout; }
        return add_query_arg($args, admin_url('admin.php'));
    }

    public static function locationInput(array $source): array
    {
        $out = ['mode'=>sanitize_key(self::value($source, 'mode')) ?: 'seated'];
        foreach (['seated_layout','allocated_layout','general_hall','designer_hall','capacity','rows','columns'] as $key) {
            $value = SetupInput::number(self::value($source, $key));
            $out[$key] = preg_match('/^\d{1,10}$/D', $value) ? (int) $value : 0;
        }
        foreach (['hall_name','advanced_hall_name'] as $key) { $out[$key] = sanitize_text_field(self::value($source, $key)); }
        return $out;
    }

    public static function mergeRows(array $stored, array $source, array $published): array
    {
        foreach ($stored as $i=>&$row) {
            if (isset($published[$i]) || !isset($source[$i]) || !is_array($source[$i])) { continue; }
            foreach (['start','open','close','kind','amount'] as $field) {
                $row[$field] = substr(sanitize_text_field(self::value($source[$i], $field)), 0, 40);
            }
            foreach (['custom','adjacent','gap'] as $flag) { $row[$flag] = self::value($source[$i], $flag) === '1'; }
        }
        unset($row);
        return $stored;
    }

    private static function saveRows(object $draft, string $action): void
    {
        $stored = $draft->data['rows'];
        $input = isset($_POST['sessions']) && is_array($_POST['sessions']) ? $_POST['sessions'] : [];
        $draft->data['rows'] = self::mergeRows($stored, $input, $draft->data['published']);
        if (preg_match('/^(layout|duplicate|remove):(\d+)$/D', $action, $match)) {
            $index = (int) $match[2];
            if (!isset($stored[$index]) || isset($draft->data['published'][$index])) { throw new RuntimeException('invalid_request'); }
            if ($match[1] === 'layout') {
                $draft->data['index'] = $index;
                $draft->step = 2;
            } elseif ($match[1] === 'duplicate') {
                self::append($draft, $draft->data['rows'][$index]);
            } elseif (count($stored) > 1 && !isset($draft->data['prepared'][$index])) {
                unset($draft->data['rows'][$index]);
            }
        } elseif ($action === 'add') {
            $last = end($draft->data['rows']);
            self::append($draft, SetupInput::blank($last['location']));
        } elseif ($action === 'next') {
            $now = SetupService::now();
            foreach ($draft->data['rows'] as $i=>$row) {
                if (isset($draft->data['published'][$i])) { continue; }
                try {
                    SetupService::validateRow($draft, $row, $now);
                } catch (InvalidArgumentException $error) {
                    throw new InvalidArgumentException($error->getMessage() . ':' . ($i + 1), 0, $error);
                }
            }
            $draft->data['currency'] = SetupService::currency();
            $draft->step = 4;
        } elseif ($action !== 'exit') {
            throw new RuntimeException('invalid_request');
        }
    }

    private static function append(object $draft, array $row): void
    {
        if (count($draft->data['rows']) >= SetupInput::MAX_SESSIONS) { throw new InvalidArgumentException('session_limit'); }
        $row['start'] = $row['open'] = $row['close'] = '';
        $draft->data['rows'][] = $row;
    }

    private static function review(object $draft, int $actor, string $action): int
    {
        if ($action === 'back' || preg_match('/^edit:\d+$/D', $action)) {
            $draft->step = 3;
            return 0;
        }
        if ($action === 'publish') {
            $allowed = array_keys($draft->data['rows']);
            $selected = (array) ($_POST['selected'] ?? []);
            $indices = array_values(array_intersect($allowed, array_map('intval', array_filter($selected,
                static fn($index): bool => is_string($index) && preg_match('/^\d{1,6}$/D', $index)))));
            SetupService::publish($draft, $actor, $indices, SetupBookingPage::allowed() && self::value($_POST, 'make_page') === '1');
            return 0;
        }
        if (preg_match('/^(prepare|run):(\d+)$/D', $action, $matched)) {
            $index = (int) $matched[2];
            if (!isset($draft->data['rows'][$index]) || isset($draft->data['published'][$index])) {
                throw new RuntimeException('invalid_request');
            }
            if ($matched[1] === 'prepare') {
                if (isset($draft->data['prepared'][$index]) && self::value((array) ($_POST['replace'] ?? []), (string) $index) !== '1') {
                    throw new InvalidArgumentException('rebuild_confirm');
                }
                return SetupService::prepare($draft, $actor, $index);
            }
            $status = SetupService::readiness($draft, $index, SetupService::now());
            if (!in_array($status, ['preparing','job_failed'], true)) { throw new RuntimeException('invalid_request'); }
            return -(int) $draft->data['prepared'][$index]['job'];
        }
        if ($action !== 'exit') { throw new RuntimeException('invalid_request'); }
        return 0;
    }

    public static function preview(): void
    {
        self::authorize();
        check_ajax_referer('pesm_setup_preview', 'nonce');
        try {
            $setup = SetupService::load(absint(self::value($_GET, 'setup')), get_current_user_id());
        } catch (RuntimeException) {
            wp_send_json_error(null, 403);
        }
        $id = absint(self::value($_GET, 'layout'));
        $mode = self::value($_GET, 'mode');
        if (!in_array($mode, ['seated','allocated'], true)) { wp_send_json_error(null, 400); }
        try {
            $loc = SetupService::location((int) $setup->center_id, $mode, 0, $id, 0);
        } catch (InvalidArgumentException) {
            wp_send_json_error(null, 404);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $bounds = $wpdb->get_row($wpdb->prepare(
            "SELECT MIN(pos_x) AS min_x,MAX(pos_x) AS max_x,MIN(pos_y) AS min_y,MAX(pos_y) AS max_y FROM {$p}seats WHERE layout_id=%d", $id
        ), ARRAY_A);
        $seats = $wpdb->get_results($wpdb->prepare(
            "SELECT pos_x AS x,pos_y AS y FROM {$p}seats WHERE layout_id=%d ORDER BY id LIMIT 400", $id
        ), ARRAY_A);
        wp_send_json_success(['bounds'=>$bounds, 'seats'=>$seats, 'capacity'=>$loc['capacity']]);
    }

    private static function existingEvent(int $id, int $actor): ?object
    {
        if (!$id) { return null; }
        $event = SetupService::event($id);
        if (!$event || $event->status === 'cancelled' || !current_user_can('pesm_manage_sessions')
            || !Capabilities::centerAllowed((int) $event->center_id, $actor)) {
            throw new RuntimeException('access');
        }
        return $event;
    }

    public static function value(array $source, string $key): string
    {
        return isset($source[$key]) && is_string($source[$key]) ? wp_unslash($source[$key]) : '';
    }

    private static function details(array $source): array
    {
        $values = [];
        foreach (EventDetails::FIELDS as $field) {
            $values[$field] = in_array($field, ['description','admission_terms'], true)
                ? sanitize_textarea_field(self::value($source, $field)) : sanitize_text_field(self::value($source, $field));
        }
        $poster = absint(self::value($source, 'poster_attachment_id'));
        if ($poster && !wp_attachment_is_image($poster)) { throw new InvalidArgumentException('invalid_poster'); }
        return ['title'=>sanitize_text_field(self::value($source, 'title')), 'poster_attachment_id'=>$poster] + $values;
    }

    private static function feedback(int $id, int $event, int $stage, string $error): never
    {
        $token = wp_generate_uuid4();
        $key = self::feedbackKey($token);
        // A short-lived, actor-bound form snapshot lets WordPress render its
        // normal admin shell after a validation error or JavaScript-free preview.
        $fields = ['request_key','intent','title','center','new_center','poster_attachment_id', ...EventDetails::FIELDS,
            'mode','rows','columns','capacity','seated_layout','allocated_layout','general_hall','designer_hall',
            'hall_name','advanced_hall_name','sessions','selected','make_page'];
        $posted = array_intersect_key($_POST, array_fill_keys($fields, true));
        set_transient($key, ['setup'=>$id,'stage'=>$stage,'error'=>$error,'posted'=>$posted], 300);
        $args = $id ? ['setup'=>$id] : ($event ? ['event'=>$event] : []);
        self::redirect(self::url($args + ['feedback'=>$token]));
    }

    private static function takeFeedback(int $id): array
    {
        $token = self::value($_GET, 'feedback');
        if (!wp_is_uuid($token, 4)) { return []; }
        $key = self::feedbackKey($token);
        $flash = get_transient($key);
        delete_transient($key);
        return is_array($flash) && (int) $flash['setup'] === $id ? $flash : [];
    }

    private static function feedbackKey(string $token): string
    {
        return 'pesm_setup_' . get_current_user_id() . '_' . $token;
    }

    private static function authorize(): void
    {
        if (!current_user_can('pesm_access_dashboard') || !SetupService::allowed(get_current_user_id())) {
            wp_die(esc_html(SetupText::text('access')), '', ['response'=>403]);
        }
    }

    private static function publishPage(int $id, int $actor): string
    {
        try {
            SetupBookingPage::publish($id, $actor);
            return 'page_ready';
        } catch (Throwable) {
            return 'page_failed';
        }
    }

    private static function error(Throwable $error): string
    {
        $message = $error instanceof \PESM\Domain\Booking\HoldException ? $error->errorCode : $error->getMessage();
        return preg_match('/^[a-z_]+(?::\d+)?$/D', $message) ? $message : 'write_failed';
    }

    public static function dashboard(): string
    {
        return admin_url('admin.php?page=pesm-dashboard') . '#pesm-events';
    }

    private static function redirect(string $url): never
    {
        wp_safe_redirect($url, 303);
        exit;
    }
}
