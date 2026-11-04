<?php

declare(strict_types=1);

namespace PESM\Frontend;

use PESM\Support\I18n;
use PESM\Support\Ui;
use PESM\Domain\Reporting\AttendanceReport;
use PESM\Domain\Booking\HoldException;

final class Shortcodes
{
    public static function register(): void
    {
        add_shortcode('pesm_events', [self::class, 'events']);
        add_shortcode('pesm_event', [self::class, 'event']);
        add_shortcode('pesm_seat_map', [self::class, 'map']);
        add_shortcode('pesm_capacity', [self::class, 'capacity']);
        add_shortcode('pesm_my_tickets', [self::class, 'tickets']);
        add_shortcode('pesm_invoices', [self::class, 'tickets']);
        add_shortcode('pesm_gate_scanner', [self::class, 'gate']);
        add_shortcode('pesm_manager_dashboard', [self::class, 'manager']);
        add_shortcode('pesm_customer_panel', [self::class, 'tickets']);
        add_shortcode('pesm_transfer_ticket', [self::class, 'ownershipTransfer']);
    }

    public static function events(array|string $attributes = []): string
    {
        self::enqueuePresentation();
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $events = $wpdb->get_results("SELECT e.id,e.title,e.organizer,e.venue,e.poster_attachment_id,
            (SELECT MIN(s.starts_at) FROM {$p}sessions s WHERE s.event_id=e.id AND s.status='published' AND s.starts_at>UTC_TIMESTAMP()) AS next_session_at
            FROM {$p}events e WHERE e.status='published' ORDER BY e.id DESC LIMIT 30");
        $html = '<section class="pesm-ui pesm-event-list" dir="' . I18n::direction() . '">'
            . self::heading('رویدادها', 'رویداد بعدی خود را پیدا کنید و جایگاهتان را رزرو کنید.', 'calendar', 'h2');
        if (!$events) {
            return $html . self::emptyState('calendar', 'رویدادی برای نمایش وجود ندارد', 'رویدادهای جدید پس از انتشار در این بخش نمایش داده می‌شوند.') . '</section>';
        }
        $html .= '<div class="pesm-event-grid">';
        foreach ($events as $event) {
            $html .= '<article class="pesm-event-card">';
            if ((int) ($event->poster_attachment_id ?? 0) > 0) {
                $html .= '<div class="pesm-event-poster">' . wp_get_attachment_image((int) $event->poster_attachment_id, 'medium_large', false, ['loading'=>'lazy']) . '</div>';
            } else {
                $html .= '<div class="pesm-event-card-mark" aria-hidden="true">' . Ui::icon('calendar') . '</div>';
            }
            $html .= '<div class="pesm-event-card-content"><p class="pesm-eyebrow">' . self::t(!empty($event->next_session_at) ? 'رویداد پیش رو' : 'رویداد') . '</p><h3>' . esc_html($event->title) . '</h3><ul class="pesm-event-meta">';
            if (!empty($event->next_session_at)) {
                $html .= '<li>' . Ui::icon('clock') . '<span>' . esc_html(I18n::formatDate((string) $event->next_session_at)) . '</span></li>';
            }
            foreach (['venue'=>'location', 'organizer'=>'users'] as $field=>$icon) {
                if (!empty($event->$field)) { $html .= '<li>' . Ui::icon($icon) . '<span>' . esc_html((string) $event->$field) . '</span></li>'; }
            }
            $html .= '</ul><a class="pesm-button pesm-button-secondary pesm-event-link" href="'
                . esc_url(add_query_arg('pesm_event', (int) $event->id, get_permalink())) . '">'
                . self::t('مشاهده رویداد') . Ui::icon('arrow') . '</a></div></article>';
        }
        $html .= '</div></section>';
        $requested = isset($_GET['pesm_event']) ? absint(wp_unslash($_GET['pesm_event'])) : 0;
        return $html . ($requested > 0 ? self::event(['id' => $requested]) : '');
    }

    public static function event(array|string $attributes = []): string
    {
        self::enqueuePresentation();
        global $wpdb;
        $atts = shortcode_atts(['id' => 0], is_array($attributes) ? $attributes : [], 'pesm_event');
        $id = absint($atts['id']);
        if (!$id) { return '<div class="pesm-ui" dir="' . I18n::direction() . '">' . self::emptyState('calendar', 'شناسه رویداد نامعتبر است.', 'لطفاً از فهرست رویدادها یک رویداد را انتخاب کنید.') . '</div>'; }
        $p = $wpdb->prefix . 'pesm_';
        $event = $wpdb->get_row($wpdb->prepare("SELECT id,title,description,organizer,venue,contact,admission_terms,poster_attachment_id FROM {$p}events WHERE id=%d AND status='published'", $id));
        if (!$event) { return '<div class="pesm-ui" dir="' . I18n::direction() . '">' . self::emptyState('calendar', 'رویداد منتشرشده پیدا نشد.', 'لطفاً از فهرست رویدادها یک رویداد را انتخاب کنید.') . '</div>'; }
        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT id,mode,starts_at,sale_starts_at,sale_ends_at FROM {$p}sessions WHERE event_id=%d AND status='published' ORDER BY starts_at ASC LIMIT 50", $id
        ));
        $html = '<section class="pesm-ui pesm-event-detail" dir="' . I18n::direction() . '"><header class="pesm-event-hero"><div class="pesm-event-hero-content">'
            . '<p class="pesm-eyebrow">' . self::t('تجربه بعدی شما') . '</p><h2>' . esc_html((string) $event->title) . '</h2><ul class="pesm-event-meta">';
        foreach (['organizer'=>['برگزارکننده','users'], 'venue'=>['محل برگزاری','location'], 'contact'=>['اطلاعات تماس','users']] as $field=>$info) {
            if (!empty($event->$field)) { $html .= '<li>' . Ui::icon($info[1]) . '<span><span class="pesm-meta-label">' . self::t($info[0]) . '</span>' . esc_html((string) $event->$field) . '</span></li>'; }
        }
        $html .= '</ul>';
        if ($sessions) { $html .= '<a class="pesm-button pesm-button-primary" href="#pesm-sessions-' . esc_attr((string) $id) . '">' . self::t('انتخاب جلسه و رزرو بلیت') . Ui::icon('arrow') . '</a>'; }
        $html .= '</div>';
        if ((int) $event->poster_attachment_id > 0) { $html .= '<div class="pesm-event-hero-poster">' . wp_get_attachment_image((int) $event->poster_attachment_id, 'large', false, ['loading'=>'lazy']) . '</div>'; }
        $html .= '</header>';
        if ($event->description) { $html .= '<div class="pesm-event-description"><h3>' . self::t('درباره رویداد') . '</h3><p>' . nl2br(esc_html((string) $event->description)) . '</p></div>'; }
        if ($event->admission_terms) { $html .= '<details class="pesm-event-terms"><summary>' . self::t('شرایط حضور') . '</summary><p>' . nl2br(esc_html((string) $event->admission_terms)) . '</p></details>'; }
        $html .= '<div class="pesm-session-list" id="pesm-sessions-' . esc_attr((string) $id) . '">'
            . self::heading('جلسه مناسب خود را انتخاب کنید', 'برای مشاهده ظرفیت و انتخاب جایگاه، جلسه را باز کنید.', 'clock');
        if (!$sessions) { return $html . self::emptyState('clock', 'هنوز جلسه‌ای برای مشاهده وجود ندارد.', 'زمان‌بندی جلسه‌ها پس از انتشار در این بخش نمایش داده می‌شود.') . '</div></section>'; }
        foreach ($sessions as $session) {
            $calendarUrl = add_query_arg(['action' => 'pesm_session_calendar', 'session_id' => (int) $session->id], admin_url('admin-post.php'));
            $html .= '<details class="pesm-session"><summary><span class="pesm-session-icon">' . Ui::icon('calendar') . '</span><span class="pesm-session-label">'
                . '<span class="pesm-meta-label">' . self::t('جلسه') . ' ' . esc_html((string) $session->id) . '</span><strong>'
                . esc_html(I18n::formatDate((string) $session->starts_at)) . '</strong></span><span class="pesm-badge">'
                . self::t((string) $session->mode === 'general' ? 'ظرفیت آزاد' : 'صندلی شماره‌دار') . '</span>' . Ui::icon('chevron') . '</summary>'
                . '<div class="pesm-session-content"><a class="pesm-calendar-link" href="' . esc_url($calendarUrl) . '">' . Ui::icon('calendar') . self::t('افزودن به تقویم') . '</a>'
                . ((string) $session->mode === 'general' ? self::capacity(['session' => (int) $session->id]) : self::map(['session' => (int) $session->id])) . '</div></details>';
        }
        return $html . '</div></section>';
    }

    /** General-admission reservation with the same checkout boundaries as numbered seating. */
    public static function capacity(array|string $attributes = []): string
    {
        $atts = shortcode_atts(['session' => 0], is_array($attributes) ? $attributes : [], 'pesm_capacity');
        $sessionId = absint($atts['session']);
        if ($sessionId < 1) { return '<p dir="' . I18n::direction() . '">' . self::t('شناسه جلسه معتبر نیست.') . '</p>'; }
        self::enqueueTickets();
        wp_enqueue_script('pesm-attendees', PESM_URL . 'assets/js/attendees.js', ['pesm-i18n'], Ui::assetVersion('assets/js/attendees.js'), true);
        wp_enqueue_script('pesm-capacity', PESM_URL . 'assets/js/capacity.js', ['pesm-i18n', 'pesm-tickets', 'pesm-attendees'], Ui::assetVersion('assets/js/capacity.js'), true);
        wp_add_inline_script('pesm-capacity', 'window.PESM_CAPACITY_ROOT = ' . wp_json_encode(esc_url_raw(rest_url('pesm/v1/'))) . ';', 'before');
        return '<section class="pesm-ui pesm-capacity" dir="' . I18n::direction() . '" data-session="' . esc_attr((string) $sessionId) . '">'
            . self::heading('رزرو بلیت ظرفیت آزاد', 'در این جلسه صندلی مشخصی تخصیص داده نمی‌شود؛ تعداد بلیت خود را انتخاب کنید.', 'ticket')
            . '<div class="pesm-capacity-reservation"><div class="pesm-capacity-overview"><p class="pesm-capacity-counts" aria-live="polite">' . self::t('در حال دریافت ظرفیت...') . '</p>'
            . '<p class="pesm-help-text">' . self::t('رزرو موقت تا پایان مهلت برای شما نگه داشته می‌شود. صدور بلیت پس از ثبت سفارش انجام می‌شود.') . '</p></div>'
            . '<div class="pesm-capacity-actions"><label>' . self::t('تعداد بلیت') . '<input type="number" class="pesm-capacity-quantity" min="1" max="20" value="1"></label>'
            . '<button type="button" class="pesm-capacity-hold pesm-button-primary">' . self::t('رزرو موقت بلیت') . '</button>'
            . '<button type="button" class="pesm-capacity-release pesm-button-quiet" disabled>' . self::t('آزادسازی ظرفیت') . '</button>'
            . '<button type="button" class="pesm-capacity-refresh pesm-button-quiet">' . Ui::icon('clock') . self::t('تازه‌سازی') . '</button></div></div>'
            . '<p class="pesm-capacity-result pesm-status" role="status" aria-live="polite"></p>'
            . '<form class="pesm-capacity-checkout pesm-checkout-form" hidden autocomplete="on">' . self::heading('مشخصات خریدار', 'بدون نیاز به ساخت حساب کاربری، اطلاعات دریافت بلیت را تکمیل کنید.', 'users', 'h4')
            . self::buyerFields(false) . '</form><section class="pesm-capacity-receipt pesm-receipt" aria-live="polite"></section></section>';
    }

    /** Numbered seating keeps the inventory and order flow authoritative on the server. */
    public static function map(array|string $attributes = []): string
    {
        $atts = shortcode_atts(['session' => 0], is_array($attributes) ? $attributes : [], 'pesm_seat_map');
        $sessionId = absint($atts['session']);
        if ($sessionId < 1) { return '<p dir="' . I18n::direction() . '">' . self::t('شناسه جلسه معتبر نیست.') . '</p>'; }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $mode = $wpdb->get_var($wpdb->prepare("SELECT s.mode FROM {$p}sessions s JOIN {$p}events e ON e.id=s.event_id WHERE s.id=%d AND s.status='published' AND e.status='published'", $sessionId));
        if ($mode === 'general') { return self::capacity(['session' => $sessionId]); }
        if ($mode !== 'seated') { return '<p dir="' . I18n::direction() . '">' . self::t('جلسه منتشرشده یافت نشد.') . '</p>'; }
        self::enqueueTickets();
        wp_enqueue_script('pesm-attendees', PESM_URL . 'assets/js/attendees.js', ['pesm-i18n'], Ui::assetVersion('assets/js/attendees.js'), true);
        wp_enqueue_script('pesm-map', PESM_URL . 'assets/js/map.js', ['pesm-i18n', 'pesm-tickets', 'pesm-attendees'], Ui::assetVersion('assets/js/map.js'), true);
        wp_add_inline_script('pesm-map', 'window.PESM_CONFIG = ' . wp_json_encode(['apiRoot' => esc_url_raw(rest_url('pesm/v1/'))]) . ';', 'before');
        $uid = 'pesm-map-' . wp_unique_id();
        $html = '<section class="pesm-ui pesm-map" dir="' . I18n::direction() . '" id="' . esc_attr($uid) . '" data-session="' . esc_attr((string) $sessionId) . '">'
            . self::heading('جایگاه خود را انتخاب کنید', 'صندلی‌های آزاد را روی نقشه انتخاب کنید؛ سپس رزرو و اطلاعات خریدار را تکمیل کنید.', 'seat')
            . '<ol class="pesm-booking-steps"><li class="is-current" data-booking-step="1" aria-current="step"><span>1</span>' . self::t('انتخاب جایگاه') . '</li><li data-booking-step="2"><span>2</span>' . self::t('مشخصات خریدار') . '</li><li data-booking-step="3"><span>3</span>' . self::t('دریافت بلیت') . '</li></ol>'
            . '<div class="pesm-booking-layout"><div class="pesm-map-panel"><div class="pesm-toolbar pesm-map-toolbar"><div class="pesm-toolbar-group">'
            . '<button type="button" class="pesm-zoom-out pesm-icon-button" aria-label="' . self::t('کوچک‌نمایی') . '">−</button><span class="pesm-scale-label" dir="ltr">100%</span>'
            . '<button type="button" class="pesm-zoom-in pesm-icon-button" aria-label="' . self::t('بزرگ‌نمایی') . '">+</button><button type="button" class="pesm-zoom-reset pesm-button-quiet">' . self::t('نمای کامل') . '</button></div>'
            . '<div class="pesm-toolbar-group pesm-seat-search"><label>' . self::t('کد صندلی') . '<input class="pesm-search" type="search" placeholder="A-12" dir="ltr"></label>'
            . '<button type="button" class="pesm-find pesm-button-secondary">' . Ui::icon('search') . self::t('جست‌وجو') . '</button><button type="button" class="pesm-refresh pesm-button-quiet">' . Ui::icon('clock') . self::t('تازه‌سازی موجودی') . '</button></div></div>'
            . '<ul class="pesm-seat-legend">';
        foreach (['available'=>'آزاد', 'selected'=>'انتخاب شما', 'held'=>'رزرو دیگران', 'sold'=>'فروخته یا مسدود'] as $state=>$label) {
            $html .= '<li class="pesm-legend-item"><span class="pesm-legend-dot pesm-legend-' . $state . '" aria-hidden="true"></span>' . self::t($label) . '</li>';
        }
        $html .= '</ul><div class="pesm-viewport" tabindex="0" aria-label="' . self::t('نقشه صندلی‌ها؛ پیمایش افقی و عمودی فعال است') . '"><div class="pesm-stage"><div class="pesm-canvas" role="group" aria-label="' . self::t('صندلی‌ها') . '"></div></div></div>'
            . '<p class="pesm-help-text pesm-map-help">' . Ui::icon('seat') . self::t('برای جابه‌جایی در نقشه پیمایش کنید. انتخاب با کلید فاصله و حرکت با کلیدهای جهت نیز ممکن است.') . '</p>'
            . '<details class="pesm-seat-assist"><summary>' . self::t('صندلی‌های کنار هم می‌خواهید؟') . '</summary><div class="pesm-toolbar-group"><label>' . self::t('تعداد صندلی') . '<input class="pesm-suggest-count" type="number" min="1" max="20" value="2"></label>'
            . '<button type="button" class="pesm-suggest pesm-button-secondary">' . self::t('پیشنهاد صندلی مجاور') . '</button></div></details></div>'
            . '<aside class="pesm-booking-summary"><p class="pesm-eyebrow">' . self::t('خلاصه رزرو') . '</p><h4>' . self::t('انتخاب شما') . '</h4>'
            . '<p class="pesm-selected" aria-live="polite" aria-atomic="true">' . self::t('هیچ صندلی انتخاب نشده است.') . '</p><div class="pesm-price">' . self::t('در حال دریافت قیمت...') . '</div>'
            . '<button type="button" class="pesm-hold pesm-button-primary" disabled>' . self::t('رزرو موقت و ادامه') . Ui::icon('arrow') . '</button>'
            . '<p class="pesm-help-text">' . self::t('انتخاب صندلی به‌تنهایی رزرو نیست؛ برای نگهداری جایگاه، دکمه رزرو را بزنید.') . '</p><p class="pesm-ttl" aria-live="off"></p>'
            . '<div class="pesm-lease-actions" hidden><button type="button" class="pesm-replace pesm-button-secondary" disabled>' . self::t('ثبت تغییر صندلی‌ها') . '</button>'
            . '<button type="button" class="pesm-renew pesm-button-quiet" disabled>' . self::t('تمدید مهلت رزرو') . '</button><button type="button" class="pesm-release pesm-button-quiet" disabled>' . self::t('آزادسازی رزرو') . '</button></div>';
        if (function_exists('WC')) { $html .= '<button type="button" class="pesm-add-cart pesm-button-quiet" hidden disabled>' . self::t('ادامه از سبد خرید') . '</button>'; }
        return $html . '</aside></div><form class="pesm-checkout-form" hidden autocomplete="on">'
            . self::heading('مشخصات خریدار', 'بدون نیاز به ساخت حساب کاربری، اطلاعات دریافت بلیت را تکمیل کنید.', 'users', 'h4') . self::buyerFields(true) . '</form>'
            . '<section class="pesm-receipt" hidden aria-live="polite"></section><p class="pesm-feedback pesm-status" role="status" aria-live="polite"></p></section>';
    }

    /** Cards and empty event pages need presentation styles even without a seat map. */
    private static function enqueuePresentation(): void
    {
        Ui::enqueue();
        wp_enqueue_style('pesm-map', PESM_URL . 'assets/css/map.css', ['pesm-ui'], Ui::assetVersion('assets/css/map.css'));
    }

    private static function enqueueTickets(): void
    {
        Ui::enqueue();
        wp_enqueue_style('pesm-map', PESM_URL . 'assets/css/map.css', ['pesm-ui'], Ui::assetVersion('assets/css/map.css'));
        wp_enqueue_script('pesm-qr', PESM_URL . 'assets/vendor/qrcodegen-bundled.js', [], Ui::assetVersion('assets/vendor/qrcodegen-bundled.js'), true);
        wp_enqueue_script('pesm-tickets', PESM_URL . 'assets/js/tickets.js', ['pesm-i18n', 'pesm-qr'], Ui::assetVersion('assets/js/tickets.js'), true);
        wp_add_inline_script('pesm-tickets', 'window.PESM_TICKETS_ROOT = ' . wp_json_encode(esc_url_raw(rest_url('pesm/v1/'))) . ';', 'before');
    }

    public static function tickets(): string
    {
        self::enqueueTickets();
        return '<section class="pesm-ui pesm-tickets-panel" dir="' . I18n::direction() . '">'
            . self::heading('بلیت‌های من', 'بلیت‌ها و جزئیات سفارش خود را با دسترسی امن مشاهده کنید.', 'ticket', 'h2')
            . '<div class="pesm-ticket-access-grid"><div class="pesm-ticket-entry"><div class="pesm-panel-heading"><span class="pesm-panel-icon">' . Ui::icon('ticket') . '</span><div><h3>' . self::t('دریافت بلیت') . '</h3><p>' . self::t('شماره سفارش و کلید دسترسی را از رسید خرید وارد کنید.') . '</p></div></div>'
            . '<form class="pesm-ticket-search"><div class="pesm-form-grid"><label>' . self::t('شماره سفارش') . '<input name="order_id" type="number" min="1" required placeholder="1234" inputmode="numeric" dir="ltr"></label>'
            . '<label>' . self::t('کلید دسترسی') . '<input name="guest_token" type="password" pattern="[a-f0-9]{64}" required autocomplete="off" dir="ltr"></label></div>'
            . '<button type="submit" class="pesm-button-primary">' . self::t('نمایش بلیت‌ها') . Ui::icon('arrow') . '</button></form><p class="pesm-ticket-message pesm-status" role="status" aria-live="polite"></p>'
            . '<p class="pesm-help-text">' . Ui::icon('check') . self::t('کلید دسترسی خصوصی است؛ آن را فقط در این صفحه وارد کنید.') . '</p></div>'
            . '<details class="pesm-ticket-recovery"><summary>' . Ui::icon('users') . '<span>' . self::t('کلید دسترسی را ندارید؟') . '</span>' . Ui::icon('chevron') . '</summary>'
            . '<div class="pesm-recovery-content"><p>' . self::t('با ایمیل ثبت‌شده هنگام خرید، کد بازیابی دریافت کنید. نیازی به ثبت‌نام نیست.') . '</p>'
            . '<form class="pesm-recovery-request"><div class="pesm-form-grid"><label>' . self::t('شماره سفارش') . '<input name="order_id" type="number" min="1" required inputmode="numeric" dir="ltr"></label>'
            . '<label>' . self::t('ایمیل ثبت‌شده در سفارش') . '<input name="email" type="email" required autocomplete="email" dir="ltr"></label></div>'
            . '<button type="submit" class="pesm-button-secondary">' . self::t('دریافت کد بازیابی') . '</button></form>'
            . '<form class="pesm-recovery-verify" hidden><input name="order_id" type="hidden"><label>' . self::t('کد ۸ رقمی') . '<input name="code" inputmode="numeric" pattern="[0-9]{8}" maxlength="8" required autocomplete="one-time-code" dir="ltr"></label>'
            . '<button type="submit" class="pesm-button-primary">' . self::t('تأیید و نمایش بلیت‌ها') . '</button></form><p class="pesm-recovery-message pesm-status" role="status" aria-live="polite"></p></div></details></div>'
            . '<div class="pesm-invoice-results"></div><div class="pesm-ticket-results"></div></section>';
    }

    /** Consent from the current bearer holder and a recipient verification code. */
    public static function ownershipTransfer(): string
    {
        Ui::enqueue();
        if (get_option('pesm_ownership_transfer_preview_enabled', 'no') !== 'yes') {
            return '<p class="pesm-ui pesm-info-note" dir="' . I18n::direction() . '">' . self::t('انتقال مالکیت بلیت در این سایت فعال نیست.') . '</p>';
        }
        self::enqueueTickets();
        wp_enqueue_script('pesm-ownership-transfer', PESM_URL . 'assets/js/ownership-transfer.js', ['pesm-i18n', 'pesm-tickets'], Ui::assetVersion('assets/js/ownership-transfer.js'), true);
        wp_add_inline_script('pesm-ownership-transfer', 'window.PESM_OWNERSHIP_ROOT = ' . wp_json_encode(esc_url_raw(rest_url('pesm/v1/'))) . ';', 'before');
        return '<section class="pesm-ui pesm-ownership-transfer" dir="' . I18n::direction() . '">'
            . self::heading('انتقال مالکیت بلیت', 'انتقال با درخواست مالک فعلی و تأیید ایمیل گیرنده انجام می‌شود.', 'users', 'h2')
            . '<div class="pesm-owner-grid"><section class="pesm-owner-card"><h3>' . self::t('۱. درخواست انتقال') . '</h3><p class="pesm-help-text">' . self::t('اطلاعات بلیت و گیرنده جدید را وارد کنید.') . '</p>'
            . '<form class="pesm-owner-request"><div class="pesm-form-grid"><label>' . self::t('نوع بلیت') . '<select name="type"><option value="seated">' . self::t('صندلی‌دار') . '</option><option value="general">' . self::t('بدون صندلی') . '</option></select></label>'
            . '<label>' . self::t('شناسه بلیت از بخش «بلیت‌های من»') . '<input name="ticket_id" type="number" min="1" required dir="ltr"></label>'
            . '<label>' . self::t('کلید دسترسی مالک فعلی') . '<input type="password" name="owner_token" pattern="[a-f0-9]{64}" required autocomplete="off" dir="ltr"></label>'
            . '<label>' . self::t('نام گیرنده') . '<input name="recipient_name" maxlength="190" required autocomplete="name"></label>'
            . '<label>' . self::t('ایمیل گیرنده') . '<input type="email" name="recipient_email" maxlength="190" required autocomplete="email" dir="ltr"></label></div>'
            . '<button type="submit" class="pesm-button-primary">' . self::t('ارسال کد تأیید به گیرنده') . '</button></form></section>'
            . '<section class="pesm-owner-card"><h3>' . self::t('۲. تأیید توسط گیرنده') . '</h3><p class="pesm-help-text">' . self::t('گیرنده با شناسه درخواست و کد ایمیلی، انتقال را تأیید می‌کند.') . '</p><form class="pesm-owner-accept"><label>' . self::t('شناسه درخواست') . '<input name="transfer_id" type="number" min="1" required dir="ltr"></label>'
            . '<label>' . self::t('کد ۸ رقمی گیرنده') . '<input name="code" inputmode="numeric" pattern="[0-9]{8}" maxlength="8" required autocomplete="one-time-code" dir="ltr"></label>'
            . '<button type="submit" class="pesm-button-primary">' . self::t('تأیید انتقال') . '</button></form></section></div>'
            . '<details class="pesm-owner-view-panel"><summary>' . self::t('مشاهده بلیت با کلید مالک جدید') . '</summary><form class="pesm-owner-view"><label>' . self::t('توکن مالک جدید') . '<input name="token" type="password" pattern="[a-f0-9]{64}" autocomplete="off" required dir="ltr"></label>'
            . '<button type="submit" class="pesm-button-secondary">' . self::t('نمایش بلیت منتقل‌شده') . '</button></form></details><p class="pesm-owner-message pesm-status" aria-live="polite" role="status"></p><div class="pesm-owner-ticket"></div></section>';
    }

    /** Lightweight report widget. No customer data; never includes WooCommerce revenue. */
    public static function insightsWidget(int $centerId, array $sessionRows = []): string
    {
        if ($centerId < 1 || !current_user_can('pesm_view_reports')) { return ''; }
        $endpoint = rest_url('pesm/v1/admin/reports/insights');
        if (function_exists('is_admin') && is_admin()) { $endpoint = add_query_arg('_locale', 'user', $endpoint); }
        $html = ('<section class="pesm-ui pesm-sales-insights" ' . 'dir="' . \PESM\Support\I18n::direction() . '"' . ' data-center-id="') . esc_attr((string)$centerId)
            . '" data-endpoint="' . esc_url($endpoint)
            . '" data-nonce="' . esc_attr(wp_create_nonce('wp_rest')) . '">'
            . (\PESM\Support\I18n::text('<h3>روند بلیت و مقایسه رویدادها</h3>'))
            . (\PESM\Support\I18n::text('<p>بازه زمانی فقط روند صدور بلیت را محدود می‌کند؛ درصد ظرفیت و رتبه جایگاه‌ها وضعیت فعلی هستند. داده‌ها درآمد یا فروش مالی قطعی نیستند. تا ۲۰۰ جلسه اخیر نمایش داده می‌شود.</p>'))
            . (\PESM\Support\I18n::text('<label>بازه (روز) <select class="pesm-insights-days"><option value="7">۷</option>'))
            . (\PESM\Support\I18n::text('<option value="30" selected>۳۰</option><option value="90">۹۰</option></select></label> '))
            . (\PESM\Support\I18n::text('<label>جلسه <select class="pesm-insights-session"><option value="0">همه جلسات</option>'));
        foreach ($sessionRows as $row) {
            $html .= '<option value="' . esc_attr((string)$row['session_id']) . '">'
                . esc_html((string)$row['title'] . ' — #' . $row['session_id']) . '</option>';
        }
        $html .= (\PESM\Support\I18n::text('</select></label> <button class="pesm-insights-load" type="button">تازه‌سازی آمار</button>'))
            . '<p class="pesm-insights-status" role="status" aria-live="polite"></p>'
            . (\PESM\Support\I18n::text('<h4>روند صدور بلیت‌های معتبر (UTC)</h4><svg class="pesm-insights-trend-chart" viewBox="0 0 800 160" role="img" aria-label="نمودار روند صدور بلیت" style="width:100%;max-width:800px;height:160px"></svg>'))
            . (\PESM\Support\I18n::text('<details><summary>جدول روزانه برای دسترس‌پذیری</summary><table class="widefat"><thead><tr><th>روز</th><th>بلیت فعال صادرشده</th></tr></thead><tbody class="pesm-insights-trend"></tbody></table></details>'))
            . (\PESM\Support\I18n::text('<h4>مقایسه رویدادها و درصد تکمیل ظرفیت</h4><table class="widefat"><thead><tr><th>رویداد</th><th>جلسه‌ها</th><th>فروخته‌شده</th><th>ظرفیت</th><th>درصد اشغال</th></tr></thead><tbody class="pesm-insights-events"></tbody></table>'))
            . (\PESM\Support\I18n::text('<h4>زون‌ها و ردیف‌های پربلیت</h4><table class="widefat"><thead><tr><th>زون</th><th>ردیف</th><th>بلیت معتبر</th></tr></thead><tbody class="pesm-insights-zones"></tbody></table>'))
            . '</section>';
        return $html;
    }

    /** Center-scoped, authenticated, aggregate-only dashboard. */
    public static function manager(array|string $attributes = []): string
    {
        if (!is_user_logged_in() || !current_user_can('pesm_view_reports')) {
            return ('<p ' . 'dir="' . \PESM\Support\I18n::direction() . '"' . \PESM\Support\I18n::text('>برای مشاهده گزارش‌ها ورود مدیر مجاز ضروری است.</p>'));
        }
        $atts = shortcode_atts(['center'=>0], is_array($attributes) ? $attributes : [], 'pesm_manager_dashboard');
        $centerId = absint($atts['center']);
        if (!$centerId) { return ('<p ' . 'dir="' . \PESM\Support\I18n::direction() . '"' . \PESM\Support\I18n::text('>شناسه مرکز را در ویژگی center تعیین کنید.</p>')); }
        try {
            // The same center scope is enforced on initial render and every REST refresh.
            $report = (new AttendanceReport())->summary(get_current_user_id(), $centerId);
        } catch (HoldException $e) {
            return ('<p ' . 'dir="' . \PESM\Support\I18n::direction() . '"' . '>') . esc_html($e->getMessage()) . '</p>';
        }
        wp_enqueue_style('pesm-map', PESM_URL . 'assets/css/map.css', ['pesm-ui'], Ui::assetVersion('assets/css/map.css'));
        Ui::enqueue();
        wp_enqueue_script('pesm-attendance', PESM_URL . 'assets/js/attendance.js', ['pesm-i18n'], Ui::assetVersion('assets/js/attendance.js'), true);
        wp_enqueue_script('pesm-sales-insights', PESM_URL . 'assets/js/sales-insights.js', ['pesm-i18n'], Ui::assetVersion('assets/js/sales-insights.js'), true);
        $csv = wp_nonce_url(admin_url('admin-post.php?action=pesm_attendance_csv&center_id=' . $centerId),
            'pesm_attendance_csv_' . $centerId);
        $xlsx = wp_nonce_url(admin_url('admin-post.php?action=pesm_attendance_xlsx&center_id=' . $centerId),
            'pesm_attendance_xlsx_' . $centerId);
        $html = ('<section class="pesm-ui pesm-attendance" ' . 'dir="' . \PESM\Support\I18n::direction() . '"' . ' data-center-id="') . esc_attr((string) $centerId)
            . '" data-endpoint="' . esc_url(rest_url('pesm/v1/admin/reports/attendance'))
            . '" data-nonce="' . esc_attr(wp_create_nonce('wp_rest')) . '">'
            . (\PESM\Support\I18n::text('<h3>گزارش تجمیعی مرکز ')) . esc_html((string) $centerId) . '</h3>'
            . '<p><a href="' . esc_url($csv) . (\PESM\Support\I18n::text('">دریافت CSV</a> · '))
            . '<a href="' . esc_url($xlsx) . (\PESM\Support\I18n::text('">دریافت Excel (XLSX)</a></p>'))
            . (\PESM\Support\I18n::text('<div class="pesm-report-controls"><label>فیلتر جلسه: <select class="pesm-attendance-session">'))
            . (\PESM\Support\I18n::text('<option value="0">همه جلسات (حداکثر ۲۰۰)</option>'));
        foreach ($report['sessions'] as $row) {
            $html .= '<option value="' . esc_attr((string) $row['session_id']) . '">'
                . esc_html((string) $row['title'] . ' — #' . $row['session_id']) . '</option>';
        }
        $html .= (\PESM\Support\I18n::text('</select></label> <button type="button" class="pesm-attendance-refresh">تازه‌سازی</button> '))
            . (\PESM\Support\I18n::text('<button type="button" class="pesm-attendance-print">چاپ گزارش</button></div>'))
            . (\PESM\Support\I18n::text('<p class="pesm-attendance-status" role="status" aria-live="polite">آمار هر ۶۰ ثانیه تازه‌سازی می‌شود.</p>'))
            . (\PESM\Support\I18n::text('<table><thead><tr><th>رویداد</th><th>جلسه</th><th>ظرفیت</th><th>فروخته</th><th>ورود</th><th>عدم حضور پس از مهلت ورود</th></tr></thead>'))
            . '<tbody class="pesm-attendance-rows">';
        foreach ($report['sessions'] as $row) {
            $html .= '<tr><td>' . esc_html((string) $row['title']) . '</td><td>'
                . esc_html((string) $row['session_id']) . '</td><td>' . esc_html((string) $row['capacity'])
                . '</td><td>' . esc_html((string) $row['sold']) . '</td><td>'
                . esc_html((string) $row['checked_in']) . '</td><td>' . esc_html((string) $row['no_show']) . '</td></tr>';
        }
        return $html . '</tbody></table></section>' . self::insightsWidget($centerId, $report['sessions']);
    }

    public static function gate(): string
    {
        Ui::enqueue();
        if (!is_user_logged_in() || !current_user_can('pesm_scan_tickets')) {
            return '<section class="pesm-ui" dir="' . I18n::direction() . '">' . self::emptyState('ticket', 'برای کنترل ورود، حساب اپراتور مجاز لازم است.', 'با حساب اپراتور مرکز وارد شوید و دوباره این صفحه را باز کنید.') . '</section>';
        }
        wp_enqueue_style('pesm-map', PESM_URL . 'assets/css/map.css', ['pesm-ui'], Ui::assetVersion('assets/css/map.css'));
        wp_enqueue_script('pesm-gate', PESM_URL . 'assets/js/gate.js', ['pesm-i18n'], Ui::assetVersion('assets/js/gate.js'), true);
        wp_add_inline_script('pesm-gate', 'window.PESM_GATE_CONFIG = ' . wp_json_encode(['apiRoot' => esc_url_raw(rest_url('pesm/v1/')), 'nonce' => wp_create_nonce('wp_rest')]) . ';', 'before');
        return '<section class="pesm-ui pesm-gate" dir="' . I18n::direction() . '">'
            . self::heading('کنترل ورود بلیت', 'QR بلیت را اسکن کنید یا شماره بلیت را برای ثبت ورود وارد کنید.', 'ticket', 'h2')
            . '<div class="pesm-gate-layout"><section class="pesm-gate-camera"><div class="pesm-panel-heading"><span class="pesm-panel-icon">' . Ui::icon('ticket') . '</span><div><h3>' . self::t('اسکن بلیت') . '</h3><p>' . self::t('اعتبار بلیت به‌صورت برخط بررسی می‌شود.') . '</p></div></div>'
            . '<form class="pesm-gate-form"><label>' . self::t('نام گیت') . '<input name="gate" maxlength="100" autocomplete="off"></label>'
            . '<details class="pesm-gate-token"><summary>' . self::t('ورود مستقیم محتوای QR') . '</summary><label>' . self::t('توکن QR') . '<input name="qr_payload" required autocomplete="off" maxlength="97" dir="ltr"></label>'
            . '<button type="submit" class="pesm-button-secondary">' . self::t('تأیید ورود') . '</button></details></form>'
            . '<div class="pesm-gate-camera-placeholder" aria-hidden="true">' . Ui::icon('ticket') . '</div><video class="pesm-gate-video" playsinline muted hidden></video>'
            . '<div class="pesm-form-actions"><button type="button" class="pesm-start-camera pesm-button-primary">' . self::t('شروع اسکن با دوربین') . '</button><button type="button" class="pesm-stop-camera pesm-button-quiet" disabled>' . self::t('توقف دوربین') . '</button></div>'
            . '<p class="pesm-help-text">' . self::t('اگر اسکن دوربین در دسترس نبود، از شماره بلیت استفاده کنید.') . '</p></section>'
            . '<section class="pesm-gate-manual"><div class="pesm-panel-heading"><span class="pesm-panel-icon">' . Ui::icon('search') . '</span><div><h3>' . self::t('بررسی با شماره بلیت') . '</h3><p>' . self::t('شماره درج‌شده روی بلیت را وارد کنید.') . '</p></div></div>'
            . '<form class="pesm-gate-number-form"><label>' . self::t('شماره بلیت') . '<input name="ticket_number" pattern="P-[A-F0-9]{16}" maxlength="18" required placeholder="P-0123456789ABCDEF" dir="ltr" autocomplete="off"></label>'
            . '<label>' . self::t('نام گیت') . '<input name="gate" maxlength="100" autocomplete="off"></label><div class="pesm-form-actions"><button type="button" class="pesm-gate-lookup pesm-button-secondary">' . self::t('بررسی بلیت') . '</button>'
            . '<button type="submit" class="pesm-button-primary">' . self::t('ثبت ورود') . '</button></div></form></section></div><p class="pesm-gate-status pesm-status" role="status" aria-live="polite"></p></section>';
    }

    /** Static source labels only; dynamic user values are escaped at their call sites. */
    private static function t(string $message): string
    {
        return esc_html(I18n::text($message));
    }

    private static function heading(string $title, string $description, string $icon, string $level = 'h3'): string
    {
        $level = in_array($level, ['h2','h3','h4'], true) ? $level : 'h3';
        return '<header class="pesm-page-heading"><span class="pesm-panel-icon">' . Ui::icon($icon) . '</span><div><' . $level . '>' . self::t($title) . '</' . $level . '><p>' . self::t($description) . '</p></div></header>';
    }

    private static function emptyState(string $icon, string $title, string $description): string
    {
        return '<div class="pesm-empty-state"><span class="pesm-empty-icon">' . Ui::icon($icon) . '</span><h3>' . self::t($title) . '</h3><p>' . self::t($description) . '</p></div>';
    }

    private static function buyerFields(bool $coupon): string
    {
        $html = '<div class="pesm-form-grid"><label>' . self::t('نام') . '<input name="first_name" maxlength="90" required autocomplete="given-name"></label>'
            . '<label>' . self::t('نام خانوادگی') . '<input name="last_name" maxlength="90" required autocomplete="family-name"></label>'
            . '<label>' . self::t('شماره موبایل') . '<input name="phone" type="tel" inputmode="tel" maxlength="30" required autocomplete="tel" dir="ltr"><small>' . self::t('موبایل ایران؛ برای کنترل محدودیت خرید') . '</small></label>'
            . '<label>' . self::t('ایمیل دریافت و بازیابی بلیت') . '<input name="email" type="email" maxlength="190" autocomplete="email" dir="ltr"><small>' . self::t('برای سفارش پولی اجباری است.') . '</small></label></div>';
        if ($coupon) {
            $html .= '<details class="pesm-coupon-details"><summary>' . self::t('کد تخفیف دارید؟') . '</summary><div class="pesm-toolbar-group"><label>' . self::t('کد تخفیف') . '<input name="coupon_code" maxlength="32" autocomplete="off" dir="ltr"></label><button type="button" class="pesm-coupon-preview pesm-button-secondary">' . self::t('بررسی تخفیف') . '</button></div><p class="pesm-coupon-result" aria-live="polite"></p></details>';
        }
        return $html . '<label class="pesm-attendees-opt-in"><input type="checkbox" class="pesm-attendees-toggle"><span>' . self::t('نام شرکت‌کنندگان را جداگانه وارد می‌کنم') . '</span></label><div class="pesm-attendee-fields" hidden></div><div class="pesm-form-actions"><button type="submit" class="pesm-button-primary">' . self::t('ثبت سفارش و ادامه') . Ui::icon('arrow') . '</button><p class="pesm-help-text">' . self::t('صدور بلیت پس از ثبت سفارش و تأیید پرداخت انجام می‌شود.') . '</p></div>';
    }


}
