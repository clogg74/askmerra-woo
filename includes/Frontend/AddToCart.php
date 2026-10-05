<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Frontend;

use AskMerra\WooCommerce\Catalog\ExternalId;
use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Log\Logger;

/**
 * "Add to cart" in the AskMerra chat. A product that can be bought as it is - a simple product,
 * purchasable and in stock - goes into the WooCommerce cart; any other product (variable, grouped,
 * external, needing options) answers with its page, where the shopper picks what they want.
 *
 * - POST ?wc-ajax=askmerra_add_to_cart (external_id, sku), from assets/frontend/askmerra.js. Answers
 *   {success: true, name, message, cartUrl, cartLabel, qty, fragments, cart_hash} or
 *   {success: false, redirect?, message?}.
 * - GET ?askmerra_add_to_cart=<external id or SKU>, for the "add-to-cart link" of the AskMerra
 *   widget designer (used on pages without an add_to_cart handler): adds the product and opens the
 *   cart, or opens the product page.
 *
 * Like WooCommerce's own Ajax add to cart and ?add-to-cart= link there is no nonce: cached pages
 * would carry expired ones, and the worst a forged request does is add a product to a cart.
 */
final class AddToCart
{
    public const AJAX_ACTION = 'askmerra_add_to_cart';
    public const LINK_PARAMETER = 'askmerra_add_to_cart';

    public function __construct(
        private readonly Config $config,
        private readonly ExternalId $externalId,
        private readonly Logger $logger
    ) {
    }

    /** wc_ajax_askmerra_add_to_cart */
    public function handleAjax(): void
    {
        ob_start();
        [$data, $status] = $this->answer();
        // Nothing printed by other code on the way (notices, plugins) may break the JSON.
        ob_end_clean();

        wp_send_json($data, $status);
    }

    /** wp_loaded, after WooCommerce loaded the cart: ?askmerra_add_to_cart=<external id or SKU> */
    public function handleLink(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- no nonce, see the class comment
        $value = self::input($_GET, self::LINK_PARAMETER);

        if ($value === ''
            || is_admin()
            || wp_doing_ajax()
            || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET'
            || !$this->config->isEnabled()
        ) {
            return;
        }

        if (WC()->cart === null && function_exists('wc_load_cart')) {
            wc_load_cart();
        }

        nocache_headers();
        $product = $this->findProduct($value, $value);

        if ($product === null) {
            // A new visitor has no session yet: the notice must survive the redirect.
            if (WC()->session !== null && !WC()->session->has_session()) {
                WC()->session->set_customer_session_cookie(true);
            }

            wc_add_notice(__('This product is no longer available.', 'askmerra-for-woocommerce'), 'error');
            $this->redirect(wc_get_page_permalink('shop'));
        }

        if (!$this->config->isAddToCartEnabled() || WC()->cart === null || !$this->canAddDirectly($product)) {
            $this->redirect($product->get_permalink());
        }

        if ($this->add($product) !== true) {
            $this->redirect($this->getErrorRedirect($product));
        }

        wc_add_to_cart_message([$product->get_id() => 1], true);
        $this->redirect(wc_get_cart_url());
    }

    /** @return array{0: array, 1: int} the JSON answer and its HTTP status */
    private function answer(): array
    {
        if (!$this->config->isWidgetEnabled() || !$this->config->isAddToCartEnabled()) {
            return [['success' => false], 404];
        }

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            return [['success' => false], 405];
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- no nonce, see the class comment
        $product = $this->findProduct(self::input($_POST, 'external_id'), self::input($_POST, 'sku'));
        // phpcs:enable

        if ($product === null) {
            return [['success' => false, 'message' => __('This product is no longer available.', 'askmerra-for-woocommerce')], 200];
        }

        if (!$this->canAddDirectly($product)) {
            return [['success' => false, 'redirect' => $product->get_permalink()], 200];
        }

        $added = $this->add($product);

        if ($added !== true) {
            // As WooCommerce's own add to cart: the product page shows why (stock, quantity...).
            $answer = ['success' => false, 'redirect' => $this->getErrorRedirect($product)];

            return [$added !== '' ? $answer + ['message' => $added] : $answer, 200];
        }

        if ($this->config->getAfterAddToCart() === Config::AFTER_ADD_CART) {
            // The cart page, opened next, says what was added.
            wc_add_to_cart_message([$product->get_id() => 1], true);
        }

        return [[
            'success' => true,
            'name' => $product->get_name(),
            /* translators: %s: product name */
            'message' => sprintf(__('%s was added to your cart.', 'askmerra-for-woocommerce'), $product->get_name()),
            'cartUrl' => wc_get_cart_url(),
            'cartLabel' => __('View cart', 'askmerra-for-woocommerce'),
            'qty' => (int) WC()->cart->get_cart_contents_count(),
            'fragments' => $this->getFragments(),
            'cart_hash' => WC()->cart->get_cart_hash(),
        ], 200];
    }

