<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/I18n.php';
require_once __DIR__ . '/../src/Support/Ui.php';

/** Behavioral WC cart/session double. Not a WordPress, MySQL, Cart Blocks or payment test. */
define('PESM_URL', 'https://example.test/wp-content/plugins/pesm/');
define('PESM_VERSION','1.0.0');
define('PESM_DIR', dirname(__DIR__) . '/');
require_once __DIR__ . '/../src/Domain/Booking/HoldException.php';
require_once __DIR__ . '/../src/Domain/Booking/HoldPolicy.php';
require_once __DIR__ . '/../src/Domain/Checkout/CheckoutPolicy.php';
require_once __DIR__ . '/../src/Domain/Checkout/AttendeeManifest.php';
require_once __DIR__ . '/../src/Integrations/CartSeatManager.php';

use PESM\Domain\Booking\HoldException;
use PESM\Integrations\CartSeatManager as Cart;

$checks = 0;
function expect(bool $value, string $name): void {
    global $checks;
    if (!$value) { throw new RuntimeException('FAILED: ' . $name); }
    ++$checks;
}
function expectRejection(callable $fn, string $code): void {
    try { $fn(); }
    catch (HoldException $e) { expect($e->errorCode === $code, $code); return; }
    throw new RuntimeException('Missing failure: ' . $code);
}
function get_option(string $name, mixed $default = false): mixed {
    return $name === 'pesm_wc_product_id' ? 111 : $default;
}
function wc_get_price_decimals(): int { return 2; }
function wc_get_product(int $id): FakeProduct|false { return $id === 111 ? new FakeProduct() : false; }
function wc_get_cart_url(): string { return 'https://example.test/cart/'; }
function WC(): FakeWoo { return $GLOBALS['woo']; }
function wc_add_notice(string $message, string $type): void { $GLOBALS['notices'][] = [$type,$message]; }
function wp_parse_url(string $url, int $component): string|int|false|null { return parse_url($url, $component); }
function home_url(string $suffix = ''): string { return 'https://example.test/blog/' . ltrim($suffix, '/'); }
function esc_html(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
function esc_attr(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
function esc_url_raw(string $s): string { return $s; }
function rest_url(string $s): string { return 'https://example.test/wp-json/' . $s; }
function wp_json_encode(mixed $x): string { return json_encode($x, JSON_THROW_ON_ERROR); }
function wp_add_inline_script(string $name, string $script, string $position): void {}
function wp_enqueue_script(string $name, string $src, array $deps, string $ver, bool $footer): void {}
function wp_enqueue_style(string $name, string $src, array $deps, string $ver): void {}
function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): void { $GLOBALS['filters'][$hook] = $callback; }
function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): void { $GLOBALS['actions'][$hook] = $callback; }
function add_shortcode(string $name, callable $callback): void { $GLOBALS['shortcodes'][$name] = $callback; }
function register_rest_route(string $ns, string $path, array $config): void { $GLOBALS['routes'][$path] = $config; }
class WP_REST_Request implements ArrayAccess {
    public function __construct(private array $params, private array $headers = []) {}
    public function offsetExists(mixed $k): bool { return array_key_exists($k,$this->params); }
    public function offsetGet(mixed $k): mixed { return $this->params[$k] ?? null; }
    public function offsetSet(mixed $k,mixed $v): void { $this->params[$k]=$v; }
    public function offsetUnset(mixed $k): void { unset($this->params[$k]); }
    public function get_header(string $key): string { return $this->headers[$key] ?? ''; }
}
class WP_REST_Response {
    public function __construct(public mixed $data, public int $status = 200, public array $headers = []) {}
}
class WP_Error {
    public array $errors = [];
    public function __construct(public string $code = '', public string $message = '', public array $data = []) {}
    public function add(string $code,string $message): void { $this->errors[$code] = $message; }
}

