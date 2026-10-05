<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Admin;

use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Crypto;
use AskMerra\WooCommerce\Feed\FeedFlags;
use AskMerra\WooCommerce\Feed\FeedGenerator;
use AskMerra\WooCommerce\Plugin;
use AskMerra\WooCommerce\Storefront;
use AskMerra\WooCommerce\StorefrontRepository;
use AskMerra\WooCommerce\Sync\Queue;
use AskMerra\WooCommerce\Sync\State;

/**
 * The settings rows WooCommerce has no field type for (secret key, connection check, sync summary,
 * feed URLs), and the checks every AskMerra option goes through when it is saved.
 */
final class SettingsFields
{
    public const TYPE_SECRET = 'askmerra_secret';
    public const TYPE_CONNECTION_TEST = 'askmerra_connection_test';
    public const TYPE_SYNC_STATUS = 'askmerra_sync_status';
    public const TYPE_FEED_URLS = 'askmerra_feed_urls';

    /** Id of the "Product feed" settings group; its rows only show while the feed is chosen. */
    public const FEED_SECTION_ID = 'askmerra_feed';

    /** Checkbox next to the secret key: delete the saved key. */
    public const REMOVE_SECRET_FIELD = 'askmerra_secret_key_remove';

    /** Secret keys are sk_live_ (sk_test_ for test shops) and letters and digits. */
    private const SECRET_KEY_PATTERN = '/^sk_[A-Za-z0-9_]+$/';

    public function __construct(
        private readonly Plugin $plugin,
        private readonly Config $config,
        private readonly Crypto $crypto,
        private readonly StorefrontRepository $storefronts
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_admin_field_' . self::TYPE_SECRET, [$this, 'renderSecretKey']);
        add_action('woocommerce_admin_field_' . self::TYPE_CONNECTION_TEST, [$this, 'renderConnectionTest']);
        add_action('woocommerce_admin_field_' . self::TYPE_SYNC_STATUS, [$this, 'renderSyncStatus']);
        add_action('woocommerce_admin_field_' . self::TYPE_FEED_URLS, [$this, 'renderFeedUrls']);

        $sanitizers = [
            Config::OPTION_SECRET_KEY => 'sanitizeSecretKey',
            Config::OPTION_SITE_KEY => 'sanitizeSiteKey',
            Config::OPTION_ATTRIBUTES => 'sanitizeList',
            Config::OPTION_PRODUCT_TYPES => 'sanitizeRequiredList',
            Config::OPTION_VISIBILITIES => 'sanitizeRequiredList',
            Config::OPTION_EXCLUDED_CATEGORIES => 'sanitizeCategoryIds',
            Config::OPTION_BATCH_SIZE => 'sanitizeBatchSize',
            Config::OPTION_DAILY_TIME => 'sanitizeDailyTime',
            Config::OPTION_TIMEOUT => 'sanitizeTimeout',
            Config::OPTION_WIDGET_URL => 'sanitizeUrl',
            Config::OPTION_API_URL => 'sanitizeUrl',
        ];

        foreach ($sanitizers as $option => $method) {
            add_filter('woocommerce_admin_settings_sanitize_option_' . $option, [$this, $method], 10, 3);
        }
    }

