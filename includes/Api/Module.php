<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Api;

use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Log\Logger;
use AskMerra\WooCommerce\ModuleInterface;
use AskMerra\WooCommerce\Plugin;

/** The AskMerra Push API client. No hooks: the sync, the admin and WP-CLI call it. */
final class Module implements ModuleInterface
{
    public function services(): array
    {
        return [
            Client::class => static fn (Plugin $plugin): Client => new Client(
                $plugin->get(Config::class),
                $plugin->get(Logger::class)
            ),
        ];
    }

    public function register(Plugin $plugin): void
    {
    }
}
