<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Sync;

use AskMerra\WooCommerce\Config;

/**
 * Queues the products that change, from WooCommerce's and WordPress's hooks. Only ids are collected
 * here (Enqueuer); products are built and compared later, in the background, so a product saved
 * without a visible change costs nothing more.
 *
 * Imports and integrations that write the database directly are caught by the modified check
 * (every 15 minutes, Jobs::checkModified()), the stock check and the daily check.
 */
final class ChangeListener
{
    private const PRODUCT_TYPES = ['product', 'product_variation'];

    /** Product meta that changes what AskMerra gets, for code that writes meta without the product API. */
    private const META_KEYS = [
        '_price', '_regular_price', '_sale_price', '_sale_price_dates_from', '_sale_price_dates_to', '_sku',
        '_stock', '_stock_status', '_manage_stock', '_backorders', '_thumbnail_id', '_product_image_gallery',
        '_product_attributes', '_children', Config::EXCLUDE_META,
    ];

    /** Taxonomies whose terms are part of the product, besides attributes (pa_*) and the brand. */
    private const TAXONOMIES = ['product_cat', 'product_type', 'product_visibility', 'product_brand'];

    /** @var array<int, array{name: string, parent: int}> terms as they were before an edit */
    private array $termsBefore = [];

    /** @var array<int, int[]> subcategories of a category about to be deleted */
    private array $subcategoriesBefore = [];

    public function __construct(
        private readonly Enqueuer $enqueuer,
        private readonly Config $config
    ) {
    }

