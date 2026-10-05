<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce;

/**
 * Every setting of the plugin. Stored as WordPress options (WooCommerce > Settings > AskMerra writes
 * them, one option per field): checkboxes as 'yes' / 'no', multi-selects as arrays.
 *
 * The option names are the OPTION_* constants; read them only through this class, which applies
 * the defaults and validation.
 */
final class Config
{
    /** The languages AskMerra serves. */
    public const LOCALES = ['en', 'ro', 'it', 'fr', 'de', 'es'];

    public const SYNC_PUSH = 'push';
    public const SYNC_FEED = 'feed';

    public const IDENTIFIER_ID = 'id';
    public const IDENTIFIER_SKU = 'sku';

    public const FEED_JSON = 'json';
    public const FEED_GOOGLE_XML = 'google_xml';
    public const FEED_FREQUENCIES = [60, 180, 360, 480, 720, 1440];

    public const DESCRIPTION_LONG = 'description';
    public const DESCRIPTION_SHORT = 'short_description';
    public const DESCRIPTION_BOTH = 'both';

    public const REBUILD_FREQUENCIES = ['daily', 'weekly', 'monthly', 'never'];

    public const CONSENT_AUTO = 'auto';
    public const CONSENT_GRANTED = 'granted';
    public const CONSENT_WP_CONSENT_API = 'wp_consent_api';
    public const CONSENT_MANUAL = 'manual';

    public const AFTER_ADD_STAY = 'stay';
    public const AFTER_ADD_CART = 'cart';

    public const DEFAULT_API_URL = 'https://api.askmerra.com';
    public const DEFAULT_WIDGET_URL = 'https://cdn.askmerra.com/v1/widget.js';

    /** AskMerra accepts at most 500 products per batchUpsert call. */
    public const MAX_BATCH_SIZE = 500;

    /** WooCommerce product types that can be sent. */
    public const PRODUCT_TYPES = ['simple', 'variable', 'grouped', 'external'];

    /** WooCommerce catalog visibility values ($product->get_catalog_visibility()). */
    public const VISIBILITIES = ['visible', 'catalog', 'search', 'hidden'];

    /** Product meta: 'yes' hides the product from AskMerra. */
    public const EXCLUDE_META = '_askmerra_exclude';

    // Connection
    public const OPTION_ENABLED = 'askmerra_enabled';
    public const OPTION_SECRET_KEY = 'askmerra_secret_key';
    public const OPTION_SITE_KEY = 'askmerra_site_key';
    public const OPTION_LOCALE = 'askmerra_locale';

    // Catalog
    public const OPTION_SYNC_METHOD = 'askmerra_sync_method';
    public const OPTION_IDENTIFIER = 'askmerra_product_identifier';
    public const OPTION_ATTRIBUTES = 'askmerra_attributes';
    public const OPTION_BRAND_SOURCE = 'askmerra_brand_source';
    public const OPTION_DESCRIPTION = 'askmerra_description_source';
    public const OPTION_VARIANT_OPTIONS = 'askmerra_include_variant_options';
    public const OPTION_IMAGE_COUNT = 'askmerra_image_count';
    public const OPTION_PRODUCT_TYPES = 'askmerra_product_types';
    public const OPTION_VISIBILITIES = 'askmerra_visibilities';
    public const OPTION_OUT_OF_STOCK = 'askmerra_include_out_of_stock';
    public const OPTION_STOCK_QTY = 'askmerra_include_stock_qty';
    public const OPTION_EXCLUDED_CATEGORIES = 'askmerra_excluded_categories';

    // Sync
    public const OPTION_BATCH_SIZE = 'askmerra_batch_size';
    public const OPTION_DAILY_TIME = 'askmerra_daily_time';
    public const OPTION_REBUILD = 'askmerra_full_rebuild';

    // Feed
    public const OPTION_FEED_FORMAT = 'askmerra_feed_format';
    public const OPTION_FEED_FREQUENCY = 'askmerra_feed_frequency';
    public const OPTION_FEED_TOKEN = 'askmerra_feed_token';