    /** A password field that is always empty: the saved key never goes back to the browser. */
    public function renderSecretKey(array $field): void
    {
        $stored = (string) get_option(Config::OPTION_SECRET_KEY, '');
        $plain = $this->crypto->decrypt($stored);

        if ($stored === '') {
            $placeholder = 'sk_live_…';
        } elseif ($plain === '') {
            $placeholder = __('A key is saved but can no longer be read (the site security keys changed): enter it again', 'askmerra-for-woocommerce');
        } else {
            /* translators: %s: the start of the saved key, e.g. sk_live_ */
            $placeholder = sprintf(__('Saved (%s…) - type a new key to replace it', 'askmerra-for-woocommerce'), $this->keyType($plain));
        }

        ?>
        <tr class="<?php echo esc_attr((string) ($field['row_class'] ?? '')); ?>">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field['id']); ?>"><?php echo esc_html($field['title']); ?></label>
            </th>
            <td class="forminp forminp-text">
                <input
                    type="password"
                    name="<?php echo esc_attr($field['field_name'] ?? $field['id']); ?>"
                    id="<?php echo esc_attr($field['id']); ?>"
                    value=""
                    autocomplete="new-password"
                    spellcheck="false"
                    placeholder="<?php echo esc_attr($placeholder); ?>"
                    style="min-width: 360px;"
                />
                <p class="description"><?php echo wp_kses_post($field['desc'] ?? ''); ?></p>
                <?php if ($stored !== '') : ?>
                    <p>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr(self::REMOVE_SECRET_FIELD); ?>" value="1" />
                            <?php esc_html_e('Remove the saved key', 'askmerra-for-woocommerce'); ?>
                        </label>
                    </p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /** "Check connection": tests the keys as typed, before saving (ConnectionTest answers). */
    public function renderConnectionTest(array $field): void
    {
        ?>
        <tr>
            <th scope="row" class="titledesc"><?php echo esc_html($field['title']); ?></th>
            <td class="forminp">
                <button type="button" class="button" id="askmerra-test-connection"><?php esc_html_e('Check connection', 'askmerra-for-woocommerce'); ?></button>
                <div id="askmerra-test-connection-result" class="askmerra-test-result" aria-live="polite"></div>
                <p class="description"><?php esc_html_e('Checks the keys as typed, before saving.', 'askmerra-for-woocommerce'); ?></p>
            </td>
        </tr>
        <?php
    }

    /** A one-line summary of the sync, with the way to the status page. */
    public function renderSyncStatus(array $field): void
    {
        $syncing = $this->storefronts->syncing();

        if (!$syncing) {
            $summary = __('Nothing is sent yet: enable AskMerra (Connection) and enter the secret key, or choose the product feed.', 'askmerra-for-woocommerce');
        } else {
            try {
                $state = $this->plugin->get(State::class);
                $queue = $this->plugin->get(Queue::class);
                $products = $pending = $failed = 0;

                foreach ($syncing as $storefront) {
                    $products += $state->count($storefront->id);
                    $pending += $queue->countPending($storefront->id);
                    $failed += count($queue->getFailedProductIds($storefront->id));
                }

                /* translators: 1: products in AskMerra, 2: products waiting, 3: products that failed */
                $summary = sprintf(__('%1$d products sent, %2$d waiting, %3$d failed.', 'askmerra-for-woocommerce'), $products, $pending, $failed);
            } catch (\Throwable) {
                $summary = __('The sync status could not be read.', 'askmerra-for-woocommerce');
            }
        }

        ?>
        <tr>
            <th scope="row" class="titledesc"><?php echo esc_html($field['title']); ?></th>
            <td class="forminp">
                <p><?php echo esc_html($summary); ?></p>
                <p><a href="<?php echo esc_url(AdminUrls::status()); ?>"><?php esc_html_e('Open the sync status: errors, full rebuild, product preview', 'askmerra-for-woocommerce'); ?></a></p>
            </td>
        </tr>
        <?php
    }

