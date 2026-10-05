<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Admin;

use AskMerra\WooCommerce\Catalog\AttributeValues;
use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Crypto;
use AskMerra\WooCommerce\Log\Logger;
use AskMerra\WooCommerce\Plugin;
use AskMerra\WooCommerce\StorefrontRepository;

/**
 * WooCommerce > Settings > AskMerra: every setting of Config, in five sections. WooCommerce creates
 * it when it loads its settings pages (filter woocommerce_get_settings_pages).
 *
 * Saving fires askmerra_settings_saved with the options whose value changed; the Sync module then
 * rebuilds the catalog if what is sent changed (docs/ARCHITECTURE.md).
 */
final class SettingsPage extends \WC_Settings_Page
{
    public const SECTION_CATALOG = 'catalog';
    public const SECTION_SYNC = 'sync';
    public const SECTION_WIDGET = 'widget';
    public const SECTION_ADVANCED = 'advanced';

    /** Field types that show something instead of storing a value. */
    private const DISPLAY_TYPES = [
        'title',
        'sectionend',
        SettingsFields::TYPE_CONNECTION_TEST,
        SettingsFields::TYPE_SYNC_STATUS,
        SettingsFields::TYPE_FEED_URLS,
    ];

    public function __construct(
        private readonly Plugin $plugin,
        private readonly Config $config,
        private readonly Crypto $crypto
    ) {
        $this->id = AdminUrls::SETTINGS_TAB;
        $this->label = __('AskMerra', 'askmerra-for-woocommerce');

        parent::__construct();
    }

    /** Saves the section, then tells the other modules which options changed. */
    public function save()
    {
        global $current_section;

        $storefronts = $this->plugin->get(StorefrontRepository::class);
        $fields = $this->get_settings_for_section((string) $current_section);
        $before = $this->currentValues($fields);
        $wasSyncing = (bool) $storefronts->syncing();

        parent::save();

        $changed = [];

        foreach ($this->currentValues($fields) as $option => $value) {
            if ($value !== $before[$option]) {
                $changed[] = $option;
            }
        }

        if (!$changed) {
            return;
        }

        $storefronts->reset();
        do_action('askmerra_settings_saved', $changed);

        if (!$storefronts->syncing() || !array_intersect($changed, Config::CATALOG_OPTIONS)) {
            return;
        }

        $message = $wasSyncing
            ? __('AskMerra: the catalog is being rebuilt; only products whose content changed are sent. Follow it under WooCommerce > AskMerra.', 'askmerra-for-woocommerce')
            : __('AskMerra: the first sync of the catalog starts in the background. Follow it under WooCommerce > AskMerra.', 'askmerra-for-woocommerce');

        // After WooCommerce's "Your settings have been saved."
        add_action('woocommerce_settings_saved', static function () use ($message): void {
            \WC_Admin_Settings::add_message($message);
        });
    }

    protected function get_own_sections()
    {
        return [
            '' => __('Connection', 'askmerra-for-woocommerce'),
            self::SECTION_CATALOG => __('Catalog', 'askmerra-for-woocommerce'),
            self::SECTION_SYNC => __('Sync & feed', 'askmerra-for-woocommerce'),
            self::SECTION_WIDGET => __('Storefront widget', 'askmerra-for-woocommerce'),
            self::SECTION_ADVANCED => __('Advanced', 'askmerra-for-woocommerce'),
        ];
    }