    // Widget
    public const OPTION_WIDGET_ENABLED = 'askmerra_widget_enabled';
    public const OPTION_WIDGET_URL = 'askmerra_widget_script_url';
    public const OPTION_WIDGET_POSITION = 'askmerra_widget_position';
    public const OPTION_WIDGET_OPEN = 'askmerra_widget_open_on_load';
    public const OPTION_WIDGET_CHECKOUT = 'askmerra_widget_show_on_checkout';
    public const OPTION_PRODUCT_CONTEXT = 'askmerra_widget_product_context';
    public const OPTION_ADD_TO_CART = 'askmerra_widget_add_to_cart';
    public const OPTION_AFTER_ADD = 'askmerra_widget_after_add';
    public const OPTION_TRACK_PURCHASES = 'askmerra_widget_track_purchases';
    public const OPTION_CONSENT = 'askmerra_widget_consent';

    // Advanced
    public const OPTION_API_URL = 'askmerra_api_url';
    public const OPTION_TIMEOUT = 'askmerra_timeout';
    public const OPTION_DEBUG = 'askmerra_debug';

    /**
     * Options whose change alters what is sent: saving them rebuilds the catalog (only products
     * whose content changed are sent). See docs/ARCHITECTURE.md, "askmerra_settings_saved".
     */
    public const CATALOG_OPTIONS = [
        self::OPTION_ENABLED, self::OPTION_SECRET_KEY, self::OPTION_LOCALE, self::OPTION_SYNC_METHOD,
        self::OPTION_IDENTIFIER, self::OPTION_ATTRIBUTES, self::OPTION_BRAND_SOURCE, self::OPTION_DESCRIPTION,
        self::OPTION_VARIANT_OPTIONS, self::OPTION_IMAGE_COUNT, self::OPTION_PRODUCT_TYPES, self::OPTION_VISIBILITIES,
        self::OPTION_OUT_OF_STOCK, self::OPTION_STOCK_QTY, self::OPTION_EXCLUDED_CATEGORIES, self::OPTION_API_URL,
    ];

    public function __construct(private readonly Crypto $crypto)
    {
    }

    // ---- Connection ----

    public function isEnabled(): bool
    {
        return $this->flag(self::OPTION_ENABLED, false);
    }

    public function getSecretKey(): string
    {
        return trim($this->crypto->decrypt((string) get_option(self::OPTION_SECRET_KEY, '')));
    }

    public function getSiteKey(): string
    {
        return trim((string) get_option(self::OPTION_SITE_KEY, ''));
    }

    /** The AskMerra locale chosen, or 'auto'. Storefront::locale() resolves 'auto'. */
    public function getLocaleSetting(): string
    {
        $value = (string) get_option(self::OPTION_LOCALE, 'auto');

        return in_array($value, self::LOCALES, true) ? $value : 'auto';
    }

    /** An AskMerra locale for a WordPress locale (ro_RO => ro), or null when AskMerra does not serve it. */
    public static function toAskMerraLocale(string $wpLocale): ?string
    {
        $code = strtolower(substr($wpLocale, 0, 2));

        return in_array($code, self::LOCALES, true) ? $code : null;
    }

