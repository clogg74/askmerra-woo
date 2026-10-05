<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Catalog;

/**
 * Product attributes as AskMerra takes them: under the label the shop shows, as text or lists of
 * text. Global attributes (taxonomies pa_*) and attributes typed on products ("custom:<slug>") can
 * be chosen in the settings; the options of a variable product come from its purchasable variations.
 */
final class AttributeValues
{
    public const CUSTOM_PREFIX = 'custom:';

    /** AskMerra: keys up to 100 characters; text up to 2,000; lists of up to 50 texts of up to 500. */
    private const MAX_KEY = 100;
    private const MAX_TEXT = 2000;
    private const MAX_LIST = 50;
    private const MAX_LIST_ITEM = 500;

    /** The attributes typed on products (slug => name), found by scanning them; kept a day. */
    private const CUSTOM_CACHE = 'askmerra_custom_attributes';
    private const SCAN_PAGE = 1000;

    /** Product taxonomies that cannot hold a brand. */
    private const NOT_BRANDS = ['product_cat', 'product_tag', 'product_type', 'product_visibility', 'product_shipping_class'];

    public function __construct(private readonly TextCleaner $text)
    {
    }

    /**
     * Attributes a shop can choose: 'pa_color' => 'Color' (global attributes) and
     * 'custom:material' => 'Material (product attribute)' (typed on products).
     *
     * @return array<string, string>
     */
    public function getAvailableAttributes(): array
    {
        $options = [];

        foreach (wc_get_attribute_taxonomies() as $attribute) {
            $taxonomy = wc_attribute_taxonomy_name((string) $attribute->attribute_name);
            $options[$taxonomy] = $this->text->toLine((string) $attribute->attribute_label, 200) ?? $taxonomy;
        }

        // Two global attributes with the same label: the taxonomy tells them apart.
        $counts = array_count_values($options);

        foreach ($options as $taxonomy => $label) {
            if ($counts[$label] > 1) {
                $options[$taxonomy] = sprintf('%s (%s)', $label, $taxonomy);
            }
        }

        foreach ($this->getCustomAttributes() as $slug => $name) {
            /* translators: %s: name of an attribute typed on products */
            $options[self::CUSTOM_PREFIX . $slug] = sprintf(__('%s (product attribute)', 'askmerra-for-woocommerce'), $name);
        }

        uasort($options, 'strnatcasecmp');

        return $options;
    }

    /**
     * Where a brand can come from: 'taxonomy:product_brand' => 'Brands' (WooCommerce Brands, Perfect
     * Brands, YITH... or any product taxonomy), 'attribute:pa_brand' => 'Brand (attribute)'.
     *
     * @return array<string, string>
     */
    public function getBrandSources(): array
    {
        $sources = [];

        foreach (get_object_taxonomies('product', 'objects') as $taxonomy) {
            // Only public taxonomies a shop manages in the admin (brand plugins register them so);
            // WooCommerce's internal ones (product_visibility, pos_product_visibility...) are neither.
            if (!$taxonomy->public || !$taxonomy->show_ui || in_array($taxonomy->name, self::NOT_BRANDS, true) || taxonomy_is_product_attribute($taxonomy->name)) {
                continue;
            }

            $label = (string) ($taxonomy->labels->name ?? $taxonomy->label ?? $taxonomy->name);
            $sources['taxonomy:' . $taxonomy->name] = in_array($label, $sources, true) ? sprintf('%s (%s)', $label, $taxonomy->name) : $label;
        }

        foreach ($this->getAvailableAttributes() as $key => $label) {
            $sources['attribute:' . $key] = str_starts_with($key, self::CUSTOM_PREFIX)
                ? $label
                /* translators: %s: attribute label */
                : sprintf(__('%s (attribute)', 'askmerra-for-woocommerce'), $label);
        }

        return $sources;
    }