    protected function get_settings_for_default_section(): array
    {
        return [
            [
                'title' => __('Connection', 'askmerra-for-woocommerce'),
                'type' => 'title',
                'id' => 'askmerra_connection',
                'desc' => sprintf(
                    /* translators: %s: the store currency code, e.g. EUR */
                    __('Create the keys in your AskMerra dashboard, under <strong>API keys</strong>. One AskMerra shop has one currency: connect this store to an AskMerra shop in %s.', 'askmerra-for-woocommerce'),
                    esc_html(get_woocommerce_currency())
                ),
            ],
            [
                'title' => __('Enable AskMerra', 'askmerra-for-woocommerce'),
                'desc' => __('Send the catalog to AskMerra and show the chat on the storefront', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_ENABLED,
                'type' => 'checkbox',
                'default' => 'no',
            ],
            [
                'title' => __('Secret key', 'askmerra-for-woocommerce'),
                'desc' => __('Starts with <code>sk_live_</code>. Used only by the server to send your catalog; it is stored encrypted and never reaches the storefront.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_SECRET_KEY,
                'type' => SettingsFields::TYPE_SECRET,
                'autoload' => false,
            ],
            [
                'title' => __('Site key', 'askmerra-for-woocommerce'),
                'desc' => __('Starts with <code>pk_live_</code>. The public key of the chat widget. Add this store\'s domain to the allowed domains of your AskMerra shop.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_SITE_KEY,
                'type' => 'text',
                'default' => '',
                'placeholder' => 'pk_live_…',
                'css' => 'min-width: 360px;',
                'custom_attributes' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
            ],
            [
                'title' => __('Language', 'askmerra-for-woocommerce'),
                'desc' => __('The language the products are sent in and the assistant answers in. Automatic uses the site language (Settings > General) when AskMerra supports it.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_LOCALE,
                'type' => 'select',
                'class' => 'wc-enhanced-select',
                'default' => 'auto',
                'options' => $this->localeOptions(),
            ],
            [
                'title' => __('Check connection', 'askmerra-for-woocommerce'),
                'id' => 'askmerra_connection_test',
                'type' => SettingsFields::TYPE_CONNECTION_TEST,
                'is_option' => false,
            ],
            ['type' => 'sectionend', 'id' => 'askmerra_connection'],
        ];
    }

    protected function get_settings_for_catalog_section(): array
    {
        [$attributes, $brands, $problem] = $this->catalogChoices();

        return [
            [
                'title' => __('Catalog', 'askmerra-for-woocommerce'),
                'type' => 'title',
                'id' => 'askmerra_catalog',
                'desc' => __('What AskMerra learns about your products. Saving changes here sends the whole catalog again (still only the products whose content changed). <strong>Note:</strong> AskMerra re-reads a product with AI (billed as usage) when its name, description, categories, brand or attributes change - pick attributes that describe the product, not ones that change every day.', 'askmerra-for-woocommerce')
                    . ($problem !== null ? '<br><strong>' . esc_html($problem) . '</strong>' : ''),
            ],
            [
                'title' => __('Product identifier', 'askmerra-for-woocommerce'),
                'desc' => __('How products are matched between WooCommerce and AskMerra. Product ID never changes; choose SKU only if your other AskMerra data already uses SKUs (a product without a SKU is sent as id: followed by its ID). Changing it later replaces the whole catalog in AskMerra.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_IDENTIFIER,
                'type' => 'select',
                'default' => Config::IDENTIFIER_ID,
                'options' => [
                    Config::IDENTIFIER_ID => __('Product ID (recommended)', 'askmerra-for-woocommerce'),
                    Config::IDENTIFIER_SKU => __('SKU', 'askmerra-for-woocommerce'),
                ],
            ],
            [
                'title' => __('Product attributes to include', 'askmerra-for-woocommerce'),
                'desc' => __('The details the assistant can use to answer and filter: e.g. skin type, ingredients, colour, size, material. Sent with their labels. Avoid stock, prices and dates - they are sent separately.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_ATTRIBUTES,
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'css' => 'min-width: 400px;',
                'default' => [],
                'options' => $attributes,
                'custom_attributes' => ['data-placeholder' => __('No attributes', 'askmerra-for-woocommerce')],
            ],
            [
                'title' => __('Brand', 'askmerra-for-woocommerce'),
                'desc' => __('Sent as the product\'s brand: WooCommerce Brands, a brand plugin\'s brands or a product attribute.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_BRAND_SOURCE,
                'type' => 'select',
                'class' => 'wc-enhanced-select',
                'default' => $this->config->getBrandSource() ?? 'none',
                'options' => ['none' => __('-- No brand --', 'askmerra-for-woocommerce')] + $brands,
            ],
            [
                'title' => __('Description', 'askmerra-for-woocommerce'),
                'desc' => __('Sent as plain text (HTML, shortcodes and page builder markup are removed), up to 20,000 characters.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_DESCRIPTION,
                'type' => 'select',
                'default' => Config::DESCRIPTION_LONG,
                'options' => [
                    Config::DESCRIPTION_LONG => __('Description', 'askmerra-for-woocommerce'),
                    Config::DESCRIPTION_SHORT => __('Short description', 'askmerra-for-woocommerce'),
                    Config::DESCRIPTION_BOTH => __('Short description, then description', 'askmerra-for-woocommerce'),
                ],
            ],
            [
                'title' => __('Include variant options', 'askmerra-for-woocommerce'),
                'desc' => __('Send the options of variable products', 'askmerra-for-woocommerce'),
                'desc_tip' => __('The sizes, colours... a shopper can choose are sent as attributes of the product. The product itself is sent once, not once per variation.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_VARIANT_OPTIONS,
                'type' => 'checkbox',
                'default' => 'yes',
            ],
            [
                'title' => __('Images per product', 'askmerra-for-woocommerce'),
                'desc' => __('The main image comes first, then the gallery; the chat shows the first one on the product card.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_IMAGE_COUNT,
                'type' => 'select',
                'default' => '4',
                'options' => array_combine(['1', '2', '3', '4', '6', '8', '10'], ['1', '2', '3', '4', '6', '8', '10']),
            ],
            [
                'title' => __('Product types', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_PRODUCT_TYPES,
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'css' => 'min-width: 400px;',
                'default' => Config::PRODUCT_TYPES,
                'options' => array_intersect_key(wc_get_product_types(), array_flip(Config::PRODUCT_TYPES)),
            ],
            [
                'title' => __('Catalog visibility', 'askmerra-for-woocommerce'),
                'desc' => __('Products with the catalog visibility chosen here are sent.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_VISIBILITIES,
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'css' => 'min-width: 400px;',
                'default' => ['visible', 'catalog', 'search'],
                'options' => array_intersect_key(wc_get_product_visibility_options(), array_flip(Config::VISIBILITIES)),
            ],
            [
                'title' => __('Include out-of-stock products', 'askmerra-for-woocommerce'),
                'desc' => __('Send products that are out of stock', 'askmerra-for-woocommerce'),
                'desc_tip' => __('Recommended: the assistant then knows the product and can say it is out of stock - it never recommends it. Unchecked, products leave AskMerra while they are out of stock.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_OUT_OF_STOCK,
                'type' => 'checkbox',
                'default' => 'yes',
            ],
            [
                'title' => __('Send stock quantity', 'askmerra-for-woocommerce'),
                'desc' => __('Send the quantity in stock', 'askmerra-for-woocommerce'),
                'desc_tip' => __('For products that manage their own stock.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_STOCK_QTY,
                'type' => 'checkbox',
                'default' => 'no',
            ],
            [
                'title' => __('Exclude categories', 'askmerra-for-woocommerce'),
                'desc' => __('Their subcategories are excluded too. To leave out single products, check <strong>Hide from AskMerra</strong> on the product (Product data, General).', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_EXCLUDED_CATEGORIES,
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'css' => 'min-width: 400px;',
                'default' => [],
                'options' => $this->categoryOptions(),
                'custom_attributes' => ['data-placeholder' => __('No categories', 'askmerra-for-woocommerce')],
            ],
            ['type' => 'sectionend', 'id' => 'askmerra_catalog'],
        ];
    }

    protected function get_settings_for_sync_section(): array
    {
        return [
            [
                'title' => __('Sync', 'askmerra-for-woocommerce'),
                'type' => 'title',
                'id' => 'askmerra_sync',
                'desc' => __('Only what changed is rebuilt and sent: product, price and stock changes - also those made by imports - are picked up within about a minute by WooCommerce\'s background jobs (Action Scheduler). Every day the plugin also adds products that are missing in AskMerra and removes the ones that left the catalog. Large catalogs are fine: 50,000 products are not sent again unless they change.', 'askmerra-for-woocommerce'),
            ],
            [
                'title' => __('How AskMerra gets the catalog', 'askmerra-for-woocommerce'),
                'desc' => __('<strong>Push API</strong> sends changes within about a minute and removes deleted products. <strong>Product feed</strong> publishes a file that AskMerra downloads every 1 to 6 hours (depending on your plan) - add its URL in the AskMerra dashboard, under Catalog > Add source > Feed URL. Changing it sends the whole catalog again the new way.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_SYNC_METHOD,
                'type' => 'select',
                'default' => Config::SYNC_PUSH,
                'options' => [
                    Config::SYNC_PUSH => __('Push API - changes within a minute (recommended)', 'askmerra-for-woocommerce'),
                    Config::SYNC_FEED => __('Product feed - AskMerra downloads a file', 'askmerra-for-woocommerce'),
                ],
            ],
            [
                'title' => __('Products per request', 'askmerra-for-woocommerce'),
                'desc' => __('Push API, 1 to 500. Lower it if products have very long descriptions.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_BATCH_SIZE,
                'type' => 'number',
                'default' => '200',
                'css' => 'width: 90px;',
                'custom_attributes' => ['min' => '1', 'max' => (string) Config::MAX_BATCH_SIZE, 'step' => '1'],
            ],
            [
                'title' => __('Daily maintenance at', 'askmerra-for-woocommerce'),
                'desc' => sprintf(
                    /* translators: %s: the site time zone, e.g. Europe/Bucharest */
                    __('Site time (%s). Adds missing products, removes products that left the catalog, picks up sale prices that start or end today.', 'askmerra-for-woocommerce'),
                    esc_html(wp_timezone_string())
                ),
                'id' => Config::OPTION_DAILY_TIME,
                'type' => 'time',
                'default' => '03:15',
                'css' => 'width: 110px;',
            ],
            [
                'title' => __('Full rebuild', 'askmerra-for-woocommerce'),
                'desc' => __('A safety net: every product is rebuilt and compared - still only changed ones are sent. Runs at the maintenance time.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_REBUILD,
                'type' => 'select',
                'default' => 'weekly',
                'options' => [
                    'weekly' => __('Every week, on Sunday (recommended)', 'askmerra-for-woocommerce'),
                    'daily' => __('Every day', 'askmerra-for-woocommerce'),
                    'monthly' => __('Every month, on the 1st', 'askmerra-for-woocommerce'),
                    'never' => __('Only when started by hand', 'askmerra-for-woocommerce'),
                ],
            ],
            [
                'title' => __('Sync status', 'askmerra-for-woocommerce'),
                'id' => 'askmerra_sync_status',
                'type' => SettingsFields::TYPE_SYNC_STATUS,
                'is_option' => false,
            ],
            ['type' => 'sectionend', 'id' => 'askmerra_sync'],
            [
                'title' => __('Product feed', 'askmerra-for-woocommerce'),
                'type' => 'title',
                'id' => SettingsFields::FEED_SECTION_ID,
                'desc' => __('Used when AskMerra gets the catalog as a product feed.', 'askmerra-for-woocommerce'),
            ],
            [
                'title' => __('Format', 'askmerra-for-woocommerce'),
                'desc' => __('<strong>JSON</strong> carries every selected attribute. <strong>Google Shopping XML</strong> can also be used for Google Merchant Center, but only keeps standard attributes (colour, size, material, gender, GTIN, EAN, skin type, ingredients, volume, weight...).', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_FEED_FORMAT,
                'type' => 'select',
                'default' => Config::FEED_JSON,
                'options' => [
                    Config::FEED_JSON => __('JSON - all attributes (recommended)', 'askmerra-for-woocommerce'),
                    Config::FEED_GOOGLE_XML => __('Google Shopping XML', 'askmerra-for-woocommerce'),
                ],
            ],
            [
                'title' => __('Regenerate the feed', 'askmerra-for-woocommerce'),
                'desc' => __('Only changed products are rebuilt; the file itself is rewritten from them in seconds, and only when something changed. Keep it at least as often as AskMerra downloads it (every 1 to 6 hours, depending on the plan).', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_FEED_FREQUENCY,
                'type' => 'select',
                'default' => '60',
                'options' => [
                    '60' => __('Every hour', 'askmerra-for-woocommerce'),
                    '180' => __('Every 3 hours', 'askmerra-for-woocommerce'),
                    '360' => __('Every 6 hours', 'askmerra-for-woocommerce'),
                    '480' => __('Every 8 hours', 'askmerra-for-woocommerce'),
                    '720' => __('Every 12 hours', 'askmerra-for-woocommerce'),
                    '1440' => __('Once a day', 'askmerra-for-woocommerce'),
                ],
            ],
            [
                'title' => __('Feed URL', 'askmerra-for-woocommerce'),
                'id' => 'askmerra_feed_urls',
                'type' => SettingsFields::TYPE_FEED_URLS,
                'is_option' => false,
            ],
            ['type' => 'sectionend', 'id' => SettingsFields::FEED_SECTION_ID],
        ];
    }

    protected function get_settings_for_widget_section(): array
    {
        return [
            [
                'title' => __('Storefront widget', 'askmerra-for-woocommerce'),
                'type' => 'title',
                'id' => 'askmerra_widget',
                'desc' => __('Look, greeting, colours and where the chat shows are set in the AskMerra widget designer. The script is added to the <code>&lt;head&gt;</code> of every page, with classic and block themes alike.', 'askmerra-for-woocommerce'),
            ],
            [
                'title' => __('Show the chat widget', 'askmerra-for-woocommerce'),
                'desc' => __('Show the AskMerra chat on the storefront', 'askmerra-for-woocommerce'),
                'desc_tip' => __('Needs the site key (Connection).', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_WIDGET_ENABLED,
                'type' => 'checkbox',
                'default' => 'yes',
            ],
            [
                'title' => __('Widget script URL', 'askmerra-for-woocommerce'),
                'desc' => sprintf(
                    /* translators: %s: the default widget script URL */
                    __('The <code>src</code> of the snippet in the AskMerra dashboard (Install); the snippet\'s other value is the site key. Empty: %s', 'askmerra-for-woocommerce'),
                    '<code>' . esc_html(Config::DEFAULT_WIDGET_URL) . '</code>'
                ),
                'id' => Config::OPTION_WIDGET_URL,
                'type' => 'url',
                'default' => '',
                'placeholder' => Config::DEFAULT_WIDGET_URL,
                'css' => 'min-width: 400px;',
            ],
            [
                'title' => __('Position', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_WIDGET_POSITION,
                'type' => 'select',
                'default' => '',
                'options' => [
                    '' => __('As set in the AskMerra designer', 'askmerra-for-woocommerce'),
                    'bottom-right' => __('Bottom right', 'askmerra-for-woocommerce'),
                    'bottom-left' => __('Bottom left', 'askmerra-for-woocommerce'),
                ],
            ],
            [
                'title' => __('Open on page load', 'askmerra-for-woocommerce'),
                'desc' => __('Open the chat when a page loads', 'askmerra-for-woocommerce'),
                'desc_tip' => __('Desktop only.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_WIDGET_OPEN,
                'type' => 'checkbox',
                'default' => 'no',
            ],
            [
                'title' => __('Show on the checkout page', 'askmerra-for-woocommerce'),
                'desc' => __('Show the chat while the shopper pays', 'askmerra-for-woocommerce'),
                'desc_tip' => __('Off by default so shoppers are not distracted while paying. The order received page always loads the widget when orders are reported.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_WIDGET_CHECKOUT,
                'type' => 'checkbox',
                'default' => 'no',
            ],
            [
                'title' => __('Know the product being viewed', 'askmerra-for-woocommerce'),
                'desc' => __('Tell the assistant which product page is open', 'askmerra-for-woocommerce'),
                'desc_tip' => __('On product pages the assistant knows which product the shopper is looking at ("Is this good for dry skin?").', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_PRODUCT_CONTEXT,
                'type' => 'checkbox',
                'default' => 'yes',
            ],
            [
                'title' => __('Add to cart from the chat', 'askmerra-for-woocommerce'),
                'desc' => __('Let shoppers add products to the cart from the chat', 'askmerra-for-woocommerce'),
                'desc_tip' => __('Simple products go straight into the WooCommerce cart and the mini cart refreshes; products with options (sizes, colours...) open their page. Also turn on "Show add to cart" in the AskMerra widget designer.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_ADD_TO_CART,
                'type' => 'checkbox',
                'default' => 'yes',
            ],
            [
                'title' => __('After adding to cart', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_AFTER_ADD,
                'type' => 'select',
                'default' => Config::AFTER_ADD_STAY,
                'options' => [
                    Config::AFTER_ADD_STAY => __('Stay on the page (mini cart refreshes)', 'askmerra-for-woocommerce'),
                    Config::AFTER_ADD_CART => __('Go to the cart page', 'askmerra-for-woocommerce'),
                ],
            ],
            [
                'title' => __('Report orders to AskMerra', 'askmerra-for-woocommerce'),
                'desc' => __('Report orders on the order received page', 'askmerra-for-woocommerce'),
                'desc_tip' => __('So AskMerra can show the sales the assistant helped with. No name, e-mail or address is sent - only the order number, total and products.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_TRACK_PURCHASES,
                'type' => 'checkbox',
                'default' => 'yes',
            ],
            [
                'title' => __('Analytics consent', 'askmerra-for-woocommerce'),
                'desc' => __('Orders are reported only with the shopper\'s analytics consent. <strong>Automatic</strong>: read from Google Consent Mode / Google Tag Manager. <strong>WP Consent API</strong>: from a consent plugin that supports it (Complianz, CookieYes, Cookiebot...). <strong>Not needed</strong>: no consent banner is required on this site. <strong>Manual</strong>: your cookie banner calls <code>AskMerra.setConsent({analytics: true})</code>.', 'askmerra-for-woocommerce')
                    . $this->consentApiWarning(),
                'id' => Config::OPTION_CONSENT,
                'type' => 'select',
                'default' => Config::CONSENT_AUTO,
                'options' => [
                    Config::CONSENT_AUTO => __('Automatic (Google Consent Mode / Tag Manager)', 'askmerra-for-woocommerce'),
                    Config::CONSENT_WP_CONSENT_API => __('WP Consent API (consent plugin)', 'askmerra-for-woocommerce'),
                    Config::CONSENT_GRANTED => __('Not needed on this site', 'askmerra-for-woocommerce'),
                    Config::CONSENT_MANUAL => __('Manual (set by my cookie banner)', 'askmerra-for-woocommerce'),
                ],
            ],
            ['type' => 'sectionend', 'id' => 'askmerra_widget'],
        ];
    }

    protected function get_settings_for_advanced_section(): array
    {
        return [
            [
                'title' => __('Advanced', 'askmerra-for-woocommerce'),
                'type' => 'title',
                'id' => 'askmerra_advanced',
            ],
            [
                'title' => __('API URL', 'askmerra-for-woocommerce'),
                'desc' => __('Change only for an AskMerra test environment.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_API_URL,
                'type' => 'url',
                'default' => '',
                'placeholder' => Config::DEFAULT_API_URL,
                'css' => 'min-width: 400px;',
            ],
            [
                'title' => __('Request timeout (seconds)', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_TIMEOUT,
                'type' => 'number',
                'default' => '30',
                'css' => 'width: 90px;',
                'custom_attributes' => ['min' => '5', 'max' => '600', 'step' => '1'],
            ],
            [
                'title' => __('Debug log', 'askmerra-for-woocommerce'),
                'desc' => __('Log every request and response', 'askmerra-for-woocommerce'),
                'desc_tip' => __('Written to WooCommerce > Status > Logs, source "askmerra". Errors are always logged.', 'askmerra-for-woocommerce'),
                'id' => Config::OPTION_DEBUG,
                'type' => 'checkbox',
                'default' => 'no',
            ],
            ['type' => 'sectionend', 'id' => 'askmerra_advanced'],
        ];
    }

    /**
     * The value of every option among the fields, as compared before and after saving: the secret
     * key decrypted (saving the same key again is no change), lists in a fixed order.
     *
     * @return array<string, string|string[]>
     */
    private function currentValues(array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            if (!isset($field['id'], $field['type'])
                || in_array($field['type'], self::DISPLAY_TYPES, true)
                || ($field['is_option'] ?? true) === false
            ) {
                continue;
            }

            $value = get_option($field['id'], $field['default'] ?? '');

            if ($field['id'] === Config::OPTION_SECRET_KEY) {
                $value = $this->crypto->decrypt((string) $value);
            } elseif (is_array($value)) {
                $value = array_map('strval', $value);
                sort($value);
            } else {
                $value = (string) $value;
            }

            $values[$field['id']] = $value;
        }

        return $values;
    }

    /**
     * Without the WP Consent API plugin, the "WP Consent API" mode never grants consent: no order is
     * reported. Shown while that mode is chosen (admin.js follows the select).
     */
    private function consentApiWarning(): string
    {
        if (function_exists('wp_has_consent')) {
            return '';
        }

        return sprintf(
            '<br><strong id="askmerra-consent-api-missing" class="askmerra-warning"%s>%s</strong>',
            $this->config->getConsentMode() === Config::CONSENT_WP_CONSENT_API ? '' : ' style="display: none;"',
            esc_html__('The WP Consent API plugin is not active: with this choice no order is ever reported. Activate WP Consent API (and a consent plugin that supports it), or choose another option.', 'askmerra-for-woocommerce')
        );
    }

    /** @return array<string, string> */
    private function localeOptions(): array
    {
        $names = [
            'en' => __('English', 'askmerra-for-woocommerce'),
            'ro' => __('Romanian', 'askmerra-for-woocommerce'),
            'it' => __('Italian', 'askmerra-for-woocommerce'),
            'fr' => __('French', 'askmerra-for-woocommerce'),
            'de' => __('German', 'askmerra-for-woocommerce'),
            'es' => __('Spanish', 'askmerra-for-woocommerce'),
        ];
        $site = Config::toAskMerraLocale((string) get_locale());
        $options = [
            'auto' => $site !== null
                /* translators: %s: a language name, e.g. Romanian */
                ? sprintf(__('Automatic (from the site language: %s)', 'askmerra-for-woocommerce'), $names[$site] ?? $site)
                : __('Automatic - AskMerra does not serve the site language: choose one', 'askmerra-for-woocommerce'),
        ];

        foreach (Config::LOCALES as $code) {
            $options[$code] = $names[$code] ?? $code;
        }

        return $options;
    }

    /**
     * The attributes and brand sources to choose from (Catalog module), and why they are missing
     * if they could not be read.
     *
     * @return array{0: array<string, string>, 1: array<string, string>, 2: ?string}
     */
    private function catalogChoices(): array
    {
        try {
            $values = $this->plugin->get(AttributeValues::class);

            return [$values->getAvailableAttributes(), $values->getBrandSources(), null];
        } catch (\Throwable $e) {
            $this->plugin->get(Logger::class)->error('AskMerra settings: the product attributes could not be read.', ['exception' => $e]);

            return [[], [], __('The product attributes could not be read; see WooCommerce > Status > Logs (askmerra).', 'askmerra-for-woocommerce')];
        }
    }

    /** @return array<string, string> product_cat term id => "Parent > Child" */
    private function categoryOptions(): array
    {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);

        if (!is_array($terms)) {
            return [];
        }

        $byId = [];

        foreach ($terms as $term) {
            $byId[$term->term_id] = $term;
        }

        $options = [];

        foreach ($byId as $id => $term) {
            $names = [];

            // The depth limit guards against a loop in damaged term data.
            for ($current = $term, $depth = 0; $current !== null && $depth < 20; $current = $byId[$current->parent] ?? null, $depth++) {
                array_unshift($names, html_entity_decode($current->name, ENT_QUOTES, 'UTF-8'));
            }

            $options[(string) $id] = implode(' > ', $names);
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }
}
