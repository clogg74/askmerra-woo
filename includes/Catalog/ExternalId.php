<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Catalog;

use AskMerra\WooCommerce\Config;

/**
 * The id a product has in AskMerra: its WooCommerce product id, or its SKU (setting "Product
 * identifier"; "id:<product id>" for a product without SKU). Always the parent product's: a
 * variation is known to AskMerra through its variable product. The same value goes into the
 * catalog, the product page context, add to cart and the order report, so AskMerra can match them
 * all.
 */
final class ExternalId
{
    /**
     * With the SKU identifier, what a product without SKU is known by, followed by its id: its bare
     * id could be another product's (numeric) SKU, and the two would share one AskMerra product.
     */
    private const ID_PREFIX = 'id:';

    public function __construct(private readonly Config $config)
    {
    }

    public function get(\WC_Product $product): string
    {
        $id = $product->is_type('variation') && $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();

        if ($this->config->getProductIdentifier() !== Config::IDENTIFIER_SKU) {
            return (string) $id;
        }

        $parent = $id === $product->get_id() ? $product : wc_get_product($id);
        // The stored SKU: display filters must not change the id AskMerra knows.
        $sku = $parent instanceof \WC_Product ? trim((string) $parent->get_sku('edit')) : '';

        return $sku !== '' ? $sku : self::ID_PREFIX . $id;
    }

    /**
     * The parent product behind an AskMerra id, or null when there is none. With the SKU identifier
     * the SKU is looked up first (a variation's SKU leads to its parent); then "id:<product id>",
     * then a bare product id (the ID identifier's, also what AskMerra holds from before a switch).
     */
    public function toProductId(string $externalId): ?int
    {
        $externalId = trim($externalId);

        if ($externalId === '') {
            return null;
        }

        $bySku = $this->config->getProductIdentifier() === Config::IDENTIFIER_SKU;

        if ($bySku) {
            $id = (int) wc_get_product_id_by_sku($externalId);

            if ($id > 0) {
                return $this->toParentId($id);
            }
        }

        $number = str_starts_with($externalId, self::ID_PREFIX) ? substr($externalId, strlen(self::ID_PREFIX)) : null;

        if ($number !== null && ctype_digit($number)) {
            return $this->toProductIdOrNull((int) $number);
        }

        if ($bySku) {
            $id = $this->findBySkuMeta($externalId);

            if ($id > 0) {
                return $this->toParentId($id);
            }
        }

        return ctype_digit($externalId) ? $this->toProductIdOrNull((int) $externalId) : null;
    }

    /**
     * wc_get_product_id_by_sku() reads WooCommerce's product lookup table, which imports that write
     * the database directly leave incomplete: the SKU meta itself is the fallback. Meta values have
     * no index, so this reads every SKU row: only after WooCommerce's lookup missed, never for an
     * "id:" id.
     */
    private function findBySkuMeta(string $sku): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_sku' AND pm.meta_value = %s
            AND p.post_type IN ('product', 'product_variation') AND p.post_status <> 'trash'
            ORDER BY p.post_type = 'product' DESC, p.ID LIMIT 1",
            $sku
        ));
    }

    /** A product found by SKU, or the variable product of a variation found by SKU. */
    private function toParentId(int $id): ?int
    {
        return get_post_type($id) === 'product_variation' ? (wp_get_post_parent_id($id) ?: null) : $id;
    }

    /** The id when it is a product's (a parent product: variations have no AskMerra id of their own). */
    private function toProductIdOrNull(int $id): ?int
    {
        return $id > 0 && get_post_type($id) === 'product' ? $id : null;
    }
}
