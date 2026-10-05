<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Cli;

use AskMerra\WooCommerce\ModuleInterface;
use AskMerra\WooCommerce\Plugin;

/** WP-CLI: wp askmerra test | status | sync | feed | payload | remove (see Command). */
final class Module implements ModuleInterface
{
    public function services(): array
    {
        return [];
    }

    public function register(Plugin $plugin): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        // WP-CLI creates the command only when an askmerra subcommand runs.
        \WP_CLI::add_command('askmerra', Command::class, [
            'shortdesc' => 'Checks the AskMerra connection and runs the catalog sync.',
        ]);
    }
}