final class FakeProduct {
    public float $price = 0;
    public function get_id(): int { return 111; }
    public function is_type(string $type): bool { return $type === 'simple'; }
    public function set_price(float $price): void { $this->price = $price; }
}
final class FakeSession {
    private array $values = [];
    public function set_customer_session_cookie(bool $set): void {}
    public function get(string $name, mixed $default = ''): mixed { return $this->values[$name] ?? $default; }
    public function set(string $name, mixed $value): void { $this->values[$name] = $value; }
}
final class FakeCart {
    public array $cart_contents = [];
    public array $removed_cart_contents = [];
    public function get_cart(): array { return $this->cart_contents; }
    public function get_cart_item(string $key): array|false { return $this->cart_contents[$key] ?? false; }
    public function add_to_cart(int $pid, int $qty, int $variation, array $attrs, array $extra): string|false {
        expect($pid === 111, 'technical product');
        expect(Cart::purchasable(false, new FakeProduct()), 'authorized insertion');
        expect(Cart::validateAddition(true, $pid, $qty), 'authorized cart add');
        $this->cart_contents['wc-line-1'] = array_merge($extra, [
            'product_id'=>$pid, 'quantity'=>$qty,'data'=>new FakeProduct()]);
        return 'wc-line-1';
    }
    public function calculate_totals(): void { Cart::calculatePrices($this); }
    public function set_quantity(string $key, int $n, bool $refresh = true): void { $this->cart_contents[$key]['quantity']=$n; }
    public function remove_cart_item(string $key): bool {
        $this->removed_cart_contents[$key]=$this->cart_contents[$key];
        unset($this->cart_contents[$key]);
        return true;
    }
}
final class FakeWoo {
    public FakeCart $cart;
    public FakeSession $session;
    public function __construct() { $this->cart = new FakeCart(); $this->session = new FakeSession(); }
}
final class FakeDb {
    public string $prefix = 'wp_';
    public bool $mutated = false;
    public function prepare(string $sql, mixed ...$values): string {
        return vsprintf($sql, array_map(static fn($v)=>is_int($v)?$v:"'".str_replace("'","''",(string)$v)."'", $values));
    }
    public function get_row(string $sql): ?object {
        if (str_contains($sql, 'FROM wp_pesm_holds h JOIN wp_pesm_sessions s')) {
            return str_contains($sql, "token_hash='" . hash('sha256', str_repeat('a',64)) . "'")
                ? (object)['id'=>7,'status'=>'active','expires_at'=>'2026-11-06 12:10:00','mode'=>'seated','unit_price_minor'=>4500]
                : null;
        }
        if (str_contains($sql, 'FROM wp_pesm_holds WHERE session_id=')) {
            return (object)['id'=>7,'status'=>'active'];
        }
        throw new RuntimeException('unknown row query: ' . $sql);
    }
    public function get_var(string $sql): string|int {
        if ($sql === 'SELECT UTC_TIMESTAMP()') return '2026-11-06 12:00:00';
        if (str_contains($sql,'SELECT unit_price_minor FROM wp_pesm_sessions')) return 4500;
        throw new RuntimeException('unknown var query: ' . $sql);
    }
    public function get_col(string $sql): array {
        if (str_contains($sql, 'wp_pesm_hold_items')) return [10,11];
        if (str_contains($sql, 'wp_pesm_session_seats')) return [10,11];
        throw new RuntimeException('unknown col query: ' . $sql);
    }
}

