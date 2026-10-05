<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce;

/**
 * Where products are shown and sold in one language and currency - the WooCommerce counterpart of a
 * Magento store view. Version 1 has one storefront per site ('default'); multilingual shops
 * (WPML, Polylang) can get one per language later without changing the sync engine, which keys
 * everything by storefront id.
 */
final class Storefront
{
    public const DEFAULT_ID = 'default';

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        /** The storefront's home URL, with a trailing slash. */
        public readonly string $homeUrl,
        /** ISO 4217 code, e.g. EUR. */
        public readonly string $currency,
        /** WordPress locale, e.g. ro_RO. */
        public readonly string $wpLocale,
        /** AskMerra locale (en, ro, it, fr, de, es) or null when AskMerra does not serve the language. */
        public readonly ?string $locale
    ) {
    }
}
