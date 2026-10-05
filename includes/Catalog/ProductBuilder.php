<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Catalog;

use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Log\Logger;
use AskMerra\WooCommerce\Storefront;

/**
 * Turns WooCommerce products into AskMerra products (the ProductInput shape of the Push API, also
 * used for the JSON feed) for one storefront: its names, prices, currency, stock, URLs, categories
 * and language.
 *
 * One product is one AskMerra product: simple, variable, grouped and external products. Variations
 * are not sent on their own; the options a shopper can pick become attributes of the variable
 * product, as AskMerra's own Shopify connector does.
 *
 * Products are built as a guest sees them (no logged-in user, the guest tax location), whoever
 * starts the sync. getCandidateIds() and getStockFlags() apply the same rules as build() in SQL, so
 * the daily check and the stock check agree with what is sent.
 *
 * Multilingual shops (WPML, Polylang) send the products of their default language: a storefront is
 * one language, and a translation would be one more product (see Translations).
 */
final class ProductBuilder
{
    /** Batches at least this big clear the request's object cache afterwards (see freeObjectCache()). */
    private const LARGE_BATCH = 50;

    private const OUT_OF_STOCK = 'outofstock';

    private bool $hasBuilt = false;

    public function __construct(
        private readonly Config $config,
        private readonly ExternalId $externalId,
        private readonly AttributeValues $attributeValues,
        private readonly CategoryPaths $categoryPaths,
        private readonly PriceResolver $priceResolver,
        private readonly TextCleaner $text,
        private readonly Logger $logger,
        private readonly Translations $translations
    ) {
    }

