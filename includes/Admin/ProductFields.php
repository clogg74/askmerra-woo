<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Admin;

use AskMerra\WooCommerce\Config;

/**
 * "Hide from AskMerra" on a product: the checkbox in Product data > General, in quick edit and bulk
 * edit, and a filter of the products list. Saved through the product object, so the sync hears it.
 */
final class ProductFields
{
    /** Bulk edit select: '' keeps each product's choice. */
    private const BULK_FIELD = '_askmerra_exclude_bulk';

    /** Products list filter. */
    private const FILTER = 'askmerra_hidden';

    public function register(): void
    {
        add_action('woocommerce_product_options_general_product_data', [$this, 'renderCheckbox']);
        add_action('woocommerce_admin_process_product_object', [$this, 'saveFromProductForm']);

        add_action('woocommerce_product_quick_edit_end', [$this, 'renderQuickEdit']);
        add_action('woocommerce_product_quick_edit_save', [$this, 'saveFromQuickEdit']);
        add_action('woocommerce_product_bulk_edit_end', [$this, 'renderBulkEdit']);
        add_action('woocommerce_product_bulk_edit_save', [$this, 'saveFromBulkEdit']);
        add_action('manage_product_posts_custom_column', [$this, 'renderInlineData'], 20, 2);

        add_action('restrict_manage_posts', [$this, 'renderListFilter'], 20, 2);
        add_action('pre_get_posts', [$this, 'applyListFilter']);
    }

    public function renderCheckbox(): void
    {
        global $product_object;

        if (!$product_object instanceof \WC_Product) {
            return;
        }

        echo '<div class="options_group">';
        woocommerce_wp_checkbox([
            'id' => Config::EXCLUDE_META,
            'label' => __('Hide from AskMerra', 'askmerra-for-woocommerce'),
            'description' => __('The AI shopping assistant will not know or recommend this product.', 'askmerra-for-woocommerce'),
            'value' => $this->isHidden($product_object) ? 'yes' : 'no',
            'cbvalue' => 'yes',
        ]);
        echo '</div>';
    }

    /** Product edit screen; WooCommerce checked the nonce and saves the product afterwards. */
    public function saveFromProductForm(\WC_Product $product): void
    {
        $this->setHidden($product, !empty($_POST[Config::EXCLUDE_META])); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }

    public function renderQuickEdit(): void
    {
        ?>
        <br class="clear" />
        <label class="alignleft askmerra-quick-edit">
            <input type="checkbox" name="<?php echo esc_attr(Config::EXCLUDE_META); ?>" value="yes" />
            <span class="checkbox-title"><?php esc_html_e('Hide from AskMerra', 'askmerra-for-woocommerce'); ?></span>
        </label>
        <?php
    }

    /** Quick edit runs after WooCommerce saved the product (its nonce is checked there). */
    public function saveFromQuickEdit(\WC_Product $product): void
    {
        if ($this->setHidden($product, !empty($_REQUEST[Config::EXCLUDE_META]))) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $product->save();
        }
    }

    public function renderBulkEdit(): void
    {
        ?>
        <label class="alignleft askmerra-bulk-edit">
            <span class="title"><?php esc_html_e('AskMerra', 'askmerra-for-woocommerce'); ?></span>
            <span class="input-text-wrap">
                <select name="<?php echo esc_attr(self::BULK_FIELD); ?>">
                    <option value=""><?php esc_html_e('— No change —', 'askmerra-for-woocommerce'); ?></option>
                    <option value="yes"><?php esc_html_e('Hide from AskMerra', 'askmerra-for-woocommerce'); ?></option>
                    <option value="no"><?php esc_html_e('Show in AskMerra', 'askmerra-for-woocommerce'); ?></option>
                </select>
            </span>
        </label>
        <?php
    }

    /** Bulk edit runs after WooCommerce saved each product (its nonce is checked there). */
    public function saveFromBulkEdit(\WC_Product $product): void
    {
        $choice = isset($_REQUEST[self::BULK_FIELD]) ? sanitize_key(wp_unslash($_REQUEST[self::BULK_FIELD])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (in_array($choice, ['yes', 'no'], true) && $this->setHidden($product, $choice === 'yes')) {
            $product->save();
        }
    }

    /** The current choice, hidden in the products list, for quick edit to start from. */
    public function renderInlineData(string $column, int $postId): void
    {
        if ($column !== 'name') {
            return;
        }

        printf(
            '<div class="hidden" id="askmerra_inline_%d" data-hidden="%s"></div>',
            $postId, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integer
            get_post_meta($postId, Config::EXCLUDE_META, true) === 'yes' ? 'yes' : 'no'
        );
    }

    public function renderListFilter(string $postType, string $which = 'top'): void
    {
        if ($postType !== 'product' || $which !== 'top') {
            return;
        }

        $current = isset($_GET[self::FILTER]) ? sanitize_key(wp_unslash($_GET[self::FILTER])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <select name="<?php echo esc_attr(self::FILTER); ?>">
            <option value=""><?php esc_html_e('AskMerra: all products', 'askmerra-for-woocommerce'); ?></option>
            <option value="yes" <?php selected($current, 'yes'); ?>><?php esc_html_e('Hidden from AskMerra', 'askmerra-for-woocommerce'); ?></option>
        </select>
        <?php
    }

    public function applyListFilter(\WP_Query $query): void
    {
        global $pagenow;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter
        if (!is_admin() || !$query->is_main_query() || $pagenow !== 'edit.php' || ($_GET[self::FILTER] ?? '') !== 'yes'
            || $query->get('post_type') !== 'product'
        ) {
            return;
        }

        $metaQuery = (array) $query->get('meta_query');
        $metaQuery[] = ['key' => Config::EXCLUDE_META, 'value' => 'yes'];
        $query->set('meta_query', $metaQuery);
    }

    private function isHidden(\WC_Product $product): bool
    {
        return $product->get_meta(Config::EXCLUDE_META) === 'yes';
    }

    /**
     * Sets the choice; a product that was never hidden gets no meta row.
     *
     * @return bool whether it changed
     */
    private function setHidden(\WC_Product $product, bool $hidden): bool
    {
        if ($this->isHidden($product) === $hidden) {
            return false;
        }

        $product->update_meta_data(Config::EXCLUDE_META, $hidden ? 'yes' : 'no');

        return true;
    }
}
