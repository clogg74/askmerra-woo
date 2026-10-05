<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Frontend;

use AskMerra\WooCommerce\Catalog\ExternalId;
use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\StorefrontRepository;

/**
 * The AskMerra chat widget in the <head> of every storefront page, classic and block themes: the
 * snippet from the AskMerra dashboard with the site key and language, the product being viewed,
 * and the settings assets/frontend/askmerra.js needs for the WooCommerce cart, consent and order
 * reporting (window.AskMerraWooCommerce).
 */
final class Widget
{
    public const SCRIPT_ID = 'askmerra-widget-script';
    public const STOREFRONT_HANDLE = 'askmerra-storefront';

    private ?bool $visible = null;

    public function __construct(
        private readonly Config $config,
        private readonly StorefrontRepository $storefronts,
        private readonly ExternalId $externalId
    ) {
    }

    /**
     * On every storefront page while the widget is on; the checkout (and order payment) page only
     * with "Show on the checkout page". The order received page is not the checkout: the order is
     * reported there.
     */
    public function isVisible(): bool
    {
        if ($this->visible === null) {
            $this->visible = $this->config->isWidgetEnabled()
                && $this->getWidgetUrl() !== ''
                && !is_admin()
                && !is_feed()
                && !is_embed()
                && !is_robots()
                && !(function_exists('amp_is_request') && amp_is_request())
                && ($this->config->isShownOnCheckout() || !$this->isCheckoutPage());
        }

        return $this->visible;
    }

    /** wp_enqueue_scripts: the script that connects the chat to the cart (deferred, in the head). */
    public function enqueue(): void
    {
        if (!$this->isVisible()) {
            return;
        }

        wp_enqueue_script(
            self::STOREFRONT_HANDLE,
            ASKMERRA_WC_URL . 'assets/frontend/askmerra.js',
            [],
            ASKMERRA_WC_VERSION,
            ['strategy' => 'defer', 'in_footer' => false]
        );
    }

    /** wp_head, early: the settings, then the widget script (async: it never delays the page). */
    public function render(): void
    {
        if (!$this->isVisible()) {
            return;
        }

        wp_print_inline_script_tag(
            // Settings a theme or tag manager defined before keep precedence.
            'window.AskMerraSettings = Object.assign(' . Json::encode($this->getSettings()) . ', window.AskMerraSettings || {});'
            . 'window.AskMerraWooCommerce = ' . Json::encode($this->getStorefrontConfig()) . ';'
        );
        wp_print_script_tag($this->getScriptAttributes());
    }

    /** Attributes of the widget's script tag, as in the AskMerra dashboard snippet. */
    public function getScriptAttributes(): array
    {
        $attributes = [
            'id' => self::SCRIPT_ID,
            'src' => $this->getWidgetUrl(),
            'data-site-key' => $this->config->getSiteKey(),
        ];

        $locale = $this->storefronts->getDefault()->locale;

        if ($locale !== null) {
            $attributes['data-locale'] = $locale;
        }

        $position = $this->config->getWidgetPosition();

        if ($position !== null) {
            $attributes['data-position'] = $position;
        }

        if ($this->config->isOpenOnLoad()) {
            $attributes['data-open'] = 'true';
        }

        if ($this->config->getApiUrl() !== Config::DEFAULT_API_URL) {
            $attributes['data-api-url'] = $this->config->getApiUrl();
        }

        $attributes['async'] = true;

        return $attributes;
    }

    /** window.AskMerraSettings: read by the widget when it starts. */
    public function getSettings(): array
    {
        $settings = [];
        $productId = $this->getProductExternalId();

        if ($productId !== null) {
            $settings['productId'] = $productId;
        }

        if ($this->config->getConsentMode() === Config::CONSENT_GRANTED) {
            $settings['consent'] = ['analytics' => true];
        }

        return $settings;
    }

    /** window.AskMerraWooCommerce: read by assets/frontend/askmerra.js. */
    public function getStorefrontConfig(): array
    {
        $addToCart = $this->config->isAddToCartEnabled();

        return [
            'addToCart' => $addToCart,
            'ajaxUrl' => $addToCart ? \WC_AJAX::get_endpoint(AddToCart::AJAX_ACTION) : null,
            'cartUrl' => wc_get_cart_url(),
            'afterAdd' => $this->config->getAfterAddToCart(),
            'productIdentifier' => $this->config->getProductIdentifier(),
            'trackPurchases' => $this->config->isPurchaseTrackingEnabled(),
            // wp_consent_api: askmerra.js passes the WP Consent API's "statistics" consent on.
            'consentMode' => $this->config->getConsentMode(),
            'errorMessage' => __('The product could not be added to your cart. Please try again.', 'askmerra-for-woocommerce'),
        ];
    }

    /** The AskMerra id of the product on a single product page, when the chat may know it. */
    private function getProductExternalId(): ?string
    {
        if (!$this->config->isProductContextEnabled() || !is_product()) {
            return null;
        }

        $product = wc_get_product(get_queried_object_id());

        return $product instanceof \WC_Product && !$product->is_type('variation')
            ? $this->externalId->get($product)
            : null;
    }

    /** The checkout and order payment pages; other checkout plugins answer through the filter. */
    private function isCheckoutPage(): bool
    {
        $checkout = function_exists('is_checkout') && is_checkout() && !is_wc_endpoint_url('order-received');

        /**
         * Whether the current page is a checkout page, where the chat stays hidden unless "Show on
         * the checkout page" is on. One-page checkout and funnel plugins can add their pages.
         *
         * @param bool $checkout
         */
        return (bool) apply_filters('askmerra_is_checkout_page', $checkout);
    }

    /** The widget URL from the settings, when it is an http(s) URL. */
    private function getWidgetUrl(): string
    {
        return (string) esc_url_raw($this->config->getWidgetUrl(), ['https', 'http']);
    }
}