    /** Whether a URL is on AskMerra's own hosts: askmerra.com and its subdomains. */
    public static function isAskMerraUrl(string $url): bool
    {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));

        return $host === 'askmerra.com' || str_ends_with($host, '.askmerra.com');
    }

    /**
     * Whether the current user may point the widget script or the API at a host other than
     * AskMerra's: that script runs on every storefront page and the API receives the secret key,
     * so it takes a user who may add unfiltered HTML anyway (administrators; super admins on a
     * network) - shop managers may not. ASKMERRA_ALLOW_CUSTOM_URLS (wp-config.php) allows it for
     * everyone, for development and staging with a mock.
     */
    public static function canUseCustomUrls(): bool
    {
        return (defined('ASKMERRA_ALLOW_CUSTOM_URLS') && ASKMERRA_ALLOW_CUSTOM_URLS) || current_user_can('unfiltered_html');
    }

    // ---- Catalog ----

    public function getSyncMethod(): string
    {
        return get_option(self::OPTION_SYNC_METHOD, self::SYNC_PUSH) === self::SYNC_FEED ? self::SYNC_FEED : self::SYNC_PUSH;
    }

    /** Products are pushed through the Push API (enabled, push, and a secret key). */
    public function isPushEnabled(): bool
    {
        return $this->isEnabled() && $this->getSyncMethod() === self::SYNC_PUSH && $this->getSecretKey() !== '';
    }

    /** Products are published as a feed AskMerra downloads. */
    public function isFeedEnabled(): bool
    {
        return $this->isEnabled() && $this->getSyncMethod() === self::SYNC_FEED;
    }

    public function isSyncEnabled(): bool
    {
        return $this->isPushEnabled() || $this->isFeedEnabled();
    }

    public function getProductIdentifier(): string
    {
        return get_option(self::OPTION_IDENTIFIER, self::IDENTIFIER_ID) === self::IDENTIFIER_SKU
            ? self::IDENTIFIER_SKU
            : self::IDENTIFIER_ID;
    }

    /**
     * Attributes to send: 'pa_color' (a global attribute, i.e. its taxonomy) or 'custom:material'
     * (a product-level attribute, by sanitize_title() of its name).
     *
     * @return string[]
     */
    public function getAttributeKeys(): array
    {
        return $this->list(self::OPTION_ATTRIBUTES, []);
    }

    /** 'taxonomy:product_brand', 'attribute:pa_brand'... or null for no brand. */
    public function getBrandSource(): ?string
    {
        $value = trim((string) get_option(self::OPTION_BRAND_SOURCE, ''));

        if ($value === '' && taxonomy_exists('product_brand')) {
            return 'taxonomy:product_brand';
        }

        return $value === '' || $value === 'none' ? null : $value;
    }

    public function getDescriptionSource(): string
    {
        $value = (string) get_option(self::OPTION_DESCRIPTION, self::DESCRIPTION_LONG);

        return in_array($value, [self::DESCRIPTION_SHORT, self::DESCRIPTION_BOTH], true) ? $value : self::DESCRIPTION_LONG;
    }

    public function includeVariantOptions(): bool
    {
        return $this->flag(self::OPTION_VARIANT_OPTIONS, true);
    }

    public function getImageCount(): int
    {
        return max(1, min(20, (int) get_option(self::OPTION_IMAGE_COUNT, 4) ?: 4));
    }

    /** @return string[] */
    public function getProductTypes(): array
    {
        $types = array_values(array_intersect($this->list(self::OPTION_PRODUCT_TYPES, self::PRODUCT_TYPES), self::PRODUCT_TYPES));

        return $types ?: self::PRODUCT_TYPES;
    }

    /** @return string[] catalog visibilities that are sent */
    public function getVisibilities(): array
    {
        $values = array_values(array_intersect($this->list(self::OPTION_VISIBILITIES, ['visible', 'catalog', 'search']), self::VISIBILITIES));

        return $values ?: ['visible', 'catalog', 'search'];
    }

    public function includeOutOfStock(): bool
    {
        return $this->flag(self::OPTION_OUT_OF_STOCK, true);
    }

    public function includeStockQty(): bool
    {
        return $this->flag(self::OPTION_STOCK_QTY, false);
    }

    /** @return int[] product_cat term ids; their children are excluded too */
    public function getExcludedCategoryIds(): array
    {
        return array_values(array_filter(array_map('intval', $this->list(self::OPTION_EXCLUDED_CATEGORIES, []))));
    }

    // ---- Sync ----

    public function getBatchSize(): int
    {
        return max(1, min(self::MAX_BATCH_SIZE, (int) get_option(self::OPTION_BATCH_SIZE, 200) ?: 200));
    }

    /** @return array{0: int, 1: int} hour and minute of the daily maintenance, site time zone */
    public function getDailyTime(): array
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', (string) get_option(self::OPTION_DAILY_TIME, '03:15'), $match)) {
            return [min(23, (int) $match[1]), min(59, (int) $match[2])];
        }

        return [3, 15];
    }

    public function getRebuildFrequency(): string
    {
        $value = (string) get_option(self::OPTION_REBUILD, 'weekly');

        return in_array($value, self::REBUILD_FREQUENCIES, true) ? $value : 'weekly';
    }

    // ---- Feed ----

    public function getFeedFormat(): string
    {
        return get_option(self::OPTION_FEED_FORMAT, self::FEED_JSON) === self::FEED_GOOGLE_XML ? self::FEED_GOOGLE_XML : self::FEED_JSON;
    }

    /** Minutes between feed runs (the file is written only when something changed). */
    public function getFeedFrequency(): int
    {
        $value = (int) get_option(self::OPTION_FEED_FREQUENCY, 60);

        return in_array($value, self::FEED_FREQUENCIES, true) ? $value : 60;
    }

    /** Secret part of the feed file names; Feed\FeedToken creates it when missing. */
    public function getFeedToken(): string
    {
        $token = (string) get_option(self::OPTION_FEED_TOKEN, '');

        return preg_match('/^[a-f0-9]{32}$/', $token) ? $token : '';
    }

    // ---- Widget ----

    public function isWidgetEnabled(): bool
    {
        return $this->isEnabled() && $this->flag(self::OPTION_WIDGET_ENABLED, true) && $this->getSiteKey() !== '';
    }

    public function getWidgetUrl(): string
    {
        $url = trim((string) get_option(self::OPTION_WIDGET_URL, ''));

        return $url !== '' ? $url : self::DEFAULT_WIDGET_URL;
    }

    public function getWidgetPosition(): ?string
    {
        $value = (string) get_option(self::OPTION_WIDGET_POSITION, '');

        return in_array($value, ['bottom-right', 'bottom-left'], true) ? $value : null;
    }

    public function isOpenOnLoad(): bool
    {
        return $this->flag(self::OPTION_WIDGET_OPEN, false);
    }

    public function isShownOnCheckout(): bool
    {
        return $this->flag(self::OPTION_WIDGET_CHECKOUT, false);
    }

    public function isProductContextEnabled(): bool
    {
        return $this->flag(self::OPTION_PRODUCT_CONTEXT, true);
    }

    public function isAddToCartEnabled(): bool
    {
        return $this->flag(self::OPTION_ADD_TO_CART, true);
    }

    public function getAfterAddToCart(): string
    {
        return get_option(self::OPTION_AFTER_ADD, self::AFTER_ADD_STAY) === self::AFTER_ADD_CART ? self::AFTER_ADD_CART : self::AFTER_ADD_STAY;
    }

    public function isPurchaseTrackingEnabled(): bool
    {
        return $this->flag(self::OPTION_TRACK_PURCHASES, true);
    }

    public function getConsentMode(): string
    {
        $value = (string) get_option(self::OPTION_CONSENT, self::CONSENT_AUTO);

        return in_array($value, [self::CONSENT_GRANTED, self::CONSENT_WP_CONSENT_API, self::CONSENT_MANUAL], true)
            ? $value
            : self::CONSENT_AUTO;
    }

    // ---- Advanced ----

    public function getApiUrl(): string
    {
        $url = trim((string) get_option(self::OPTION_API_URL, ''));

        return rtrim($url !== '' ? $url : self::DEFAULT_API_URL, '/');
    }

    public function getTimeout(): int
    {
        return max(5, (int) get_option(self::OPTION_TIMEOUT, 30) ?: 30);
    }

    public function isDebug(): bool
    {
        return $this->flag(self::OPTION_DEBUG, false);
    }

    private function flag(string $option, bool $default): bool
    {
        $value = get_option($option, $default ? 'yes' : 'no');

        return $value === 'yes' || $value === true || $value === '1' || $value === 1;
    }

    /** @return string[] an option saved as an array or a comma-separated string */
    private function list(string $option, array $default): array
    {
        $value = get_option($option, $default);

        if (is_string($value)) {
            $value = $value === '' ? [] : explode(',', $value);
        }

        return is_array($value) ? array_values(array_filter(array_map('strval', $value), static fn (string $v): bool => $v !== '')) : $default;
    }
}
