<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Admin;

use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Crypto;
use AskMerra\WooCommerce\Log\Logger;
use AskMerra\WooCommerce\ModuleInterface;
use AskMerra\WooCommerce\Plugin;
use AskMerra\WooCommerce\StorefrontRepository;

/**
 * wp-admin: WooCommerce > Settings > AskMerra, the sync status page (WooCommerce > AskMerra), the
 * product's "Hide from AskMerra", the "Send to AskMerra now" bulk action and the notices.
 */
final class Module implements ModuleInterface
{
    /** Classes whose register() adds their hooks. */
    private const HOOKED = [
        SettingsFields::class,
        ConnectionTest::class,
        StatusPage::class,
        ProductFields::class,
        BulkActions::class,
        Notices::class,
        PluginLinks::class,
        Assets::class,
    ];

    public function services(): array
    {
        return [
            Messages::class => static fn (): Messages => new Messages(),
            SettingsPage::class => static fn (Plugin $p): SettingsPage => new SettingsPage(
                $p,
                $p->get(Config::class),
                $p->get(Crypto::class)
            ),
            SettingsFields::class => static fn (Plugin $p): SettingsFields => new SettingsFields(
                $p,
                $p->get(Config::class),
                $p->get(Crypto::class),
                $p->get(StorefrontRepository::class)
            ),
            ConnectionTest::class => static fn (Plugin $p): ConnectionTest => new ConnectionTest(
                $p,
                $p->get(Config::class),
                $p->get(StorefrontRepository::class)
            ),
            StatusPage::class => static fn (Plugin $p): StatusPage => new StatusPage(
                $p,
                $p->get(Config::class),
                $p->get(StorefrontRepository::class),
                $p->get(Messages::class),
                $p->get(Logger::class)
            ),
            ProductFields::class => static fn (): ProductFields => new ProductFields(),
            BulkActions::class => static fn (Plugin $p): BulkActions => new BulkActions(
                $p,
                $p->get(StorefrontRepository::class),
                $p->get(Messages::class)
            ),
            Notices::class => static fn (Plugin $p): Notices => new Notices(
                $p,
                $p->get(Config::class),
                $p->get(StorefrontRepository::class),
                $p->get(Messages::class)
            ),
            PluginLinks::class => static fn (): PluginLinks => new PluginLinks(),
            Assets::class => static fn (Plugin $p): Assets => new Assets($p->get(StatusPage::class)),
        ];
    }

    public function register(Plugin $plugin): void
    {
        // Everything here belongs to wp-admin (its pages, admin-ajax.php and admin-post.php).
        if (!is_admin()) {
            return;
        }

        // WooCommerce loads its settings pages late; WC_Settings_Page only exists from then on.
        add_filter('woocommerce_get_settings_pages', static function (array $pages) use ($plugin): array {
            $pages[] = $plugin->get(SettingsPage::class);

            return $pages;
        });

        foreach (self::HOOKED as $class) {
            $plugin->get($class)->register();
        }
    }
}
