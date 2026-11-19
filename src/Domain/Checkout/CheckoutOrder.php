<?php

declare(strict_types=1);

namespace PESM\Domain\Checkout;

use RuntimeException;
use Throwable;

/** The Woo order becomes payable only after its inventory association commits. */
final class CheckoutOrder
{
    public const DRAFT = 'checkout-draft';
    private const MODES = [
        'seated' => ['holds', 'order_links', '_pesm_hold_id'],
        'general' => ['capacity_holds', 'capacity_order_links', '_pesm_capacity_hold_id'],
        'allocated' => ['allocation_holds', 'allocation_orders', '_pesm_allocation_hold_id'],
    ];

    public static function stage(string $mode, int $sessionId, int $holdId): \WC_Order
    {
        $order = new \WC_Order();
        $order->set_status(self::DRAFT);
        $order->set_created_via('pesm');
        $order->set_prices_include_tax(get_option('woocommerce_prices_include_tax') === 'yes');
        $order->set_customer_ip_address(\WC_Geolocation::get_ip_address());
        $order->set_customer_user_agent(wc_get_user_agent());
        $order->update_meta_data('_pesm_is_ticket_order', 'yes');
        $order->update_meta_data('_pesm_mode', $mode);
        $order->update_meta_data('_pesm_session_id', $sessionId);
        $order->update_meta_data(self::MODES[$mode][2], $holdId);
        return $order;
    }

    /** Record the Woo identity while the hold is still reserved for this checkout. */
    public static function remember(string $mode, int $holdId, object $order): int
    {
        global $wpdb;
        $id = (int) $order->get_id();
        [$holds, , $meta] = self::MODES[$mode];
        if ($id < 1) { throw new RuntimeException('WooCommerce did not create a draft order.'); }
        $saved = new \WC_Order($id);
        $total = FinancialSnapshot::integer($order->get_meta('_pesm_total_minor'));
        $currency = (string) $order->get_meta('_pesm_currency');
        $decimals = FinancialSnapshot::integer($order->get_meta('_pesm_price_decimals'), 6);
        FinancialSnapshot::validate($saved, $total, $currency, $decimals, $total === 0);
        if ($saved->get_status() !== self::DRAFT || (int) $saved->get_meta($meta) !== $holdId ||
            $saved->get_meta('_pesm_is_ticket_order') !== 'yes' || $saved->get_meta('_pesm_mode') !== $mode ||
            (int) $saved->get_meta('_pesm_session_id') !== (int) $order->get_meta('_pesm_session_id') ||
            FinancialSnapshot::integer($saved->get_meta('_pesm_total_minor')) !== $total ||
            FinancialSnapshot::integer($saved->get_meta('_pesm_price_decimals'), 6) !== $decimals ||
            $saved->get_meta('_pesm_currency') !== $currency || !$saved->get_items() ||
            count($saved->get_items()) !== count($order->get_items()) ||
            $wpdb->update($wpdb->prefix . 'pesm_' . $holds, ['order_id'=>$id],
            ['id'=>$holdId, 'status'=>'checkout_creating', 'order_id'=>0]) !== 1) {
            throw new RuntimeException('Could not record a staged WooCommerce order.');
        }
        return $id;
    }

    /** An interrupted publish is repeatable with the original hold token. */
    public static function publish(object $order): void
    {
        if ($order->get_status() !== self::DRAFT) { return; }
        if (!self::canPay(true, $order)) {
            throw new RuntimeException('Could not publish the linked WooCommerce order.');
        }
        self::updateStatus($order, 'pending');
    }

    public static function updateStatus(object $order, string $status, string $note = '', bool $manual = false): void
    {
        // WooCommerce can log a failed data-store write and still return true from update_status().
        if (!$order->update_status($status, $note, $manual) ||
            (new \WC_Order($order->get_id()))->get_status('edit') !== $status) {
            throw new RuntimeException('WooCommerce did not save the requested order status.');
        }
    }

    /** WooCommerce's draft status is payable in other checkout flows. Exclude our own drafts there. */
    public static function paymentStatuses(array $statuses, object $order): array
    {
        return $order->get_meta('_pesm_is_ticket_order') === 'yes'
            ? array_values(array_diff($statuses, [self::DRAFT])) : $statuses;
    }

    public static function canPay(bool $payable, object $order): bool
    {
        if (!$payable || $order->get_meta('_pesm_is_ticket_order') !== 'yes') { return $payable; }
        [$holds, $links, $meta] = self::MODES[(string) $order->get_meta('_pesm_mode')] ?? self::MODES['seated'];
        global $wpdb;
        $p = $wpdb->prefix . 'pesm_';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$p}{$links} l JOIN {$p}{$holds} h ON h.id=l.hold_id AND h.order_id=l.order_id
             JOIN {$p}sessions s ON s.id=h.session_id JOIN {$p}events e ON e.id=s.event_id
             WHERE l.order_id=%d AND l.hold_id=%d AND h.session_id=%d AND l.status='pending'
             AND h.status='payment_pending' AND h.expires_at>UTC_TIMESTAMP()
             AND s.status<>'cancelled' AND e.status<>'cancelled'", (int) $order->get_id(),
            (int) $order->get_meta($meta), (int) $order->get_meta('_pesm_session_id')
        )) === 1;
    }

    /** A failed link can cancel an unpaid draft. Preserve it for recovery if the commit outcome is unknown. */
    public static function onFailure(?object $order, string $mode, int $holdId): void
    {
        $id = (int) ($order?->get_id() ?? 0);
        error_log('PESM checkout needs reconciliation for ' . $mode . ' hold ' . $holdId . ' (order ' . $id . ').');
        if ($id < 1) { return; }
        try {
            $saved = new \WC_Order($id);
            $note = 'PESM: checkout did not finish. Verify the reservation before collecting payment.';
            global $wpdb;
            $links = $wpdb->prefix . 'pesm_' . self::MODES[$mode][1];
            $linked = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$links} WHERE order_id=%d", $id));
            if ((string) $linked === '0' && $saved->get_status() === self::DRAFT && !$saved->get_date_paid()) {
                self::updateStatus($saved, 'cancelled', $note);
                return;
            }
            $saved->add_order_note($note);
        } catch (Throwable $e) {
            // The ID in the log and the held inventory remain available for reconciliation.
        }
    }
}
