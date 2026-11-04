<?php
declare(strict_types=1);
namespace PESM\Domain\Allocation;

/** WC cart is a private review surface; SQL allocation remains authoritative. */
final class CartService
{
    private static bool $adding=false;

    public static function register(): void
    {
        add_filter('woocommerce_add_to_cart_validation',[self::class,'validateAddition'],20,3);
        add_filter('woocommerce_is_purchasable',[self::class,'purchasable'],30,2);
        add_action('woocommerce_before_calculate_totals',[self::class,'calculatePrices'],950);
        add_filter('woocommerce_cart_item_quantity',[self::class,'quantity'],20,3);
        add_filter('woocommerce_cart_item_name',[self::class,'itemName'],20,3);
        add_filter('woocommerce_update_cart_validation',[self::class,'validateQuantity'],20,4);
        add_action('woocommerce_cart_item_removed',[self::class,'removed'],20,2);
        add_action('woocommerce_checkout_process',[self::class,'blockNative']);
        add_action('woocommerce_check_cart_items',[self::class,'blockNative']);
        add_action('woocommerce_store_api_cart_errors',[self::class,'blockStoreCart'],20,2);
        add_action('woocommerce_store_api_validate_cart_item',[self::class,'blockStoreAdd'],20,2);
        add_action('woocommerce_store_api_checkout_update_order_from_request',[self::class,'blockStoreCheckout'],1,2);
        add_action('woocommerce_after_cart_table',[self::class,'render']);
        add_shortcode('pesm_cart_seats',[self::class,'shortcode']);
    }

    public static function attach(int $sessionId,string $token,array $attendees=[],array $modes=[]): array
    {
        $cart=self::cart();self::cleanup();$hold=ReservationService::view($sessionId,$token);self::active($hold);$entries=self::entries();
        foreach($cart->get_cart()as$key=>$item){
            if(empty($item['pesm_allocation_hold_id'])){continue;}
            if((int)$item['pesm_allocation_hold_id']===$hold['hold_id']){
                if(!isset($entries[$key])||!hash_equals($entries[$key]['token'],$token)){Policy::fail('cart_conflict');}
                return ['cart_key'=>$key,'cart_url'=>wc_get_cart_url(),'cart_nonce'=>self::nonce(),'existing'=>true];
            }
            if((int)$item['pesm_allocation_session_id']===$sessionId){Policy::fail('cart_conflict');}
        }
        $product=self::product();self::$adding=true;
        try{$key=$cart->add_to_cart((int)$product->get_id(),1,0,[],['pesm_allocation_hold_id'=>$hold['hold_id'],'pesm_allocation_session_id'=>$sessionId,'pesm_allocation_unique'=>hash('sha256',$sessionId.':'.$hold['hold_id'])]);}finally{self::$adding=false;}
        if(!is_string($key)||$key===''){Policy::fail('cart_unavailable',503);}
        $entries[$key]=['session_id'=>$sessionId,'hold_id'=>$hold['hold_id'],'token'=>$token,'attendees'=>$attendees,'ticket_modes'=>$modes];self::saveEntries($entries);$cart->calculate_totals();self::persist();
        return ['cart_key'=>$key,'cart_url'=>wc_get_cart_url(),'cart_nonce'=>self::nonce(),'existing'=>false];
    }

    public static function preview(string $key,string $nonce): array
    {
        self::validateNonce($nonce);$entry=self::entry($key);$hold=ReservationService::view($entry['session_id'],$entry['token']);
        if($hold['hold_id']!==$entry['hold_id']){Policy::fail('cart_conflict');}
        unset($hold['hold_token'],$hold['token']);
        return $hold+['cart_key'=>$key,'session_id'=>$entry['session_id'],'units'=>self::units($hold['quote']),'attendees'=>$entry['attendees'],'ticket_modes'=>$entry['ticket_modes']];
    }

    public static function replace(string $key,array $units,string $requestKey,string $nonce): array
    {
        self::validateNonce($nonce);$entry=self::entry($key);$hold=ReservationService::mutate($entry['session_id'],$entry['token'],'replace',$units,$requestKey);$cart=self::cart();
        // No hold credentials are written into cart item metadata or the Store API.
        $cart->set_quantity($key,1,false);$cart->calculate_totals();self::persist();unset($hold['hold_token'],$hold['token']);return $hold+['cart_key'=>$key,'session_id'=>$entry['session_id']];
    }