    /**
     * Builds the products of a storefront.
     *
     * - payloads: products to send, keyed by product id
     * - ineligible: products that must not be in AskMerra (anymore), with the reason
     * - errors: products that could not be built this time; they are tried again, never removed
     *
     * @param int[] $productIds parent product ids
     * @return array{payloads: array<int, array>, ineligible: array<int, string>, errors: array<int, string>}
     */
    public function build(Storefront $storefront, array $productIds): array
    {
        $result = ['payloads' => [], 'ineligible' => [], 'errors' => []];
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));

        if (!$productIds) {
            return $result;
        }

        // A product built earlier in this process (a long queue run, WP-CLI) may have been changed
        // by another request since: drop what this process cached (WooCommerce also keeps product
        // objects in memory) so that every batch reads current data.
        if ($this->hasBuilt) {
            $this->freeObjectCache();
        }

        $this->hasBuilt = true;
        $this->categoryPaths->reset();
        $restore = $this->enterGuestContext();

        try {
            $products = $this->loadProducts($productIds);
            $languages = $this->translations->getOtherLanguages(array_keys($products));

            foreach ($productIds as $productId) {
                try {
                    $product = $products[$productId] ?? null;
                    $reason = $this->getIneligibleReason($product, $storefront, $languages[$productId] ?? null);

                    if ($reason !== null) {
                        $result['ineligible'][$productId] = $reason;
                    } else {
                        $result['payloads'][$productId] = $this->buildPayload($product, $storefront);
                    }
                } catch (\Throwable $e) {
                    $result['errors'][$productId] = $e->getMessage();
                    $this->logger->error(sprintf('Product %d, storefront %s: %s', $productId, $storefront->id, $e->getMessage()), ['exception' => $e]);
                }
            }
        } finally {
            $restore();

            if (count($productIds) >= self::LARGE_BATCH) {
                $this->freeObjectCache();
            }
        }

        return $result;
    }

    /**
     * Ids of the products a storefront may send: published, not in another language than the
     * default (WPML, Polylang), of a type and catalog visibility that are sent, not hidden from
     * AskMerra, not in an excluded category, and in stock unless out-of-stock products are sent.
     * Queries only, no product objects: fast for 50,000 products.
     *
     * @return int[]
     */
    public function getCandidateIds(Storefront $storefront): array
    {
        $candidates = $this->loadCandidates();

        if (!$this->config->includeOutOfStock()) {
            $candidates = array_filter($candidates);
        }

        return array_keys($candidates);
    }

    /**
     * Whether each product a storefront may send can be bought now - for the stock check. Products
     * that are left out only for being out of stock are included, so a product that runs out is
     * noticed (and removed) at the next check.
     *
     * @return array<int, bool>
     */
    public function getStockFlags(Storefront $storefront): array
    {
        return $this->loadCandidates();
    }

    /**
     * Every published product that passes the rules other than the stock rule, with whether it is
     * in stock: its own stock status, or for a grouped product, whether one of its published
     * products is (WooCommerce keeps a variable product's status in line with its variations).
     *
     * @return array<int, bool>
     */
    private function loadCandidates(): array
    {
        global $wpdb;

        // Translations into other languages are left out (prepared SQL; empty without WPML or Polylang).
        $languageFilter = $this->translations->getSqlFilter('p.ID');
        $ids = array_map('intval', $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p{$languageFilter['join']}
            WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.post_password = '' AND TRIM(p.post_title) <> ''{$languageFilter['where']}"
        ));

        if (!$ids) {
            return [];
        }

        $candidates = array_fill_keys($ids, true);

        // Product type: the product_type term; none means simple, as WooCommerce reads it.
        $types = $this->getObjectTerms('product_type', null);
        $allowedTypes = array_flip($this->config->getProductTypes());

        foreach ($candidates as $id => $unused) {
            if (!isset($allowedTypes[$types[$id] ?? 'simple'])) {
                unset($candidates[$id]);
            }
        }

        // Catalog visibility, from the exclude-from-catalog and exclude-from-search terms.
        $hiddenIn = [];

        foreach ($this->getObjectTerms('product_visibility', ['exclude-from-catalog', 'exclude-from-search'], true) as $id => $slugs) {
            $hiddenIn[$id] = $slugs;
        }

        $allowedVisibilities = array_flip($this->config->getVisibilities());

        foreach ($candidates as $id => $unused) {
            if (!isset($allowedVisibilities[$this->toVisibility($hiddenIn[$id] ?? [])])) {
                unset($candidates[$id]);
            }
        }

        // "Hide from AskMerra".
        $hidden = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'yes'",
            Config::EXCLUDE_META
        ));

        foreach ($hidden as $id) {
            unset($candidates[(int) $id]);
        }

        // Excluded categories, their subcategories included.
        foreach ($this->getProductsInCategories($this->categoryPaths->getDescendantIds($this->config->getExcludedCategoryIds())) as $id) {
            unset($candidates[$id]);
        }

        if (!$candidates) {
            return [];
        }

        $outOfStock = array_flip(array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product'
            WHERE pm.meta_key = '_stock_status' AND pm.meta_value = %s",
            self::OUT_OF_STOCK
        ))));

        $grouped = array_keys(array_filter(
            $candidates,
            static fn (bool $unused, int $id): bool => ($types[$id] ?? '') === 'grouped',
            ARRAY_FILTER_USE_BOTH
        ));
        $groupedStock = $this->getGroupedStock($grouped);

        foreach ($candidates as $id => $unused) {
            $candidates[$id] = array_key_exists($id, $groupedStock) ? $groupedStock[$id] : !isset($outOfStock[$id]);
        }

        return $candidates;
    }

    /**
     * The terms of a taxonomy that products have, by product: one slug (the first) or, with
     * $all, every slug. Only the slugs in $slugs when given.
     *
     * @param string[]|null $slugs
     * @return array<int, string>|array<int, string[]>
     */
    private function getObjectTerms(string $taxonomy, ?array $slugs, bool $all = false): array
    {
        global $wpdb;

        $termTaxonomyIds = [];

        foreach ($wpdb->get_results($wpdb->prepare(
            "SELECT t.slug, tt.term_taxonomy_id FROM {$wpdb->terms} t
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
            WHERE tt.taxonomy = %s",
            $taxonomy
        )) as $row) {
            if ($slugs === null || in_array($row->slug, $slugs, true)) {
                $termTaxonomyIds[(int) $row->term_taxonomy_id] = (string) $row->slug;
            }
        }

        if (!$termTaxonomyIds) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT object_id, term_taxonomy_id FROM {$wpdb->term_relationships}
            WHERE term_taxonomy_id IN (" . $this->placeholders(count($termTaxonomyIds)) . ')
            ORDER BY object_id, term_taxonomy_id',
            ...array_keys($termTaxonomyIds)
        ), ARRAY_N);
        $terms = [];

        foreach ($rows as [$objectId, $termTaxonomyId]) {
            $slug = $termTaxonomyIds[(int) $termTaxonomyId];

            if ($all) {
                $terms[(int) $objectId][] = $slug;
            } else {
                $terms[(int) $objectId] ??= $slug;
            }
        }

        return $terms;
    }

    /**
     * @param int[] $termIds product_cat term ids
     * @return int[] the products in them
     */
    private function getProductsInCategories(array $termIds): array
    {
        if (!$termIds) {
            return [];
        }

        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN (" . $this->placeholders(count($termIds)) . ')',
            ...$termIds
        )));
    }

    /**
     * Whether each grouped product has a published product in stock among its products.
     *
     * @param int[] $groupedIds
     * @return array<int, bool>
     */
    private function getGroupedStock(array $groupedIds): array
    {
        if (!$groupedIds) {
            return [];
        }

        global $wpdb;

        $children = array_fill_keys($groupedIds, []);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
            WHERE meta_key = '_children' AND post_id IN (" . $this->placeholders(count($groupedIds)) . ')',
            ...$groupedIds
        ));

        foreach ($rows as $row) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- damaged meta counts as no children
            $ids = @unserialize((string) $row->meta_value, ['allowed_classes' => false]);
            $children[(int) $row->post_id] = is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
        }

        $allChildren = array_values(array_unique(array_merge([], ...array_values($children))));
        $inStock = [];

        if ($allChildren) {
            $inStock = array_flip(array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_stock_status'
                WHERE p.ID IN (" . $this->placeholders(count($allChildren)) . ")
                AND p.post_type = 'product' AND p.post_status = 'publish'
                AND (pm.meta_value IS NULL OR pm.meta_value <> %s)",
                ...[...$allChildren, self::OUT_OF_STOCK]
            ))));
        }

        $result = [];

        foreach ($children as $groupedId => $ids) {
            $result[$groupedId] = (bool) array_intersect_key(array_flip($ids), $inStock);
        }

        return $result;
    }

    /**
     * Loads the products with what their payloads need - posts, meta, terms, children and images -
     * in a few queries instead of a few per product.
     *
     * @param int[] $productIds
     * @return array<int, \WC_Product>
     */
    private function loadProducts(array $productIds): array
    {
        _prime_post_caches($productIds, true, true);

        $products = [];
        $childIds = [];

        foreach ($productIds as $productId) {
            $product = wc_get_product($productId);

            if (!$product instanceof \WC_Product) {
                continue;
            }

            $products[$productId] = $product;

            if ($product instanceof \WC_Product_Variable || $product instanceof \WC_Product_Grouped) {
                array_push($childIds, ...array_map('intval', $product->get_children('edit')));
            }
        }

        $childIds = array_values(array_unique(array_filter($childIds)));

        if ($childIds) {
            _prime_post_caches($childIds, true, true);
        }

        $attachmentIds = [];

        foreach ($products as $product) {
            $attachmentIds[] = (int) $product->get_image_id('edit');
            array_push($attachmentIds, ...array_map('intval', $product->get_gallery_image_ids('edit')));
        }

        // Variable products without images of their own show their variations' images.
        foreach ($childIds as $childId) {
            $attachmentIds[] = (int) get_post_meta($childId, '_thumbnail_id', true);
        }

        $attachmentIds = array_values(array_unique(array_filter($attachmentIds)));

        if ($attachmentIds) {
            _prime_post_caches($attachmentIds, false, true);
        }

        return $products;
    }

    /** @param ?string $language the product's language when it is not the default one (WPML, Polylang) */
    private function getIneligibleReason(?\WC_Product $product, Storefront $storefront, ?string $language): ?string
    {
        if ($product === null) {
            return __('Deleted, or not a product.', 'askmerra-for-woocommerce');
        }

        if ($product->is_type('variation')) {
            return __('A variation: it is sent as part of its variable product.', 'askmerra-for-woocommerce');
        }

        if ($language !== null) {
            /* translators: %s: language code, e.g. "fr" */
            return sprintf(__('A translation (language %s): only the products of the default language are sent.', 'askmerra-for-woocommerce'), $language);
        }

        if ($product->get_status('edit') !== 'publish' || (string) $product->get_post_password('edit') !== '') {
            return __('Not published, or protected by a password.', 'askmerra-for-woocommerce');
        }

        // The type as WooCommerce reads it from the product_type term, like getCandidateIds().
        $type = (string) \WC_Product_Factory::get_product_type($product->get_id());

        if (!in_array($type, $this->config->getProductTypes(), true)) {
            /* translators: %s: product type */
            return sprintf(__('Products of type "%s" are not sent.', 'askmerra-for-woocommerce'), wc_get_product_types()[$type] ?? $type);
        }

        $visibility = $product->get_catalog_visibility('edit');

        if (!in_array($visibility, $this->config->getVisibilities(), true)) {
            /* translators: %s: catalog visibility, e.g. "Hidden" */
            return sprintf(__('Its catalog visibility ("%s") is not sent.', 'askmerra-for-woocommerce'), wc_get_product_visibility_options()[$visibility] ?? $visibility);
        }

        if ($product->get_meta(Config::EXCLUDE_META, true, 'edit') === 'yes') {
            return __('"Hide from AskMerra" is set on the product.', 'askmerra-for-woocommerce');
        }

        if ($this->categoryPaths->isExcluded($product->get_category_ids('edit'), $this->config->getExcludedCategoryIds())) {
            return __('It is in an excluded category.', 'askmerra-for-woocommerce');
        }

        if (!$this->config->includeOutOfStock() && !$this->isInStock($product)) {
            return __('It is out of stock, and out-of-stock products are not sent.', 'askmerra-for-woocommerce');
        }

        if ($this->text->toLine($product->get_name(), 500) === null) {
            return __('It has no name.', 'askmerra-for-woocommerce');
        }

        /**
         * A reason to keep a product out of AskMerra, or null to send it.
         *
         * @param ?string     $reason     null: the product passed the plugin's own rules
         * @param \WC_Product $product
         * @param Storefront  $storefront
         */
        $reason = apply_filters('askmerra_product_is_eligible', null, $product, $storefront);

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }

    private function buildPayload(\WC_Product $product, Storefront $storefront): array
    {
        $variations = $product instanceof \WC_Product_Variable ? $this->getPurchasableVariations($product) : [];
        $prices = $this->priceResolver->resolve($product);
        $brandSource = $this->config->getBrandSource();
        $modified = $product->get_date_modified('edit');

        $attributes = $this->attributeValues->getValues($product, $this->config->getAttributeKeys());

        if ($product instanceof \WC_Product_Variable && $this->config->includeVariantOptions()) {
            $attributes = array_merge($attributes, $this->attributeValues->getVariantOptions($product, $variations));
        }

        $payload = [
            'external_id' => $this->externalId->get($product),
            'sku' => $this->text->toLine((string) $product->get_sku('edit'), 255),
            'parent_external_id' => null,
            'name' => (string) $this->text->toLine($product->get_name(), 500),
            'description' => $this->getDescription($product),
            'url' => $this->toUrl((string) get_permalink($product->get_id())),
            'image_urls' => $this->getImageUrls($product, $variations),
            'price' => $prices['price'],
            'sale_price' => $prices['sale_price'],
            'currency' => strtoupper($storefront->currency) ?: null,
            'in_stock' => $this->isInStock($product),
            'stock_qty' => $this->getStockQty($product),
            'categories' => $this->categoryPaths->getPaths($product->get_category_ids('edit')),
            'brand' => $brandSource !== null ? $this->attributeValues->getBrand($product, $brandSource) : null,
            'attributes' => $attributes,
            'locale' => $storefront->locale,
            'source_updated_at' => $modified ? gmdate('Y-m-d\TH:i:sP', $modified->getTimestamp()) : null,
        ];

        /**
         * The product as it will be sent to AskMerra (and hashed). Texts must be plain text; the
         * limits of the AskMerra API are applied afterwards and unknown fields are dropped.
         *
         * @param array       $payload
         * @param \WC_Product $product
         * @param Storefront  $storefront
         */
        $filtered = apply_filters('askmerra_product_payload', $payload, $product, $storefront);

        if (!is_array($filtered)) {
            throw new \UnexpectedValueException('The askmerra_product_payload filter did not return an array.');
        }

        return $this->normalize($filtered);
    }

    /** @return \WC_Product_Variation[] the variations a shopper can pick and buy */
    private function getPurchasableVariations(\WC_Product_Variable $product): array
    {
        $variations = [];

        foreach ($product->get_visible_children() as $variationId) {
            $variation = wc_get_product($variationId);

            if ($variation instanceof \WC_Product_Variation && $variation->is_purchasable()) {
                $variations[] = $variation;
            }
        }

        return $variations;
    }

    /** Can be bought now; a grouped product when one of its published products can. */
    private function isInStock(\WC_Product $product): bool
    {
        if ($product instanceof \WC_Product_Grouped) {
            foreach ($product->get_children('edit') as $childId) {
                $child = wc_get_product($childId);

                if ($child instanceof \WC_Product && !$child->is_type('variation') && $child->get_status('edit') === 'publish'
                    && $child->get_stock_status('edit') !== self::OUT_OF_STOCK) {
                    return true;
                }
            }

            return false;
        }

        // The stored status, as getStockFlags() reads it (WooCommerce keeps a variable product's in line with its variations).
        return $product->get_stock_status('edit') !== self::OUT_OF_STOCK;
    }

    private function getStockQty(\WC_Product $product): ?int
    {
        if (!$this->config->includeStockQty() || $product->get_manage_stock('edit') !== true || !in_array($product->get_type(), ['simple', 'variable'], true)) {
            return null;
        }

        $qty = $product->get_stock_quantity('edit');

        return $qty === null ? null : max(0, (int) floor((float) $qty));
    }

    private function getDescription(\WC_Product $product): ?string
    {
        $short = (string) $product->get_short_description();
        $long = (string) $product->get_description();

        $html = match ($this->config->getDescriptionSource()) {
            Config::DESCRIPTION_SHORT => trim($short) !== '' ? $short : $long,
            Config::DESCRIPTION_BOTH => trim($short . "\n\n" . $long),
            default => trim($long) !== '' ? $long : $short,
        };

        return $this->text->toPlainText($html, 20000);
    }

    /**
     * @param \WC_Product_Variation[] $variations
     * @return string[] the main image first, then the gallery in its order
     */
    private function getImageUrls(\WC_Product $product, array $variations): array
    {
        $ids = array_merge([(int) $product->get_image_id('edit')], array_map('intval', $product->get_gallery_image_ids('edit')));
        $ids = array_values(array_unique(array_filter($ids)));

        if (!$ids) {
            foreach ($variations as $variation) {
                $ids[] = (int) $variation->get_image_id('edit');
            }

            $ids = array_values(array_unique(array_filter($ids)));
        }

        $urls = [];

        foreach ($ids as $attachmentId) {
            $url = $this->toUrl((string) wp_get_attachment_url($attachmentId));

            if ($url !== null && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }

            if (count($urls) >= $this->config->getImageCount()) {
                break;
            }
        }

        return $urls;
    }

    /**
     * The payload within AskMerra's limits, after the askmerra_product_payload filter: fields in their
     * order and types, texts cut, invalid optional values dropped. A product without a valid id or
     * name cannot be sent: it fails (and is tried again) instead of being removed.
     */
    private function normalize(array $payload): array
    {
        $externalId = is_scalar($payload['external_id'] ?? null) ? trim((string) $payload['external_id']) : '';
        $name = $this->toText($payload['name'] ?? null, 500);

        if ($externalId === '' || $this->text->units($externalId) > 255) {
            throw new \UnexpectedValueException('The product has no valid external_id (1-255 characters).');
        }

        if ($name === null) {
            throw new \UnexpectedValueException('The product has no name.');
        }

        $price = $this->toPrice($payload['price'] ?? null);
        $salePrice = $this->toPrice($payload['sale_price'] ?? null);
        $currency = is_string($payload['currency'] ?? null) ? strtoupper(trim($payload['currency'])) : '';
        $stockQty = $payload['stock_qty'] ?? null;
        $updatedAt = $payload['source_updated_at'] ?? null;

        return [
            'external_id' => $externalId,
            'sku' => $this->toText($payload['sku'] ?? null, 255),
            'parent_external_id' => null,
            'name' => $name,
            'description' => $this->toText($payload['description'] ?? null, 20000, true),
            'url' => is_string($payload['url'] ?? null) ? $this->toUrl($payload['url']) : null,
            'image_urls' => array_slice($this->toUrls($payload['image_urls'] ?? []), 0, 20),
            'price' => $price,
            'sale_price' => $price !== null && $salePrice !== null && $salePrice < $price ? $salePrice : null,
            'currency' => preg_match('/^[A-Z]{3}$/', $currency) ? $currency : null,
            'in_stock' => (bool) ($payload['in_stock'] ?? false),
            'stock_qty' => is_numeric($stockQty) ? max(0, (int) $stockQty) : null,
            'categories' => $this->toTexts($payload['categories'] ?? [], 50, 500),
            'brand' => $this->toText($payload['brand'] ?? null, 255),
            'attributes' => $this->toAttributes($payload['attributes'] ?? []),
            'locale' => in_array($payload['locale'] ?? null, Config::LOCALES, true) ? $payload['locale'] : null,
            'source_updated_at' => is_string($updatedAt) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/', $updatedAt)
                ? $updatedAt
                : null,
        ];
    }

    /** Trimmed text within $maxLength (one line unless $multiline); null when empty. Tags are not stripped again. */
    private function toText(mixed $value, int $maxLength, bool $multiline = false): ?string
    {
        if (!is_scalar($value) || is_bool($value)) {
            return null;
        }

        $text = mb_scrub((string) $value, 'UTF-8');
        $text = trim($multiline ? $text : (preg_replace('/\s+/u', ' ', $text) ?? $text));

        return $text === '' ? null : $this->text->limit($text, $maxLength, $multiline);
    }

    /** @return string[] unique texts */
    private function toTexts(mixed $values, int $maxItems, int $maxLength): array
    {
        $texts = [];

        foreach (is_array($values) ? $values : [] as $value) {
            $text = $this->toText($value, $maxLength);

            if ($text !== null && !in_array($text, $texts, true)) {
                $texts[] = $text;
            }
        }

        return array_slice($texts, 0, $maxItems);
    }

    /** @return string[] unique http(s) URLs */
    private function toUrls(mixed $values): array
    {
        $urls = [];

        foreach (is_array($values) ? $values : [] as $value) {
            $url = is_string($value) ? $this->toUrl($value) : null;

            if ($url !== null && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * AskMerra accepts only http(s) URLs of up to 2,048 characters. Spaces or non-ASCII letters in
     * slugs and file names are percent-encoded; what is already encoded stays.
     */
    private function toUrl(string $url): ?string
    {
        $url = (string) preg_replace_callback(
            '#[^A-Za-z0-9\-._~!$&\'()*+,;=:@/%?\#]#u',
            static fn (array $match): string => rawurlencode($match[0]),
            trim(mb_scrub($url, 'UTF-8'))
        );

        return preg_match('#^https?://[^\s/$.?\#][^\s]*$#i', $url) && strlen($url) <= 2048 ? $url : null;
    }

    private function toPrice(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $price = round((float) $value, max(2, wc_get_price_decimals()));

        return is_finite($price) && $price >= 0 && $price <= 100000000 ? $price : null;
    }

    /**
     * Attribute values AskMerra accepts: texts, numbers, yes/no and lists of texts under keys of up
     * to 100 characters. Always a JSON object, even when empty or keyed by numbers.
     *
     * @return array<string, mixed>|\stdClass
     */
    private function toAttributes(mixed $attributes): array|\stdClass
    {
        $result = [];

        foreach (is_array($attributes) || $attributes instanceof \stdClass ? (array) $attributes : [] as $key => $value) {
            $key = $this->toText((string) $key, 100);

            if ($key === null || array_key_exists($key, $result)) {
                continue;
            }

            $value = match (true) {
                is_bool($value), is_int($value) => $value,
                is_float($value) => is_finite($value) ? $value : null,
                is_string($value) => $this->toText($value, 2000),
                is_array($value) => $this->toTexts($value, 50, 500) ?: null,
                default => null,
            };

            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        return $result === [] || array_is_list($result) ? (object) $result : $result;
    }

    /** "visible", "catalog", "search" or "hidden" from the product's exclude-from-* terms. */
    private function toVisibility(array $excludedFrom): string
    {
        $catalog = in_array('exclude-from-catalog', $excludedFrom, true);
        $search = in_array('exclude-from-search', $excludedFrom, true);

        return match (true) {
            $catalog && $search => 'hidden',
            $search => 'catalog',
            $catalog => 'search',
            default => 'visible',
        };
    }

    /**
     * Builds as a guest sees the shop - no logged-in user (no role prices, no private products) and
     * the guest tax location - whoever started the sync. Returns what restores the request.
     */
    private function enterGuestContext(): \Closure
    {
        $userId = get_current_user_id();
        $location = $this->priceResolver->getGuestTaxLocation();
        $taxLocation = static fn (): array => $location;

        if ($userId !== 0) {
            wp_set_current_user(0);
        }

        add_filter('woocommerce_get_tax_location', $taxLocation, PHP_INT_MAX);

        return static function () use ($userId, $taxLocation): void {
            remove_filter('woocommerce_get_tax_location', $taxLocation, PHP_INT_MAX);

            if ($userId !== 0) {
                wp_set_current_user($userId);
            }
        };
    }

    /**
     * A batch leaves its posts, meta and terms in the request's object cache; a long sync (cron,
     * WP-CLI) would run out of memory. Only this process's memory is cleared: a persistent object
     * cache (Redis, Memcached) is never flushed.
     */
    private function freeObjectCache(): void
    {
        if (!wp_using_ext_object_cache()) {
            wp_cache_flush();
        } elseif (function_exists('wp_cache_supports') && wp_cache_supports('flush_runtime')) {
            wp_cache_flush_runtime();
        }
    }

    private function placeholders(int $count): string
    {
        return implode(',', array_fill(0, $count, '%d'));
    }
}