    /**
     * The product behind an add_to_cart event of the chat: by its AskMerra id, else by SKU.
     * Published products only; a variation found by SKU is returned as it is (its page preselects
     * its options).
     */
    private function findProduct(string $externalId, string $sku): ?\WC_Product
    {
        $productId = $externalId !== '' ? $this->externalId->toProductId($externalId) : null;

        if ($productId === null && $sku !== '') {
            $productId = (int) wc_get_product_id_by_sku($sku) ?: null;
        }

        $product = $productId !== null ? wc_get_product($productId) : null;

        if (!$product instanceof \WC_Product || $product->get_status() !== 'publish') {
            return null;
        }

        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());

            if (!$parent instanceof \WC_Product || $parent->get_status() !== 'publish') {
                return null;
            }
        }

        return $product;
    }

    /** A simple product the cart takes as it is. */
    private function canAddDirectly(\WC_Product $product): bool
    {
        $canAdd = $product->is_type('simple')
            && $product->is_purchasable()
            && $product->is_in_stock()
            && !post_password_required($product->get_id())
            && !$this->hasRequiredAddOns($product);

        /**
         * Whether the chat may put this product into the cart directly; false opens its page.
         * Return false for products that need input on their page (custom options, bookings...).
         *
         * @param bool        $canAdd
         * @param \WC_Product $product
         */
        return (bool) apply_filters('askmerra_can_add_directly', $canAdd, $product);
    }

    /** WooCommerce Product Add-Ons: a required add-on is chosen on the product page. */
    private function hasRequiredAddOns(\WC_Product $product): bool
    {
        if (!class_exists('WC_Product_Addons_Helper') || !method_exists('WC_Product_Addons_Helper', 'get_product_addons')) {
            return false;
        }

        foreach ((array) \WC_Product_Addons_Helper::get_product_addons($product->get_id()) as $addon) {
            if (is_array($addon) && !empty($addon['required'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adds one, as WooCommerce's own Ajax add to cart does (WC_AJAX::add_to_cart).
     *
     * @return true|string true, or why it was refused ('' when unknown)
     */
    private function add(\WC_Product $product): bool|string
    {
        $productId = $product->get_id();

        try {
            $passed = (bool) apply_filters('woocommerce_add_to_cart_validation', true, $productId, 1);

            if ($passed && WC()->cart->add_to_cart($productId, 1) !== false) {
                // Analytics and marketing plugins listen to this, as for WooCommerce's own Ajax add to cart.
                do_action('woocommerce_ajax_added_to_cart', $productId);

                /**
                 * After the AskMerra chat put a product into the cart.
                 *
                 * @param \WC_Product $product
                 * @param string      $externalId the product's AskMerra id
                 */
                do_action('askmerra_cart_added', $product, $this->externalId->get($product));

                return true;
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Add to cart of product %d: %s', $productId, $e->getMessage()), ['exception' => $e]);

            return '';
        }

        // WooCommerce keeps the reason as an error notice; the product page shows it too.
        $notices = wc_get_notices('error');
        $last = is_array($notices) && $notices ? end($notices) : null;
        $text = is_array($last) ? (string) ($last['notice'] ?? '') : (string) $last;

        return trim(html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES, 'UTF-8'));
    }

    private function getErrorRedirect(\WC_Product $product): string
    {
        return (string) apply_filters('woocommerce_cart_redirect_after_error', $product->get_permalink(), $product->get_id());
    }

    /** The mini cart and the theme's cart fragments, as WC_AJAX::get_refreshed_fragments() makes them. */
    private function getFragments(): array
    {
        ob_start();
        woocommerce_mini_cart();
        $miniCart = (string) ob_get_clean();

        return (array) apply_filters(
            'woocommerce_add_to_cart_fragments',
            ['div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $miniCart . '</div>']
        );
    }

    private function redirect(string $url): never
    {
        wp_safe_redirect($url !== '' ? $url : home_url('/'));
        exit;
    }

    /** A request value as text ('' when missing or not a single value). */
    private static function input(array $source, string $key): string
    {
        return isset($source[$key]) && is_scalar($source[$key])
            ? trim(sanitize_text_field(wp_unslash((string) $source[$key])))
            : '';
    }
}
