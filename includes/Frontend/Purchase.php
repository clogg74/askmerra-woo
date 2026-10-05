<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Frontend;

use AskMerra\WooCommerce\Catalog\ExternalId;
use AskMerra\WooCommerce\Config;

/**
 * The order on the order received page, for AskMerra's sales attribution: order number, total,
 * currency and products (GA4 "purchase" shape). No name, e-mail or address. Products are
 * identified the way the catalog sends them (the parent of a variation), so AskMerra can tell which
 * ones the assistant recommended. assets/frontend/askmerra.js passes it to AskMerra.trackPurchase(),
 * which sends it once the shopper's analytics consent allows it.
 */
final class Purchase
{
    /** Order meta: when the order was handed to the page for reporting (each order once). */
    public const REPORTED_META = '_askmerra_reported';

    /** Orders that are not sales. */
    private const SKIPPED_STATUSES = ['failed', 'cancelled'];

    /** @var array<int, true> orders printed in this request (themes may fire the hook twice) */
    private array $printed = [];

    public function __construct(
        private readonly Config $config,
        private readonly ExternalId $externalId
    ) {
    }

    /** woocommerce_thankyou: WooCommerce shows the order only to the shopper who placed it. */
    public function render(mixed $orderId): void
    {
        $orderId = absint($orderId);

        if (isset($this->printed[$orderId]) || !$this->config->isWidgetEnabled() || !$this->config->isPurchaseTrackingEnabled()) {
            return;
        }

        $order = wc_get_order($orderId);

        if (!$order instanceof \WC_Order
            || $order->has_status(self::SKIPPED_STATUSES)
            || $order->get_meta(self::REPORTED_META) !== ''
        ) {
            return;
        }

        $purchase = $this->build($order);
        $this->printed[$orderId] = true;

        // A reload of the page, or the page opened again later, does not report the order again.
        $order->update_meta_data(self::REPORTED_META, gmdate('Y-m-d H:i:s'));
        $order->save_meta_data();

        wp_print_inline_script_tag('window.AskMerraWooPurchase = ' . Json::encode($purchase) . ';');
    }

    /** @return array{transaction_id: string, value: float, currency: string, tax: float, shipping: float, items: array[]} */
    public function build(\WC_Order $order): array
    {
        $items = [];

        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $quantity = (float) $item->get_quantity();
            // The parent product of a variation: AskMerra knows the variable product itself.
            $product = $item->get_product_id() ? wc_get_product($item->get_product_id()) : null;
            $id = $product instanceof \WC_Product ? $this->externalId->get($product) : (string) ($item->get_product_id() ?: '');

            if ($quantity <= 0 || $id === '') {
                continue;
            }

            // Line total after discounts, with tax.
            $paid = (float) $item->get_total() + (float) $item->get_total_tax();

            $items[] = [
                'item_id' => $id,
                'item_name' => $product instanceof \WC_Product ? $product->get_name() : $item->get_name(),
                'price' => round(max(0.0, $paid) / $quantity, 2),
                'quantity' => (int) ceil($quantity),
            ];
        }

        return [
            'transaction_id' => (string) $order->get_order_number(),
            'value' => round((float) $order->get_total(), 2),
            'currency' => $order->get_currency(),
            'tax' => round((float) $order->get_total_tax(), 2),
            'shipping' => round((float) $order->get_shipping_total(), 2),
            'items' => $items,
        ];
    }
}
