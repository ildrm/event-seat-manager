# قرارداد REST — event-seat-manager 2.0.0

API نسخهٔ دوم برای چیدمان بزرگ، میز/جایگاه، قیمت دسته‌ای و بلیت گروهی در [راهنمای طراح سالن](VENUE-DESIGNER.md) آمده است. مسیرهای قدیمی اجازهٔ تغییر جلسه‌های `allocated` را ندارند. صفحه‌بندی گزارش مغایرت، cursor مستقل `before_seated`، `before_general` و `after_allocated` دارد؛ مقدار `-1` یعنی آن نوع موجودی به انتها رسیده و نباید از ابتدا خوانده شود.

تمام مسیرها با prefix `wp-json/pesm/v1/`، پاسخ JSON و `Cache-Control: private, no-store` پیاده شده‌اند. نتایج آزمون واقعی و محدودیت‌های مسیرها در `INTEGRATION-RESULTS.md` ثبت می‌شود. آدرس پایه را با `rest_url()` دریافت کنید؛ در پیوند یکتای ساده مسیر از query `rest_route` استفاده می‌کند.

| متد | مسیر | مجوز و ورودی | نتیجه |
|---|---|---|---|
| GET | `events` | عمومی | رویداد منتشرشده |
| GET | `events/{id}` | عمومی | عنوان رویداد |
| GET | `events/{id}/sessions` | عمومی | جلسات و قیمت واحد |
| GET | `sessions/{id}/layout` | عمومی | نقشه، قیمت واحد، کد ارز، اعشار |
| GET | `sessions/{id}/availability` | عمومی | وضعیت هر صندلی؛ بدون PII |
| GET | `sessions/{id}/capacity` | عمومی؛ فقط جلسه منتشرشده از نوع `general` | شمارنده کل/آزاد/نگهداری‌شده/مسدود؛ بدون PII |
| POST | `capacity/holds` | عمومی، rate limit؛ JSON `session_id`, `quantity` (۱..۲۰), `idempotency_key` (۳۲..۱۲۸) | hold موقت، توکن bearer و انقضا؛ این مرحله هنوز فروش نیست |
| DELETE | `capacity/holds` | عمومی، rate limit؛ JSON `session_id` و هدر `X-PESM-Hold-Token` | آزادسازی اتمیک و idempotent |
| POST | `holds` | عمومی، rate limit، `session_id`، `seat_ids`، `idempotency_key` | شناسه و توکن امن hold/انقضا |
| PATCH | `holds` | `session_id`، `action=renew/replace`، `idempotency_key`، هدر `X-PESM-Hold-Token`؛ برای replace آرایه `seat_ids` | تمدید محدود و تغییر اتمیک صندلی‌های یک hold پیش از پرداخت |
| DELETE | `holds` | `session_id`، هدر `X-PESM-Hold-Token` | آزادسازی hold فعال |
| POST | `checkout/prepare` | `session_id`، `first_name`، `last_name`، `phone`، `email` اختیاری برای رایگان، هدر `X-PESM-Hold-Token` | `order_id`، `guest_access_token` و در صورت پولی `payment_url` |
| GET | `tickets/me?order_id=N` | هدر `X-PESM-Guest-Token` | فقط بلیت‌های همان سفارش، QR، وضعیت |
| POST | `admin/tickets/{id}/transfer-seat` | `pesm_issue_tickets` یا `pesm_manage_refunds` + scope مرکز؛ `destination_seat_id` و `request_key` یکتا با طول 32–128 | جابه‌جایی صندلی بلیت صادرشده بدون تغییر مبلغ، با چرخش QR؛ در صورت تعارض 409 |
| GET | `admin/tickets/{id}/transfers` | `pesm_view_orders` یا مجوز صدور/استرداد + scope مرکز | ۵۰ انتقال اخیر؛ بدون bearer-token یا درخواست کلید |
| POST | `admin/tickets/{id}/cancel-free` | `pesm_manage_refunds` + scope مرکز؛ بلیت رایگان ثبت‌شده/فعال/استفاده‌نشده | ابطال تک‌بلیت، آزادسازی صندلی؛ تکرار idempotent |
| POST | `gate/lookup` | اپراتور احرازشده و محدوده مرکز؛ شماره بلیت `P-…` | اطلاعات محدود بلیت بدون نام/موبایل/QR |
| POST | `gate/manual-check-in` | اپراتور احرازشده و محدوده مرکز؛ شماره بلیت، گیت | ورود دستی با بازاعتبارسنجی سروری و جلوگیری از دوباره‌ورود |
| POST | `gate/check-in` | اپراتور احرازشده با `pesm_scan_tickets`، REST nonce، `qr_payload`، `gate` | تأیید اولین ورود یا خطای فارسی |

