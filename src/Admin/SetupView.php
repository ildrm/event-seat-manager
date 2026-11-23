<?php

declare(strict_types=1);

namespace PESM\Admin;

use PESM\Domain\Event\EventDetails;
use PESM\Domain\Event\SetupInput;
use PESM\Domain\Event\SetupService;
use PESM\Domain\Hall\GridGenerator;
use PESM\Domain\Hall\LayoutDocument;
use PESM\Domain\Venue\Acceptance;
use PESM\Domain\Venue\JobService;
use PESM\Support\I18n;
use Throwable;

final class SetupView
{
    public static function render(?object $setup, ?object $event, int $stage, string $notice, string $error = '', array $posted = []): void
    {
        $actor = get_current_user_id();
        $labels = [1=>'details', 2=>'location', 3=>'timing', 4=>'review'];
        $stage = isset($labels[$stage]) ? $stage : 1;
        $heading = $event || ($setup && $setup->data['existing']) ? 'add_to_event' : 'new';
        $title = $setup->data['details']['title'] ?? $event->title ?? '';
        echo '<main class="wrap pesm-ui pesm-setup" dir="' . esc_attr(I18n::direction()) . '" data-pesm-setup>';
        self::link(SetupText::text('overview'), EventSetup::dashboard(), 'pesm-setup-back');
        echo '<header><h1>' . self::t($heading) . '</h1>';
        if ($title !== '') { echo '<p>' . esc_html($title) . '</p>'; }
        echo '</header><nav aria-label="' . self::t('steps') . '"><ol class="pesm-setup-steps">';
        foreach ($labels as $number=>$label) {
            echo '<li' . ($number === $stage ? ' aria-current="step"' : '') . '><span aria-hidden="true">'
                . esc_html((string) $number) . '</span>' . self::t($label) . '</li>';
        }
        echo '</ol></nav>';
        if ($notice !== '') { echo '<p class="pesm-setup-notice" role="status">' . self::t($notice) . '</p>'; }
        if ($error !== '') {
            [$key, $number] = array_pad(explode(':', $error, 2), 2, '');
            $message = $number !== '' ? sprintf(SetupText::text('session_error'), (int) $number, SetupText::text($key)) : SetupText::text($key);
            echo '<p class="pesm-setup-error" role="alert" tabindex="-1" data-setup-error="' . esc_attr($key)
                . '" data-error-row="' . esc_attr($number) . '">' . esc_html($message) . '</p>';
        }
        echo '<div class="pesm-setup-body"><section class="pesm-setup-panel"><h2>' . self::t($labels[$stage]) . '</h2>';
        if ($setup && !$setup->finished && $stage !== 1 && !current_user_can('pesm_manage_sessions')) {
            echo '<p>' . self::t('no_session_rights') . '</p>';
            self::link(SetupText::text('overview'), EventSetup::dashboard());
        } else {
            self::form($setup, $event, $stage, $posted);
            if ($stage === 1) {
                self::details($setup, $event, $actor, $posted);
            } elseif ($stage === 2 && $setup) {
                self::location($setup, $posted);
            } elseif ($stage === 3 && $setup) {
                self::timing($setup, $posted);
            } elseif ($stage === 4 && $setup) {
                self::review($setup, $posted);
            }
            echo '</form>';
        }
        echo '</section><aside class="pesm-setup-summary" aria-label="' . self::t('review') . '">';
        if ($setup) {
            $centers = array_column(SetupService::centers($actor), 'name', 'id');
            echo '<dl><dt>' . self::t('center') . '</dt><dd>' . esc_html($centers[(int) $setup->center_id] ?? '') . '</dd>';
            $row = $setup->data['rows'][(int) $setup->data['index']] ?? null;
            if ($row) {
                echo '<dt>' . self::t('location') . '</dt><dd>' . esc_html(self::locationLabel($row['location'], (int) $setup->center_id)) . '</dd>';
            }
            echo '</dl>';
            if ($stage !== 1 && !$setup->data['existing'] && !$setup->data['published'] && current_user_can('pesm_manage_events')) {
                self::link(SetupText::text('details'), EventSetup::url(['setup'=>(int) $setup->id,'stage'=>1]));
            }
        }
        echo '</aside></div></main>';
    }

