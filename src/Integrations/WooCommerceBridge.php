<?php

declare(strict_types=1);

namespace PESM\Integrations;

use PESM\Domain\Ticket\TicketService;
use PESM\Domain\GeneralAdmission\FreeAdmissionService;
use PESM\Domain\Notification\NotificationQueue;
use PESM\Domain\Pricing\DiscountService;
use Throwable;

final class WooCommerceBridge
{
    public static function register(): void
    {
        // A status change alone is NOT proof of payment; only payment_complete may confirm paid seats.
        add_filter('woocommerce_is_purchasable', [self::class, 'preventDirectProductPurchase'], 10, 2);
        add_action('woocommerce_payment_complete', [self::class, 'paymentComplete'], 10, 1);
        add_action('woocommerce_order_status_failed', [self::class, 'flagCancellation'], 10, 1);
        add_action('woocommerce_order_status_cancelled', [self::class, 'flagCancellation'], 10, 1);
        add_action('woocommerce_order_status_refunded', [self::class, 'flagCancellation'], 10, 1);
    }

    public static function preventDirectProductPurchase(bool $purchasable, object $product): bool
    {
        // The virtual product is solely an internal order item, not a public cart product.
        if (method_exists($product, 'get_id') &&
            (int) $product->get_id() === (int) get_option('pesm_wc_product_id', 0) &&
            (int) $product->get_id() > 0) {
            return CartSeatManager::purchasable(false, $product);
        }
        return $purchasable;
    }

    public static function paymentComplete(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order || $order->get_meta('_pesm_is_ticket_order') !== 'yes') { return; }
        if ($order->get_meta('_pesm_mode') === 'general') {
            try {
                $status = (new FreeAdmissionService())->confirmPaid($orderId);
                if (in_array($status, ['issued','already_issued'], true)) { NotificationQueue::onTicketsIssued($orderId); }
                if ($status === 'review_required') {
                    $order->add_order_note('PESM: GA payment received but capacity lease expired; manual reconciliation/refund required.');
                    $order->save();
                }
            } catch (Throwable $e) {
                $order->add_order_note('PESM: GA payment confirmed but ticket issuance needs manual reconciliation.');
                $order->save();
                error_log('PESM GA confirmation failed for order ' . $orderId . ': ' . get_class($e));
            }
            return;
        }
        try {
            $status = (new TicketService())->confirmOrder($orderId, false);
            if (in_array($status, ['issued','already_issued'], true)) {
                (new DiscountService())->redeemOrder($orderId);
                NotificationQueue::onTicketsIssued($orderId);
            }
            if ($status === 'review_required') {
                $order->add_order_note('PESM: payment received after inventory expiry; manual refund/reconciliation required.');
                $order->save();
            }
        } catch (Throwable $e) {
            $order->add_order_note('PESM: ticket issuance failed; manual reconciliation required.');
            $order->save();
            error_log('PESM ticket issuance failed on order ' . $orderId . ': ' . get_class($e));
        }
    }

    public static function flagCancellation(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if ($order && $order->get_meta('_pesm_is_ticket_order') === 'yes') {
            $status = $order->get_meta('_pesm_mode') === 'general'
                ? (new FreeAdmissionService())->revokeOrder($orderId)
                : (new TicketService())->revokeOrder($orderId);
            if (in_array((string) $order->get_status(), ['failed', 'cancelled'], true)) {
                try { (new DiscountService())->releaseUnredeemed($orderId); }
                catch (Throwable $e) {
                    $order->add_order_note('PESM: discount quota reconciliation required.');
                }
            }
            $order->add_order_note('PESM: ticket cancellation result: ' . $status . '. Sold seats are not automatically re-listed.');
            $order->save();
        }
    }
}
