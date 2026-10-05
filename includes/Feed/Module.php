<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Feed;

use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Log\Logger;
use AskMerra\WooCommerce\ModuleInterface;
use AskMerra\WooCommerce\Plugin;
use AskMerra\WooCommerce\StorefrontRepository;

/**
 * The product feed files. No hooks: Sync\Scheduler runs FeedGenerator::generateAll() on the feed
 * schedule, the admin and WP-CLI write them on demand.
 */
final class Module implements ModuleInterface
{
    public function services(): array
    {
        return [
            FeedGenerator::class => static fn (Plugin $plugin): FeedGenerator => new FeedGenerator(
                $plugin->get(Config::class),
                $plugin->get(StorefrontRepository::class),
                $plugin->get(FeedFlags::class),
                $plugin->get(FeedToken::class),
                $plugin->get(Logger::class),
                $plugin
            ),
            FeedToken::class => static fn (Plugin $plugin): FeedToken => new FeedToken($plugin->get(Config::class)),
            FeedFlags::class => static fn (): FeedFlags => new FeedFlags(),
        ];
    }

    public function register(Plugin $plugin): void
    {
    }
}