    private static function form(?object $setup, ?object $event, int $stage, array $posted): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-setup-form>';
        foreach (['action'=>'pesm_event_setup','setup'=>(int) ($setup->id ?? 0), 'revision'=>(int) ($setup->revision ?? 0),
            'stage'=>$stage,'event'=>(int) ($event->id ?? 0)] as $name=>$value) {
            self::hidden($name, (string) $value);
        }
        if (!$setup) {
            self::hidden('request_key', EventSetup::value($posted, 'request_key') ?: wp_generate_uuid4());
        }
        wp_nonce_field('pesm_event_setup');
    }

    private static function details(?object $setup, ?object $event, int $actor, array $posted): void
    {
        if ($event) {
            echo '<p>' . esc_html((string) $event->title) . '</p>';
            self::actions();
            return;
        }
        $data = $setup->data['details'] ?? [];
        foreach (['title','poster_attachment_id', ...EventDetails::FIELDS] as $field) {
            if (array_key_exists($field, $posted)) { $data[$field] = EventSetup::value($posted, $field); }
        }
        $centers = SetupService::centers($actor);
        if (!$setup && !$centers && !current_user_can('pesm_manage_centers')) {
            echo '<p>' . self::t('no_centers') . '</p>';
            return;
        }
        self::field('title', 'title', (string) ($data['title'] ?? ''), 'text', ' required maxlength="190" data-field="invalid_title"');
        if (!$setup) {
            if (count($centers) === 1) {
                self::hidden('center', (string) $centers[0]->id);
                echo '<p><strong>' . self::t('center') . '</strong> ' . esc_html((string) $centers[0]->name) . '</p>';
            } elseif ($centers) {
                echo '<label>' . self::t('center') . '<select name="center" required>';
                self::option('', SetupText::text('choose'), EventSetup::value($posted, 'center'), ' disabled');
                foreach ($centers as $center) {
                    self::option((string) $center->id, (string) $center->name, EventSetup::value($posted, 'center') ?: '0');
                }
                echo '</select></label>';
            } else {
                self::field('new_center', 'new_center', EventSetup::value($posted, 'new_center'), 'text', ' required maxlength="190"');
            }
        }
        echo '<details class="pesm-setup-details"><summary>' . self::t('more_details') . '</summary><div class="pesm-setup-fields">';
        foreach (['organizer','venue','contact','description','admission_terms'] as $field) {
            if (in_array($field, ['description','admission_terms'], true)) {
                echo '<label class="pesm-setup-full">' . self::t($field) . '<textarea name="' . esc_attr($field)
                    . '" rows="3" maxlength="3000">' . esc_textarea((string) ($data[$field] ?? '')) . '</textarea></label>';
            } else {
                self::field($field, $field, (string) ($data[$field] ?? ''), 'text', ' maxlength="190"');
            }
        }
        self::poster(absint($data['poster_attachment_id'] ?? 0));
        echo '</div></details>';
        self::actions($setup ? EventSetup::url(['setup'=>(int) $setup->id]) : '');
    }

    public static function poster(int $poster): void
    {
        echo '<div class="pesm-setup-full" data-poster><div data-poster-image>';
        if ($poster) { echo wp_get_attachment_image($poster, 'medium'); }
        echo '</div><div data-poster-manual>';
        self::field('poster_id', 'poster_attachment_id', $poster ? (string) $poster : '', 'number', ' min="1"');
        echo '</div><div class="pesm-setup-inline"><button type="button" class="button" data-media-picker hidden>' . self::t('select_poster')
            . '</button><button type="button" class="button" data-media-remove hidden>' . self::t('remove_poster') . '</button></div></div>';
    }

    private static function location(object $setup, array $posted): void
    {
        $index = (int) $setup->data['index'];
        $current = $setup->data['rows'][$index]['location'] ?? [];
        $input = $posted ? EventSetup::locationInput($posted) : ($setup->data['location_input'] ?? []);
        $input += ['mode'=>$current['mode'] ?? 'seated', 'seated_layout'=>($current['mode'] ?? '') === 'seated' ? $current['layout'] : 0,
            'allocated_layout'=>($current['mode'] ?? '') === 'allocated' ? $current['layout'] : 0,
            'general_hall'=>$current['hall'] ?? 0, 'designer_hall'=>$current['hall'] ?? 0, 'capacity'=>$current['capacity'] ?? 0,
            'hall_name'=>'', 'advanced_hall_name'=>'', 'rows'=>0, 'columns'=>0];
        $halls = SetupService::halls((int) $setup->center_id);
        $layouts = SetupService::layouts((int) $setup->center_id);
        echo '<fieldset class="pesm-setup-mode"><legend>' . self::t('type') . '</legend>';
        foreach (SetupInput::MODES as $mode) {
            echo '<label><input type="radio" name="mode" value="' . esc_attr($mode) . '"' . checked($input['mode'], $mode, false)
                . '><span><strong>' . self::t($mode) . '</strong><small>' . self::t($mode . '_help') . '</small></span></label>';
        }
        echo '</fieldset>';
        foreach (['seated'=>LayoutDocument::FORMAT, 'allocated'=>\PESM\Domain\Venue\Document::FORMAT] as $mode=>$format) {
            $versions = array_values(array_filter($layouts, static fn(object $item): bool => $item->format === $format));
            echo '<div class="pesm-setup-choice" data-mode-panel="' . esc_attr($mode) . '"'
                . (!$versions ? ' data-no-layout' : '') . '>';
            if ($versions) {
                echo '<div class="pesm-setup-fields"><label data-layout-search hidden>' . self::t('find_layout')
                    . '<input type="search" data-search-input></label><label>' . self::t('layout') . '<select name="'
                    . esc_attr($mode . '_layout') . '" data-layout-select data-preview-url="' . esc_url(add_query_arg([
                        'action'=>'pesm_setup_layout_preview','nonce'=>wp_create_nonce('pesm_setup_preview'),
                        'setup'=>(int) $setup->id,'mode'=>$mode,
                    ], admin_url('admin-ajax.php'))) . '">';
                $selected = (string) $input[$mode . '_layout'];
                // One compatible venue is a useful default. Leave a deliberate choice otherwise.
                $single = count(array_unique(array_column($versions, 'hall_id'))) === 1;
                if ($selected === '0' && $single && !$posted) { $selected = (string) $versions[0]->id; }
                self::option('0', SetupText::text('choose'), $selected);
                $preferred = [];
                foreach ($versions as $version) { $preferred[(int) $version->hall_id] ??= (int) $version->id; }
                foreach (['active_layout'=>true,'other_layout'=>false] as $group=>$primary) {
                    $items = array_filter($versions, static fn(object $v): bool => ($preferred[(int) $v->hall_id] === (int) $v->id) === $primary);
                    if (!$items) { continue; }
                    echo '<optgroup label="' . self::t($group) . '">';
                    foreach ($items as $v) {
                        self::option((string) $v->id, sprintf(SetupText::text('layout_option'), $v->name, $v->version_no,
                            number_format_i18n((int) $v->seat_count)), $selected, ' data-hall="' . esc_attr($v->name) . '"');
                    }
                    echo '</optgroup>';
                }
                echo '</select></label></div><figure class="pesm-setup-map" data-existing-map hidden><figcaption>'
                    . self::t('layout_preview') . '</figcaption><canvas width="540" height="160" aria-label="' . self::t('preview_hint')
                    . '"></canvas><p>' . self::t('preview_hint') . '</p></figure>';
            } else {
                echo '<p>' . self::t('no_layout') . '</p>';
            }
            if ($mode === 'seated') { self::seatedTools($input, !$versions, $posted); }
            if ($mode === 'allocated') { self::designTools($input, $halls); }
            echo '</div>';
        }
        echo '<div class="pesm-setup-choice pesm-setup-fields" data-mode-panel="general">';
        self::field('capacity_label', 'capacity', $input['capacity'] ? (string) $input['capacity'] : '', 'number', ' min="1" max="100000"');
        self::hallSelect('general_hall', 'optional_hall', $halls, $input['general_hall'], 'no_hall');
        echo '</div>';
        $canEditDetails = !$setup->data['existing'] && !$setup->data['published'] && current_user_can('pesm_manage_events');
        self::actions(!$setup->data['rows'] && $canEditDetails ? EventSetup::url(['setup'=>(int) $setup->id, 'stage'=>1]) : '',
            (bool) $setup->data['rows']);
    }

    private static function seatedTools(array $input, bool $empty, array $posted): void
    {
        if (current_user_can('pesm_manage_halls') && current_user_can('pesm_edit_layouts')) {
            $open = $empty || $input['hall_name'] !== '' || EventSetup::value($posted, 'intent') === 'preview';
            echo '<details class="pesm-setup-details" data-grid' . ($open ? ' open' : '') . '><summary>' . self::t('new_hall')
                . '</summary><div class="pesm-setup-fields">';
            self::field('hall_name', 'hall_name', $input['hall_name'], 'text', ' maxlength="190"');
            self::field('row_count', 'rows', $input['rows'] ? (string) $input['rows'] : '', 'number', ' min="1" max="100"');
            self::field('column_count', 'columns', $input['columns'] ? (string) $input['columns'] : '', 'number', ' min="1" max="100"');
            echo '</div><div class="pesm-setup-grid-preview pesm-setup-full" data-grid-preview data-seat-count="' . self::t('seat_count') . '">';
            self::grid($input['rows'], $input['columns']);
            echo '</div>';
            self::button('preview', 'show_preview', 'button pesm-setup-preview-submit', true);
            self::button('build', 'build', $empty ? 'button button-primary' : 'button', true);
            echo '</details>';
        }
        if (current_user_can('pesm_edit_layouts') && !$empty) {
            echo '<details class="pesm-setup-details"><summary>' . self::t('manage_layouts') . '</summary>';
            self::button('design-seated', 'legacy_editor', 'button button-link', true);
            echo '</details>';
        }
    }

    private static function grid(int $rows, int $columns): void
    {
        if (min($rows, $columns) < 1 || max($rows, $columns) > 100) { return; }
        $count = sprintf(SetupText::text('seat_count'), number_format_i18n($rows * $columns));
        echo '<p role="status">' . esc_html($count) . '</p><div class="pesm-setup-grid" role="img" aria-label="' . esc_attr($count) . '">';
        for ($r = 0; $r < min($rows, 7); ++$r) {
            $label = GridGenerator::rowLabel($r);
            echo '<div class="pesm-setup-grid-line" aria-hidden="true"><strong>' . esc_html($label) . '</strong>';
            for ($c = 1; $c <= min($columns, 14); ++$c) {
                echo '<span title="' . esc_attr($label . '-' . $c) . '">' . esc_html((string) $c) . '</span>';
            }
            if ($columns > 14) { echo '<span>…</span>'; }
            echo '</div>';
        }
        if ($rows > 7) { echo '<div aria-hidden="true">… ' . esc_html(GridGenerator::rowLabel($rows - 1)) . '</div>'; }
        echo '</div>';
    }

    private static function designTools(array $input, array $halls): void
    {
        if (!Acceptance::creationEnabled()) { echo '<p class="pesm-setup-notice">' . self::t('acceptance') . '</p>'; }
        if (!current_user_can('pesm_edit_layouts')) { return; }
        echo '<details class="pesm-setup-details"' . ($input['designer_hall'] ? ' open' : '') . '><summary>' . self::t('design') . '</summary>';
        echo '<p>' . self::t('design_hint') . ' ' . self::t('new_layout_effect') . '</p>';
        if ($halls) {
            self::hallSelect('designer_hall', 'hall', $halls, $input['designer_hall'], 'choose');
            self::button('design', 'design', 'button', true);
        }
        if (current_user_can('pesm_manage_halls')) {
            self::field('hall_name', 'advanced_hall_name', $input['advanced_hall_name'], 'text', ' maxlength="190"');
            self::button('build-advanced', 'advanced_new', 'button', true);
        }
        echo '</details>';
    }

    private static function timing(object $setup, array $posted): void
    {
        $source = isset($posted['sessions']) && is_array($posted['sessions']) ? $posted['sessions'] : [];
        $rows = EventSetup::mergeRows($setup->data['rows'], $source, $setup->data['published']);
        [$currency] = SetupService::currency();
        [$halls, $versions] = self::lookups((int) $setup->center_id);
        foreach ($rows as $i=>$row) {
            $prefix = 'sessions[' . $i . ']';
            echo '<fieldset id="pesm-session-' . esc_attr((string) ($i + 1)) . '" class="pesm-setup-session" data-session-row="'
                . esc_attr((string) ($i + 1)) . '"><legend>'
                . esc_html(sprintf(SetupText::text('session'), $i + 1)) . '</legend>';
            echo '<p class="pesm-setup-context">' . esc_html(self::locationLabel($row['location'], (int) $setup->center_id, $halls, $versions)) . '</p>';
            if (isset($setup->data['published'][$i])) {
                echo '<p>' . self::t('published') . '</p></fieldset>';
                continue;
            }
            echo '<div class="pesm-setup-fields">';
            self::field('start', $prefix . '[start]', $row['start'], 'datetime-local', ' data-field="invalid_time invalid_window"');
            echo '<label>' . self::t('pricing') . '<select name="' . esc_attr($prefix . '[kind]') . '" data-price-mode data-field="invalid_kind">';
            foreach ([''=>'choose','free'=>'free','paid'=>'paid'] as $value=>$label) {
                self::option($value, SetupText::text($label), $row['kind']);
            }
            echo '</select></label>';
            self::field('amount', $prefix . '[amount]', $row['amount'], 'text', ' inputmode="decimal" maxlength="16" data-field="invalid_price"',
                sprintf(SetupText::text('amount'), $currency ?: '—'), ' data-price-amount');
            echo '</div><details class="pesm-setup-details"' . ($row['custom'] ? ' open' : '') . '><summary>' . self::t('sales_window') . '</summary>';
            echo '<p>' . self::t('default_window') . '</p>';
            self::check($prefix . '[custom]', '1', $row['custom'], SetupText::text('custom_window'), ' data-custom-sales');
            echo '<div class="pesm-setup-fields" data-custom-fields>';
            self::field('sale_open', $prefix . '[open]', $row['open'], 'datetime-local', ' data-field="invalid_window"');
            self::field('sale_close', $prefix . '[close]', $row['close'], 'datetime-local', ' data-field="invalid_window"');
            echo '</div></details>';
            if ($row['location']['mode'] === 'seated') {
                echo '<details class="pesm-setup-details"' . ($row['adjacent'] || $row['gap'] ? ' open' : '') . '><summary>' . self::t('rules') . '</summary>';
                self::check($prefix . '[adjacent]', '1', $row['adjacent'], SetupText::text('adjacent'));
                self::check($prefix . '[gap]', '1', $row['gap'], SetupText::text('gap'));
                echo '</details>';
            }
            echo '<details class="pesm-setup-details"><summary>' . self::t('session_tools') . '</summary><div class="pesm-setup-inline">';
            self::button('layout:' . $i, 'edit_location', 'button', true);
            if (count($rows) < SetupInput::MAX_SESSIONS) { self::button('duplicate:' . $i, 'duplicate', 'button', true); }
            if (count($rows) > 1 && !isset($setup->data['prepared'][$i])) { self::button('remove:' . $i, 'remove', 'button-link-delete', true); }
            echo '</div></details></fieldset>';
        }
        if (count($rows) < SetupInput::MAX_SESSIONS) { self::button('add', 'add_to_event', 'button', true); }
        self::actions();
    }

    private static function review(object $setup, array $posted): void
    {
        $done = $setup->data['published'];
        $page = (int) $setup->data['page_id'];
        if ($done && !$page) { $page = SetupBookingPage::find((int) $setup->event_id); }
        if ($done) {
            echo '<div class="pesm-setup-result"><h3>' . self::t('published') . '</h3>';
            if ($page && get_post_status($page) === 'publish') {
                $url = get_permalink($page);
                if ($url) {
                    self::link(SetupText::text('view_page'), $url, 'button button-primary');
                    self::copy($url, 'copy_link');
                }
                $edit = get_edit_post_link($page, 'raw');
                if ($edit) { self::link(SetupText::text('edit_page'), $edit, 'button'); }
            } else {
                self::copy('[pesm_event id="' . (int) $setup->event_id . '"]', 'copy_shortcode');
                if ($setup->data['make_page'] && SetupBookingPage::allowed()) { self::button('retry-page', 'retry_page', 'button', true); }
            }
            if (!$setup->finished) { echo '<p>' . self::t('open_sessions') . '</p>'; }
            echo '</div>';
        }
        $ready = 0;
        $running = false;
        $now = SetupService::now();
        [$halls, $versions] = self::lookups((int) $setup->center_id);
        $selected = EventSetup::value($posted, 'intent') === 'publish' ? (array) ($posted['selected'] ?? []) : null;
        foreach ($setup->data['rows'] as $i=>$row) {
            $status = SetupService::readiness($setup, $i, $now);
            if (in_array($status, ['ready','unprepared'], true) && !current_user_can('pesm_manage_events')
                && SetupService::event((int) $setup->event_id)?->status !== 'published') {
                $status = 'event_access';
            }
            $ready += (int) ($status === 'ready');
            echo '<section class="pesm-setup-review-row" data-session-row="' . esc_attr((string) ($i + 1)) . '"><h3>' . esc_html(sprintf(SetupText::text('session'), $i + 1)) . '</h3><dl>';
            self::summary('location', self::locationLabel($row['location'], (int) $setup->center_id, $halls, $versions));
            self::summary('start', self::date($row['start']));
            self::summary('pricing', $row['kind'] === 'free' ? SetupText::text('free') : $row['amount'] . ' ' . SetupService::currency()[0]);
            self::summary('sale_open', $row['custom'] ? self::date($row['open']) : SetupText::text('on_publish'));
            try {
                $close = $row['custom'] ? self::date($row['close'])
                    : I18n::formatDate(SetupInput::time($row['start'])->modify('-1 minute')->format('Y-m-d H:i:s'));
            } catch (Throwable) { $close = '—'; }
            self::summary('sale_close', $close);
            echo '</dl><p class="pesm-setup-session-status" data-status="' . esc_attr($status) . '">' . self::t($status) . '</p>';
            if ($status === 'ready') {
                self::check('selected[]', (string) $i, $selected === null || in_array((string) $i, $selected, true),
                    sprintf(SetupText::text('select_for_publish'), $i + 1));
            }
            $prepared = $setup->data['prepared'][$i] ?? [];
            if ($status !== 'published') { self::button('edit:' . $i, 'edit_session', 'button', true); }
            if ($status === 'unprepared') {
                if ($prepared) { self::check('replace[' . $i . ']', '1', false, SetupText::text('rebuild_confirm')); }
                self::button('prepare:' . $i, $prepared ? 'prepare_again' : 'prepare', 'button', true);
            } elseif (in_array($status, ['preparing','job_failed'], true)) {
                $running = $running || $status === 'preparing';
                $job = JobService::status((int) $prepared['job']);
                if ((int) $job['total'] > 0) {
                    echo '<progress aria-label="' . self::t('preparing') . '" value="' . esc_attr((string) $job['processed'])
                        . '" max="' . esc_attr((string) $job['total']) . '"></progress>';
                }
                self::button('run:' . $i, $status === 'job_failed' ? 'retry_job' : 'run_job', 'button', true);
            }
            if ($prepared && $status !== 'published' && $status !== 'unprepared' && current_user_can('pesm_edit_layouts')) {
                self::link(SetupText::text('settings'), add_query_arg(['page'=>'pesm-venue-designer','hall_id'=>$row['location']['hall'],
                    'layout_id'=>$row['location']['layout'], 'session_id'=>$prepared['id'],'pesm_setup'=>(int) $setup->id], admin_url('admin.php')));
            }
            echo '</section>';
        }
        if ($setup->finished) {
            self::link(SetupText::text('overview'), EventSetup::dashboard(), 'button');
            return;
        }
        if ($running) { self::link(SetupText::text('refresh'), EventSetup::url(['setup'=>(int) $setup->id]), 'button'); }
        if (!$setup->data['existing'] && !$done) {
            if (SetupBookingPage::allowed()) {
                self::check('make_page', '1', !$posted || EventSetup::value($posted, 'make_page') === '1', SetupText::text('make_page'));
            } else {
                echo '<p class="pesm-setup-context">' . self::t('page_access') . '</p>';
            }
        }
        echo '<div class="pesm-setup-actions">';
        if ($ready) { self::button('publish', 'publish', 'button button-primary'); }
        self::button('back', 'back', 'button', true);
        self::button('exit', 'save_exit', 'button', true);
        echo '</div>';
    }

    private static function lookups(int $center): array
    {
        return [array_column(SetupService::halls($center), null, 'id'), array_column(SetupService::layouts($center), null, 'id')];
    }

    private static function locationLabel(array $loc, int $center, ?array $halls = null, ?array $versions = null): string
    {
        if ($halls === null || $versions === null) { [$halls, $versions] = self::lookups($center); }
        if ($loc['mode'] === 'general') {
            $name = $halls[$loc['hall']]->name ?? '';
            return SetupText::text('general') . ' · ' . number_format_i18n($loc['capacity']) . ($name ? ' · ' . $name : '');
        }
        $v = $versions[$loc['layout']] ?? null;
        return $v ? sprintf(SetupText::text('layout_option'), $v->name, $v->version_no, number_format_i18n($loc['capacity']))
            : SetupText::text('invalid_layout');
    }

    private static function date(string $input): string
    {
        try { return I18n::formatDate(SetupInput::time($input)->format('Y-m-d H:i:s')); }
        catch (Throwable) { return '—'; }
    }

    private static function summary(string $label, string $value): void
    {
        echo '<div><dt>' . self::t($label) . '</dt><dd>' . esc_html($value) . '</dd></div>';
    }

    private static function copy(string $value, string $label): void
    {
        echo '<label class="pesm-setup-copy">' . self::t($label) . '<input type="text" readonly dir="ltr" value="' . esc_attr($value)
            . '" data-copy-value></label><button type="button" class="button" data-copy data-copied="' . self::t('copied')
            . '" hidden>' . self::t($label) . '</button><span role="status" data-copy-status></span>';
    }

    private static function hallSelect(string $name, string $label, array $halls, int $selected, string $placeholder): void
    {
        echo '<label>' . self::t($label) . '<select name="' . esc_attr($name) . '">';
        self::option('0', SetupText::text($placeholder), (string) $selected);
        foreach ($halls as $hall) { self::option((string) $hall->id, (string) $hall->name, (string) $selected); }
        echo '</select></label>';
    }

    private static function actions(string $back = '', bool $returnToSessions = false): void
    {
        echo '<div class="pesm-setup-actions">';
        self::button('next', 'next', 'button button-primary');
        if ($back !== '') { self::link(SetupText::text('back'), $back, 'button'); }
        if ($returnToSessions) { self::button('back', 'back', 'button', true); }
        self::button('exit', 'save_exit', 'button', true);
        echo '</div>';
    }

    private static function button(string $intent, string $label, string $class, bool $skipBrowserValidation = false): void
    {
        echo '<button class="' . esc_attr($class) . '" type="submit" name="intent" value="' . esc_attr($intent)
            . '"' . ($skipBrowserValidation ? ' formnovalidate' : '') . '>' . self::t($label) . '</button>';
    }

    public static function link(string $label, string $url, string $class = ''): void
    {
        echo '<a' . ($class === '' ? '' : ' class="' . esc_attr($class) . '"') . ' href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    private static function field(string $label, string $name, string $value, string $type, string $attrs = '', string $title = '', string $labelAttrs = ''): void
    {
        echo '<label' . $labelAttrs . '>' . ($title !== '' ? esc_html($title) : self::t($label)) . '<input name="' . esc_attr($name)
            . '" value="' . esc_attr($value) . '" type="' . esc_attr($type) . '"' . $attrs . '></label>';
    }

    private static function check(string $name, string $value, bool $selected, string $label, string $attrs = ''): void
    {
        echo '<label class="pesm-setup-check"><input type="checkbox" name="' . esc_attr($name) . '" value="' . esc_attr($value)
            . '"' . checked($selected, true, false) . $attrs . '><span>' . esc_html($label) . '</span></label>';
    }

    private static function option(string $value, string $label, string $selected, string $attrs = ''): void
    {
        echo '<option value="' . esc_attr($value) . '"' . selected($value, $selected, false) . $attrs . '>' . esc_html($label) . '</option>';
    }

    private static function hidden(string $name, string $value): void
    {
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
    }

    private static function t(string $key): string
    {
        return esc_html(SetupText::text($key));
    }
}