    public function register(): void
    {
        foreach (['woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation'] as $hook) {
            add_action($hook, [$this, 'onProductId']);
        }

        // Stock changes made by orders write the stock with SQL, then fire these.
        add_action('woocommerce_product_set_stock', [$this, 'onProduct']);
        add_action('woocommerce_variation_set_stock', [$this, 'onProduct']);
        add_action('woocommerce_product_set_stock_status', [$this, 'onProductId']);
        add_action('woocommerce_variation_set_stock_status', [$this, 'onProductId']);

        // Scheduled sales starting or ending (WooCommerce's daily wc_scheduled_sales).
        add_action('wc_after_products_starting_sales', [$this, 'onProductIds']);
        add_action('wc_after_products_ending_sales', [$this, 'onProductIds']);

        add_action('transition_post_status', [$this, 'onStatusChange'], 10, 3);
        add_action('trashed_post', [$this, 'onPost']);
        add_action('untrashed_post', [$this, 'onPost']);
        add_action('before_delete_post', [$this, 'onDelete'], 10, 2);

        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, [$this, 'onMeta'], 10, 3);
        }

        add_action('set_object_terms', [$this, 'onObjectTerms'], 10, 6);
        add_action('edit_terms', [$this, 'beforeTermEdit'], 10, 2);
        add_action('edited_term', [$this, 'onTermEdited'], 10, 3);
        add_action('pre_delete_term', [$this, 'beforeTermDelete'], 10, 2);
        add_action('delete_term', [$this, 'onTermDeleted'], 10, 5);
        add_action('woocommerce_attribute_updated', [$this, 'onAttributeUpdated'], 10, 2);
    }

    /** @param int|string $productId */
    public function onProductId($productId): void
    {
        $this->enqueuer->enqueue([(int) $productId]);
    }

    /** @param \WC_Product|int $product */
    public function onProduct($product): void
    {
        $this->enqueuer->enqueue([$product instanceof \WC_Product ? $product->get_id() : (int) $product]);
    }

    /** @param int[] $productIds */
    public function onProductIds($productIds): void
    {
        $this->enqueuer->enqueue(array_map('intval', (array) $productIds));
    }

    /** Published, unpublished, scheduled... through any code path. */
    public function onStatusChange(string $newStatus, string $oldStatus, $post): void
    {
        if ($newStatus !== $oldStatus && $post instanceof \WP_Post && in_array($post->post_type, self::PRODUCT_TYPES, true)) {
            $this->enqueuer->enqueue([$post->ID]);
        }
    }

    /** Moved to or out of the trash. */
    public function onPost($postId): void
    {
        if (in_array(get_post_type((int) $postId), self::PRODUCT_TYPES, true)) {
            $this->enqueuer->enqueue([(int) $postId]);
        }
    }

    /** Deleted for good: the product is removed from AskMerra (a variation: its product is rebuilt). */
    public function onDelete($postId, $post = null): void
    {
        $post = $post instanceof \WP_Post ? $post : get_post((int) $postId);

        if (!$post || !in_array($post->post_type, self::PRODUCT_TYPES, true)) {
            return;
        }

        if ($post->post_type === 'product_variation') {
            $this->enqueuer->rememberParent((int) $post->ID, (int) $post->post_parent);
        }

        $this->enqueuer->enqueue([(int) $post->ID]);
    }

    /** @param int|int[] $metaIds */
    public function onMeta($metaIds, $objectId, $metaKey): void
    {
        $metaKey = (string) $metaKey;

        if ((in_array($metaKey, self::META_KEYS, true) || str_starts_with($metaKey, 'attribute_'))
            && in_array(get_post_type((int) $objectId), self::PRODUCT_TYPES, true)
        ) {
            $this->enqueuer->enqueue([(int) $objectId]);
        }
    }

    /** Categories, attributes, brand or visibility set directly (WooCommerce sets the same terms again on every save). */
    public function onObjectTerms($objectId, $terms, $ttIds, $taxonomy, $append, $oldTtIds): void
    {
        if (!$this->isProductTaxonomy((string) $taxonomy)) {
            return;
        }

        $new = array_map('intval', (array) $ttIds);
        $old = array_map('intval', (array) $oldTtIds);
        sort($new);
        sort($old);

        if ($new !== $old) {
            $this->enqueuer->enqueue([(int) $objectId]);
        }
    }

    /** Remembers the name and parent of a term about to be edited: only those show in AskMerra. */
    public function beforeTermEdit($termId, $taxonomy): void
    {
        if (!$this->isProductTaxonomy((string) $taxonomy)) {
            return;
        }

        $term = get_term((int) $termId, (string) $taxonomy);

        if ($term instanceof \WP_Term) {
            $this->termsBefore[(int) $termId] = ['name' => $term->name, 'parent' => (int) $term->parent];
        }
    }

    /** A category, attribute value or brand renamed or moved: its products (and its subcategories' products) change. */
    public function onTermEdited($termId, $ttId, $taxonomy): void
    {
        $termId = (int) $termId;
        $taxonomy = (string) $taxonomy;

        if (!$this->isProductTaxonomy($taxonomy) || $taxonomy === 'product_type' || $taxonomy === 'product_visibility') {
            return;
        }

        $before = $this->termsBefore[$termId] ?? null;
        unset($this->termsBefore[$termId]);
        $term = get_term($termId, $taxonomy);

        if (!$term instanceof \WP_Term
            || ($before !== null && $before['name'] === $term->name && $before['parent'] === (int) $term->parent)
        ) {
            return;
        }

        $termIds = [$termId];

        if (is_taxonomy_hierarchical($taxonomy)) {
            $children = get_term_children($termId, $taxonomy);
            $termIds = array_merge($termIds, is_array($children) ? array_map('intval', $children) : []);
        }

        $this->enqueueTermObjects($termIds, $taxonomy);
    }

    /** Remembers the subcategories of a category about to be deleted: WordPress moves them up without a hook. */
    public function beforeTermDelete($termId, $taxonomy): void
    {
        if ((string) $taxonomy !== 'product_cat') {
            return;
        }

        $children = get_term_children((int) $termId, 'product_cat');

        if (is_array($children) && $children) {
            $this->subcategoriesBefore[(int) $termId] = array_map('intval', $children);
        }
    }

    /**
     * A term deleted: the products that had it change, and so do the products of a deleted
     * category's subcategories (their category paths lose it).
     *
     * @param int[] $objectIds the products that had the term
     */
    public function onTermDeleted($term, $ttId, $taxonomy, $deletedTerm, $objectIds): void
    {
        $taxonomy = (string) $taxonomy;

        if (!$this->isProductTaxonomy($taxonomy)) {
            return;
        }

        if (is_array($objectIds) && $objectIds) {
            $this->enqueuer->enqueue(array_map('intval', $objectIds));
        }

        $subcategories = $this->subcategoriesBefore[(int) $term] ?? [];
        unset($this->subcategoriesBefore[(int) $term]);

        if ($subcategories) {
            $this->enqueueTermObjects($subcategories, $taxonomy);
        }
    }

    /** A global attribute renamed: every product using it shows the new label. */
    public function onAttributeUpdated($attributeId, $data): void
    {
        $name = is_array($data) ? (string) ($data['attribute_name'] ?? '') : '';

        if ($name === '') {
            return;
        }

        $taxonomy = wc_attribute_taxonomy_name($name);
        $termIds = get_terms(['taxonomy' => $taxonomy, 'fields' => 'ids', 'hide_empty' => false]);

        if (is_array($termIds) && $termIds) {
            $this->enqueueTermObjects(array_map('intval', $termIds), $taxonomy);
        }
    }

    /** @param int[] $termIds */
    private function enqueueTermObjects(array $termIds, string $taxonomy): void
    {
        $objectIds = get_objects_in_term($termIds, $taxonomy);

        if (is_array($objectIds) && $objectIds) {
            $this->enqueuer->enqueue(array_map('intval', $objectIds));
        }
    }

    private function isProductTaxonomy(string $taxonomy): bool
    {
        if (in_array($taxonomy, self::TAXONOMIES, true) || str_starts_with($taxonomy, 'pa_')) {
            return true;
        }

        $brand = $this->config->getBrandSource();

        return $brand !== null && $brand === 'taxonomy:' . $taxonomy;
    }
}