    public static function complete(string $key,array $buyer,string $nonce): array
    {
        self::validateNonce($nonce);$done=(array)WC()->session->get('pesm_allocation_cart_completed',[]);
        if(isset($done[$key])&&$done[$key]['expires']>time()){return $done[$key]['result']+['idempotent_replay'=>true];}
        $entry=self::entry($key);$buyer['attendees']=$buyer['attendees']??$entry['attendees'];$buyer['ticket_modes']=$buyer['ticket_modes']??$entry['ticket_modes'];
        $result=CheckoutService::prepare($entry['session_id'],$entry['token'],$buyer);
        $done[$key]=['expires'=>time()+3600,'result'=>$result];$done=array_filter($done,static fn($v)=>$v['expires']>time());if(count($done)>20){$done=array_slice($done,-20,null,true);}WC()->session->set('pesm_allocation_cart_completed',$done);
        self::cart()->remove_cart_item($key);self::persist();return $result;
    }

    public static function nonce(): string
    {
        self::cart();$nonce=(string)WC()->session->get('pesm_allocation_cart_nonce','');
        if(!preg_match('/^[a-f0-9]{64}$/D',$nonce)){$nonce=bin2hex(random_bytes(32));WC()->session->set('pesm_allocation_cart_nonce',$nonce);}return $nonce;
    }
    public static function validateNonce(string $nonce): void
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$nonce)||!hash_equals(self::nonce(),$nonce)){Policy::fail('csrf_denied',403);}
    }
    public static function validateAddition(bool $valid,int $productId,int $quantity): bool
    {
        if($productId===(int)get_option('pesm_allocation_wc_product_id',0)&&!self::$adding){return false;}return $valid;
    }
    public static function purchasable(bool $valid,object $product): bool
    {
        if((int)$product->get_id()===(int)get_option('pesm_allocation_wc_product_id',0)){return self::$adding||self::hasItems();}return $valid;
    }
    public static function calculatePrices(object $cart): void
    {
        foreach($cart->get_cart()as$key=>$item){if(empty($item['pesm_allocation_hold_id'])||empty($item['data'])){continue;}
            try{$entry=self::entry($key);$hold=ReservationService::view($entry['session_id'],$entry['token']);self::active($hold);$item['data']->set_price(\PESM\Domain\Checkout\CheckoutPolicy::amount($hold['quote']['total_minor'],$hold['quote']['price_decimals']));}catch(\Throwable){/* Native checkout stays blocked for expired or inconsistent lines. */}
        }
    }
    public static function quantity(string $html,string $key,array $item): string{return empty($item['pesm_allocation_hold_id'])?$html:'<span class="pesm-cart-quantity">1</span>';}
    public static function itemName(string $name,array $item,string $key): string{return empty($item['pesm_allocation_hold_id'])?$name:esc_html(Messages::text('cart_product').' #'.(int)$item['pesm_allocation_session_id']);}
    public static function validateQuantity(bool $valid,string $key,array $item,int $quantity): bool{return !empty($item['pesm_allocation_hold_id'])&&$quantity!==1?false:$valid;}
    public static function removed(string $key,object $cart): void
    {
        $entries=self::entries();$entry=$entries[$key]??null;if(!$entry){return;}
        try{ReservationService::mutate($entry['session_id'],$entry['token'],'release',[],bin2hex(random_bytes(24)));}catch(\Throwable){/* converted stock belongs to order reconciliation */}
        unset($entries[$key]);self::saveEntries($entries);
    }
    public static function blockNative(): void{if(self::hasItems()&&function_exists('wc_add_notice')){wc_add_notice(Messages::text('cart_native'),'error');}}
    public static function blockStoreCart(object $errors): void{if(self::hasItems()&&method_exists($errors,'add')){$errors->add('pesm_allocation_checkout_unsupported',Messages::text('cart_native'));}}
    public static function blockStoreAdd(object $product,array $item): void{if((int)$product->get_id()===(int)get_option('pesm_allocation_wc_product_id',0)&&!self::$adding){throw new \RuntimeException(Messages::text('cart_direct_add'));}}
    public static function blockStoreCheckout(): void{if(self::hasItems()){throw new \RuntimeException(Messages::text('cart_native'));}}
    public static function render(): void
    {
        if(!function_exists('WC')||!WC()->cart||!WC()->session){return;}
        foreach(WC()->cart->get_cart()as$key=>$item){if(empty($item['pesm_allocation_hold_id'])){continue;}
            echo \PESM\Support\VenueUi::render('cart',['sessionId'=>(int)$item['pesm_allocation_session_id'],'cartKey'=>$key,'cartNonce'=>self::nonce()]);
        }
    }
    public static function shortcode(): string
    {
        $legacy=\PESM\Integrations\CartSeatManager::shortcode();ob_start();self::render();return $legacy.(string)ob_get_clean();
    }
    public static function cleanup(): int
    {
        $cart=self::cart();$removed=0;
        foreach(self::entries()as$key=>$entry){try{$hold=ReservationService::view((int)$entry['session_id'],(string)$entry['token']);
                if(in_array($hold['status'],['expired','released'],true)||($hold['status']==='active'&&$hold['expires_at_utc']<=$hold['server_time_utc'])){$cart->remove_cart_item($key);++$removed;}
            }catch(\PESM\Domain\Booking\HoldException$e){if(in_array($e->errorCode,['permission_denied','invalid_session'],true)){$cart->remove_cart_item($key);++$removed;}}
        }
        return $removed;
    }
    private static function units(array $quote): array{return array_map(static fn($l)=>['type'=>$l['unit_type']==='seat'?'seat':'group','id'=>$l['unit_id']],$quote['lines']);}
    private static function active(array $hold): void{if($hold['status']!=='active'||$hold['expires_at_utc']<=$hold['server_time_utc']){Policy::fail('hold_expired',410);}}
    private static function cart(): object
    {
        if(!function_exists('WC')||!function_exists('wc_load_cart')){Policy::fail('woocommerce_required',503);}
        if(!WC()->session||!WC()->cart){wc_load_cart();}if(!WC()->session||!WC()->cart){Policy::fail('cart_unavailable',503);}
        if((!method_exists(WC()->session,'has_session')||!WC()->session->has_session())&&method_exists(WC()->session,'set_customer_session_cookie')&&!headers_sent()){WC()->session->set_customer_session_cookie(true);}return WC()->cart;
    }
    private static function entries(): array{self::cart();return (array)WC()->session->get('pesm_allocation_cart_holds',[]);}
    private static function saveEntries(array $entries): void{WC()->session->set('pesm_allocation_cart_holds',$entries);}
    private static function entry(string $key): array
    {
        if($key===''||strlen($key)>128){Policy::fail('invalid_cart_item',422);}$cart=self::cart();$item=$cart->get_cart_item($key);$entry=self::entries()[$key]??null;
        if(!is_array($item)||!is_array($entry)||empty($item['pesm_allocation_hold_id'])||(int)$item['pesm_allocation_hold_id']!==$entry['hold_id']||(int)$item['pesm_allocation_session_id']!==$entry['session_id']){Policy::fail('permission_denied',403);}return $entry;
    }
    private static function hasItems(): bool
    {
        if(!function_exists('WC')||!WC()->cart){return false;}foreach(WC()->cart->get_cart()as$item){if(!empty($item['pesm_allocation_hold_id'])){return true;}}return false;
    }
    private static function persist(): void{if(WC()->session&&method_exists(WC()->session,'save_data')){WC()->session->save_data();}}
    private static function product(): object
    {
        $id=(int)get_option('pesm_allocation_wc_product_id',0);$product=$id?wc_get_product($id):false;if($product&&$product->is_type('simple')){return $product;}
        $product=new \WC_Product_Simple();$product->set_name(Messages::text('cart_product'));$product->set_status('publish');$product->set_catalog_visibility('hidden');$product->set_virtual(true);$product->set_regular_price('0');$product->set_tax_status('none');$product->save();update_option('pesm_allocation_wc_product_id',$product->get_id(),false);return $product;
    }
}
