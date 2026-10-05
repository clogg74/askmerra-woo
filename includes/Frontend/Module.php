<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Frontend;

use AskMerra\WooCommerce\Catalog\ExternalId;
use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Log\Logger;
use AskMerra\WooCommerce\ModuleInterface;
use AskMerra\WooCommerce\Plugin;
use AskMerra\WooCommerce\StorefrontRepository;

/**
 * The storefront: the chat widget in the page head, add to cart from the chat, the order on the
 * order received page and the widget settings for headless storefronts. See README "Storefront".
 */
final class Module implements ModuleInterface
{
    public function services(): array
    {
        return [
            Widget::class => static fn (Plugin $plugin): Widget => new Widget(
                $plugin->get(Config::class),
                $plugin->get(StorefrontRepository::class),
                $plugin->get(ExternalId::class)
            ),
            AddToCart::class => static fn (Plugin $plugin): AddToCart => new AddToCart(
                $plugin->get(Config::class),
                $plugin->get(ExternalId::class),
                $plugin->get(Logger::class)
            ),
            Purchase::class => static fn (Plugin $plugin): Purchase => new Purchase(
                $plugin->get(Config::class),
                $plugin->get(ExternalId::class)
            ),
            RestConfig::class => static fn (Plugin $plugin): RestConfig => new RestConfig(
                $plugin->get(Config::class),
                $plugin->get(Widget::class)
            ),
        ];
    }

    public function register(Plugin $plugin): void
    {
        add_action('wp_enqueue_scripts', static function () use ($plugin): void {
            $plugin->get(Widget::class)->enqueue();
        });
        // Early in the head, before the theme's and other plugins' scripts.
        add_action('wp_head', static function () use ($plugin): void {
            $plugin->get(Widget::class)->render();
        }, 2);

        add_action('wc_ajax_' . AddToCart::AJAX_ACTION, static function () use ($plugin): void {
            $plugin->get(AddToCart::class)->handleAjax();
        });
        // After WooCommerce loaded the cart from the session (wp_loaded, 10) and its own handlers (20).
        add_action('wp_loaded', static function () use ($plugin): void {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only checks the link is used
            if (isset($_GET[AddToCart::LINK_PARAMETER])) {
                $plugin->get(AddToCart::class)->handleLink();
            }
        }, 25);

        add_action('woocommerce_thankyou', static function ($orderId) use ($plugin): void {
            $plugin->get(Purchase::class)->render($orderId);
        }, 20);

        // Headless storefronts read the widget settings here.
        add_action('rest_api_init', static function () use ($plugin): void {
            $plugin->get(RestConfig::class)->register();
        });

        // WP Consent API: orders are reported only with the shopper's "statistics" consent.
        add_filter('wp_consent_api_registered_' . plugin_basename(ASKMERRA_WC_FILE), '__return_true');
    }
}
