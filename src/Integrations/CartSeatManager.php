<?php

declare(strict_types=1);

namespace PESM\Integrations;

use PESM\Domain\Booking\HoldException;
use PESM\Domain\Booking\HoldPolicy;
use PESM\Domain\Booking\HoldService;
use PESM\Domain\Checkout\CheckoutService;
use PESM\Domain\Checkout\AttendeeManifest;
use PESM\Domain\Checkout\CheckoutPolicy;
use PESM\Security\RateLimiter;
use Throwable;

/**
 * Staging-only WooCommerce Cart bridge for active (not payment-pending) seated holds.
 * The WC cart is a review surface, NOT a second inventory authority. The existing
 * direct-order workflow creates the final order. Native WC Checkout/Blocks remains blocked.
 * Sensitive bearer hold tokens remain in the server-side WC session, never rendered in HTML.
 */
final class CartSeatManager
{
    private static bool $adding = false;

    public static function register(): void
    {
        add_filter('woocommerce_add_to_cart_validation', [self::class, 'validateAddition'], 10, 3);
        add_filter('woocommerce_is_purchasable', [self::class, 'purchasable'], 20, 2);
        add_action('woocommerce_before_calculate_totals', [self::class, 'calculatePrices'], 900);
        add_filter('woocommerce_cart_item_name', [self::class, 'cartItemName'], 10, 3);
        add_filter('woocommerce_cart_item_quantity', [self::class, 'readonlyQuantity'], 10, 3);
        add_filter('woocommerce_update_cart_validation', [self::class, 'validateQuantityUpdate'], 10, 4);
        add_action('woocommerce_cart_item_removed', [self::class, 'onRemoved'], 10, 2);
        add_action('woocommerce_check_cart_items', [self::class, 'blockNativeCheckout']);
        add_action('woocommerce_checkout_process', [self::class, 'blockNativeCheckout']);
        // Store API validates cart *before* creating an order; fail there, not just
        // after the Checkout Block has already started writing an order.
        add_action('woocommerce_store_api_cart_errors', [self::class, 'blockStoreCart'], 10, 2);
        add_action('woocommerce_store_api_validate_cart_item', [self::class, 'blockStoreDirectAdd'], 10, 2);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [self::class, 'blockStoreCheckout'], 1, 2);
        add_action('woocommerce_after_cart_table', [self::class, 'echoCartEditor']);
        add_shortcode('pesm_cart_seats', [self::class, 'shortcode']);
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        register_rest_route('pesm/v1', '/cart/attach', [
            'methods'=>'POST', 'callback'=>[self::class, 'attachEndpoint'], 'permission_callback'=>'__return_true',
            'args'=>[
                'session_id'=>['type'=>'integer','minimum'=>1,'required'=>true],
                'attendees'=>['type'=>'array','minItems'=>1,'maxItems'=>20,
                    'items'=>['type'=>'object','additionalProperties'=>false,'properties'=>[
                        'seat_id'=>['type'=>'integer','minimum'=>1],
                        'first_name'=>['type'=>'string','maxLength'=>90],
                        'last_name'=>['type'=>'string','maxLength'=>90]],
                        'required'=>['seat_id','first_name','last_name']]],
            ],
        ]);
        register_rest_route('pesm/v1', '/cart/preview', [
            'methods'=>'GET', 'callback'=>[self::class, 'previewEndpoint'], 'permission_callback'=>'__return_true',
            'args'=>['cart_key'=>['type'=>'string','required'=>true,'maxLength'=>128]],
        ]);
        register_rest_route('pesm/v1', '/cart/replace', [
            'methods'=>'POST', 'callback'=>[self::class, 'replaceEndpoint'], 'permission_callback'=>'__return_true',
            'args'=>[
                'cart_key'=>['type'=>'string','required'=>true,'minLength'=>1,'maxLength'=>128],
                'seat_ids'=>['type'=>'array','required'=>true,'minItems'=>1,'maxItems'=>20,
                    'items'=>['type'=>'integer','minimum'=>1]],
                'request_key'=>['type'=>'string','required'=>true,'minLength'=>32,'maxLength'=>128],
            ],
        ]);
        register_rest_route('pesm/v1', '/cart/complete', [
            'methods'=>'POST', 'callback'=>[self::class, 'completeEndpoint'], 'permission_callback'=>'__return_true',
            'args'=>[
                'cart_key'=>['type'=>'string','required'=>true],
                'first_name'=>['type'=>'string','required'=>true,'maxLength'=>90],
                'last_name'=>['type'=>'string','required'=>true,'maxLength'=>90],
                'phone'=>['type'=>'string','required'=>true,'maxLength'=>30],
                'email'=>['type'=>'string','maxLength'=>190],
                'coupon_code'=>['type'=>'string','maxLength'=>32],
            ],
        ]);
    }

    /** @param mixed $product */
    public static function purchasable(bool $result, $product): bool
    {
        $id = (int) get_option('pesm_wc_product_id', 0);
        if ($id && $product && method_exists($product, 'get_id') && (int)$product->get_id() === $id) {
            return self::$adding || self::hasCartSeatItems();
        }
        return $result;
    }

    public static function validateAddition(bool $valid, int $productId, int $quantity): bool
    {
        $id = (int) get_option('pesm_wc_product_id', 0);
        if ($id > 0 && $productId === $id && !self::$adding) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice((\PESM\Support\I18n::text('این محصول فقط از مسیر انتخاب صندلی سامانه قابل افزودن است.')), 'error');
            }
            return false;
        }
        return $valid;
    }

    private static function hasCartSeatItems(): bool
    {
        if (!function_exists('WC') || !WC() || !WC()->cart) { return false; }
        foreach (WC()->cart->get_cart() as $item) {
            if (!empty($item['pesm_cart_hold_id'])) { return true; }
        }
        return false;
    }

    private static function cart(): object
    {
        if (!function_exists('WC') || !function_exists('wc_get_product')) {
            throw new HoldException('woocommerce_required', (\PESM\Support\I18n::text('ووکامرس برای سبد خرید ضروری است.')), 503);
        }
        if ((!WC()->session || !WC()->cart) && function_exists('wc_load_cart')) { wc_load_cart(); }
        if (!WC()->session || !WC()->cart) {
            throw new HoldException('cart_unavailable', (\PESM\Support\I18n::text('جلسه سبد خرید در دسترس نیست.')), 503);
        }
        if (method_exists(WC()->session, 'set_customer_session_cookie')) {
            WC()->session->set_customer_session_cookie(true);
        }
        return WC()->cart;
    }

    /** @return array{session_id:int,hold_id:int,token:string,seat_ids:list<int>,expires_at:string,price_minor:int} */
    private static function activeHold(int $sessionId, string $token): array
    {
        if ($sessionId < 1 || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new HoldException('invalid_token', (\PESM\Support\I18n::text('شناسه رزرو معتبر نیست.')), 403);
        }
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT h.id,h.status,h.expires_at,s.mode,s.unit_price_minor
             FROM {$p}holds h JOIN {$p}sessions s ON s.id=h.session_id
             WHERE h.session_id=%d AND h.token_hash=%s", $sessionId, hash('sha256', $token)
        ));
        if (!$row) { throw new HoldException('permission_denied', (\PESM\Support\I18n::text('رزرو متعلق به این درخواست نیست.')), 403); }
        $now = (string) $wpdb->get_var('SELECT UTC_TIMESTAMP()');
        if (!$now || $row->status !== 'active' || $row->expires_at <= $now || $row->mode !== 'seated') {
            throw new HoldException('hold_expired', (\PESM\Support\I18n::text('رزرو موقت قابل تغییر یا افزودن به سبد نیست.')), 410);
        }
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT seat_id FROM {$p}hold_items WHERE hold_id=%d ORDER BY seat_id", (int)$row->id
        )));
        // A valid token alone must never make stale or reassigned inventory appear owned.
        $owned = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT seat_id FROM {$p}session_seats WHERE session_id=%d AND hold_id=%d AND status='held' ORDER BY seat_id",
            $sessionId, (int)$row->id
        )));
        if ($owned !== $ids) {
            throw new HoldException('seat_unavailable', (\PESM\Support\I18n::text('موجودی صندلی‌ها با نگهداری فعلی سازگار نیست.')), 409);
        }
        if (!$ids || count($ids) > HoldPolicy::MAX_SEATS) {
            throw new HoldException('invalid_selection', (\PESM\Support\I18n::text('صندلی‌های رزرو نامعتبرند.')), 409);
        }
        return ['session_id'=>$sessionId,'hold_id'=>(int)$row->id,'token'=>$token,
            'seat_ids'=>$ids,'expires_at'=>(string)$row->expires_at,'price_minor'=>(int)$row->unit_price_minor];
    }

    /** @return array<string,mixed> */
    public function attach(int $sessionId, string $token, ?array $attendees = null): array
    {
        $cart = self::cart();
        $hold = self::activeHold($sessionId, $token);
        $attendeeSnapshot = $attendees === null ? [] : AttendeeManifest::seats(
            $attendees, $hold['seat_ids'], (\PESM\Support\I18n::text('خریدار')), (\PESM\Support\I18n::text('نمونه'))
        );
        foreach ($cart->get_cart() as $key => $item) {
            if ((int)($item['pesm_cart_hold_id'] ?? 0) === $hold['hold_id']) {
                // Do not silently overwrite participant names on a duplicate attach.
                if ($attendees !== null && $attendeeSnapshot !== ($item['pesm_cart_attendees'] ?? [])) {
                    throw new HoldException('idempotency_conflict', (\PESM\Support\I18n::text('نام شرکت‌کنندگان برای همین رزرو تغییر کرده است.')), 409);
                }
                return ['cart_key'=>(string)$key,'seat_ids'=>$hold['seat_ids'],
                    'cart_url'=>wc_get_cart_url(),'existing'=>true];
            }
            if ((int)($item['pesm_cart_session_id'] ?? 0) === $sessionId) {
                throw new HoldException('cart_conflict', (\PESM\Support\I18n::text('برای این جلسه یک رزرو دیگر در سبد موجود است.')), 409);
            }
        }
        $product = self::technicalProduct();
        self::$adding = true;
        try {
            $key = $cart->add_to_cart((int)$product->get_id(), count($hold['seat_ids']), 0, [], [
                'pesm_cart_session_id'=>$sessionId, 'pesm_cart_hold_id'=>$hold['hold_id'],
                'pesm_cart_hold_token'=>$token, 'pesm_cart_seat_ids'=>$hold['seat_ids'],
                'pesm_cart_attendees'=>$attendeeSnapshot,
                // A stable unique identifier prevents accidental merging across sessions/holds.
                'pesm_cart_unique'=>hash('sha256', $sessionId . ':' . $hold['hold_id']),
            ]);
        } finally { self::$adding = false; }
        if (!is_string($key) || $key === '') {
            throw new HoldException('cart_unavailable', (\PESM\Support\I18n::text('افزودن رزرو به سبد انجام نشد.')), 503);
        }
        $cart->calculate_totals();
        return ['cart_key'=>$key,'seat_ids'=>$hold['seat_ids'],'cart_url'=>wc_get_cart_url(),'existing'=>false];
    }

    /** @return array<string,mixed> */
    private static function item(string $cartKey): array
    {
        if ($cartKey === '' || strlen($cartKey) > 128) {
            throw new HoldException('invalid_cart_item', (\PESM\Support\I18n::text('آیتم سبد نامعتبر است.')), 422);
        }
        $cart = self::cart();
        $item = $cart->get_cart_item($cartKey);
        if (!is_array($item) || empty($item['pesm_cart_hold_id']) || empty($item['pesm_cart_hold_token'])) {
            throw new HoldException('permission_denied', (\PESM\Support\I18n::text('رزرو در سبد خرید فعلی وجود ندارد.')), 403);
        }
        return $item;
    }

    /** @return array<string,mixed> */
    public function replace(string $cartKey, array $seatIds, string $requestKey): array
    {
        $item = self::item($cartKey);
        $hold = self::activeHold((int)$item['pesm_cart_session_id'], (string)$item['pesm_cart_hold_token']);
        if ($hold['hold_id'] !== (int)$item['pesm_cart_hold_id']) {
            throw new HoldException('permission_denied', (\PESM\Support\I18n::text('رزرو از سبد فعلی جدا شده است.')), 403);
        }
        $seatIds = HoldPolicy::normalizeSeats($seatIds);
        $result = (new HoldService())->modify($hold['session_id'], $hold['token'], $requestKey, $seatIds);
        // HoldService is the inventory authority. Updating WC quantity only happens AFTER SQL commit.
        $cart = self::cart();
        $cart->cart_contents[$cartKey]['pesm_cart_seat_ids'] = $result['seat_ids'];
        // Keep metadata for unchanged seats; new seats get buyer name at checkout.
        $cart->cart_contents[$cartKey]['pesm_cart_attendees'] = array_values(array_filter(
            (array)($item['pesm_cart_attendees'] ?? []),
            static fn($person): bool => is_array($person) && in_array((int)($person['seat_id'] ?? 0), $result['seat_ids'], true)
        ));
        $cart->set_quantity($cartKey, count($result['seat_ids']), false);
        $cart->calculate_totals();
        return ['seat_ids'=>$result['seat_ids'], 'expires_at_utc'=>$result['expires_at_utc'],
            'server_now_utc'=>$result['server_now_utc'], 'replayed'=>$result['idempotent_replay']];
    }

    /** @param array<string,mixed> $buyer @return array<string,mixed> */
    public function complete(string $cartKey, array $buyer): array
    {
        $item = self::item($cartKey);
        $sessionId = (int)$item['pesm_cart_session_id'];
        $token = (string)$item['pesm_cart_hold_token'];
        // A previous order may have been created before an HTTP timeout. The checkout
        // service handles payment_pending/sold idempotently; do not reject such retries.
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        $holdRow = $wpdb->get_row($wpdb->prepare(
            "SELECT id,status FROM {$p}holds WHERE session_id=%d AND token_hash=%s",
            $sessionId, hash('sha256', $token)
        ));
        if (!$holdRow || (int)$holdRow->id !== (int)$item['pesm_cart_hold_id']) {
            throw new HoldException('permission_denied', (\PESM\Support\I18n::text('رزرو این سبد قابل تأیید نیست.')), 403);
        }
        if ($holdRow->status === 'active') {
            $hold = self::activeHold($sessionId, $token);
            if ($hold['seat_ids'] !== array_map('intval', (array)$item['pesm_cart_seat_ids'])) {
                throw new HoldException('cart_conflict', (\PESM\Support\I18n::text('صندلی‌های سبد با سرور یکسان نیستند.')), 409);
            }
        }
        // Complete the attendee list for all seats. The unchanged seat-name pairs
        // survive cart replacements; only newly selected seats inherit buyer name.
        $first = CheckoutPolicy::normalizeName((string)($buyer['first_name'] ?? ''));
        $last = CheckoutPolicy::normalizeName((string)($buyer['last_name'] ?? ''));
        $known = [];
        foreach ((array)($item['pesm_cart_attendees'] ?? []) as $person) {
            if (is_array($person) && isset($person['seat_id'])) { $known[(int)$person['seat_id']] = $person; }
        }
        $buyer['attendees'] = [];
        foreach ((array)($item['pesm_cart_seat_ids'] ?? []) as $seatId) {
            $buyer['attendees'][] = $known[(int)$seatId] ??
                ['seat_id'=>(int)$seatId, 'first_name'=>$first, 'last_name'=>$last];
        }
        $buyer['attendees'] = AttendeeManifest::seats($buyer['attendees'],
            array_map('intval',(array)$item['pesm_cart_seat_ids']), $first, $last);
        // Existing CheckoutService remains sole owner of order creation + reconciliation.
        $result = (new CheckoutService())->prepare($sessionId, $token, $buyer);
        self::cart()->remove_cart_item($cartKey);
        return $result;
    }

    /** @return array{seat_ids:list<int>,expires_at_utc:string} */
    public static function cartHoldForDisplay(array $item): array
    {
        $hold = self::activeHold((int)($item['pesm_cart_session_id'] ?? 0), (string)($item['pesm_cart_hold_token'] ?? ''));
        if ($hold['hold_id'] !== (int)($item['pesm_cart_hold_id'] ?? 0)) {
            throw new HoldException('cart_conflict', (\PESM\Support\I18n::text('رزرو سبد منقضی یا تغییر کرده است.')), 409);
        }
        return ['seat_ids'=>$hold['seat_ids'], 'expires_at_utc'=>str_replace(' ', 'T', $hold['expires_at']) . 'Z'];
    }

    public static function calculatePrices($cart): void
    {
        if (!is_object($cart) || !method_exists($cart, 'get_cart') || !function_exists('wc_get_price_decimals')) { return; }
        foreach ($cart->get_cart() as $key => $item) {
            if (empty($item['pesm_cart_hold_id']) || empty($item['data'])) { continue; }
            try {
                $hold = self::cartHoldForDisplay($item);
                if (count($hold['seat_ids']) !== (int)$item['quantity']) { continue; }
                global $wpdb;
                $p = $wpdb->prefix . 'pesm_';
                $priceMinor = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT unit_price_minor FROM {$p}sessions WHERE id=%d", (int)$item['pesm_cart_session_id']
                ));
                $item['data']->set_price($priceMinor / (10 ** wc_get_price_decimals()));
            } catch (HoldException $e) {
                // Inconsistent/expired carts MUST NOT proceed to native WooCommerce checkout.
            }
        }
    }

    public static function cartItemName(string $name, array $item, string $key): string
    {
        if (empty($item['pesm_cart_hold_id'])) { return $name; }
        return $name . (\PESM\Support\I18n::text(' — جلسه ')) . esc_html((string)$item['pesm_cart_session_id']) . (\PESM\Support\I18n::text(' (نگهداری موقت)'));
    }

    public static function readonlyQuantity(string $quantityHtml, string $key, array $item): string
    {
        if (empty($item['pesm_cart_hold_id'])) { return $quantityHtml; }
        return '<span class="pesm-cart-quantity">' . esc_html((string)$item['quantity']) . '</span>';
    }

    public static function validateQuantityUpdate(bool $valid, string $cartKey, array $values, int $quantity): bool
    {
        if (!empty($values['pesm_cart_hold_id']) && $quantity !== (int)$values['quantity']) {
            if (function_exists('wc_add_notice')) { wc_add_notice((\PESM\Support\I18n::text('تعداد بلیت فقط با تغییر انتخاب صندلی در نقشه سبد قابل تغییر است.')), 'error'); }
            return false;
        }
        return $valid;
    }

    public static function onRemoved(string $key, $cart): void
    {
        $item = $cart->removed_cart_contents[$key] ?? null;
        if (!is_array($item) || empty($item['pesm_cart_hold_token'])) { return; }
        try {
            (new HoldService())->release((int)$item['pesm_cart_session_id'], (string)$item['pesm_cart_hold_token']);
        } catch (Throwable $e) {
            // A converted/expired hold is not released; financial reconciliation owns it.
        }
    }

    public static function blockNativeCheckout(): void
    {
        if (self::hasCartSeatItems() && function_exists('wc_add_notice')) {
            wc_add_notice((\PESM\Support\I18n::text('برای بلیت‌های رزروشده از دکمه «ثبت سفارش از سبد» در پنل صندلی‌های سبد استفاده کنید؛ پرداخت معمول ووکامرس برای این آیتم‌ها فعال نیست.')), 'error');
        }
    }

    public static function blockStoreCart($errors): void
    {
        if (self::hasCartSeatItems() && is_object($errors) && method_exists($errors, 'add')) {
            $errors->add('pesm_cart_checkout_unsupported',
                (\PESM\Support\I18n::text('پرداخت این بلیت‌ها تنها از فرم اختصاصی سبد افزونه مجاز است؛ Checkout Blocks هنوز تأیید نشده است.')));
        }
    }

    public static function blockStoreDirectAdd($product, array $cartItem): void
    {
        $id = (int)get_option('pesm_wc_product_id', 0);
        if ($id && $product && method_exists($product, 'get_id') &&
            (int)$product->get_id() === $id && !self::$adding) {
            throw new \RuntimeException((\PESM\Support\I18n::text('افزودن مستقیم محصول فنی بلیت به سبد مجاز نیست.')));
        }
    }

    public static function blockStoreCheckout(): void
    {
        if (self::hasCartSeatItems()) {
            throw new \RuntimeException((\PESM\Support\I18n::text('تکمیل سفارش این بلیت‌ها فقط از مسیر اختصاصی افزونه در محیط آزمایشی مجاز است.')));
        }
    }

    public static function echoCartEditor(): void { echo self::shortcode(); }

    public static function shortcode(): string
    {
        if (!function_exists('WC')) { return ''; }
        try { $cart = self::cart(); } catch (HoldException $e) { return ''; }
        $entries = [];
        foreach ($cart->get_cart() as $key => $item) {
            if (empty($item['pesm_cart_hold_id'])) { continue; }
            $entries[] = ['key'=>(string)$key, 'session'=>(int)$item['pesm_cart_session_id']];
        }
        if (!$entries) { return ''; }
        \PESM\Support\Ui::enqueue();
        wp_enqueue_style('pesm-cart-seats', PESM_URL . 'assets/css/cart-seats.css', ['pesm-ui'], \PESM\Support\Ui::assetVersion('assets/css/cart-seats.css'));
        wp_enqueue_script('pesm-cart-seats', PESM_URL . 'assets/js/cart-seats.js', ['pesm-i18n'], \PESM\Support\Ui::assetVersion('assets/js/cart-seats.js'), true);
        wp_add_inline_script('pesm-cart-seats', 'window.PESM_CART_CONFIG = ' . wp_json_encode([
            'apiRoot'=>esc_url_raw(rest_url('pesm/v1/')), 'cartNonce'=>self::nonce(),
        ]) . ';', 'before');
        $t = static fn (string $message): string => esc_html(\PESM\Support\I18n::text($message));
        $html = '<section class="pesm-ui pesm-cart-editor" dir="' . \PESM\Support\I18n::direction() . '"><header class="pesm-page-heading"><span class="pesm-panel-icon">'
            . \PESM\Support\Ui::icon('seat') . '</span><div><h3>' . $t('صندلی‌های سبد خرید شما') . '</h3><p>'
            . $t('جایگاه را بررسی یا ویرایش کنید؛ سپس سفارش هر جلسه را از همین بخش ثبت کنید.') . '</p></div></header>';
        foreach ($entries as $entry) {
            $html .= '<article class="pesm-cart-entry" data-cart-key="' . esc_attr($entry['key']) . '" data-session="' . esc_attr((string)$entry['session'])
                . '"><header class="pesm-panel-heading"><span class="pesm-panel-icon">' . \PESM\Support\Ui::icon('calendar') . '</span><div><p class="pesm-eyebrow">' . $t('رزرو موقت شما') . '</p><h4>'
                . $t('جلسه') . ' #' . esc_html((string)$entry['session']) . '</h4></div></header>'
                . '<div class="pesm-form-actions"><button type="button" class="pesm-cart-load pesm-button-secondary">' . $t('نمایش نقشه و انتخاب جایگاه') . '</button>'
                . '<button type="button" class="pesm-cart-save pesm-button-secondary" disabled>' . $t('ثبت تغییر صندلی‌ها') . '</button></div><div class="pesm-cart-board" hidden></div>'
                . '<p class="pesm-cart-summary" aria-live="polite"></p><p class="pesm-cart-message pesm-status" role="status" aria-live="polite"></p>'
                . '<form class="pesm-cart-complete pesm-checkout-form" autocomplete="on"><h4>' . $t('مشخصات خریدار') . '</h4><div class="pesm-form-grid">'
                . '<label>' . $t('نام') . '<input name="first_name" maxlength="90" required autocomplete="given-name"></label>'
                . '<label>' . $t('نام خانوادگی') . '<input name="last_name" maxlength="90" required autocomplete="family-name"></label>'
                . '<label>' . $t('موبایل') . '<input name="phone" type="tel" maxlength="30" required inputmode="tel" autocomplete="tel" dir="ltr"></label>'
                . '<label>' . $t('ایمیل') . '<input type="email" name="email" maxlength="190" autocomplete="email" dir="ltr"></label></div>'
                . '<details class="pesm-coupon-details"><summary>' . $t('کد تخفیف دارید؟') . '</summary><label>' . $t('کد تخفیف') . '<input name="coupon_code" maxlength="32" autocomplete="off" dir="ltr"></label></details>'
                . '<div class="pesm-form-actions"><button type="submit" class="pesm-button-primary">' . $t('ثبت سفارش از سبد') . \PESM\Support\Ui::icon('arrow') . '</button></div></form></article>';
        }
        return $html . '</section>';
    }

    private static function nonce(): string
    {
        $session = WC()->session;
        $nonce = (string) $session->get('pesm_cart_nonce', '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $nonce)) {
            $nonce = bin2hex(random_bytes(32));
            $session->set('pesm_cart_nonce', $nonce);
        }
        return $nonce;
    }

    private static function validateNonce(string $provided): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $provided) ||
            !hash_equals(self::nonce(), $provided)) {
            throw new HoldException('csrf_denied', (\PESM\Support\I18n::text('اعتبار درخواست سبد خرید نامعتبر است.')), 403);
        }
    }

    private static function checkOrigin(): void
    {
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        $site = home_url('/');
        $host = wp_parse_url($site, PHP_URL_HOST);
        $scheme = wp_parse_url($site, PHP_URL_SCHEME);
        $port = wp_parse_url($site, PHP_URL_PORT);
        $expected = $scheme . '://' . $host . ($port ? ':' . $port : '');
        if ($origin !== '' && rtrim($origin, '/') !== $expected) {
            throw new HoldException('csrf_denied', (\PESM\Support\I18n::text('درخواست از دامنه نامعتبر ارسال شده است.')), 403);
        }
    }

    public static function previewEndpoint(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            self::cart(); self::validateNonce((string)$request->get_header('X-PESM-Cart-Nonce'));
            $item = self::item((string)$request['cart_key']);
            $hold = self::cartHoldForDisplay($item);
            return new \WP_REST_Response(['session_id'=>(int)$item['pesm_cart_session_id'],
                'seat_ids'=>$hold['seat_ids'], 'expires_at_utc'=>$hold['expires_at_utc']],
                200, ['Cache-Control'=>'no-store']);
        } catch (HoldException $e) { return new \WP_Error($e->errorCode, $e->getMessage(), ['status'=>$e->httpStatus]); }
    }

    public static function attachEndpoint(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            self::checkOrigin();
            if (!RateLimiter::allow('cart_attach')) { throw new HoldException('rate_limited',(\PESM\Support\I18n::text('تعداد درخواست بیش از حد مجاز است.')),429); }
            $result = (new self())->attach((int)$request['session_id'], (string)$request->get_header('X-PESM-Hold-Token'),
                is_array($request['attendees'] ?? null) ? $request['attendees'] : null);
            return new \WP_REST_Response($result, 200, ['Cache-Control'=>'no-store']);
        } catch (HoldException $e) { return new \WP_Error($e->errorCode, $e->getMessage(), ['status'=>$e->httpStatus]); }
    }

    public static function replaceEndpoint(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            self::checkOrigin(); self::cart(); self::validateNonce((string)$request->get_header('X-PESM-Cart-Nonce'));
            if (!RateLimiter::allow('cart_replace')) { throw new HoldException('rate_limited',(\PESM\Support\I18n::text('تعداد درخواست بیش از حد مجاز است.')),429); }
            $result = (new self())->replace((string)$request['cart_key'], (array)$request['seat_ids'], (string)$request['request_key']);
            return new \WP_REST_Response($result, 200, ['Cache-Control'=>'no-store']);
        } catch (HoldException $e) { return new \WP_Error($e->errorCode, $e->getMessage(), ['status'=>$e->httpStatus]); }
    }

    public static function completeEndpoint(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            self::checkOrigin(); self::cart(); self::validateNonce((string)$request->get_header('X-PESM-Cart-Nonce'));
            if (!RateLimiter::allow('cart_complete')) { throw new HoldException('rate_limited',(\PESM\Support\I18n::text('تعداد درخواست بیش از حد مجاز است.')),429); }
            $buyer = ['first_name'=>(string)$request['first_name'], 'last_name'=>(string)$request['last_name'],
                'phone'=>(string)$request['phone'], 'email'=>(string)$request['email'],
                'coupon_code'=>(string)($request->get_param('coupon_code') ?? '')];
            $result = (new self())->complete((string)$request['cart_key'], $buyer);
            return new \WP_REST_Response($result, 200, ['Cache-Control'=>'no-store']);
        } catch (HoldException $e) { return new \WP_Error($e->errorCode, $e->getMessage(), ['status'=>$e->httpStatus]); }
    }

    private static function technicalProduct(): object
    {
        $id = (int) get_option('pesm_wc_product_id', 0);
        $product = $id ? wc_get_product($id) : false;
        if ($product && $product->is_type('simple')) { return $product; }
        $product = new \WC_Product_Simple();
        $product->set_name((\PESM\Support\I18n::text('بلیت رویداد (محصول فنی)')));
        $product->set_status('publish');
        $product->set_catalog_visibility('hidden');
        $product->set_virtual(true);
        $product->set_sold_individually(false);
        $product->set_regular_price('0');
        $product->set_tax_status('none');
        $product->save();
        update_option('pesm_wc_product_id', $product->get_id(), false);
        return $product;
    }
}
