<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce;

/**
 * A part of the plugin (Api, Catalog, Feed, Sync, Admin, Frontend, Cli): the services it provides
 * and the WordPress hooks it registers. See docs/ARCHITECTURE.md.
 */
interface ModuleInterface
{
    /**
     * Factories of the module's services, by class name. Each factory receives the plugin and gets
     * the services it needs with $plugin->get(Other::class); services are created once, when first
     * needed.
     *
     * @return array<class-string, callable(Plugin): object>
     */
    public function services(): array;

    /** Adds the module's actions and filters. Called once, on plugins_loaded, when WooCommerce is active. */
    public function register(Plugin $plugin): void;
}