نمونه درخواست checkout:

```http
POST /wp-json/pesm/v1/checkout/prepare
Content-Type: application/json
X-PESM-Hold-Token: <secure 64-character token>

{"session_id":123,"first_name":"علی","last_name":"نمونه","phone":"09123456789","email":""}
```

واحد قیمت: کوچک‌ترین واحد پولی WC؛ قیمت از `sessions.unit_price_minor` و نه از payload دریافت می‌شود. درخواست پولی وقتی WooCommerce Tax محاسبه می‌کند fail-closed است. مبلغ سفارش باید با جمع server-side دقیقاً مطابقت داشته باشد.

`qr_payload`: قالب `<32-character-hex-public-id>.<64-character-hmac>`. token/PII در query string عمومی فرستاده نشود.

**تکمیل‌نشده:** تغییر hold پس از ورود به مرحله پرداخت، checkout بلوکی Store API، PDF بومی بلیت و تسویهٔ اختلاف قیمت صندلی. مسیرهای بازیابی مهمان، انتقال مالکیت و گزارش‌ها در کنترلر وجود دارند؛ قابلیت‌های آزمایشی به تنظیمات و پذیرش مستقل نیاز دارند.

## ملاحظات انتقال اداری (1.0.0)

- نمونه POST: `{"destination_seat_id": 455, "request_key": "<random-id-32-to-128-chars>"}`. شناسه صندلی در API **شناسه داخلی** است؛ فرم مدیریتی، کد نمایشی صندلی را به شناسه تبدیل می‌کند.
- درخواست‌های login-session باید هدر `X-WP-Nonce` داشته باشند؛ nonce جای کنترل نقش و مرکز را نمی‌گیرد.
- انتقال در رویدادهای فعلی با قیمت واحد است؛ پس از فعال‌سازی قیمت‌گذاری منطقه‌ای یا مالیات باید اختلاف قیمت جداگانه تأیید/تسویه شود و این سرویس تا تکمیل آن fail-closed بماند.
- `qr_version` افزایش می‌یابد؛ QR قبلی مردود است و مشتری باید بلیت فعلی را دوباره دریافت کند. اعلان خودکار هنوز پیاده نشده است.
- لغو رایگان وضعیت WooCommerce را تغییر نمی‌دهد و استرداد پولی محسوب نمی‌شود. برای لغو گروهی یا تغییر وضعیت مالی سفارش، workflow دیگری لازم است.

## ظرفیت آزاد و بازیابی مهمان

- رزرو ظرفیت از `POST capacity/holds` توکن موقت و تعداد می‌دهد؛ پاسخ hold به‌تنهایی فروش قطعی نیست.
- `checkout/prepare` برای ظرفیت آزاد رایگان از WooCommerce سفارش صفر و برای هر واحد بلیت مستقل می‌سازد. مسیر پولی ظرفیت آزاد فقط با تنظیم آزمایشی مدیریتی فعال است؛ تأیید مالی مشابه سفارش پولی بررسی می‌شود.
- `tickets/me`، `gate/check-in` و `gate/manual-check-in` هر دو نوع بلیت را پوشش می‌دهند.
- `POST guest-access/request` و `POST guest-access/verify` برای بازیابی محدود و زمان‌دار مهمان هستند؛ ثبت‌نام ایجاد نمی‌کنند.
- مسیرهای `admin/reconciliation/retry-free-general` و `retry-paid-general` به مجوز و مرکز صحیح نیاز دارند؛ ظرفیت منقضی یا متعلق به دیگری به اجبار واگذار نمی‌شود.
- `ticket-ownership/request`، `accept` و `view` مسیر اختیاری انتقال مالکیت‌اند؛ توکن مالک قبلی و تأیید گیرنده باید سروری اعتبارسنجی شوند.

