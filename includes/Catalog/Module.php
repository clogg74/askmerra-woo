<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Catalog;

use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Log\Logger;
use AskMerra\WooCommerce\ModuleInterface;
use AskMerra\WooCommerce\Plugin;

/** WooCommerce products as AskMerra products: building them, their ids, attributes, categories, prices and languages. */
final class Module implements ModuleInterface
{
    public function services(): array
    {
        return [
            TextCleaner::class => static fn (): TextCleaner => new TextCleaner(),
            CategoryPaths::class => static fn (Plugin $plugin): CategoryPaths => new CategoryPaths($plugin->get(TextCleaner::class)),
            PriceResolver::class => static fn (): PriceResolver => new PriceResolver(),
            ExternalId::class => static fn (Plugin $plugin): ExternalId => new ExternalId($plugin->get(Config::class)),
            AttributeValues::class => static fn (Plugin $plugin): AttributeValues => new AttributeValues($plugin->get(TextCleaner::class)),
            Translations::class => static fn (): Translations => new Translations(),
            ProductBuilder::class => static fn (Plugin $plugin): ProductBuilder => new ProductBuilder(
                $plugin->get(Config::class),
                $plugin->get(ExternalId::class),
                $plugin->get(AttributeValues::class),
                $plugin->get(CategoryPaths::class),
                $plugin->get(PriceResolver::class),
                $plugin->get(TextCleaner::class),
                $plugin->get(Logger::class),
                $plugin->get(Translations::class)
            ),
        ];
    }

    public function register(Plugin $plugin): void
    {
        // The attribute picker in the settings lists the attributes typed on products: keep it current.
        $forget = static function () use ($plugin): void {
            $plugin->get(AttributeValues::class)->forgetCustomAttributes();
        };

        add_action('woocommerce_attribute_added', $forget);
        add_action('woocommerce_attribute_updated', $forget);
        add_action('woocommerce_attribute_deleted', $forget);

        $notice = static function ($productId, $product = null) use ($plugin): void {
            if ($product instanceof \WC_Product) {
                $plugin->get(AttributeValues::class)->noticeProduct($product);
            }
        };

        add_action('woocommerce_new_product', $notice, 10, 2);
        add_action('woocommerce_update_product', $notice, 10, 2);
    }
}