    /** The feed URL of each storefront sending its catalog as a feed, ready to copy into AskMerra. */
    public function renderFeedUrls(array $field): void
    {
        $feeding = $this->storefronts->feeding();

        ?>
        <tr id="askmerra_feed_urls">
            <th scope="row" class="titledesc"><?php echo esc_html($field['title']); ?></th>
            <td class="forminp">
                <?php if (!$feeding) : ?>
                    <p><?php esc_html_e('The catalog is not sent as a feed yet. Choose "Product feed" above and save: the feed URL appears here.', 'askmerra-for-woocommerce'); ?></p>
                <?php else : ?>
                    <?php foreach ($feeding as $storefront) : ?>
                        <?php $feed = $this->describeFeed($storefront); ?>
                        <div class="askmerra-feed-url">
                            <?php if (count($feeding) > 1) : ?>
                                <strong><?php echo esc_html($storefront->name); ?></strong><br>
                            <?php endif; ?>
                            <input type="text" readonly="readonly" class="large-text code" id="<?php echo esc_attr('askmerra-feed-url-' . $storefront->id); ?>" value="<?php echo esc_attr($feed['url']); ?>" />
                            <button type="button" class="button" data-askmerra-copy="<?php echo esc_attr('askmerra-feed-url-' . $storefront->id); ?>"><?php esc_html_e('Copy', 'askmerra-for-woocommerce'); ?></button>
                            <p class="description"><?php echo esc_html($feed['status']); ?></p>
                        </div>
                    <?php endforeach; ?>
                    <p class="description">
                        <?php esc_html_e('In the AskMerra dashboard: Catalog, Add source, Feed URL. The URL holds a secret part - share it only with AskMerra.', 'askmerra-for-woocommerce'); ?>
                        <a href="<?php echo esc_url(AdminUrls::status()); ?>"><?php esc_html_e('Write it now or change the secret part on the sync status page.', 'askmerra-for-woocommerce'); ?></a>
                    </p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Encrypts a newly typed secret key. An empty field keeps the saved key (WooCommerce skips null),
     * so does the same key typed again; "Remove the saved key" deletes it.
     */
    public function sanitizeSecretKey(mixed $value, array $option, mixed $raw): ?string
    {
        $typed = is_string($raw) ? trim($raw) : '';

        if ($typed === '') {
            // WooCommerce verified the settings nonce before saving.
            return empty($_POST[self::REMOVE_SECRET_FIELD]) ? null : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        if (str_starts_with($typed, 'pk_')) {
            \WC_Admin_Settings::add_error(__('The secret key field held the site key (pk_...): it was not saved. The secret key starts with sk_live_.', 'askmerra-for-woocommerce'));

            return null;
        }

        if (!preg_match(self::SECRET_KEY_PATTERN, $typed)) {
            \WC_Admin_Settings::add_error(__('The secret key was not saved: it starts with sk_live_ and has only letters and digits. Copy it again from the AskMerra dashboard (API keys).', 'askmerra-for-woocommerce'));

            return null;
        }

        if ($typed === $this->config->getSecretKey()) {
            return null;
        }

        return $this->crypto->encrypt($typed);
    }

    /** The site key is public: a secret key typed there is refused, never saved. */
    public function sanitizeSiteKey(mixed $value, array $option, mixed $raw): ?string
    {
        $typed = is_string($raw) ? trim($raw) : '';

        if (str_starts_with($typed, 'sk_')) {
            \WC_Admin_Settings::add_error(__('The site key field held the secret key: it was not saved there. The site key is public and starts with pk_live_; keep the secret key only in the "Secret key" field.', 'askmerra-for-woocommerce'));

            return null;
        }

        return sanitize_text_field($typed);
    }

    /** @return string[] */
    public function sanitizeList(mixed $value): array
    {
        return array_values(array_unique(array_map('strval', (array) $value)));
    }

    /** A list that may not be empty: without a choice, the previous one stays. */
    public function sanitizeRequiredList(mixed $value, array $option): ?array
    {
        $list = array_values(array_intersect($this->sanitizeList($value), array_map('strval', array_keys($option['options'] ?? []))));

        if (!$list) {
            /* translators: %s: a settings field label, e.g. Product types */
            \WC_Admin_Settings::add_error(sprintf(__('%s: choose at least one. The previous choice was kept.', 'askmerra-for-woocommerce'), $option['title'] ?? ''));

            return null;
        }

        return $list;
    }

    /** @return string[] term ids as strings, as the multi-select compares them */
    public function sanitizeCategoryIds(mixed $value): array
    {
        return array_values(array_unique(array_map('strval', array_filter(array_map('absint', (array) $value)))));
    }

    public function sanitizeBatchSize(mixed $value, array $option, mixed $raw): ?string
    {
        return $this->sanitizeInteger($raw, $option, 1, Config::MAX_BATCH_SIZE);
    }

    public function sanitizeTimeout(mixed $value, array $option, mixed $raw): ?string
    {
        return $this->sanitizeInteger($raw, $option, 5, 600);
    }

    /** HH:MM, 24 hours. */
    public function sanitizeDailyTime(mixed $value, array $option, mixed $raw): ?string
    {
        $time = is_string($raw) ? trim($raw) : '';

        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $match) && (int) $match[1] <= 23 && (int) $match[2] <= 59) {
            return sprintf('%02d:%02d', (int) $match[1], (int) $match[2]);
        }

        /* translators: %s: a settings field label */
        \WC_Admin_Settings::add_error(sprintf(__('%s: enter a time such as 03:15. The previous value was kept.', 'askmerra-for-woocommerce'), $option['title'] ?? ''));

        return null;
    }

