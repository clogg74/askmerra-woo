<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Catalog;

/**
 * The price a guest sees in the shop - sale prices and the "Display prices in the shop" tax setting
 * included - in the shop's currency. Run inside ProductBuilder's guest context, so the tax location
 * is the same whoever starts a sync.
 */
final class PriceResolver
{
    /**
     * @return array{price: ?float, sale_price: ?float}
     */
    public function resolve(\WC_Product $product): array
    {
        try {
            [$regular, $active] = $this->getPrices($product, true);
        } catch (\Throwable) {
            return ['price' => null, 'sale_price' => null];
        }

        if ($regular === null || $regular <= 0) {
            $regular = $active;
        }

        if ($regular === null || $regular <= 0) {
            return ['price' => null, 'sale_price' => null];
        }

        return [
            'price' => $regular,
            // AskMerra shows a sale only below the regular price.
            'sale_price' => $active !== null && $active > 0 && $active < $regular - 0.004 ? $active : null,
        ];
    }

    /**
     * The tax location of a guest from the shop's country: the shop's base address, unless the shop
     * starts guests with no address at all. Applied to every price while products are built, so a
     * sync started by a logged-in admin (with an address of their own) sends the same prices as cron.
     */
    public function getGuestTaxLocation(): array
    {
        if (!function_exists('WC') || !WC()->countries) {
            return [];
        }

        $usesBase = wc_prices_include_tax()
            || in_array(get_option('woocommerce_default_customer_address'), ['base', 'geolocation', 'geolocation_ajax'], true)
            || get_option('woocommerce_tax_based_on') === 'base';

        return $usesBase
            ? [WC()->countries->get_base_country(), WC()->countries->get_base_state(), WC()->countries->get_base_postcode(), WC()->countries->get_base_city()]
            : [];
    }

    /**
     * Regular and current display price. Products with options are priced "from" their cheapest
     * option: both prices come from that one option, never from two different ones.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function getPrices(\WC_Product $product, bool $withChildren): array
    {
        if ($product instanceof \WC_Product_Variable) {
            // WooCommerce's own list of the shop's variation prices (cached, tax display applied), cheapest first.
            $prices = $product->get_variation_prices(true);
            $variationId = array_key_first($prices['price'] ?? []);

            return $variationId === null
                ? [null, null]
                : [$this->toFloat($prices['regular_price'][$variationId] ?? null), $this->toFloat($prices['price'][$variationId])];
        }

        if ($product instanceof \WC_Product_Grouped) {
            return $withChildren ? $this->getCheapestChildPrices($product) : [null, null];
        }

        return [
            $this->display($product, (string) $product->get_regular_price()),
            $this->display($product, (string) $product->get_price()),
        ];
    }

    /**
     * The published child with the lowest current price, as the grouped product's price range shows it.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function getCheapestChildPrices(\WC_Product_Grouped $product): array
    {
        $best = [null, null];

        foreach ($product->get_children() as $childId) {
            $child = wc_get_product($childId);

            if (!$child instanceof \WC_Product || $child->is_type('variation') || $child->get_status() !== 'publish') {
                continue;
            }

            [$regular, $active] = $this->getPrices($child, false);

            if ($active !== null && ($best[1] === null || $active < $best[1])) {
                $best = [$regular, $active];
            }
        }

        return $best;
    }

    /** A raw price as the shop displays it (with or without tax), rounded to the shop's decimals; null when not set. */
    private function display(\WC_Product $product, string $price): ?float
    {
        if ($price === '' || !is_numeric($price)) {
            return null;
        }

        return $this->toFloat(wc_get_price_to_display($product, ['price' => (float) $price]));
    }

    private function toFloat(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, wc_get_price_decimals()) : null;
    }
}