$woo = $GLOBALS['woo'] = new FakeWoo();
$wpdb = new FakeDb();
$GLOBALS['notices'] = [];
Cart::register();
Cart::routes();
expect(isset($GLOBALS['actions']['woocommerce_cart_item_removed']), 'remove hook fires after removal');
expect(isset($GLOBALS['actions']['woocommerce_store_api_checkout_update_order_from_request']), 'blocks checkout fail-closed hook');
expect(isset($GLOBALS['actions']['woocommerce_store_api_cart_errors']), 'early Store API cart validation');
expect(isset($GLOBALS['routes']['/cart/replace']), 'replace endpoint exists');
expect(isset($GLOBALS['routes']['/cart/preview']), 'preview endpoint exists');
expect(isset($GLOBALS['routes']['/cart/complete']), 'order endpoint exists');
expect(isset($GLOBALS['shortcodes']['pesm_cart_seats']), 'shortcode exists');
expect(!Cart::purchasable(false, new FakeProduct()), 'technical product not publicly purchasable');
expect(!Cart::validateAddition(true,111,2), 'direct addition rejected');
expect(Cart::validateAddition(true,999,2), 'other products unchanged');
expectRejection(fn()=>(new Cart())->attach(1, 'bad'), 'invalid_token');
expectRejection(fn()=>(new Cart())->attach(1, str_repeat('b',64)), 'permission_denied');
$token = str_repeat('a',64);
$first = (new Cart())->attach(1,$token);
expect($first['cart_key']==='wc-line-1' && $first['seat_ids'] === [10,11], 'attach active hold');
expect($woo->cart->get_cart_item('wc-line-1')['pesm_cart_hold_token'] === $token, 'token server-side only');
expect($woo->cart->get_cart_item('wc-line-1')['data']->price === 45., 'server-side cart price');
expect((new Cart())->attach(1,$token)['existing'] === true, 'duplicate attach idempotent');
expect(Cart::purchasable(false,new FakeProduct()), 'technical product remains in existing cart');
expect(!Cart::validateAddition(true,111,2), 'manual direct add stays rejected');
expect(!Cart::validateQuantityUpdate(true, 'wc-line-1',$woo->cart->get_cart_item('wc-line-1'),3), 'quantity override rejected');
expect(Cart::validateQuantityUpdate(true,'wc-line-1',$woo->cart->get_cart_item('wc-line-1'),2), 'unchanged quantity allowed');
expect(str_contains(Cart::readonlyQuantity('edit','wc-line-1',$woo->cart->get_cart_item('wc-line-1')),'>2</span>'), 'read-only quantity');
expectRejection(fn()=>(new Cart())->replace('guessed-key',[10,12],str_repeat('c',32)), 'permission_denied');
$markup = Cart::shortcode();
expect(str_contains($markup,'pesm-cart-entry') && str_contains($markup,'wc-line-1'), 'editor rendered');
expect(!str_contains($markup, $token), 'hold token never rendered');
$restNonce = (string)$woo->session->get('pesm_cart_nonce');
expect(strlen($restNonce) === 64 && ctype_xdigit($restNonce), 'cart-specific random CSRF token');
$bad = Cart::previewEndpoint(new WP_REST_Request(['cart_key'=>'wc-line-1'],['X-PESM-Cart-Nonce'=>str_repeat('0',64)]));
expect($bad instanceof WP_Error && $bad->code === 'csrf_denied', 'bad nonce denied');
$good = Cart::previewEndpoint(new WP_REST_Request(['cart_key'=>'wc-line-1'],['X-PESM-Cart-Nonce'=>$restNonce]));
expect($good instanceof WP_REST_Response && $good->data['seat_ids'] === [10,11], 'cart-scoped preview');
expect(!array_key_exists('token',$good->data), 'preview never exposes bearer token');
$guessed = Cart::previewEndpoint(new WP_REST_Request(['cart_key'=>'other'],['X-PESM-Cart-Nonce'=>$restNonce]));
expect($guessed instanceof WP_Error && $guessed->code === 'permission_denied', 'other cart key denied');
$storeErrors = new WP_Error();
Cart::blockStoreCart($storeErrors);
expect(isset($storeErrors->errors['pesm_cart_checkout_unsupported']), 'Store API blocks before order creation');
try { Cart::blockStoreDirectAdd(new FakeProduct(), []); throw new RuntimeException('Store API should reject direct add'); }
catch (RuntimeException $e) { expect(str_contains($e->getMessage(),'مجاز نیست'), 'Store API direct add rejected'); }
Cart::blockNativeCheckout();
expect(count($GLOBALS['notices']) >= 2, 'native checkout blocked with error notice');
try { Cart::blockStoreCheckout(); throw new RuntimeException('store checkout should fail'); }
catch (RuntimeException $e) { expect(str_contains($e->getMessage(),'اختصاصی'), 'block Store API checkout'); }
$woo->cart->cart_contents = [];
$attendees = [
    ['seat_id'=>10,'first_name'=>'علی','last_name'=>'محمدی'],
    ['seat_id'=>11,'first_name'=>'سارا','last_name'=>'رضایی'],
];
$withPeople = (new Cart())->attach(1,$token,$attendees);
expect($withPeople['existing']===false, 'attendee manifest can be staged');
$stored = $woo->cart->get_cart_item('wc-line-1')['pesm_cart_attendees'];
expect(count($stored) === 2 && $stored[0]['first_name'] === 'علی', 'attendee name stored only in WC session');
expect(!str_contains(Cart::shortcode(),'محمدی'), 'private participant names not output in cart editor');
expect((new Cart())->attach(1,$token,$attendees)['existing']===true, 'same attendee manifest duplicate attaches');
expectRejection(fn()=>(new Cart())->attach(1,$token,[
    ['seat_id'=>10,'first_name'=>'دیگر','last_name'=>'محمدی'],
    ['seat_id'=>11,'first_name'=>'سارا','last_name'=>'رضایی']
]), 'idempotency_conflict');
expectRejection(fn()=>(new Cart())->attach(1,$token,[['seat_id'=>10,'first_name'=>'کم','last_name'=>'آزمایشی']]), 'invalid_attendees');
$woo->cart->cart_contents = [];
expect(Cart::purchasable(false,new FakeProduct()) === false, 'product no longer purchasable after cart removal');
Cart::blockStoreCheckout(); expect(true,'no unrelated checkout blocked');
$errorsAfterRemoval = new WP_Error(); Cart::blockStoreCart($errorsAfterRemoval);
expect($errorsAfterRemoval->errors === [], 'unrelated Store cart remains allowed');
echo "PASS: {$checks} WC Cart bridge mock checks (no real WooCommerce, Blocks or InnoDB).\n";
