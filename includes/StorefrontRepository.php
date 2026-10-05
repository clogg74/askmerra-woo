<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce;

/** The storefronts of the site and which of them sync. Version 1: one storefront per site. */
final class StorefrontRepository
{
    private ?Storefront $default = null;

    public function __construct(private readonly Config $config)
    {
    }

    /** @return Storefront[] keyed by id */
    public function all(): array
    {
        $storefront = $this->getDefault();

        return [$storefront->id => $storefront];
    }

    public function get(string $id): ?Storefront
    {
        return $this->all()[$id] ?? null;
    }

    public function getDefault(): Storefront
    {
        if ($this->default === null) {
            $wpLocale = (string) get_locale();
            $setting = $this->config->getLocaleSetting();

            $this->default = new Storefront(
                Storefront::DEFAULT_ID,
                (string) get_bloginfo('name'),
                trailingslashit(home_url('/')),
                function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : 'EUR',
                $wpLocale,
                $setting !== 'auto' ? $setting : Config::toAskMerraLocale($wpLocale)
            );
        }

        return $this->default;
    }

    /** @return Storefront[] keyed by id: the storefronts whose catalog is kept in AskMerra (push or feed) */
    public function syncing(): array
    {
        return $this->config->isSyncEnabled() ? $this->all() : [];
    }

    /** @return Storefront[] keyed by id */
    public function pushing(): array
    {
        return $this->config->isPushEnabled() ? $this->all() : [];
    }

    /** @return Storefront[] keyed by id */
    public function feeding(): array
    {
        return $this->config->isFeedEnabled() ? $this->all() : [];
    }

    /** Forgets cached storefront data (after the settings or the site language changed). */
    public function reset(): void
    {
        $this->default = null;
    }
}
