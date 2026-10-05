<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Feed;

use AskMerra\WooCommerce\Config;

/**
 * The secret part of the feed file names. AskMerra cannot send credentials when it downloads a
 * feed, so an unguessable URL is what keeps the catalog file private. Regenerating it changes every
 * feed URL; the AskMerra feed sources then need the new ones.
 */
final class FeedToken
{
    public function __construct(private readonly Config $config)
    {
    }

    public function get(): string
    {
        $token = $this->config->getFeedToken();

        return $token !== '' ? $token : $this->regenerate();
    }

    public function regenerate(): string
    {
        $token = bin2hex(random_bytes(16));
        update_option(Config::OPTION_FEED_TOKEN, $token, false);

        return $token;
    }
}