    /** An https:// address (http:// only for a local development host); empty means the default. */
    public function sanitizeUrl(mixed $value, array $option, mixed $raw): ?string
    {
        $url = is_string($raw) ? trim($raw) : '';

        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '' || ($scheme !== 'https' && !($scheme === 'http' && $this->isDevelopmentHost($host)))) {
            /* translators: %s: a settings field label */
            \WC_Admin_Settings::add_error(sprintf(__('%s: enter a full address starting with https://. The previous value was kept.', 'askmerra-for-woocommerce'), $option['title'] ?? ''));

            return null;
        }

        $url = esc_url_raw($url, ['http', 'https']);

        // Another host than AskMerra's takes a user who may add unfiltered HTML (Config::canUseCustomUrls());
        // a value an administrator saved stays when someone else saves the page.
        if (!Config::isAskMerraUrl($url) && !Config::canUseCustomUrls() && $url !== (string) get_option((string) ($option['id'] ?? ''), '')) {
            \WC_Admin_Settings::add_error(sprintf(
                /* translators: %s: a settings field label */
                __('%s: only an administrator can use an address outside askmerra.com. The previous value was kept.', 'askmerra-for-woocommerce'),
                $option['title'] ?? ''
            ));

            return null;
        }

        return $url;
    }

    /** @return array{url: string, status: string} */
    private function describeFeed(Storefront $storefront): array
    {
        try {
            $info = $this->plugin->get(FeedGenerator::class)->getInfo($storefront);
        } catch (\Throwable) {
            return ['url' => '', 'status' => __('The feed could not be read.', 'askmerra-for-woocommerce')];
        }

        if ($info['exists']) {
            $status = sprintf(
                /* translators: 1: date and time, 2: size in KB */
                __('Published %1$s (%2$s KB).', 'askmerra-for-woocommerce'),
                Format::utc($info['modified_at']),
                number_format_i18n($info['bytes'] / 1024)
            );
        } elseif (!$this->plugin->get(FeedFlags::class)->isReady($storefront->id)
            && ($pending = $this->plugin->get(Queue::class)->countPending($storefront->id)) > 0
        ) {
            $status = sprintf(
                /* translators: %d: number of products */
                _n('Being prepared: %d product to go. The URL works once the whole catalog is ready.', 'Being prepared: %d products to go. The URL works once the whole catalog is ready.', $pending, 'askmerra-for-woocommerce'),
                $pending
            );
        } else {
            $status = __('Not written yet: it is written at the next scheduled run.', 'askmerra-for-woocommerce');
        }

        return ['url' => $info['url'], 'status' => $status];
    }

    private function sanitizeInteger(mixed $raw, array $option, int $min, int $max): ?string
    {
        $text = is_scalar($raw) ? trim((string) $raw) : '';

        if (preg_match('/^\d+$/', $text) && (int) $text >= $min && (int) $text <= $max) {
            return (string) (int) $text;
        }

        \WC_Admin_Settings::add_error(sprintf(
            /* translators: 1: a settings field label, 2: lowest value, 3: highest value */
            __('%1$s: enter a whole number from %2$d to %3$d. The previous value was kept.', 'askmerra-for-woocommerce'),
            $option['title'] ?? '',
            $min,
            $max
        ));

        return null;
    }

    /** Hosts that may use plain http://: local development and test environments. */
    private function isDevelopmentHost(string $host): bool
    {
        return in_array(wp_get_environment_type(), ['local', 'development'], true)
            || in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.localhost');
    }

    /** The non-secret start of a key: sk_live_ or sk_test_. */
    private function keyType(string $key): string
    {
        return preg_match('/^([a-z]{2}_[a-z]+_)/', $key, $match) ? $match[1] : substr($key, 0, 3);
    }
}
