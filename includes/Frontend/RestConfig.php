<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Frontend;

use AskMerra\WooCommerce\Config;

/**
 * GET /wp-json/askmerra/v1/widget-config: the widget settings for headless storefronts (Next.js,
 * Faust.js...), which load the widget themselves - the counterpart of the Magento module's
 * askMerraWidgetConfig GraphQL query, with the same fields. Public: everything here is printed in
 * every page's head anyway; the secret key never is.
 */
final class RestConfig
{
    public const NAMESPACE = 'askmerra/v1';
    public const ROUTE = '/widget-config';

    public function __construct(
        private readonly Config $config,
        private readonly Widget $widget
    ) {
    }

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => fn (): \WP_REST_Response => new \WP_REST_Response($this->getConfig()),
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Load script_url with the attributes data-site-key, data-locale, data-position, data-open and
     * data-api-url, as in the AskMerra snippet; set window.AskMerraSettings.productId on product pages
     * (product_context) and handle the widget's add_to_cart event (add_to_cart) with
     * add_to_cart_endpoint (POST external_id, sku) or add_to_cart_link.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        if (!$this->config->isWidgetEnabled()) {
            return ['enabled' => false];
        }

        $script = $this->widget->getScriptAttributes();
        $storefront = $this->widget->getStorefrontConfig();

        return [
            'enabled' => true,
            'script_url' => $script['src'],
            'site_key' => $script['data-site-key'],
            'locale' => $script['data-locale'] ?? null,
            'position' => $script['data-position'] ?? null,
            'open_on_load' => isset($script['data-open']),
            'api_url' => $script['data-api-url'] ?? null,
            'product_identifier' => $storefront['productIdentifier'],
            'product_context' => $this->config->isProductContextEnabled(),
            'add_to_cart' => $storefront['addToCart'],
            'after_add_to_cart' => $storefront['afterAdd'],
            'track_purchases' => $storefront['trackPurchases'],
            'consent_mode' => $storefront['consentMode'],
            // WooCommerce gives a path; a storefront on another host needs the whole URL.
            'add_to_cart_endpoint' => $storefront['ajaxUrl'] !== null
                ? \WP_Http::make_absolute_url($storefront['ajaxUrl'], home_url('/'))
                : null,
            'add_to_cart_link' => add_query_arg(AddToCart::LINK_PARAMETER, '{external_id}', home_url('/')),
            'cart_url' => $storefront['cartUrl'],
        ];
    }
}
