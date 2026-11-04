<?php

declare(strict_types=1);

namespace PESM\Admin;

use DateTimeImmutable;
use DateTimeZone;
use PESM\Domain\Hall\HallService;
use PESM\Domain\Hall\SeatAdministration;
use PESM\Domain\Hall\LayoutVersionService;
use PESM\Domain\Hall\DraftSaveService;
use PESM\Domain\Hall\DraftConflictException;
use PESM\Domain\Hall\LayoutPrintRenderer;
use PESM\Domain\Hall\LayoutDocument;
use PESM\Domain\Hall\LayoutPresets;
use PESM\Domain\Booking\SeatingRulesService;
use PESM\Domain\Ticket\SeatTransferService;
use PESM\Domain\Ticket\BundleService;
use PESM\Domain\Ticket\FreeTicketCancellationService;
use PESM\Domain\Ticket\RefundReviewService;
use PESM\Domain\Ticket\AttendeeChangeService;
use PESM\Domain\Event\LifecycleService;
use PESM\Domain\Event\EventChangeService;
use PESM\Domain\Event\LifecyclePolicy;
use PESM\Domain\Event\EventEditor;
use PESM\Security\Capabilities;
use Throwable;

final class Pages
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('admin_post_pesm_save_notification_settings', [self::class, 'saveNotificationSettings']);
        add_action('admin_post_pesm_create_center', [self::class, 'createCenter']);
        add_action('admin_post_pesm_create_hall', [self::class, 'createHall']);
        add_action('admin_post_pesm_layout_import', [self::class, 'importLayout']);
        add_action('admin_post_pesm_layout_preset', [self::class, 'createLayoutPreset']);
        add_action('admin_post_pesm_layout_publish', [self::class, 'publishLayout']);
        add_action('admin_post_pesm_layout_export', [self::class, 'exportLayout']);
        add_action('admin_post_pesm_layout_print', [self::class, 'printLayout']);
        add_action('wp_ajax_pesm_editor_save', [self::class, 'saveEditorDraft']);
        add_action('admin_post_pesm_create_event', [self::class, 'createEvent']);
        add_action('admin_post_pesm_edit_event', [self::class, 'editEvent']);
        add_action('admin_post_pesm_create_session', [self::class, 'createSession']);
        add_action('admin_post_pesm_assign_center', [self::class, 'assignCenter']);
        add_action('admin_post_pesm_change_status', [self::class, 'changeStatus']);
        add_action('admin_post_pesm_postpone_session', [self::class, 'postponeSession']);
        add_action('admin_post_pesm_seat_block', [self::class, 'seatBlock']);
        add_action('admin_post_pesm_seating_rules', [self::class, 'seatingRules']);
        add_action('admin_post_pesm_export_capacity', [self::class, 'exportCapacity']);
        add_action('admin_post_pesm_transfer_ticket_seat', [self::class, 'transferTicketSeat']);
        add_action('admin_post_pesm_create_ticket_bundle', [self::class, 'createTicketBundle']);
        add_action('admin_post_pesm_cancel_free_ticket', [self::class, 'cancelFreeTicket']);
        add_action('admin_post_pesm_refund_request', [self::class, 'requestPaidRefund']);
        add_action('admin_post_pesm_refund_reconcile', [self::class, 'reconcilePaidRefund']);
        add_action('admin_post_pesm_change_attendee', [self::class, 'changeAttendee']);
        add_action('admin_notices', [self::class, 'notices']);
    }

    public static function menu(): void
    {
        add_menu_page((\PESM\Support\I18n::text('مدیریت رویداد و صندلی')), (\PESM\Support\I18n::text('رویداد و صندلی')), 'pesm_access_dashboard', 'pesm-dashboard', [self::class, 'render'], 'dashicons-tickets-alt', 26);
    }

    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_pesm-dashboard') { return; }
        \PESM\Support\Ui::enqueue();
        wp_enqueue_style('pesm-admin', PESM_URL . 'assets/css/admin.css', ['pesm-ui'], \PESM\Support\Ui::assetVersion('assets/css/admin.css'));
        wp_enqueue_script('pesm-admin', PESM_URL . 'assets/js/admin.js', ['pesm-i18n'], \PESM\Support\Ui::assetVersion('assets/js/admin.js'), true);
        if (current_user_can('pesm_view_reports')) {
            wp_enqueue_script('pesm-seat-status-admin', PESM_URL . 'assets/js/seat-status-admin.js', ['pesm-i18n'], \PESM\Support\Ui::assetVersion('assets/js/seat-status-admin.js'), true);
            wp_localize_script('pesm-seat-status-admin', 'pesmSeatStatusConfig', [
                'url' => add_query_arg('_locale', 'user', rest_url('pesm/v1/admin/reports/seat-status')),
                'nonce' => wp_create_nonce('wp_rest'),
            ]);
        }
        if (!current_user_can('pesm_edit_layouts')) { return; }
        wp_enqueue_style('pesm-layout-editor', PESM_URL . 'assets/css/layout-editor.css', ['pesm-ui'], \PESM\Support\Ui::assetVersion('assets/css/layout-editor.css'));
        wp_enqueue_script('pesm-layout-editor', PESM_URL . 'assets/js/layout-editor.js', ['pesm-i18n'], \PESM\Support\Ui::assetVersion('assets/js/layout-editor.js'), true);
        wp_localize_script('pesm-layout-editor', 'pesmEditorConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pesm_editor_save'),
        ]);
    }

    public static function notices(): void
    {
        if (!current_user_can('pesm_access_dashboard')) {
            return;
        }
        $error = get_option('pesm_schema_error');
        if ($error) {
            echo '<div class="notice notice-error"><p>' . esc_html((\PESM\Support\I18n::text('خطای ساخت پایگاه داده: ')) . $error) . '</p></div>';
        }
        if (isset($_GET['page']) && sanitize_key((string) wp_unslash($_GET['page'])) === 'pesm-dashboard' && !class_exists('WooCommerce')) {
            echo (\PESM\Support\I18n::text('<div class="notice notice-warning"><p>ووکامرس فعال نیست؛ مسیر سفارش و صدور بلیت غیرفعال است.</p></div>'));
        }
    }

    public static function render(): void
    {
        if (!current_user_can('pesm_access_dashboard')) {
            wp_die(esc_html__((\PESM\Support\I18n::text('دسترسی غیرمجاز.')), 'event-seat-manager'));
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $userId = get_current_user_id();
        if (current_user_can('pesm_manage_centers')) {
            $centers = $wpdb->get_results("SELECT * FROM {$p}centers ORDER BY id DESC");
        } else {
            $centers = $wpdb->get_results($wpdb->prepare(
                "SELECT c.* FROM {$p}centers c JOIN {$p}center_users u ON u.center_id = c.id
                 WHERE u.user_id = %d ORDER BY c.id DESC", $userId
            ));
        }
        $centerIds = array_map(static fn($c): int => (int) $c->id, $centers);
        $halls = [];
        $events = [];
        if ($centerIds) {
            $placeholders = implode(',', array_fill(0, count($centerIds), '%d'));
            $halls = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}halls WHERE center_id IN ($placeholders) ORDER BY id DESC", ...$centerIds));
            $events = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}events WHERE center_id IN ($placeholders) ORDER BY id DESC", ...$centerIds));
        }
        $sessions = [];
        if ($events) {
            $eventIds = array_map(static fn($e): int => (int) $e->id, $events);
            $placeholders = implode(',', array_fill(0, count($eventIds), '%d'));
            $sessions = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}sessions WHERE event_id IN ($placeholders) ORDER BY id DESC LIMIT 30", ...$eventIds));
        }
        $url = esc_url(admin_url('admin-post.php'));
        $cards = array_fill_keys(['events', 'halls', 'tickets', 'reports', 'settings'], []);
        ob_start();
        if (current_user_can('pesm_manage_sms')) {
            self::renderNotificationSettings($url);
        }
        if (current_user_can('pesm_manage_centers')) {
            echo (\PESM\Support\I18n::text('<h2>افزودن مرکز</h2>'));
            self::formStart($url, 'pesm_create_center');
            echo (\PESM\Support\I18n::text('<label>نام مرکز <input type="text" name="name" maxlength="190" required></label> '));
            submit_button((\PESM\Support\I18n::text('ثبت مرکز')), 'secondary', 'submit', false);
            echo '</form>';
        }
        self::collectCard($cards, 'settings');
        ob_start();
        if ($centers) {
            if (current_user_can('pesm_manage_halls')) {
            echo (\PESM\Support\I18n::text('<h2>ساخت سالن و نقشه صندلی</h2>'));
            self::formStart($url, 'pesm_create_hall');
            echo (\PESM\Support\I18n::text('<label>مرکز ')); self::centerSelect($centers); echo '</label> ';
            echo (\PESM\Support\I18n::text('<label>نام سالن <input name="name" type="text" maxlength="190" required></label> '));
            echo (\PESM\Support\I18n::text('<label>ردیف <input name="rows" type="number" min="1" max="100" value="15" required></label> '));
            echo (\PESM\Support\I18n::text('<label>صندلی هر ردیف <input name="columns" type="number" min="1" max="100" value="60" required></label> '));
            submit_button((\PESM\Support\I18n::text('ایجاد سالن (نمونه ۹۰۰ صندلی)')), 'secondary', 'submit', false);
            echo '</form>';
            echo (\PESM\Support\I18n::text('<p>نمونه دیگر: ۱۸ ردیف و ۵۰ صندلی. هر دو نمونه دقیقاً ۹۰۰ صندلی دارند.</p>'));
            }
        }
        self::collectCard($cards, 'halls');
        ob_start();
        if ($halls && current_user_can('pesm_edit_layouts')) {
            echo (\PESM\Support\I18n::text('<h2>ساخت نسخه پیش‌نویس از الگوی پارامتریک</h2>'));
            self::formStart($url, 'pesm_layout_preset');
            echo (\PESM\Support\I18n::text('<label>سالن <select name="hall_id">'));
            foreach ($halls as $hall) {
                echo '<option value="' . esc_attr((string) $hall->id) . '">' . esc_html((string) $hall->name) . '</option>';
            }
            echo (\PESM\Support\I18n::text('</select></label> <label>الگو <select name="preset">'));
            foreach (LayoutPresets::PRESETS as $slug => $title) {
                echo '<option value="' . esc_attr($slug) . '">' . esc_html(\PESM\Support\I18n::text($title)) . '</option>';
            }
            echo (\PESM\Support\I18n::text('</select></label> <label>ردیف <input name="rows" type="number" min="1" max="100" value="15" required></label>'));
            echo (\PESM\Support\I18n::text(' <label>صندلی هر ردیف <input name="columns" type="number" min="1" max="100" value="60" required></label> '));
            submit_button((\PESM\Support\I18n::text('ساخت نسخه پیش‌نویس الگو')), 'secondary', 'submit', false);
            echo (\PESM\Support\I18n::text('</form><p>الگو فقط یک نسخه پیش‌نویس می‌سازد؛ نقشه منتشرشده و بلیت‌های موجود تغییر نمی‌کنند. الگو یک پیش‌نویس صندلی می‌سازد؛ اشیای مستقل مانند سن، میز و راهرو را در ویرایشگر اضافه کنید.</p>'));
            echo (\PESM\Support\I18n::text('<h2>نسخه‌بندی امن چیدمان سالن (JSON)</h2>'));
            echo (\PESM\Support\I18n::text('<p>فایل خروجی JSON شامل صندلی‌ها و اشیای غیرصندلی است. واردکردن آن نسخه پیش‌نویس تازه‌ای ایجاد می‌کند؛ جلسات قبلی تغییر نمی‌کنند.</p>'));
            echo '<form method="post" enctype="multipart/form-data" action="' . $url . '" style="padding:12px 0">';
            echo '<input type="hidden" name="action" value="pesm_layout_import">';
            wp_nonce_field('pesm_layout_import');
            echo (\PESM\Support\I18n::text('<label>سالن <select name="hall_id">'));
            foreach ($halls as $hall) {
                echo '<option value="' . esc_attr((string) $hall->id) . '">' . esc_html($hall->name . ' (#' . $hall->id . ')') . '</option>';
            }
            echo (\PESM\Support\I18n::text('</select></label> <label>فایل JSON <input type="file" name="layout_file" accept=".json,application/json" required></label> '));
            submit_button((\PESM\Support\I18n::text('واردکردن نسخه پیش‌نویس')), 'secondary', 'submit', false);
            echo '</form>';
            foreach (array_slice($halls, 0, 40) as $hall) {
                $versions = $wpdb->get_results($wpdb->prepare(
                    "SELECT id,version_no,status,seat_count,revision,format FROM {$p}layout_versions WHERE hall_id=%d ORDER BY version_no DESC LIMIT 20", (int) $hall->id
                ));
                echo '<h3>' . esc_html($hall->name) . '</h3>';
                echo (\PESM\Support\I18n::text('<table class="widefat striped"><thead><tr><th>نسخه</th><th>صندلی</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>'));
                foreach ($versions as $version) {
                    $exportUrl = wp_nonce_url(add_query_arg([
                        'action' => 'pesm_layout_export', 'hall_id' => (int) $hall->id,
                        'layout_id' => (int) $version->id,
                    ], admin_url('admin-post.php')), 'pesm_layout_export_' . $hall->id . '_' . $version->id);
                    echo '<tr><td>' . esc_html((string) $version->version_no) . '</td><td>' . esc_html((string) $version->seat_count)
                        . '</td><td>' . esc_html(\PESM\Support\I18n::status((string) $version->status)) . '</td><td>';
                    if (($version->format ?? LayoutDocument::FORMAT) === 'pesm.layout.v2') {
                        $designer = add_query_arg(['page'=>'pesm-venue-designer','hall_id'=>(int)$hall->id,'layout_id'=>(int)$version->id], admin_url('admin.php'));
                        echo '<a href="' . esc_url($designer) . '">' . esc_html(\PESM\Support\I18n::isPersian() ? 'طراح سالن' : 'Venue designer') . '</a></td></tr>';
                        continue;
                    }
                    echo '<a href="' . esc_url($exportUrl) . (\PESM\Support\I18n::text('">خروجی JSON</a>'));
                    echo ' <button type="button" class="button pesm-edit-layout" data-url="' . esc_url($exportUrl) . '" data-hall="' . esc_attr((string) $hall->id) . '" data-layout="' . esc_attr((string) $version->id) . '" data-revision="' . esc_attr((string) $version->revision) . (\PESM\Support\I18n::text('">ویرایش دیداری</button>'));
                    foreach (['A4', 'A3'] as $paper) {
                        $printUrl = wp_nonce_url(add_query_arg([
                            'action' => 'pesm_layout_print', 'hall_id' => (int) $hall->id,
                            'layout_id' => (int) $version->id, 'paper' => $paper,
                        ], admin_url('admin-post.php')), 'pesm_layout_print_' . $hall->id . '_' . $version->id . '_' . $paper);
                        echo ' <a target="_blank" rel="noopener" href="' . esc_url($printUrl) . (\PESM\Support\I18n::text('">چاپ ')) . esc_html($paper) . '</a>';
                    }
                    if ($version->status === 'draft') {
                        echo '<form method="post" action="' . $url . '" style="display:inline;margin-right:12px">';
                        echo '<input type="hidden" name="action" value="pesm_layout_publish">';
                        echo '<input type="hidden" name="hall_id" value="' . esc_attr((string) $hall->id) . '">';
                        echo '<input type="hidden" name="layout_id" value="' . esc_attr((string) $version->id) . '">';
                        wp_nonce_field('pesm_layout_publish');
                        submit_button((\PESM\Support\I18n::text('انتشار برای جلسات آینده')), 'secondary', 'submit', false);
                        echo '</form>';
                    }
                    if ((int) $hall->current_layout_id === (int) $version->id) { echo (\PESM\Support\I18n::text(' <strong>(نسخه پیش‌فرض فعلی)</strong>')); }
                    echo '</td></tr>';
                }
                echo '</tbody></table>';
            }
        }
        self::collectCard($cards, 'halls');
        ob_start();
        if ($halls && current_user_can('pesm_edit_layouts')) {
            echo ('<section class="pesm-layout-editor" ' . 'dir="' . \PESM\Support\I18n::direction() . '"' . \PESM\Support\I18n::text(' hidden aria-label="ویرایشگر چیدمان صندلی">'));
            echo (\PESM\Support\I18n::text('<h2>ویرایشگر دیداری نقشه — ذخیره خودکار پیش‌نویس</h2>'));
            echo (\PESM\Support\I18n::text('<p>انتخاب چند صندلی: Ctrl یا Shift + کلیک. برای جابه‌جایی، صندلی انتخابی را بکشید. تغییرات به‌صورت خودکار در پیش‌نویس جداگانه ذخیره می‌شوند؛ انتشار از جدول نسخه‌ها انجام می‌شود. در صورت تعارض فایل JSON را برای حفظ کار دریافت کنید.</p>'));
            echo '<div class="pesm-toolbar">';
            foreach (['pesm-undo'=>(\PESM\Support\I18n::text('بازگشت')),'pesm-redo'=>(\PESM\Support\I18n::text('انجام مجدد')),'pesm-zoom-in'=>(\PESM\Support\I18n::text('بزرگ‌نمایی')),'pesm-zoom-out'=>(\PESM\Support\I18n::text('کوچک‌نمایی')),
                'pesm-reset-view'=>(\PESM\Support\I18n::text('نمای کامل')),'pesm-save'=>(\PESM\Support\I18n::text('ذخیره اکنون')),'pesm-add'=>(\PESM\Support\I18n::text('افزودن صندلی')),'pesm-copy'=>(\PESM\Support\I18n::text('کپی انتخابی')),'pesm-delete'=>(\PESM\Support\I18n::text('حذف انتخابی')),'pesm-download'=>(\PESM\Support\I18n::text('دریافت JSON ویرایش‌شده'))] as $class=>$label) {
                echo '<button type="button" class="button ' . esc_attr($class) . '">' . esc_html($label) . '</button>';
            }
            echo (\PESM\Support\I18n::text('<div class="pesm-object-tools"><label>شیء غیرصندلی <select class="pesm-object-type">'));
            foreach (['stage'=>(\PESM\Support\I18n::text('سن/استیج')),'round_table'=>(\PESM\Support\I18n::text('میز گرد')),'rect_table'=>(\PESM\Support\I18n::text('میز مستطیلی')),'aisle'=>(\PESM\Support\I18n::text('راهرو')),
                'door'=>(\PESM\Support\I18n::text('در')),'emergency_exit'=>(\PESM\Support\I18n::text('خروج اضطراری')),'label'=>(\PESM\Support\I18n::text('برچسب/متن')),
                'separator'=>(\PESM\Support\I18n::text('جداکننده')),'accessible_area'=>(\PESM\Support\I18n::text('ناحیه دسترس‌پذیر'))] as $key=>$label) {
                echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
            }
            echo (\PESM\Support\I18n::text('</select></label> <label>عنوان <input class="pesm-object-label" maxlength="120" type="text"></label>'));
            echo (\PESM\Support\I18n::text('<label>عرض <input class="pesm-object-width" type="number" min="1" max="10000" value="90"></label>'));
            echo (\PESM\Support\I18n::text('<label>ارتفاع <input class="pesm-object-height" type="number" min="1" max="10000" value="50"></label>'));
            echo (\PESM\Support\I18n::text('<button type="button" class="button pesm-object-add">افزودن شیء</button> '));
            echo (\PESM\Support\I18n::text('<button type="button" class="button pesm-object-apply">ویرایش شیء انتخابی</button> '));
            echo (\PESM\Support\I18n::text('<button type="button" class="button pesm-object-remove">حذف شیء انتخابی</button>'));
            echo '<span class="pesm-object-count" aria-live="polite"></span></div>';
            echo '</div><div class="pesm-row-tools">';
            echo (\PESM\Support\I18n::text('<label>نام ردیف جدید <input name="new_row_name" type="text" maxlength="20" pattern="[A-Za-z0-9_-]{1,20}" placeholder="AA" autocomplete="off" dir="ltr"></label>'));
            echo (\PESM\Support\I18n::text('<label>تعداد صندلی ردیف <input name="new_row_count" type="number" min="1" max="100" value="10"></label>'));
            echo (\PESM\Support\I18n::text('<button type="button" class="button pesm-add-row">افزودن ردیف صندلی</button>'));
            echo (\PESM\Support\I18n::text('<p>ردیف تازه در پیش‌نویس چیدمان ذخیره می‌شود؛ نقشه جلسه‌های قبلی تغییر نمی‌کند.</p>'));
            echo (\PESM\Support\I18n::text('</div><p>صندلی انتخابی: <strong class="pesm-selected-total">0</strong></p>'));
            echo (\PESM\Support\I18n::text('<svg role="group" aria-label="نقشه قابل ویرایش؛ انتخاب و جابه‌جایی صندلی‌ها" tabindex="0"></svg>'));
            echo '<div class="pesm-fields">';
            foreach (['code'=>(\PESM\Support\I18n::text('کد صندلی')),'row_label'=>(\PESM\Support\I18n::text('ردیف')),'number'=>(\PESM\Support\I18n::text('شماره')),'zone'=>(\PESM\Support\I18n::text('زون')),'x'=>(\PESM\Support\I18n::text('مختصات X')),'y'=>(\PESM\Support\I18n::text('مختصات Y'))] as $name=>$label) {
                $type = in_array($name, ['number','x','y'], true) ? 'number' : 'text';
                echo '<label>' . esc_html($label) . '<input type="' . $type . '" name="' . esc_attr($name) . '"></label>';
            }
            echo (\PESM\Support\I18n::text('<button type="button" class="button pesm-apply">اعمال ویژگی‌ها</button></div>'));
            echo '<p class="pesm-editor-status" role="status" aria-live="polite"></p></section>';
        }
        self::collectCard($cards, 'halls');
        ob_start();
        if (current_user_can('pesm_issue_tickets')) {
            echo (\PESM\Support\I18n::text('<h2>اصلاح مشخصات شرکت‌کننده بلیت صادرشده</h2>'));
            echo (\PESM\Support\I18n::text('<p>فقط بلیت فعال و استفاده‌نشده تا قبل از شروع جلسه قابل ویرایش است. تغییرات در تاریخچه ثبت می‌شوند و شماره بلیت، QR، مبلغ سفارش و سایر بلیت‌ها تغییر نمی‌کنند.</p>'));
            self::formStart($url, 'pesm_change_attendee');
            echo (\PESM\Support\I18n::text('<label>نوع بلیت <select name="ticket_type"><option value="seated">صندلی‌دار</option><option value="general">بدون صندلی</option></select></label> '));
            echo (\PESM\Support\I18n::text('<label>شناسه داخلی بلیت <input name="ticket_id" type="number" min="1" required></label> '));
            echo (\PESM\Support\I18n::text('<label>نام کامل جدید <input name="attendee_name" type="text" maxlength="190" required></label> '));
            echo '<input type="hidden" name="request_key" value="' . esc_attr(bin2hex(random_bytes(20))) . '">';
            submit_button((\PESM\Support\I18n::text('ثبت اصلاح مشخصات بلیت')), 'secondary', 'submit', false);
            echo '</form>';
        }
        self::collectCard($cards, 'tickets');
        ob_start();
        if ($centers && current_user_can('pesm_manage_events')) {
            echo (\PESM\Support\I18n::text('<h2>ایجاد رویداد</h2>'));
            self::formStart($url, 'pesm_create_event');
            echo (\PESM\Support\I18n::text('<label>مرکز ')); self::centerSelect($centers); echo '</label> ';
            echo (\PESM\Support\I18n::text('<label>عنوان رویداد <input type="text" name="title" maxlength="190" required></label> '));
            self::eventDetailsFields();
            submit_button((\PESM\Support\I18n::text('ایجاد رویداد پیش‌نویس')), 'secondary', 'submit', false);
            echo '</form>';
        }
        self::collectCard($cards, 'events');
        ob_start();
        if ($events && current_user_can('pesm_manage_sessions')) {
            echo (\PESM\Support\I18n::text('<h2>ایجاد جلسه</h2>'));
            self::formStart($url, 'pesm_create_session');
            echo (\PESM\Support\I18n::text('<label>رویداد <select name="event_id">'));
            foreach ($events as $event) {
                echo '<option value="' . esc_attr((string) $event->id) . '">' . esc_html($event->title . ' (#' . $event->id . ')') . '</option>';
            }
            echo (\PESM\Support\I18n::text('</select></label> <label>سالن <select name="hall_id"><option value="0">بدون سالن (فقط فروش آزاد)</option>'));
            foreach ($halls as $hall) {
                echo '<option value="' . esc_attr((string) $hall->id) . '">' . esc_html($hall->name . ' (#' . $hall->id . ')') . '</option>';
            }
            echo (\PESM\Support\I18n::text('</select></label><p><label>نوع فروش <select name="mode"><option value="seated">صندلی شماره‌دار</option><option value="general">ظرفیت آزاد (بدون صندلی)</option></select></label> '));
            echo (\PESM\Support\I18n::text('<label>ظرفیت فروش آزاد <input type="number" name="capacity" min="0" max="100000" value="0"></label> '));
            echo (\PESM\Support\I18n::text('<small>برای فروش آزاد، ظرفیت ۱ تا ۱۰۰٬۰۰۰ و برای فروش شماره‌دار مقدار صفر تعیین کنید. فروش آزاد فعلاً فقط رزرو آزمایشی دارد.</small></p>'));
            echo (\PESM\Support\I18n::text('<p>تاریخ‌ها بر اساس ساعت تهران وارد شوند.</p>'));
            echo (\PESM\Support\I18n::text('<p><label>قیمت هر صندلی در کوچک‌ترین واحد پول ووکامرس <input name="unit_price_minor" type="number" min="0" max="1000000000" value="0" required></label>'));
            echo (\PESM\Support\I18n::text('<span> قیمت 0: رویداد رایگان؛ قیمت مثلاً 150000: مبلغ بر حسب واحد جزء ارز فروشگاه</span></p>'));
            foreach (['start' => (\PESM\Support\I18n::text('زمان آغاز جلسه')), 'sale_start' => (\PESM\Support\I18n::text('شروع نگهداری صندلی')), 'sale_end' => (\PESM\Support\I18n::text('پایان نگهداری صندلی'))] as $field => $label) {
                echo '<label style="margin-left:15px">' . esc_html($label) . ' <input type="datetime-local" name="' . esc_attr($field) . '" required></label> ';
            }
            submit_button((\PESM\Support\I18n::text('ایجاد جلسه و موجودی')), 'secondary', 'submit', false);
            echo '</form>';
        }
        self::collectCard($cards, 'events');
        ob_start();
        if ($sessions && current_user_can('pesm_edit_layouts')) {
            echo (\PESM\Support\I18n::text('<h2>مسدودکردن یا آزادسازی صندلی جلسه</h2>'));
            echo (\PESM\Support\I18n::text('<p>فقط صندلی‌های آزاد یا رزرو موقت منقضی را می‌توان مسدود کرد. عملیات روی صندلی فروخته‌شده یا رزرو موقت فعال مجاز نیست.</p>'));
            self::formStart($url, 'pesm_seat_block');
            echo (\PESM\Support\I18n::text('<label>جلسه <select name="session_id">'));
            foreach ($sessions as $session) {
                echo '<option value="' . esc_attr((string) $session->id) . '">' . esc_html((string) $session->id) . '</option>';
            }
            echo '</select></label> ';
            echo (\PESM\Support\I18n::text('<label>کد صندلی <input name="seat_code" type="text" placeholder="A-12" pattern="[A-Za-z]{1,2}-[1-9][0-9]{0,2}" required></label> '));
            echo (\PESM\Support\I18n::text('<select name="blocked"><option value="1">مسدودکردن</option><option value="0">آزادسازی صندلی مسدود</option></select> '));
            submit_button((\PESM\Support\I18n::text('اعمال تغییر')), 'secondary', 'submit', false);
            echo '</form>';
        }
        self::collectCard($cards, 'halls');
        ob_start();
        if (current_user_can('pesm_issue_tickets') || current_user_can('pesm_manage_refunds')) {
            echo (\PESM\Support\I18n::text('<h2>انتقال صندلی بلیت صادرشده (بدون تغییر قیمت)</h2>'));
            echo (\PESM\Support\I18n::text('<p>تنها بلیت فعالِ استفاده‌نشده، پیش از شروع جلسه و با مقصد آزاد قابل انتقال است. '))
                . (\PESM\Support\I18n::text('QR قبلی باطل می‌شود. شناسه سفارش ووکامرس و مبلغ آن تغییر نمی‌کنند.</p>'));
            self::formStart($url, 'pesm_transfer_ticket_seat');
            echo (\PESM\Support\I18n::text('<label>شماره بلیت <input type="text" name="ticket_number" maxlength="18" pattern="P-[A-Fa-f0-9]{16}" placeholder="P-ABCDEF0123456789" required></label> '));
            echo (\PESM\Support\I18n::text('<label>کد صندلی مقصد <input type="text" name="seat_code" maxlength="60" placeholder="A-12" required></label> '));
            echo '<input type="hidden" name="request_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
            submit_button((\PESM\Support\I18n::text('جابه‌جایی امن صندلی')), 'secondary', 'submit', false);
            echo '</form>';
        }
        self::collectCard($cards, 'tickets');
        ob_start();
        if (current_user_can('pesm_issue_tickets') && (int)get_option('pesm_schema_version',0) >= 18) {
            echo (\PESM\Support\I18n::text('<h2>بسته چندجلسه‌ای بلیت‌های موجود (آزمایشی)</h2>'));
            echo (\PESM\Support\I18n::text('<p>فقط گروه‌بندی ۲ تا ۸ بلیت صندلی‌دارِ فعال از جلسات متفاوت همان رویداد برای یک شرکت‌کننده؛ '))
                . (\PESM\Support\I18n::text('خرید بسته، QR مشترک و استرداد بسته‌ای هنوز فعال نیستند.</p>'));
            self::formStart($url, 'pesm_create_ticket_bundle');
            echo (\PESM\Support\I18n::text('<label>شناسه عددی بلیت‌ها (با ویرگول جدا شوند) <input name="ticket_ids" type="text" '))
                . 'pattern="[0-9, ]+" placeholder="101, 102, 103" maxlength="200" required></label> ';
            echo '<input type="hidden" name="request_key" value="' . esc_attr(str_replace('-', '', wp_generate_uuid4())) . '">';
            submit_button((\PESM\Support\I18n::text('ایجاد گروه‌بندی بلیت‌های موجود')), 'secondary', 'submit', false);
            echo '</form>';
        }
        self::collectCard($cards, 'tickets');
        ob_start();
        if (current_user_can('pesm_manage_refunds')) {
            echo (\PESM\Support\I18n::text('<h2>لغو بلیت رایگان استفاده‌نشده</h2>'));
            echo (\PESM\Support\I18n::text('<p>فقط بلیت صفرریالیِ فعال، پیش از شروع جلسه لغو و صندلی آن آزاد می‌شود. '))
                . (\PESM\Support\I18n::text('بلیت‌های دیگر همان سفارش تغییری نمی‌کنند.</p>'));
            self::formStart($url, 'pesm_cancel_free_ticket');
            echo (\PESM\Support\I18n::text('<label>شماره بلیت <input type="text" name="ticket_number" maxlength="18" '))
                . 'pattern="P-[A-Fa-f0-9]{16}" required></label> ';
            submit_button((\PESM\Support\I18n::text('لغو بلیت رایگان')), 'secondary', 'submit', false);
            echo '</form>';
        }
        self::collectCard($cards, 'tickets');
        ob_start();
        if (current_user_can('pesm_manage_refunds') && (int)get_option('pesm_schema_version', 0) >= 15) {
            echo (\PESM\Support\I18n::text('<h2>استرداد بلیت پولی — تطبیق دستی با ووکامرس</h2>'));
            echo (\PESM\Support\I18n::text('<p><strong>این عملیات پول منتقل نمی‌کند.</strong> ابتدا درخواست را ثبت کنید تا ورود بلیت قفل شود؛ سپس دقیقاً همان آیتم را در سفارش ووکامرس استرداد کنید و دکمه تطبیق را بزنید. برای استرداد مالیات‌دار یا مبلغ نامنطبق از مسیر بررسی دستی استفاده کنید.</p>'));
            self::formStart($url, 'pesm_refund_request');
            echo (\PESM\Support\I18n::text('<label>شماره بلیت <input name="ticket_number" type="text" pattern="P-[A-Fa-f0-9]{16}" maxlength="18" required></label> '));
            echo (\PESM\Support\I18n::text('<label>علت استرداد <input name="reason" type="text" maxlength="500" minlength="10" required></label> '));
            echo '<input type="hidden" name="request_key" value="' . esc_attr(bin2hex(random_bytes(16))) . '">';
            submit_button((\PESM\Support\I18n::text('ثبت درخواست و توقف ورود')), 'secondary', 'submit', false);
            echo '</form>';
            self::formStart($url, 'pesm_refund_reconcile');
            echo (\PESM\Support\I18n::text('<label>شماره بلیت <input name="ticket_number" type="text" pattern="P-[A-Fa-f0-9]{16}" maxlength="18" required></label> '));
            submit_button((\PESM\Support\I18n::text('تطبیق با استرداد ثبت‌شده ووکامرس')), 'secondary', 'submit', false);
            echo '</form>';
        }
        self::collectCard($cards, 'tickets');
        ob_start();
        if ($centerIds && current_user_can('pesm_manage_refunds') && (int)get_option('pesm_schema_version',0) >= 15) {
            $placeholders = implode(',', array_fill(0, count($centerIds), '%d'));
            $cases = $wpdb->get_results($wpdb->prepare(
                "SELECT c.id,c.ticket_id,c.order_id,c.order_item_id,c.amount_minor,c.currency,c.status,
                    c.wc_refund_id,c.created_at,t.ticket_number
                 FROM {$p}refund_cases c JOIN {$p}tickets t ON t.id=c.ticket_id
                 JOIN {$p}sessions s ON s.id=t.session_id JOIN {$p}events e ON e.id=s.event_id
                 WHERE e.center_id IN ($placeholders) ORDER BY c.id DESC LIMIT 50", ...$centerIds
            ));
            echo (\PESM\Support\I18n::text('<h3>پرونده‌های استرداد بلیت (۵۰ مورد اخیر)</h3>'));
            echo (\PESM\Support\I18n::text('<p>وضعیت «نهایی» فقط به معنای تطبیق با سند استرداد ووکامرس و ابطال بلیت است؛ تسویه بانکی واقعی را به‌صورت مستقل بررسی کنید.</p>'));
            echo (\PESM\Support\I18n::text('<table class="widefat striped"><thead><tr><th>پرونده</th><th>بلیت</th><th>سفارش / آیتم</th><th>مبلغ جزء / ارز</th><th>وضعیت</th><th>سند استرداد ووکامرس</th></tr></thead><tbody>'));
            foreach ((array)$cases as $case) {
                echo '<tr><td>' . esc_html((string)$case->id) . '</td><td>' . esc_html((string)$case->ticket_number)
                    . '</td><td>' . esc_html((string)$case->order_id . ' / ' . (string)$case->order_item_id)
                    . '</td><td>' . esc_html((string)$case->amount_minor . ' / ' . (string)$case->currency)
                    . '</td><td>' . esc_html(\PESM\Support\I18n::status((string)$case->status)) . '</td><td>'
                    . esc_html((string)$case->wc_refund_id) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        self::collectCard($cards, 'tickets');
        ob_start();
        if (current_user_can('pesm_manage_centers') && $centers) {
            echo (\PESM\Support\I18n::text('<h2>اختصاص مدیر به مرکز</h2>'));
            self::formStart($url, 'pesm_assign_center');
            echo (\PESM\Support\I18n::text('<label>مرکز ')); self::centerSelect($centers); echo '</label> ';
            echo (\PESM\Support\I18n::text('<label>شناسه عددی کاربر وردپرس <input type="number" min="1" name="user_id" required></label> '));
            submit_button((\PESM\Support\I18n::text('افزودن دسترسی مرکز')), 'secondary', 'submit', false);
            echo (\PESM\Support\I18n::text('</form><p>اختصاص مرکز جایگزین Capability نیست؛ کاربر باید نقش و مجوز کاری متناسب داشته باشد.</p>'));
        }
        self::collectCard($cards, 'settings');
        ob_start();
        echo (\PESM\Support\I18n::text('<h2>رویدادها و مدیریت انتشار</h2><table class="widefat striped"><thead><tr><th>شناسه</th><th>عنوان</th><th>مرکز</th><th>وضعیت</th><th>تغییر وضعیت</th></tr></thead><tbody>'));
        foreach ($events as $event) {
            echo '<tr><td>' . esc_html((string) $event->id) . '</td><td>' . esc_html($event->title) . '</td><td>' . esc_html((string) $event->center_id) . '</td><td>' . esc_html(\PESM\Support\I18n::status($event->status)) . '</td><td>';
            if (current_user_can('pesm_manage_events')) { self::statusButtons($url, 'event', (int) $event->id, (string) $event->status); }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        self::collectCard($cards, 'events');
        ob_start();
        // Finance reviewers see only changes for the centers assigned to the current user.
        if ($centerIds && current_user_can('pesm_manage_refunds') && (int)get_option('pesm_schema_version',0) >= 14) {
            $placeholders = implode(',', array_fill(0, count($centerIds), '%d'));
            $changes = $wpdb->get_results($wpdb->prepare(
                "SELECT ch.id,ch.event_id,ch.session_id,ch.action,ch.reason,ch.affected_orders,ch.created_at
                 FROM {$p}event_changes ch JOIN {$p}events e ON e.id=ch.event_id
                 WHERE e.center_id IN ($placeholders) ORDER BY ch.id DESC LIMIT 40", ...$centerIds));
            echo (\PESM\Support\I18n::text('<h3>تغییرات رویداد و پرونده‌های مالی نیازمند رسیدگی</h3>'));
            echo (\PESM\Support\I18n::text('<p>این جدول فقط تشکیل پرونده را نشان می‌دهد. ارسال اعلان، استرداد وجه یا تسویه بانکی به‌صورت خودکار انجام نشده است.</p>'));
            echo (\PESM\Support\I18n::text('<table class="widefat striped"><thead><tr><th>تغییر</th><th>رویداد/جلسه</th><th>نوع</th><th>علت</th><th>سفارش نیازمند پیگیری</th></tr></thead><tbody>'));
            foreach ((array)$changes as $ch) {
                echo '<tr><td>' . esc_html((string)$ch->id) . '</td><td>' . esc_html((string)$ch->event_id . '/' . (string)$ch->session_id)
                    . '</td><td>' . esc_html((string)$ch->action) . '</td><td>' . esc_html((string)$ch->reason)
                    . '</td><td>' . esc_html((string)$ch->affected_orders) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        self::collectCard($cards, 'tickets');
        ob_start();
        if (current_user_can('pesm_manage_events')) {
            foreach ($events as $event) {
                if ($event->status === 'cancelled') { continue; }
                echo (\PESM\Support\I18n::text('<details><summary>ویرایش مشخصات رویداد ')) . esc_html($event->title) . '</summary>';
                self::formStart($url, 'pesm_edit_event');
                echo '<input type="hidden" name="event_id" value="' . esc_attr((string) $event->id) . '">';
                echo (\PESM\Support\I18n::text('<label>عنوان <input name="title" type="text" maxlength="190" value="')) . esc_attr($event->title) . '" required></label>';
                self::eventDetailsFields($event);
                submit_button((\PESM\Support\I18n::text('ذخیره تغییرات')), 'secondary', 'submit', false);
                echo '</form></details>';
            }
        }
        self::collectCard($cards, 'events');
        ob_start();
        echo (\PESM\Support\I18n::text('<h2>سالن‌ها</h2><table class="widefat striped"><thead><tr><th>شناسه</th><th>نام</th><th>مرکز</th><th>نسخه نقشه</th></tr></thead><tbody>'));
        foreach ($halls as $hall) {
            echo '<tr><td>' . esc_html((string) $hall->id) . '</td><td>' . esc_html($hall->name) . '</td><td>' . esc_html((string) $hall->center_id) . '</td><td>' . esc_html((string) $hall->current_layout_id) . '</td></tr>';
        }
        echo '</tbody></table>';
        self::collectCard($cards, 'halls');
        ob_start();
        echo (\PESM\Support\I18n::text('<h2>جلسات</h2><table class="widefat striped"><thead><tr><th>شناسه</th><th>رویداد</th><th>سالن</th><th>وضعیت</th><th>انتشار</th><th>قیمت صندلی (واحد جزء)</th><th>کد استفاده در صفحه سایت</th></tr></thead><tbody>'));
        foreach ($sessions as $session) {
            echo '<tr><td>' . esc_html((string) $session->id) . '</td><td>' . esc_html((string) $session->event_id) . '</td><td>' . esc_html((string) $session->hall_id) . '</td><td>' . esc_html(\PESM\Support\I18n::status($session->status)) . '</td><td>';
            if (current_user_can('pesm_manage_sessions')) { self::statusButtons($url, 'session', (int) $session->id, (string) $session->status); }
            echo '</td><td>' . esc_html((string) $session->unit_price_minor) . '</td><td><code>' . esc_html('[pesm_seat_map session="' . $session->id . '"]') . '</code></td></tr>';
        }
        echo '</tbody></table>';
        self::collectCard($cards, 'events');
        ob_start();
        if ($sessions && current_user_can('pesm_manage_sessions')) {
            echo (\PESM\Support\I18n::text('<h3>تغییر زمان جلسه (بدون لغو بلیت)</h3><p>زمان را به UTC وارد کنید. تغییر تاریخ پس از ثبت ورود یا با وجود رزرو موقت فعال مجاز نیست. سفارش‌های متأثر برای رسیدگی به اطلاع‌رسانی و امور مالی ثبت می‌شوند؛ بازپرداخت یا ارسال اعلان خودکار انجام نمی‌شود.</p>'));
            self::formStart($url, 'pesm_postpone_session');
            echo (\PESM\Support\I18n::text('<label>جلسه <select name="session_id">'));
            foreach ($sessions as $session) { if ($session->status !== 'cancelled') {
                echo '<option value="' . esc_attr((string)$session->id) . '">' . esc_html((string)$session->id) . '</option>';
            }}
            echo (\PESM\Support\I18n::text('</select></label> <label>زمان جدید UTC <input type="datetime-local" name="new_starts_at" required></label> '));
            echo (\PESM\Support\I18n::text('<label>علت تغییر <input type="text" name="reason" minlength="10" maxlength="500" required></label> '));
            echo '<input type="hidden" name="request_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
            submit_button((\PESM\Support\I18n::text('ثبت تغییر تاریخ و تشکیل پرونده‌های پیگیری')), 'secondary', 'submit', false);
            echo '</form>';
        }
        self::collectCard($cards, 'events');
        ob_start();
        if ($sessions && current_user_can('pesm_manage_sessions')) {
            echo (\PESM\Support\I18n::text('<h3>قوانین انتخاب صندلی (فقط قبل از اولین نگهداری موقت)</h3>'));
            foreach ($sessions as $session) {
                self::formStart($url, 'pesm_seating_rules');
                echo (\PESM\Support\I18n::text('<label>جلسه ')) . esc_html((string) $session->id) . '</label> ';
                echo '<input type="hidden" name="session_id" value="' . esc_attr((string) $session->id) . '">';
                echo '<label><input type="checkbox" name="require_adjacent" value="1" ' . checked((int) ($session->require_adjacent ?? 0), 1, false) . (\PESM\Support\I18n::text('> الزام صندلی‌های مجاور</label> '));
                echo '<label><input type="checkbox" name="avoid_single_gap" value="1" ' . checked((int) ($session->avoid_single_gap ?? 0), 1, false) . (\PESM\Support\I18n::text('> جلوگیری از ایجاد صندلی خالیِ تک</label> '));
                submit_button((\PESM\Support\I18n::text('ذخیره قوانین جلسه')), 'secondary', 'submit', false);
                echo '</form>';
            }
        }
        self::collectCard($cards, 'events');
        ob_start();
        if ($sessions && current_user_can('pesm_view_reports')) {
            $eventCenters=[];
            foreach ($events as $event) $eventCenters[(int)$event->id]=(int)$event->center_id;
            echo ('<section class="pesm-seat-status-report" ' . 'dir="' . \PESM\Support\I18n::direction() . '"' . \PESM\Support\I18n::text('><h2>نقشه زنده مدیریتی صندلی‌ها</h2>'));
            echo (\PESM\Support\I18n::text('<p>اطلاعات بدون نام یا شماره خریدار، از وضعیت موجودی جلسه دریافت می‌شود. هر ۳۰ ثانیه تازه‌سازی می‌گردد.</p>'));
            echo (\PESM\Support\I18n::text('<label>انتخاب جلسه صندلی‌دار <select class="pesm-status-session"><option value="">انتخاب کنید</option>'));
            foreach ($sessions as $session) {
                if (($session->mode ?? 'seated') !== 'seated') continue;
                $center=(int)($eventCenters[(int)$session->event_id]??0);
                if ($center<1) continue;
                echo '<option value="' . esc_attr($center . ':' . (int)$session->id) . (\PESM\Support\I18n::text('">جلسه ')) . esc_html((string)$session->id) . '</option>';
            }
            echo (\PESM\Support\I18n::text('</select></label> <button type="button" class="button pesm-seat-status-refresh">تازه‌سازی</button>'));
            echo '<p class="pesm-seat-status-message" role="status" aria-live="polite"></p>';
            echo '<p class="pesm-seat-status-totals" aria-live="polite"></p>';
            echo '<div class="pesm-seat-status-scroll" style="overflow:auto;max-height:420px;border:1px solid #cbd5e1">';
            echo (\PESM\Support\I18n::text('<svg class="pesm-seat-status-svg" role="img" aria-label="نقشه وضعیت صندلی‌های جلسه" style="min-width:100%;height:400px"></svg></div>'));
            echo '<div class="pesm-seat-status-groups"></div></section>';
        }
        self::collectCard($cards, 'reports');
        ob_start();
        if ($sessions && $centers && current_user_can('pesm_view_reports')) {
            wp_enqueue_script('pesm-sales-insights', PESM_URL . 'assets/js/sales-insights.js', ['pesm-i18n'], PESM_VERSION, true);
            echo (\PESM\Support\I18n::text('<h2>گزارش تحلیلی صدور بلیت و جایگاه‌ها</h2>'));
            foreach (array_slice($centers, 0, 5) as $center) {
                $cid = (int)$center->id;
                $rows = [];
                foreach ($sessions as $session) {
                    if ((int)($eventCenters[(int)$session->event_id] ?? 0) === $cid) {
                        $rows[] = ['session_id'=>(int)$session->id,'title'=>(\PESM\Support\I18n::text('جلسه ')) . (int)$session->id];
                    }
                }
                echo \PESM\Frontend\Shortcodes::insightsWidget($cid, $rows);
            }
        }
        self::collectCard($cards, 'reports');
        ob_start();
        if ($sessions && current_user_can('pesm_view_reports')) {
            $ids = array_map(static fn($s): int => (int) $s->id, $sessions);
            $report = self::capacityRows($ids);
            echo (\PESM\Support\I18n::text('<h2>گزارش ظرفیت جلسات (برخط)</h2><p>آمار این جدول صرفاً وضعیت موجودی صندلی است و درآمد مالی را نشان نمی‌دهد.</p>'));
            echo (\PESM\Support\I18n::text('<table class="widefat striped"><thead><tr><th>جلسه</th><th>کل</th><th>آزاد</th><th>نگهداری فعال</th><th>انتظار پرداخت</th><th>فروخته</th><th>مسدود</th></tr></thead><tbody>'));
            foreach ($report as $r) {
                echo '<tr>';
                foreach (['session_id', 'total', 'available', 'held', 'payment_pending', 'sold', 'blocked'] as $key) {
                    echo '<td>' . esc_html((string) $r->$key) . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
            self::formStart($url, 'pesm_export_capacity');
            submit_button((\PESM\Support\I18n::text('دریافت CSV آمار ظرفیت مراکز مجاز')), 'secondary', 'submit', false);
            echo '</form>';
        }
        self::collectCard($cards, 'reports');
        ob_start();
        if ($centerIds && current_user_can('pesm_view_orders')) {
            $centerPh = implode(',', array_fill(0, count($centerIds), '%d'));
            $reviewRows = $wpdb->get_results($wpdb->prepare(
                "SELECT ol.order_id,ol.status,h.id AS hold_id,h.session_id
                 FROM {$p}order_links ol JOIN {$p}holds h ON h.id=ol.hold_id
                 JOIN {$p}sessions ss ON ss.id=h.session_id JOIN {$p}events e ON e.id=ss.event_id
                 WHERE e.center_id IN ($centerPh) AND ol.status='review_required'
                 ORDER BY ol.id DESC LIMIT 100", ...$centerIds
            ));
            $staleHolds = $wpdb->get_results($wpdb->prepare(
                "SELECT h.id AS hold_id,h.session_id,h.order_id FROM {$p}holds h
                 JOIN {$p}sessions ss ON ss.id=h.session_id JOIN {$p}events e ON e.id=ss.event_id
                 WHERE e.center_id IN ($centerPh) AND h.status='checkout_creating' AND h.expires_at<UTC_TIMESTAMP()
                 ORDER BY h.id DESC LIMIT 100", ...$centerIds
            ));
            echo (\PESM\Support\I18n::text('<h2>سفارش‌های نیازمند تطبیق دستی</h2>'));
            echo (\PESM\Support\I18n::text('<p>پرداخت، بازگشت وجه و وضعیت نهایی سفارش‌ها را در ووکامرس بررسی کنید. این فهرست هیچ اقدامی خودکار انجام نمی‌دهد.</p>'));
            echo (\PESM\Support\I18n::text('<table class="widefat striped"><thead><tr><th>سفارش</th><th>رزرو</th><th>جلسه</th><th>علت</th></tr></thead><tbody>'));
            foreach ($reviewRows as $row) {
                echo '<tr><td>' . esc_html((string) $row->order_id) . '</td><td>' . esc_html((string) $row->hold_id)
                  . '</td><td>' . esc_html((string) $row->session_id) . (\PESM\Support\I18n::text('</td><td>نیازمند بررسی پرداخت</td></tr>'));
            }
            foreach ($staleHolds as $row) {
                echo '<tr><td>' . esc_html((string) $row->order_id) . '</td><td>' . esc_html((string) $row->hold_id)
                  . '</td><td>' . esc_html((string) $row->session_id) . (\PESM\Support\I18n::text('</td><td>ناتمام‌ماندن ایجاد سفارش</td></tr>'));
            }
            echo '</tbody></table>';
        }
        self::collectCard($cards, 'tickets');
        self::renderWorkspace($cards, $centers, $halls, $events, $sessions);
    }

    /** Collect existing permitted content without changing its form or service contracts. */
    private static function collectCard(array &$cards, string $view): void
    {
        $html = (string) ob_get_clean();
        if (trim($html) === '') { return; }
        $html = preg_replace_callback('~<table\b[^>]*>.*?</table>~s', static function (array $match): string {
            $table = str_replace('<th>', '<th scope="col">', $match[0]);
            preg_match_all('/<th(?:\s|>)/', $table, $headers);
            $columns = max(1, count($headers[0]));
            $empty = '<tr><td class="pesm-table-empty" colspan="' . $columns . '">'
                . esc_html(\PESM\Support\I18n::text('هنوز موردی ثبت نشده است.')) . '</td></tr>';
            $table = preg_replace('~(<tbody\b[^>]*>)\s*(</tbody>)~', '$1' . $empty . '$2', $table) ?? $table;
            return '<div class="pesm-table-scroll" tabindex="0">' . $table . '</div>';
        }, $html) ?? $html;
        $returnField = '<input type="hidden" name="pesm_view" value="' . esc_attr($view) . '">';
        $html = preg_replace('~(<form\b[^>]*>)~', '$1' . $returnField, $html) ?? $html;
        if (str_starts_with(ltrim($html), '<section class="pesm-layout-editor"')) {
            // Keep the hidden editor as the card itself, so no blank card is shown before opening it.
            $cards[$view][] = str_replace('class="pesm-layout-editor"', 'class="pesm-layout-editor pesm-admin-card"', $html);
        } else {
            $cards[$view][] = '<article class="pesm-admin-card">' . $html . '</article>';
        }
    }

    /** @param array<string,list<string>> $cards */
    private static function renderWorkspace(array $cards, array $centers, array $halls, array $events, array $sessions): void
    {
        $views = [
            'overview' => ['title' => \PESM\Support\I18n::text('نمای کلی'), 'icon' => 'grid',
                'description' => \PESM\Support\I18n::text('رویدادها، سالن‌ها و کارهای بعدی را از یک نمای روشن دنبال کنید.')],
            'events' => ['title' => \PESM\Support\I18n::text('رویدادها و جلسات'), 'icon' => 'calendar',
                'description' => \PESM\Support\I18n::text('رویداد بسازید، زمان فروش را تنظیم کنید و وضعیت انتشار را مدیریت کنید.')],
            'halls' => ['title' => \PESM\Support\I18n::text('سالن‌ها و چیدمان'), 'icon' => 'seat',
                'description' => \PESM\Support\I18n::text('سالن، نسخه‌های نقشه و جایگاه‌های قابل رزرو را در یک بخش مدیریت کنید.')],
            'tickets' => ['title' => \PESM\Support\I18n::text('بلیت و استرداد'), 'icon' => 'ticket',
                'description' => \PESM\Support\I18n::text('مشخصات بلیت، جابه‌جایی صندلی و پرونده‌های مالی را با دسترسی مجاز بررسی کنید.')],
            'reports' => ['title' => \PESM\Support\I18n::text('گزارش‌ها'), 'icon' => 'chart',
                'description' => \PESM\Support\I18n::text('ظرفیت و روند صدور بلیت را بررسی کنید. این آمار جایگزین گزارش مالی ووکامرس نیست.')],
            'settings' => ['title' => \PESM\Support\I18n::text('تنظیمات'), 'icon' => 'settings',
                'description' => \PESM\Support\I18n::text('مرکزها، دسترسی مدیران و تنظیمات اعلان را سامان دهید.')],
        ];
        foreach (array_keys($views) as $key) {
            if ($key !== 'overview' && empty($cards[$key])) { unset($views[$key]); }
        }
        echo '<div class="wrap pesm-ui pesm-admin" dir="' . esc_attr(\PESM\Support\I18n::direction()) . '">';
        echo '<a class="pesm-admin-skip" href="#pesm-workspace-content">' . esc_html(\PESM\Support\I18n::text('رفتن به محتوای اصلی')) . '</a>';
        echo '<header class="pesm-admin-topbar"><div class="pesm-admin-brand">' . \PESM\Support\Ui::icon('ticket')
            . '<span>' . esc_html(\PESM\Support\I18n::text('رویداد و صندلی')) . '</span></div>'
            . '<span class="pesm-admin-version">v' . esc_html(PESM_VERSION) . '</span></header>';
        echo '<div class="pesm-admin-shell"><aside class="pesm-admin-sidebar">';
        echo '<p class="pesm-nav-label">' . esc_html(\PESM\Support\I18n::text('فضای مدیریت')) . '</p>';
        echo '<nav aria-label="' . esc_attr(\PESM\Support\I18n::text('بخش‌های مدیریت رویداد')) . '">';
        foreach ($views as $key => $view) {
            echo '<a class="pesm-admin-nav-link" data-pesm-nav="' . esc_attr($key) . '" data-description="'
                . esc_attr($view['description']) . '" href="#pesm-' . esc_attr($key) . '"'
                . ($key === 'overview' ? ' aria-current="page"' : '') . '>'
                . \PESM\Support\Ui::icon($view['icon']) . '<span>' . esc_html($view['title']) . '</span></a>';
        }
        echo '</nav><div class="pesm-sidebar-note">' . \PESM\Support\Ui::icon('check')
            . '<p>' . esc_html(\PESM\Support\I18n::text('فقط اطلاعات و ابزارهای مراکز مجاز شما نمایش داده می‌شوند.')) . '</p></div></aside>';
        echo '<main class="pesm-admin-main" id="pesm-workspace-content" tabindex="-1"><header class="pesm-admin-page-header">';
        echo '<p class="pesm-admin-eyebrow">' . esc_html(\PESM\Support\I18n::text('مدیریت رویداد و صندلی')) . '</p>';
        echo '<h1 tabindex="-1" data-pesm-view-title>' . esc_html($views['overview']['title']) . '</h1>';
        echo '<p data-pesm-view-description>' . esc_html($views['overview']['description']) . '</p></header>';
        $notice = isset($_GET['pesm_notice']) && is_string($_GET['pesm_notice']) ? sanitize_key(wp_unslash($_GET['pesm_notice'])) : '';
        $messages = ['created' => \PESM\Support\I18n::text('عملیات با موفقیت ثبت شد.'), 'invalid' => \PESM\Support\I18n::text('اطلاعات واردشده نامعتبر است.'),
            'forbidden' => \PESM\Support\I18n::text('مجوز کافی برای این مرکز وجود ندارد.'), 'error' => \PESM\Support\I18n::text('عملیات انجام نشد؛ جزئیات در گزارش خطا ثبت نشده و نیازمند بررسی است.')];
        if (isset($messages[$notice])) {
            echo '<div class="pesm-admin-result ' . ($notice === 'created' ? 'pesm-admin-result-success' : 'pesm-admin-result-error')
                . '" role="status">' . \PESM\Support\Ui::icon($notice === 'created' ? 'check' : 'clock')
                . '<p>' . esc_html($messages[$notice]) . '</p></div>';
        }
        echo '<section class="pesm-admin-panel" id="pesm-overview" data-pesm-panel="overview" aria-label="'
            . esc_attr($views['overview']['title']) . '">';
        echo '<div class="pesm-admin-stats">';
        foreach ([['users', count($centers), 'مرکزهای در دسترس'], ['seat', count($halls), 'سالن‌های در دسترس'],
            ['calendar', count($events), 'رویدادهای در دسترس'], ['clock', count($sessions), 'جلسه‌های اخیر']] as [$icon, $count, $label]) {
            echo '<article class="pesm-admin-stat"><span class="pesm-stat-icon">' . \PESM\Support\Ui::icon($icon)
                . '</span><div><strong>' . esc_html((string) $count) . '</strong><span>' . esc_html(\PESM\Support\I18n::text($label)) . '</span></div></article>';
        }
        echo '</div><div class="pesm-overview-grid"><article class="pesm-admin-card pesm-next-step"><span class="pesm-overview-icon">'
            . \PESM\Support\Ui::icon('calendar') . '</span><h2>' . esc_html(\PESM\Support\I18n::text('کار بعدی شما')) . '</h2>';
        if (!$centers) {
            echo '<p>' . esc_html(\PESM\Support\I18n::text('برای شروع، یک مرکز ایجاد کنید یا از مدیر کل بخواهید دسترسی مرکز را به شما بدهد.')) . '</p>';
            $next = 'settings';
        } elseif (!$events) {
            echo '<p>' . esc_html(\PESM\Support\I18n::text('زیرساخت شما آماده است؛ نخستین رویداد را بسازید و جلسه‌های آن را مشخص کنید.')) . '</p>';
            $next = 'events';
        } elseif (!$sessions) {
            echo '<p>' . esc_html(\PESM\Support\I18n::text('برای رویداد خود جلسه، زمان فروش و نوع پذیرش تعریف کنید.')) . '</p>';
            $next = 'events';
        } else {
            echo '<p>' . esc_html(\PESM\Support\I18n::text('رویدادها و جلسه‌های شما در دسترس‌اند. انتشار و بازه فروش را پیش از معرفی به مخاطبان بررسی کنید.')) . '</p>';
            $next = 'events';
        }
        if (isset($views[$next])) {
            echo '<a class="pesm-button pesm-button-primary" data-pesm-nav="' . esc_attr($next) . '" href="#pesm-' . esc_attr($next) . '">'
                . esc_html($views[$next]['title']) . \PESM\Support\Ui::icon('arrow') . '</a>';
        }
        echo '</article><article class="pesm-admin-card pesm-start-guide"><h2>' . esc_html(\PESM\Support\I18n::text('مسیر آماده‌سازی رویداد')) . '</h2><ol>';
        foreach ([['settings', 'مرکز و دسترسی‌ها'], ['halls', 'سالن و نقشه صندلی'], ['events', 'رویداد و جلسه'], ['reports', 'بررسی ظرفیت و حضور']] as [$target, $label]) {
            if (!isset($views[$target])) { continue; }
            echo '<li><a data-pesm-nav="' . esc_attr($target) . '" href="#pesm-' . esc_attr($target) . '">'
                . esc_html(\PESM\Support\I18n::text($label)) . \PESM\Support\Ui::icon('chevron') . '</a></li>';
        }
        echo '</ol></article></div>';
        echo '<article class="pesm-admin-card pesm-recent-events"><div class="pesm-card-title"><h2>'
            . esc_html(\PESM\Support\I18n::text('رویدادهای اخیر')) . '</h2>';
        if (isset($views['events'])) {
            echo '<a data-pesm-nav="events" href="#pesm-events">' . esc_html(\PESM\Support\I18n::text('مدیریت رویدادها')) . \PESM\Support\Ui::icon('arrow') . '</a>';
        }
        echo '</div>';
        if (!$events) {
            echo '<div class="pesm-admin-empty">' . \PESM\Support\Ui::icon('calendar') . '<p>'
                . esc_html(\PESM\Support\I18n::text('هنوز رویدادی برای نمایش وجود ندارد.')) . '</p></div>';
        } else {
            echo '<ul class="pesm-recent-list">';
            foreach (array_slice($events, 0, 6) as $event) {
                echo '<li><span class="pesm-recent-icon">' . \PESM\Support\Ui::icon('calendar') . '</span><div><strong>' . esc_html((string) $event->title)
                    . '</strong><span>#' . esc_html((string) $event->id) . '</span></div><span class="pesm-admin-status pesm-status-' . esc_attr((string) $event->status) . '">'
                    . esc_html(\PESM\Support\I18n::status((string) $event->status)) . '</span></li>';
            }
            echo '</ul>';
        }
        echo '</article><details class="pesm-admin-release"><summary>' . esc_html(\PESM\Support\I18n::text('وضعیت پذیرش نسخهٔ 2.0.0')) . '</summary><p>'
            . esc_html(\PESM\Support\I18n::text('نسخهٔ 2.0.0؛ پیش از فعال‌سازی فروش واقعی، گزارش پذیرش انتشار را بررسی کنید.')) . '</p></details></section>';
        foreach ($cards as $key => $items) {
            if (!$items || !isset($views[$key])) { continue; }
            echo '<section class="pesm-admin-panel" id="pesm-' . esc_attr($key) . '" data-pesm-panel="' . esc_attr($key)
                . '" aria-label="' . esc_attr($views[$key]['title']) . '">' . implode('', $items) . '</section>';
        }
        echo '</main></div></div>';
    }

    /** Notification settings preserve the existing opt-in behavior. */
    private static function renderNotificationSettings(string $url): void
    {
        $enabled = get_option('pesm_notifications_enabled', 'no') === 'yes';
        $pattern = (int) get_option('pesm_sms_ticket_confirmed_pattern', 0);
        $credentials = defined('PESM_SMS_USERNAME') && defined('PESM_SMS_PASSWORD') ? (\PESM\Support\I18n::text('موجود')) : (\PESM\Support\I18n::text('تنظیم نشده'));
        $summary = (int) get_option('pesm_schema_version', 0) >= 11
            ? \PESM\Domain\Notification\NotificationQueue::summary() : [];
        echo (\PESM\Support\I18n::text('<h2>اعلان بلیت (آزمایشی)</h2>'));
        echo (\PESM\Support\I18n::text('<p>ارسال پیامک فقط در حالت فعال، با اعتبارنامه‌های wp-config.php و شناسه پترن تاییدشده انجام می‌شود. وضعیت اعتبارنامه: <strong>')) . esc_html($credentials) . '</strong></p>';
        echo '<form action="' . esc_url($url) . '" method="post">';
        echo '<input type="hidden" name="action" value="pesm_save_notification_settings">';
        wp_nonce_field('pesm_save_notification_settings');
        echo '<label><input type="checkbox" name="notifications_enabled" value="yes" ' . checked($enabled, true, false) . (\PESM\Support\I18n::text('> فعال‌سازی صف اعلان (صرفاً Staging)</label> '));
        echo (\PESM\Support\I18n::text('<label>شناسه پترن تأیید بلیت <input type="number" name="ticket_pattern" min="0" max="2147483647" value="')) . esc_attr((string) $pattern) . '"></label> ';
        submit_button((\PESM\Support\I18n::text('ذخیره تنظیمات اعلان')), 'secondary', 'submit', false);
        echo (\PESM\Support\I18n::text('</form><p>صف: ')) . esc_html(wp_json_encode($summary)) . (\PESM\Support\I18n::text(' (فقط تعداد، بدون شماره یا ایمیل). برای پردازش، WP-Cron باید اجرا شود.</p>'));
    }

    public static function saveNotificationSettings(): void
    {
        if (!current_user_can('pesm_manage_sms')) { wp_die((\PESM\Support\I18n::text('دسترسی غیرمجاز.')), '', ['response'=>403]); }
        check_admin_referer('pesm_save_notification_settings');
        $raw = isset($_POST['ticket_pattern']) ? wp_unslash((string) $_POST['ticket_pattern']) : '0';
        if (!preg_match('/^[0-9]{1,10}$/D', $raw) || (float) $raw > 2147483647) {
            wp_die((\PESM\Support\I18n::text('شناسه پترن معتبر نیست.')), '', ['response'=>422]);
        }
        update_option('pesm_sms_ticket_confirmed_pattern', (int) $raw, false);
        update_option('pesm_notifications_enabled', isset($_POST['notifications_enabled']) && $_POST['notifications_enabled'] === 'yes' ? 'yes' : 'no', false);
        self::redirect('created');
    }

    private static function capacityRows(array $sessionIds): array
    {
        if (!$sessionIds) { return []; }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $ph = implode(',', array_fill(0, count($sessionIds), '%d'));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT session_id,COUNT(*) AS total,
             SUM(CASE WHEN status='available' OR (status IN ('held','payment_pending') AND expires_at<=UTC_TIMESTAMP()) THEN 1 ELSE 0 END) AS available,
             SUM(CASE WHEN status='held' AND expires_at>UTC_TIMESTAMP() THEN 1 ELSE 0 END) AS held,
             SUM(CASE WHEN status='payment_pending' AND expires_at>UTC_TIMESTAMP() THEN 1 ELSE 0 END) AS payment_pending,
             SUM(CASE WHEN status='sold' THEN 1 ELSE 0 END) AS sold,
             SUM(CASE WHEN status='blocked' THEN 1 ELSE 0 END) AS blocked
             FROM {$p}session_seats WHERE session_id IN ($ph) GROUP BY session_id
             UNION ALL
             SELECT c.session_id,c.total_capacity AS total,
               c.total_capacity-c.blocked_units-c.held_units-c.pending_units-c.sold_units+COALESCE(x.expired_units,0) AS available,
               c.held_units-COALESCE(x.expired_units,0) AS held,
               c.pending_units AS payment_pending,c.sold_units AS sold,c.blocked_units AS blocked
             FROM {$p}session_capacity c
             LEFT JOIN (SELECT session_id,SUM(quantity) AS expired_units FROM {$p}capacity_holds
               WHERE status='active' AND expires_at<=UTC_TIMESTAMP() GROUP BY session_id) x ON x.session_id=c.session_id
             WHERE c.session_id IN ($ph) ORDER BY session_id",
            ...array_merge($sessionIds, $sessionIds)
        ));
    }

    public static function exportCapacity(): void
    {
        self::checked('pesm_export_capacity', 'pesm_view_reports');
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        if (current_user_can('pesm_manage_centers')) {
            $sessionIds = array_map('intval', $wpdb->get_col("SELECT s.id FROM {$p}sessions s JOIN {$p}events e ON e.id=s.event_id ORDER BY s.id LIMIT 5000"));
        } else {
            $sessionIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT s.id FROM {$p}sessions s JOIN {$p}events e ON e.id=s.event_id
                 JOIN {$p}center_users cu ON cu.center_id=e.center_id
                 WHERE cu.user_id=%d ORDER BY s.id LIMIT 5000", get_current_user_id()
            )));
        }
        $rows = self::capacityRows($sessionIds);
        if (headers_sent()) { wp_die((\PESM\Support\I18n::text('خروجی CSV ممکن نیست.'))); }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="pesm-capacity-' . gmdate('Ymd') . '.csv"');
        $output = fopen('php://output', 'wb');
        if (!$output) { wp_die((\PESM\Support\I18n::text('امکان تولید گزارش وجود ندارد.'))); }
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['session_id','total','available','held','payment_pending','sold','blocked']);
        foreach ($rows as $row) {
            // Numeric-only export; no user-supplied cells or spreadsheet formulas.
            fputcsv($output, array_map(static fn($key): int => (int) $row->$key,
                ['session_id','total','available','held','payment_pending','sold','blocked']));
        }
        fclose($output);
        exit;
    }

    /** Renders only transitions accepted by the domain state machine. */
    private static function statusButtons(string $url, string $entity, int $id, string $current): void
    {
        $labels = ['published' => (\PESM\Support\I18n::text('انتشار/ادامه')), 'paused' => (\PESM\Support\I18n::text('توقف')), 'cancelled' => (\PESM\Support\I18n::text('لغو'))];
        $targets = LifecyclePolicy::allowedTargets($current);
        foreach ($targets as $status) {
            $label = $labels[$status];
            self::formStart($url, 'pesm_change_status');
            echo '<input type="hidden" name="entity" value="' . esc_attr($entity) . '">';
            echo '<input type="hidden" name="entity_id" value="' . esc_attr((string) $id) . '">';
            echo '<input type="hidden" name="target_status" value="' . esc_attr($status) . '">';
            if ($status === 'cancelled') {
                echo (\PESM\Support\I18n::text('<label> علت لغو <input name="reason" type="text" minlength="10" maxlength="500" required></label>'));
                echo '<input type="hidden" name="request_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
            }
            echo '<button class="button button-secondary pesm-action-' . esc_attr($status) . '" type="submit">' . esc_html($label) . '</button></form>';
        }
    }

    private static function eventDetailsFields(?object $existing = null): void
    {
        $fields = [
            'organizer' => (\PESM\Support\I18n::text('برگزارکننده')), 'venue' => (\PESM\Support\I18n::text('نشانی محل')), 'contact' => (\PESM\Support\I18n::text('اطلاعات تماس')),
            'description' => (\PESM\Support\I18n::text('معرفی رویداد')), 'admission_terms' => (\PESM\Support\I18n::text('شرایط حضور')),
        ];
        foreach ($fields as $field => $label) {
            $value = $existing ? (string) ($existing->$field ?? '') : '';
            echo '<p><label>' . esc_html($label) . ' ';
            if (in_array($field, ['description', 'admission_terms'], true)) {
                echo '<textarea name="' . esc_attr($field) . '" rows="3" maxlength="3000" style="width:100%;max-width:600px">' . esc_textarea($value) . '</textarea>';
            } else {
                echo '<input name="' . esc_attr($field) . '" type="text" maxlength="190" size="38" value="' . esc_attr($value) . '">';
            }
            echo '</label></p>';
        }
        echo (\PESM\Support\I18n::text('<p><label>شناسه تصویر پوستر در رسانه وردپرس <input name="poster_attachment_id" type="number" min="0" value="'))
            . esc_attr((string) ($existing->poster_attachment_id ?? 0)) . '"></label></p>';
    }

    /** @return array<string,mixed> */
    private static function eventDetailsInput(): array
    {
        $values = [];
        foreach (\PESM\Domain\Event\EventDetails::FIELDS as $field) {
            $value = isset($_POST[$field]) && is_string($_POST[$field]) ? wp_unslash($_POST[$field]) : '';
            $values[$field] = in_array($field, ['description', 'admission_terms'], true)
                ? sanitize_textarea_field($value) : sanitize_text_field($value);
        }
        $values['poster_attachment_id'] = isset($_POST['poster_attachment_id'])
            ? absint(wp_unslash($_POST['poster_attachment_id'])) : 0;
        return $values;
    }

    public static function editEvent(): void
    {
        self::checked('pesm_edit_event', 'pesm_manage_events');
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $id = isset($_POST['event_id']) ? absint(wp_unslash($_POST['event_id'])) : 0;
        $center = (int) $wpdb->get_var($wpdb->prepare("SELECT center_id FROM {$p}events WHERE id=%d", $id));
        if (!Capabilities::centerAllowed($center, get_current_user_id())) { self::redirect('forbidden'); }
        try {
            EventEditor::update($id, self::textInput('title'), self::eventDetailsInput(), get_current_user_id());
            self::redirect('created');
        } catch (Throwable $e) { self::redirect('error'); }
    }

    public static function changeStatus(): void
    {
        $entity = isset($_POST['entity']) && is_string($_POST['entity']) ? sanitize_key(wp_unslash($_POST['entity'])) : '';
        $target = isset($_POST['target_status']) && is_string($_POST['target_status']) ? sanitize_key(wp_unslash($_POST['target_status'])) : '';
        $id = isset($_POST['entity_id']) ? absint(wp_unslash($_POST['entity_id'])) : 0;
        self::checked('pesm_change_status', $entity === 'event' ? 'pesm_manage_events' : 'pesm_manage_sessions');
        if ($id < 1 || !in_array($entity, ['event', 'session'], true)) {
            self::redirect('invalid');
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $sql = $entity === 'event'
            ? "SELECT center_id FROM {$p}events WHERE id = %d"
            : "SELECT e.center_id FROM {$p}sessions s JOIN {$p}events e ON e.id = s.event_id WHERE s.id = %d";
        $centerId = (int) $wpdb->get_var($wpdb->prepare($sql, $id));
        if (!Capabilities::centerAllowed($centerId, get_current_user_id())) {
            self::redirect('forbidden');
        }
        try {
            if ($target === 'cancelled') {
                (new EventChangeService())->cancel($entity, $id, self::textInput('reason'),
                    self::textInput('request_key'), get_current_user_id());
            } else {
                LifecycleService::change($entity, $id, $target, get_current_user_id());
            }
            self::redirect('created');
        } catch (Throwable $e) {
            self::redirect('error');
        }
    }

    public static function postponeSession(): void
    {
        self::checked('pesm_postpone_session', 'pesm_manage_sessions');
        $sessionId = isset($_POST['session_id']) ? absint(wp_unslash($_POST['session_id'])) : 0;
        $raw = self::textInput('new_starts_at');
        // datetime-local carries no timezone; the form explicitly specifies UTC.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $raw)) { self::redirect('invalid'); }
        $newUtc = str_replace('T', ' ', $raw) . ':00';
        try {
            (new EventChangeService())->postponeSession($sessionId, $newUtc,
                self::textInput('reason'), self::textInput('request_key'), get_current_user_id());
            self::redirect('created');
        } catch (\PESM\Domain\Booking\HoldException $e) {
            self::redirect($e->httpStatus === 403 ? 'forbidden' : 'error');
        } catch (Throwable $e) { self::redirect('error'); }
    }

    public static function requestPaidRefund(): void
    {
        self::checked('pesm_refund_request', 'pesm_manage_refunds');
        $ticketId = self::refundTicketId();
        if ($ticketId < 1) { self::redirect('invalid'); }
        try {
            (new RefundReviewService())->request($ticketId, self::textInput('reason'),
                self::textInput('request_key'), get_current_user_id());
            self::redirect('created');
        } catch (\PESM\Domain\Booking\HoldException $e) {
            self::redirect($e->httpStatus === 403 ? 'forbidden' : 'error');
        } catch (Throwable $e) { self::redirect('error'); }
    }

    public static function reconcilePaidRefund(): void
    {
        self::checked('pesm_refund_reconcile', 'pesm_manage_refunds');
        $ticketId = self::refundTicketId();
        if ($ticketId < 1) { self::redirect('invalid'); }
        try {
            (new RefundReviewService())->reconcile($ticketId, get_current_user_id());
            self::redirect('created');
        } catch (\PESM\Domain\Booking\HoldException $e) {
            self::redirect($e->httpStatus === 403 ? 'forbidden' : 'error');
        } catch (Throwable $e) { self::redirect('error'); }
    }

    private static function refundTicketId(): int
    {
        $number = isset($_POST['ticket_number']) && is_string($_POST['ticket_number'])
            ? strtoupper(sanitize_text_field(wp_unslash($_POST['ticket_number']))) : '';
        if (!preg_match('/^P-[A-F0-9]{16}$/D', $number)) { return 0; }
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}pesm_tickets WHERE ticket_number=%s", $number
        ));
    }

    public static function cancelFreeTicket(): void
    {
        self::checked('pesm_cancel_free_ticket', 'pesm_manage_refunds');
        $number = isset($_POST['ticket_number']) && is_string($_POST['ticket_number'])
            ? strtoupper(sanitize_text_field(wp_unslash($_POST['ticket_number']))) : '';
        if (!preg_match('/^P-[A-F0-9]{16}$/D', $number)) { self::redirect('invalid'); }
        global $wpdb;
        $ticketId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}pesm_tickets WHERE ticket_number=%s", $number
        ));
        if ($ticketId < 1) { self::redirect('invalid'); }
        try {
            (new FreeTicketCancellationService())->cancel($ticketId, get_current_user_id());
            self::redirect('created');
        } catch (\PESM\Domain\Booking\HoldException $e) {
            if ($e->httpStatus === 403) { self::redirect('forbidden'); }
            self::redirect('error');
        } catch (Throwable $e) { self::redirect('error'); }
    }

    public static function createTicketBundle(): void
    {
        self::checked('pesm_create_ticket_bundle', 'pesm_issue_tickets');
        $raw = isset($_POST['ticket_ids']) && is_string($_POST['ticket_ids'])
            ? sanitize_text_field(wp_unslash($_POST['ticket_ids'])) : '';
        $ids = preg_split('/\s*,\s*/', trim($raw));
        if (!is_array($ids) || !preg_match('/^\d+(?:\s*,\s*\d+){1,7}$/D', $raw)) {
            self::redirect('invalid');
        }
        $ticketIds = array_map('intval', $ids);
        try {
            (new BundleService())->create($ticketIds, self::textInput('request_key'), get_current_user_id());
            self::redirect('created');
        } catch (Throwable $e) {
            self::redirect('error');
        }
    }

    public static function transferTicketSeat(): void
    {
        if (!current_user_can('pesm_issue_tickets') && !current_user_can('pesm_manage_refunds')) {
            wp_die(esc_html__((\PESM\Support\I18n::text('دسترسی غیرمجاز.')), 'event-seat-manager'), '', ['response' => 403]);
        }
        check_admin_referer('pesm_transfer_ticket_seat');
        $number = isset($_POST['ticket_number']) && is_string($_POST['ticket_number'])
            ? strtoupper(sanitize_text_field(wp_unslash($_POST['ticket_number']))) : '';
        $code = isset($_POST['seat_code']) && is_string($_POST['seat_code'])
            ? strtoupper(sanitize_text_field(wp_unslash($_POST['seat_code']))) : '';
        if (!preg_match('/^P-[A-F0-9]{16}$/D', $number) || !preg_match('/^[A-Z0-9-]{1,60}$/D', $code)) {
            self::redirect('invalid');
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $located = $wpdb->get_row($wpdb->prepare(
            "SELECT id,session_id FROM {$p}tickets WHERE ticket_number=%s", $number
        ));
        if (!$located) { self::redirect('invalid'); }
        $ticketId = (int) $located->id;
        $seatId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT inv.seat_id FROM {$p}session_seats inv JOIN {$p}seats seat ON seat.id=inv.seat_id
             WHERE inv.session_id=%d AND seat.seat_code=%s LIMIT 1", (int) $located->session_id, $code
        ));
        if ($seatId < 1) { self::redirect('invalid'); }
        $key = isset($_POST['request_key']) && is_string($_POST['request_key'])
            ? sanitize_text_field(wp_unslash($_POST['request_key'])) : '';
        try {
            (new SeatTransferService())->transfer($ticketId, $seatId, $key, get_current_user_id());
            self::redirect('created');
        } catch (\PESM\Domain\Booking\HoldException $e) {
            if ($e->httpStatus === 403) { self::redirect('forbidden'); }
            self::redirect('error');
        } catch (Throwable $e) { self::redirect('error'); }
    }

    public static function seatingRules(): void
    {
        self::checked('pesm_seating_rules', 'pesm_manage_sessions');
        $id = isset($_POST['session_id']) ? absint(wp_unslash($_POST['session_id'])) : 0;
        $adjacent = isset($_POST['require_adjacent']) && (string) wp_unslash($_POST['require_adjacent']) === '1';
        $gap = isset($_POST['avoid_single_gap']) && (string) wp_unslash($_POST['avoid_single_gap']) === '1';
        try {
            SeatingRulesService::configure($id, $adjacent, $gap, get_current_user_id());
            self::redirect('created');
        } catch (Throwable $e) { self::redirect('error'); }
    }

    public static function seatBlock(): void
    {
        self::checked('pesm_seat_block', 'pesm_edit_layouts');
        global $wpdb;
        $sessionId = isset($_POST['session_id']) ? absint(wp_unslash($_POST['session_id'])) : 0;
        $seatCode = isset($_POST['seat_code']) && is_string($_POST['seat_code'])
            ? strtoupper(trim(sanitize_text_field(wp_unslash($_POST['seat_code'])))) : '';
        $blocked = isset($_POST['blocked']) && (string) wp_unslash($_POST['blocked']) === '1';
        $p = $wpdb->prefix . 'pesm_';
        $centerId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT e.center_id FROM {$p}sessions s INNER JOIN {$p}events e ON e.id=s.event_id WHERE s.id=%d", $sessionId
        ));
        if (!Capabilities::centerAllowed($centerId, get_current_user_id())) {
            self::redirect('forbidden');
        }
        try {
            SeatAdministration::changeBlocked($sessionId, $seatCode, $blocked, get_current_user_id());
            self::redirect('created');
        } catch (Throwable $e) {
            self::redirect('error');
        }
    }

    private static function formStart(string $actionUrl, string $action): void
    {
        $class = in_array($action, ['pesm_cancel_free_ticket', 'pesm_refund_request'], true) ? ' pesm-task-danger' : '';
        echo '<form class="pesm-admin-form' . $class . '" method="post" action="' . $actionUrl . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        wp_nonce_field($action);
    }

    /** @param array<int,object> $centers */
    private static function centerSelect(array $centers): void
    {
        echo '<select name="center_id">';
        foreach ($centers as $center) {
            echo '<option value="' . esc_attr((string) $center->id) . '">' . esc_html($center->name) . '</option>';
        }
        echo '</select>';
    }

    private static function checked(string $action, string $cap): void
    {
        if (!current_user_can($cap)) {
            wp_die(esc_html__((\PESM\Support\I18n::text('دسترسی غیرمجاز.')), 'event-seat-manager'), '', ['response' => 403]);
        }
        check_admin_referer($action);
        if ((int) get_option('pesm_schema_version', 0) < \PESM\Database\Migrator::VERSION) {
            wp_die((\PESM\Support\I18n::text('ابتدا اشکال پایگاه داده را برطرف کنید.')), '', ['response' => 503]);
        }
    }

    private static function centerId(): int
    {
        $id = isset($_POST['center_id']) ? absint(wp_unslash($_POST['center_id'])) : 0;
        if (!Capabilities::centerAllowed($id, get_current_user_id())) {
            self::redirect('forbidden');
        }
        return $id;
    }

    private static function textInput(string $key): string
    {
        $text = isset($_POST[$key]) && is_string($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
        if ($text === '' || strlen($text) > 750) {
            self::redirect('invalid');
        }
        return $text;
    }

    public static function createCenter(): void
    {
        self::checked('pesm_create_center', 'pesm_manage_centers');
        try { HallService::createCenter(self::textInput('name')); self::redirect('created'); }
        catch (Throwable $e) { self::redirect('error'); }
    }

    public static function createHall(): void
    {
        self::checked('pesm_create_hall', 'pesm_manage_halls');
        $center = self::centerId();
        $rows = isset($_POST['rows']) ? absint(wp_unslash($_POST['rows'])) : 0;
        $cols = isset($_POST['columns']) ? absint(wp_unslash($_POST['columns'])) : 0;
        try { HallService::createHall($center, self::textInput('name'), $rows, $cols); self::redirect('created'); }
        catch (Throwable $e) { self::redirect('error'); }
    }

    public static function createLayoutPreset(): void
    {
        self::checked('pesm_layout_preset', 'pesm_edit_layouts');
        $hallId = isset($_POST['hall_id']) ? absint(wp_unslash($_POST['hall_id'])) : 0;
        $rows = isset($_POST['rows']) ? absint(wp_unslash($_POST['rows'])) : 0;
        $columns = isset($_POST['columns']) ? absint(wp_unslash($_POST['columns'])) : 0;
        $preset = isset($_POST['preset']) && is_string($_POST['preset']) ? sanitize_key(wp_unslash($_POST['preset'])) : '';
        try {
            $seats = LayoutPresets::generate($preset, $rows, $columns);
            (new LayoutVersionService())->importDraft($hallId, get_current_user_id(), LayoutDocument::encode($seats));
            self::redirect('created');
        } catch (Throwable $e) { self::redirect('error'); }
    }

    public static function changeAttendee(): void
    {
        self::checked('pesm_change_attendee', 'pesm_issue_tickets');
        $type = isset($_POST['ticket_type']) && is_string($_POST['ticket_type']) ? sanitize_key(wp_unslash($_POST['ticket_type'])) : '';
        $ticketId = isset($_POST['ticket_id']) ? absint(wp_unslash($_POST['ticket_id'])) : 0;
        $name = isset($_POST['attendee_name']) && is_string($_POST['attendee_name']) ? wp_unslash($_POST['attendee_name']) : '';
        $key = isset($_POST['request_key']) && is_string($_POST['request_key']) ? sanitize_text_field(wp_unslash($_POST['request_key'])) : '';
        try {
            (new AttendeeChangeService())->change($type, $ticketId, $name, $key, get_current_user_id());
            self::redirect('created');
        } catch (Throwable $e) { self::redirect('error'); }
    }

    /** Authenticated admin AJAX; WordPress nonce is CSRF defense, capability + center scope are authorization. */
    public static function saveEditorDraft(): void
    {
        if (!check_ajax_referer('pesm_editor_save', 'nonce', false) || !current_user_can('pesm_edit_layouts')) {
            wp_send_json_error(['message' => (\PESM\Support\I18n::text('مجوز ذخیره وجود ندارد.'))], 403);
        }
        $json = isset($_POST['json']) && is_string($_POST['json']) ? wp_unslash($_POST['json']) : '';
        if (strlen($json) > LayoutDocument::MAX_BYTES) {
            wp_send_json_error(['message' => (\PESM\Support\I18n::text('فایل نقشه از حد مجاز بزرگ‌تر است.'))], 413);
        }
        try {
            $result = (new DraftSaveService())->save(
                isset($_POST['hall_id']) ? absint($_POST['hall_id']) : 0,
                isset($_POST['layout_id']) ? absint($_POST['layout_id']) : 0,
                get_current_user_id(),
                isset($_POST['revision']) ? absint($_POST['revision']) : 0,
                isset($_POST['request_key']) && is_string($_POST['request_key']) ? sanitize_text_field(wp_unslash($_POST['request_key'])) : '',
                $json
            );
            wp_send_json_success($result);
        } catch (DraftConflictException $error) {
            wp_send_json_error(['message' => $error->getMessage(), 'conflict' => true], 409);
        } catch (\InvalidArgumentException $error) {
            wp_send_json_error(['message' => $error->getMessage()], 422);
        } catch (\Throwable $error) {
            wp_send_json_error(['message' => (\PESM\Support\I18n::text('ذخیره پیش‌نویس ناموفق بود؛ نسخه محلی را به‌صورت JSON دریافت کنید.'))], 503);
        }
    }

    public static function printLayout(): void
    {
        if (!current_user_can('pesm_edit_layouts')) { wp_die((\PESM\Support\I18n::text('دسترسی غیرمجاز.')), '', ['response' => 403]); }
        $hallId = isset($_GET['hall_id']) ? absint($_GET['hall_id']) : 0;
        $layoutId = isset($_GET['layout_id']) ? absint($_GET['layout_id']) : 0;
        $paper = isset($_GET['paper']) && is_string($_GET['paper']) ? sanitize_text_field(wp_unslash($_GET['paper'])) : '';
        check_admin_referer('pesm_layout_print_' . $hallId . '_' . $layoutId . '_' . $paper);
        try {
            global $wpdb;
            $json = (new LayoutVersionService())->export($hallId, $layoutId, get_current_user_id());
            $hallName = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT name FROM {$wpdb->prefix}pesm_halls WHERE id=%d", $hallId
            ));
            $html = LayoutPrintRenderer::render($json, $hallName, $paper);
            nocache_headers();
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:");
            header('Referrer-Policy: no-referrer');
            echo $html; // Only escaped content from validated layout and server-side renderer.
            exit;
        } catch (\Throwable $error) {
            wp_die((\PESM\Support\I18n::text('آماده‌سازی نقشه چاپی ممکن نشد.')), '', ['response' => 422]);
        }
    }

    public static function importLayout(): void
    {
        self::checked('pesm_layout_import', 'pesm_edit_layouts');
        $hallId = isset($_POST['hall_id']) ? absint(wp_unslash($_POST['hall_id'])) : 0;
        $file = $_FILES['layout_file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? -1) !== UPLOAD_ERR_OK ||
            !is_string($file['tmp_name'] ?? null) ||
            !is_uploaded_file($file['tmp_name']) || (int) ($file['size'] ?? 0) < 1 ||
            (int) ($file['size'] ?? 0) > LayoutDocument::MAX_BYTES) {
            self::redirect('invalid');
        }
        try {
            $body = file_get_contents($file['tmp_name'], false, null, 0, LayoutDocument::MAX_BYTES + 1);
            if (!is_string($body)) { throw new \RuntimeException((\PESM\Support\I18n::text('ورودی فایل قابل خواندن نیست.'))); }
            (new LayoutVersionService())->importDraft($hallId, get_current_user_id(), $body);
            self::redirect('created');
        } catch (Throwable $e) { self::redirect('error'); }
    }

    public static function publishLayout(): void
    {
        self::checked('pesm_layout_publish', 'pesm_edit_layouts');
        $hallId = isset($_POST['hall_id']) ? absint(wp_unslash($_POST['hall_id'])) : 0;
        $layoutId = isset($_POST['layout_id']) ? absint(wp_unslash($_POST['layout_id'])) : 0;
        try {
            (new LayoutVersionService())->publish($hallId, $layoutId, get_current_user_id());
            self::redirect('created');
        } catch (Throwable $e) { self::redirect('error'); }
    }

    public static function exportLayout(): void
    {
        if (!current_user_can('pesm_edit_layouts')) { wp_die((\PESM\Support\I18n::text('دسترسی غیرمجاز.')), '', ['response' => 403]); }
        $hallId = isset($_GET['hall_id']) ? absint(wp_unslash($_GET['hall_id'])) : 0;
        $layoutId = isset($_GET['layout_id']) ? absint(wp_unslash($_GET['layout_id'])) : 0;
        check_admin_referer('pesm_layout_export_' . $hallId . '_' . $layoutId);
        if ((int) get_option('pesm_schema_version', 0) < \PESM\Database\Migrator::VERSION) {
            wp_die((\PESM\Support\I18n::text('ساختار پایگاه داده آماده نیست.')), '', ['response' => 503]);
        }
        try {
            $body = (new LayoutVersionService())->export($hallId, $layoutId, get_current_user_id());
            nocache_headers();
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: attachment; filename="pesm-layout-' . $hallId . '-' . $layoutId . '.json"');
            echo $body; // Validated JSON only; intentionally not HTML-escaped.
            exit;
        } catch (Throwable $e) {
            wp_die((\PESM\Support\I18n::text('خروجی چیدمان امکان‌پذیر نیست.')), '', ['response' => 400]);
        }
    }

    public static function createEvent(): void
    {
        self::checked('pesm_create_event', 'pesm_manage_events');
        try { HallService::createEvent(self::centerId(), self::textInput('title'), self::eventDetailsInput()); self::redirect('created'); }
        catch (Throwable $e) { self::redirect('error'); }
    }

    public static function createSession(): void
    {
        self::checked('pesm_create_session', 'pesm_manage_sessions');
        global $wpdb;
        $eventId = isset($_POST['event_id']) ? absint(wp_unslash($_POST['event_id'])) : 0;
        $hallId = isset($_POST['hall_id']) ? absint(wp_unslash($_POST['hall_id'])) : 0;
        $p = $wpdb->prefix . 'pesm_';
        $eventCenter = (int) $wpdb->get_var($wpdb->prepare("SELECT center_id FROM {$p}events WHERE id = %d", $eventId));
        if (!Capabilities::centerAllowed($eventCenter, get_current_user_id())) {
            self::redirect('forbidden');
        }
        try {
            $start = self::toUtc('start');
            $saleStart = self::toUtc('sale_start');
            $saleEnd = self::toUtc('sale_end');
            $mode = isset($_POST['mode']) && $_POST['mode'] === 'general' ? 'general' : 'seated';
            $capacity = isset($_POST['capacity']) ? absint(wp_unslash($_POST['capacity'])) : 0;
            HallService::createSession($eventId, $hallId, $start, $saleStart, $saleEnd,
                isset($_POST['unit_price_minor']) ? absint(wp_unslash($_POST['unit_price_minor'])) : 0,
                $mode, $capacity);
            self::redirect('created');
        } catch (Throwable $e) { self::redirect('error'); }
    }

    public static function assignCenter(): void
    {
        self::checked('pesm_assign_center', 'pesm_manage_centers');
        $center = self::centerId();
        $user = isset($_POST['user_id']) ? absint(wp_unslash($_POST['user_id'])) : 0;
        if (!$user || !get_user_by('id', $user)) { self::redirect('invalid'); }
        global $wpdb;
        $ok = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}pesm_center_users (center_id,user_id) VALUES (%d,%d)", $center, $user
        ));
        self::redirect($ok === false ? 'error' : 'created');
    }

    private static function toUtc(string $field): string
    {
        $raw = isset($_POST[$field]) && is_string($_POST[$field]) ? (string) wp_unslash($_POST[$field]) : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $raw)) {
            throw new \InvalidArgumentException((\PESM\Support\I18n::text('زمان نامعتبر است.')));
        }
        $tz = new DateTimeZone('Asia/Tehran');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw, $tz);
        if (!$date || $date->format('Y-m-d\TH:i') !== $raw) {
            throw new \InvalidArgumentException((\PESM\Support\I18n::text('زمان تقویمی نامعتبر است.')));
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function redirect(string $state): never
    {
        $view = isset($_POST['pesm_view']) && is_string($_POST['pesm_view'])
            ? sanitize_key(wp_unslash($_POST['pesm_view'])) : 'overview';
        if (!in_array($view, ['overview', 'events', 'halls', 'tickets', 'reports', 'settings'], true)) { $view = 'overview'; }
        wp_safe_redirect(add_query_arg('pesm_notice', $state, admin_url('admin.php?page=pesm-dashboard')) . '#pesm-' . $view);
        exit;
    }
}