    /**
     * The chosen attributes of a product under their labels: one value as text, several as a list.
     *
     * @param string[] $keys 'pa_color' or 'custom:<slug>'
     * @return array<string, string|string[]>
     */
    public function getValues(\WC_Product $product, array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $attribute = $this->findAttribute($product, $key);

            if ($attribute === null) {
                continue;
            }

            $value = $this->toValue(array_values($this->getOptionNames($attribute)));

            if ($value === null) {
                continue;
            }

            $label = $this->getLabel($attribute, $product);

            if (isset($values[$label])) {
                $label = $this->text->limit($label . ' (' . $key . ')', self::MAX_KEY);
            }

            $values[$label] = $value;
        }

        return $values;
    }

    /**
     * The options a shopper can pick on a variable product, e.g. {"Size": ["S", "M"]}: the values of
     * its purchasable variations, in the attribute's order; a variation for "any" value offers all.
     *
     * @param \WC_Product_Variation[] $variations the purchasable variations
     * @return array<string, string[]>
     */
    public function getVariantOptions(\WC_Product_Variable $product, array $variations): array
    {
        $result = [];

        if (!$variations) {
            return $result;
        }

        foreach ($product->get_attributes() as $key => $attribute) {
            if (!$attribute instanceof \WC_Product_Attribute || !$attribute->get_variation()) {
                continue;
            }

            $options = $this->getOptionNames($attribute);
            $offered = [];

            foreach ($variations as $variation) {
                $value = (string) ($variation->get_attributes('edit')[$key] ?? '');

                if ($value === '') {
                    $offered = $options;
                    break;
                }

                $option = $this->matchOption($options, $value);

                if ($option !== null) {
                    $offered[$option] = true;
                }
            }

            $list = $this->toList(array_values(array_intersect_key($options, $offered)));

            if ($list) {
                $result[$this->getLabel($attribute, $product)] = $list;
            }
        }

        return $result;
    }

    /** The brand of a product from a source of getBrandSources(): the first value. */
    public function getBrand(\WC_Product $product, string $source): ?string
    {
        [$type, $name] = array_pad(explode(':', $source, 2), 2, '');

        if ($type === 'taxonomy') {
            $terms = taxonomy_exists($name) ? get_the_terms($product->get_id(), $name) : false;

            return is_array($terms) && $terms ? $this->text->toLine((string) $terms[0]->name, 255) : null;
        }

        if ($type === 'attribute') {
            $attribute = $this->findAttribute($product, $name);
            $names = $attribute ? array_values($this->getOptionNames($attribute)) : [];

            return $names ? $this->text->toLine($names[0], 255) : null;
        }

        return null;
    }

    /** Forgets the custom attributes found on products (rescanned when next needed). */
    public function forgetCustomAttributes(): void
    {
        delete_transient(self::CUSTOM_CACHE);
    }

    /** Adds the custom attributes of a saved product to the known ones, so the settings offer them at once. */
    public function noticeProduct(\WC_Product $product): void
    {
        $new = [];

        foreach ($product->get_attributes() as $slug => $attribute) {
            if ($attribute instanceof \WC_Product_Attribute && !$attribute->is_taxonomy()) {
                $name = $this->text->toLine($attribute->get_name(), 200);

                if ($name !== null) {
                    $new[sanitize_title((string) $slug)] = $name;
                }
            }
        }

        $known = $new ? get_transient(self::CUSTOM_CACHE) : false;

        if (is_array($known) && array_diff_key($new, $known)) {
            set_transient(self::CUSTOM_CACHE, $known + $new, DAY_IN_SECONDS);
        }
    }

    /** The product's attribute behind a setting key, or null when it has none. */
    private function findAttribute(\WC_Product $product, string $key): ?\WC_Product_Attribute
    {
        $isCustom = str_starts_with($key, self::CUSTOM_PREFIX);
        $slug = $isCustom ? substr($key, strlen(self::CUSTOM_PREFIX)) : $key;
        $attribute = $product->get_attributes()[$slug] ?? null;

        // A custom key names an attribute typed on the product, a pa_ key a global one.
        if (!$attribute instanceof \WC_Product_Attribute || $attribute->is_taxonomy() === $isCustom) {
            return null;
        }

        return $attribute;
    }

    /**
     * The attribute's values on the product, in its order: term slug => term name for global
     * attributes, value => value for typed ones.
     *
     * @return array<string, string>
     */
    private function getOptionNames(\WC_Product_Attribute $attribute): array
    {
        $names = [];

        if ($attribute->is_taxonomy()) {
            foreach ((array) $attribute->get_terms() as $term) {
                if ($term instanceof \WP_Term) {
                    $names[$term->slug] = (string) $term->name;
                }
            }
        } else {
            foreach ($attribute->get_options() as $option) {
                $names[(string) $option] = (string) $option;
            }
        }

        return $names;
    }

    /**
     * The option a variation's value stands for: the term slug, or the typed value (older
     * WooCommerce versions stored typed values as slugs).
     *
     * @param array<string, string> $options
     */
    private function matchOption(array $options, string $value): ?string
    {
        if (isset($options[$value])) {
            return $value;
        }

        foreach (array_keys($options) as $option) {
            if (strcasecmp((string) $option, $value) === 0 || sanitize_title((string) $option) === sanitize_title($value)) {
                return (string) $option;
            }
        }

        return null;
    }

    private function getLabel(\WC_Product_Attribute $attribute, \WC_Product $product): string
    {
        $label = $this->text->toLine((string) wc_attribute_label($attribute->get_name(), $product), self::MAX_KEY);

        return $label ?? $this->text->limit($attribute->get_name(), self::MAX_KEY);
    }

    /**
     * @param string[] $names
     * @return string|string[]|null one value as text, several as a list
     */
    private function toValue(array $names): string|array|null
    {
        $names = array_values(array_unique(array_filter(
            array_map(fn (string $name): ?string => $this->text->toLine($name, self::MAX_TEXT), $names),
            static fn (?string $name): bool => $name !== null
        )));

        if (count($names) === 1) {
            return $names[0];
        }

        return $this->toList($names) ?: null;
    }

    /**
     * @param string[] $names
     * @return string[] at most 50 unique texts of up to 500 characters
     */
    private function toList(array $names): array
    {
        $list = [];

        foreach ($names as $name) {
            $item = $this->text->toLine($name, self::MAX_LIST_ITEM);

            if ($item !== null && !in_array($item, $list, true)) {
                $list[] = $item;
            }
        }

        return array_slice($list, 0, self::MAX_LIST);
    }

    /**
     * The attributes typed on products, found by scanning their _product_attributes in pages.
     *
     * @return array<string, string> slug => name
     */
    private function getCustomAttributes(): array
    {
        $cached = get_transient(self::CUSTOM_CACHE);

        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;

        $found = [];
        $afterId = 0;

        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product'
                WHERE pm.meta_key = '_product_attributes' AND pm.post_id > %d
                ORDER BY pm.post_id LIMIT %d",
                $afterId,
                self::SCAN_PAGE
            ));

            foreach ($rows as $row) {
                $afterId = (int) $row->post_id;
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- damaged meta of one product must not stop the scan
                $attributes = @unserialize((string) $row->meta_value, ['allowed_classes' => false]);

                foreach (is_array($attributes) ? $attributes : [] as $slug => $attribute) {
                    if (!is_array($attribute) || !empty($attribute['is_taxonomy'])) {
                        continue;
                    }

                    $name = $this->text->toLine((string) ($attribute['name'] ?? ''), 200);
                    $slug = sanitize_title((string) $slug !== '' ? (string) $slug : (string) $name);

                    if ($name !== null && $slug !== '' && !isset($found[$slug])) {
                        $found[$slug] = $name;
                    }
                }
            }
        } while (count($rows) === self::SCAN_PAGE);

        set_transient(self::CUSTOM_CACHE, $found, DAY_IN_SECONDS);

        return $found;
    }
}
