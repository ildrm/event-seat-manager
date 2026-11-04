<?php
declare(strict_types=1);
namespace PESM\Domain\Allocation;

/** Human-facing errors, separate from stable machine-readable error identifiers. */
final class Messages
{
    public static function all(): array
    {
        return [
            'operation_failed'=>['عملیات انجام نشد؛ دوباره تلاش کنید.','The operation could not be completed. Please retry.'],
            'allocation_failed'=>['ثبت رزرو انجام نشد؛ دوباره تلاش کنید.','The reservation could not be saved. Please retry.'],
            'cart_product'=>['رزرو جایگاه رویداد','Event venue reservation'],
            'cart_native'=>['برای تکمیل رزرو، از فرم اختصاصی جایگاه‌های سبد خرید استفاده کنید.','Complete the reservation using the dedicated venue form in the cart.'],
            'cart_direct_add'=>['این محصول فقط از مسیر رزرو جایگاه قابل افزودن است.','This product can only be added through venue reservations.'],
            'unit_table'=>['میز','Table'],
            'unit_box'=>['جایگاه اختصاصی','Box'],
            'unit_seat'=>['صندلی','Seat'],
            'invalid_selection'=>['انتخاب جایگاه معتبر نیست.','Choose valid seats, tables, or boxes.'],
            'duplicate_resource'=>['یک جایگاه را هم‌زمان به‌صورت تکی و گروهی انتخاب نکنید.','A place cannot be selected both individually and within a group.'],
            'selection_limit'=>['تعداد جایگاه‌های انتخابی از سقف مجاز بیشتر است.','The selection exceeds the allowed number of places.'],
            'invalid_group'=>['رزرو کامل این میز یا جایگاه در دسترس نیست.','This table or box cannot be reserved as a whole.'],
            'whole_group_required'=>['این میز یا جایگاه باید به‌صورت کامل رزرو شود.','Reserve this table or box as a whole.'],
            'invalid_group_capacity'=>['ظرفیت این میز یا جایگاه معتبر نیست.','This table or box has an invalid capacity.'],
            'group_capacity_exceeds_limit'=>['ظرفیت یک میز یا جایگاه از سقف خرید بیشتر است؛ سقف خرید را افزایش دهید یا فروش گروهی آن را غیرفعال کنید.','A table or box exceeds the purchase limit. Increase the limit or disable its whole-group sales.'],
            'seat_unavailable'=>['بخشی از انتخاب شما دیگر آزاد نیست؛ جایگاه دیگری انتخاب کنید.','Some selected places are no longer available. Choose another selection.'],
            'invalid_seat'=>['جایگاه انتخابی در این جلسه وجود ندارد.','The selected place does not belong to this session.'],
            'hold_expired'=>['مهلت رزرو پایان یافته است؛ دوباره انتخاب کنید.','The reservation has expired. Make a new selection.'],
            'hold_conflict'=>['وضعیت رزرو تغییر کرده است؛ انتخاب خود را دوباره بررسی کنید.','The reservation has changed. Review your selection.'],
            'hold_changed_retry'=>['رزرو هم‌زمان تغییر کرده است؛ دوباره تلاش کنید.','The reservation changed in another request. Please retry.'],
            'idempotency_conflict'=>['این درخواست قبلاً برای انتخاب دیگری ثبت شده است.','This request was already used for a different selection.'],
            'permission_denied'=>['اجازه انجام این عملیات را ندارید.','You do not have permission to perform this action.'],
            'invalid_token'=>['دسترسی رزرو معتبر نیست.','The reservation access is invalid.'],
            'csrf_denied'=>['اعتبار صفحه پایان یافته است؛ صفحه را تازه کنید.','The page authorization has expired. Refresh the page.'],
            'event_closed'=>['رزرو این جلسه در حال حاضر فعال نیست.','Reservations for this session are currently closed.'],
            'invalid_session'=>['جلسه مورد نظر پیدا نشد.','The requested session was not found.'],
            'inventory_not_ready'=>['آماده‌سازی جایگاه‌ها هنوز کامل نشده است.','The venue inventory is still being prepared.'],
            'inventory_count_mismatch'=>['شمار جایگاه‌ها با چیدمان مطابقت ندارد.','The inventory count does not match the layout.'],
            'configuration_locked'=>['تنظیمات این جلسه قابل تغییر نیست.','The settings for this session cannot be changed.'],
            'invalid_settings'=>['تنظیمات قیمت یا ورود معتبر نیست.','The pricing or admission settings are invalid.'],
            'layout_not_published'=>['ابتدا چیدمان سالن را منتشر کنید.','Publish the venue layout first.'],
            'db_busy'=>['سامانه رزرو مشغول است؛ چند لحظه دیگر دوباره تلاش کنید.','The reservation service is busy. Please retry shortly.'],
            'db_unavailable'=>['سامانه رزرو موقتاً در دسترس نیست.','The reservation service is temporarily unavailable.'],
            'write_failed'=>['ثبت تغییر انجام نشد؛ دوباره تلاش کنید.','The change could not be saved. Please retry.'],
            'invalid_ticket_mode'=>['نوع بلیت انتخابی معتبر نیست.','Choose a valid ticket mode.'],
            'invalid_email'=>['نشانی ایمیل معتبر وارد کنید.','Enter a valid email address.'],
            'invalid_attendee'=>['مشخصات یکی از مهمانان معتبر نیست.','One guest has invalid details.'],
            'phone_limit'=>['سقف خرید این شماره موبایل برای رویداد پر شده است.','This phone number has reached the purchase limit for the event.'],
            'payment_unverified'=>['پرداخت سفارش هنوز تأیید نشده است.','Payment for this order has not been verified.'],
            'price_mismatch'=>['مبلغ یا ارز سفارش با قیمت ثبت‌شده یکسان نیست.','The order amount or currency does not match the accepted price.'],
            'order_inactive'=>['این سفارش لغو شده یا ناموفق است.','This order is cancelled or failed.'],
            'invalid_order'=>['سفارش مورد نظر معتبر نیست.','The requested order is invalid.'],
            'checkout_reconciliation_required'=>['وضعیت سفارش نیازمند بررسی پشتیبانی است.','The order requires a support review.'],
            'woocommerce_required'=>['ووکامرس برای ثبت سفارش باید فعال باشد.','WooCommerce must be active to create an order.'],
            'paid_tax_unsupported'=>['ثبت این سفارش با تنظیمات مالیات فعلی امکان‌پذیر نیست.','This order cannot be created with the current tax settings.'],
            'invalid_qr'=>['کد بلیت معتبر نیست.','The ticket code is invalid.'],
            'ticket_not_found'=>['بلیت در محدوده دسترسی شما پیدا نشد.','The ticket was not found within your access scope.'],
            'ticket_inactive'=>['این بلیت فعال نیست.','This ticket is not active.'],
            'already_checked_in'=>['ورود این بلیت قبلاً ثبت شده است.','This ticket has already been checked in.'],
            'invalid_entry_time'=>['زمان مجاز ورود این بلیت نیست.','This ticket is outside its admission window.'],
            'admission_quantity'=>['تعداد مهمانان از ظرفیت باقی‌مانده بیشتر است یا معتبر نیست.','Enter a valid guest count within the remaining capacity.'],
            'admission_conflict'=>['بخشی از این گروه قبلاً وارد شده است.','Some members of this group have already entered.'],
            'group_already_used'=>['پس از ورود مهمانان، تغییر یا لغو گروه امکان‌پذیر نیست.','A group cannot be changed or cancelled after guests have entered.'],
            'price_difference_unsupported'=>['انتقال فقط با ظرفیت و قیمت یکسان امکان‌پذیر است.','Transfers require the same capacity and price.'],
            'line_inactive'=>['این رزرو گروهی قابل تغییر نیست.','This group reservation cannot be changed.'],
            'invalid_line'=>['رزرو گروهی پیدا نشد.','The group reservation was not found.'],
            'invalid_refund'=>['مدرک استرداد سفارش معتبر نیست.','The order refund evidence is invalid.'],
            'refund_amount_mismatch'=>['مبلغ استرداد با کل قیمت گروه یکسان نیست.','The refund must match the full group price.'],
            'refund_line_mismatch'=>['استرداد به این میز یا جایگاه تعلق ندارد.','The refund does not belong to this table or box.'],
            'invalid_cart_item'=>['رزرو سبد خرید معتبر نیست.','The cart reservation is invalid.'],
            'cart_conflict'=>['رزرو دیگری برای این جلسه در سبد وجود دارد.','Another reservation for this session is already in the cart.'],
            'cart_unavailable'=>['سبد خرید موقتاً در دسترس نیست.','The cart is temporarily unavailable.'],
            'invalid_availability_scope'=>['بخش انتخاب‌شده سالن معتبر نیست.','The selected venue section is invalid.'],
            'availability_scope_too_large'=>['برای دیدن جایگاه‌ها، نقشه را بیشتر بزرگ کنید.','Zoom in further to view individual places.'],
            'invalid_unit_filter'=>['فیلتر جایگاه‌ها معتبر نیست.','The inventory filter is invalid.'],
            'invalid_cursor'=>['صفحه جایگاه‌ها معتبر نیست؛ فهرست را تازه کنید.','This inventory page is invalid. Refresh the list.'],
        ];
    }
    public static function text(string $code): string
    {
        $pair=self::all()[$code]??self::all()['operation_failed'];
        $translated=\PESM\Support\I18n::text($pair[0]);
        return !\PESM\Support\I18n::isPersian()&&$translated===$pair[0]?$pair[1]:$translated;
    }
}