## اعلان‌ها — 1.0.0 (داخلی، بدون REST عمومی)

اعلان صدور بلیت پس از تراکنش تأییدشده در سمت سرور به `NotificationQueue::onTicketsIssued` تحویل داده می‌شود. این ماژول **API عمومی ارسال پیامک** ندارد و ورودی شماره تلفن، متن یا آدرس مقصد از کاربر ناشناس نمی‌پذیرد. پردازشگر `pesm_process_notifications` توسط WP-Cron اجرا می‌شود. تنظیمات فقط از منوی مدیریت و با مجوز `pesm_manage_sms` و nonce معتبر انجام می‌شوند. شمارش صف فاقد اطلاعات شخصی است.

خطاهای ناشناخته شبکه SMS موجب `review`، خطاهای قطعی موجب `failed` و خطای قبل از ارسال یا ایمیل ناموفق موجب تلاش محدودِ دوباره می‌شوند. در این نسخه API صدور مجدد دستی وجود ندارد و وضعیت تحویل اپراتوری تأیید نمی‌شود.


## گزارش تحلیلی بلیت (مدیر)

`GET /wp-json/pesm/v1/admin/reports/insights?center_id=7&days=30&session_id=0`

- احراز هویت با نشست وردپرس + `X-WP-Nonce` و `pesm_view_reports` و مالکیت مرکز.
- `days` عدد صحیح ۷ تا ۹۰؛ `session_id` صفر به معنی همه جلسات (حداکثر ۲۰۰).
- خروجی شامل `trend`, `events`, `sessions`, `zone_rows` و `not_financial_revenue=true` است.
- `trend` بر پایه تاریخ صدور UTC بلیت‌های **فعلاً فعال یا استفاده‌شده** است و مقدار درآمد/پرداخت را برنمی‌گرداند. ردیف‌های لغوشده یا استردادی در روند حضور ندارند؛ روند گذشته در طول زمان می‌تواند تغییر کند.
- `zone_rows` فقط برای بلیت‌های صندلی‌دار و حداکثر ۲۰۰ ترکیب زون/ردیف، با داده فعلی است.
- پاسخ حاوی نام/موبایل/شناسه خرید یا داده هویتی نیست؛ عنوان رویداد داده عمومی است و باید در مرورگر به صورت `textContent` نمایش داده شود.
- خطاها: `permission_denied` (403), `invalid_report_filter` (422), `invalid_session` (404), `schema_not_ready`/`db_unavailable` (503).


## مسیرهای اختصاصی Woo Cart (پشتیبانی از Checkout Blocks ادعا نشده)

| متد | مسیر | احراز مالکیت و ورودی | نتیجه/محدودیت |
|---|---|---|---|
| POST | `cart/attach` | توکن Hold در هدر `X-PESM-Hold-Token`، `session_id` و `attendees` اختیاری | قراردادن Hold فعال در WC session و افزودن محصول فنی مخفی به Cart؛ درخواست تکراری همان line را برمی‌گرداند |
| GET | `cart/preview?cart_key=…` | کوکی WC session + هدر `X-PESM-Cart-Nonce` نشست | شناسه جلسه و صندلی‌های خود فرد؛ هیچ bearer token به پاسخ/HTML نمی‌رود |
| POST | `cart/replace` | `cart_key`، `seat_ids`، `request_key` و هدر nonce نشست | تعویض اتمیک Hold و سپس recalc قیمت/تعداد در Cart؛ خطای 409 برای تعارض |
| POST | `cart/complete` | `cart_key` و نام/موبایل خریدار و nonce نشست | صدور سفارش مستقیم از CheckoutService فعلی؛ بدون استفاده از Native WC Checkout |

برای پیشگیری از دورزدن کنترل‌ها، افزودن مستقیم محصول فنی، تغییر عادی quantity و Checkout استاندارد/Store API برای Cart حاوی این line ممنوع است. این مسیر **پذیرش عملیاتی** ندارد.
