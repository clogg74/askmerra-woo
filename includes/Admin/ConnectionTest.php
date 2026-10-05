<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Admin;

use AskMerra\WooCommerce\Api\ApiException;
use AskMerra\WooCommerce\Api\Client;
use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Feed\FeedGenerator;
use AskMerra\WooCommerce\Plugin;
use AskMerra\WooCommerce\StorefrontRepository;
use AskMerra\WooCommerce\Sync\Problems;

/**
 * "Check connection" in the settings: checks the secret key against AskMerra and the site key's
 * format, with the values typed in the form (the saved ones where a field is left empty).
 */
final class ConnectionTest
{
    public const ACTION = 'askmerra_test_connection';

    public function __construct(
        private readonly Plugin $plugin,
        private readonly Config $config,
        private readonly StorefrontRepository $storefronts
    ) {
    }

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        check_ajax_referer(self::ACTION, 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json(['messages' => [$this->message('error', __('You are not allowed to change the AskMerra settings.', 'askmerra-for-woocommerce'))]], 403);
        }

        wp_send_json(['messages' => $this->check()]);
    }

    /** @return array<int, array{type: string, text: string}> */
    private function check(): array
    {
        // The secret key field is always empty unless a new key is typed; the others show their value.
        $secretKey = $this->typed('secret_key') ?: null;
        $siteKey = $this->typed('site_key') ?? $this->config->getSiteKey();
        $apiUrl = $this->typed('api_url') ?: null;
        $syncMethod = $this->config->getSyncMethod();
        $messages = [];

        if ($apiUrl !== null && !preg_match('#^https?://#i', $apiUrl)) {
            return [$this->message('error', __('The API URL must start with https://.', 'askmerra-for-woocommerce'))];
        }

        // The check sends the secret key to this URL: another host than AskMerra's (or the saved
        // one) takes a user who may set it (Config::canUseCustomUrls()).
        if ($apiUrl !== null && !Config::isAskMerraUrl($apiUrl) && !Config::canUseCustomUrls() && untrailingslashit($apiUrl) !== $this->config->getApiUrl()) {
            return [$this->message('error', __('Only an administrator can check the connection with an API URL outside askmerra.com.', 'askmerra-for-woocommerce'))];
        }

        $effectiveSecretKey = $secretKey ?? $this->config->getSecretKey();

        if ($effectiveSecretKey === '') {
            $messages[] = $syncMethod === Config::SYNC_FEED
                ? $this->message('info', __('No secret key: not needed with a product feed - AskMerra downloads the feed URL shown under Sync & feed.', 'askmerra-for-woocommerce'))
                : $this->message('error', __('Enter the secret key (sk_live_...) from your AskMerra dashboard, API keys.', 'askmerra-for-woocommerce'));
        } elseif (str_starts_with($effectiveSecretKey, 'pk_')) {
            $messages[] = $this->message('error', __('The secret key field holds the site key (pk_...). The secret key starts with sk_live_.', 'askmerra-for-woocommerce'));
        } else {
            $messages[] = $this->ping($effectiveSecretKey, $apiUrl, $secretKey === null);
        }

        if ($siteKey === '') {
            $messages[] = $this->message('warning', __('No site key: the chat widget will not show on the storefront.', 'askmerra-for-woocommerce'));
        } elseif (str_starts_with($siteKey, 'sk_')) {
            $messages[] = $this->message('error', __('The site key field holds the secret key - remove it from there at once: the site key is public. The site key starts with pk_live_.', 'askmerra-for-woocommerce'));
        } elseif (!str_starts_with($siteKey, 'pk_')) {
            $messages[] = $this->message('warning', __('The site key usually starts with pk_live_. Check that it was copied whole.', 'askmerra-for-woocommerce'));
        } else {
            $messages[] = $this->message('success', sprintf(
                /* translators: %s: the shop's domain, e.g. shop.example.com */
                __('Site key set. In the AskMerra dashboard, allow the domain %s for the widget (Install, Allowed domains), or the chat stays hidden.', 'askmerra-for-woocommerce'),
                (string) wp_parse_url(home_url(), PHP_URL_HOST)
            ));
        }

        if ($syncMethod === Config::SYNC_FEED) {
            foreach ($this->storefronts->feeding() as $storefront) {
                $messages[] = $this->message('info', sprintf(
                    /* translators: %s: the feed URL */
                    __('Feed URL for AskMerra (Catalog, Add source, Feed URL): %s', 'askmerra-for-woocommerce'),
                    $this->plugin->get(FeedGenerator::class)->getUrl($storefront)
                ));
            }
        }

        return $messages;
    }

    /** @return array{type: string, text: string} */
    private function ping(string $secretKey, ?string $apiUrl, bool $isSaved): array
    {
        try {
            $shop = $this->plugin->get(Client::class)->ping($secretKey, $apiUrl);
        } catch (ApiException $e) {
            if ($e->isAuthError()) {
                return $this->message('error', sprintf(
                    /* translators: %s: AskMerra's error code */
                    __('AskMerra refused the secret key (%s). Check that it is copied whole, not revoked, and that the AskMerra shop is active.', 'askmerra-for-woocommerce'),
                    $e->getErrorCode() !== '' ? $e->getErrorCode() : (string) $e->getHttpStatus()
                ));
            }

            // The message says what failed: not reached, or AskMerra's error, with the request id.
            return $this->message('error', $e->getMessage());
        }

        // The saved key works again: forget the "key refused" problem of the storefronts using it.
        if ($isSaved && $apiUrl === null) {
            $problems = $this->plugin->get(Problems::class);

            foreach (array_keys($this->storefronts->all()) as $storefrontId) {
                $problems->clear((string) $storefrontId);
            }
        }

        /* translators: %s: the AskMerra shop name */
        $text = sprintf(__('Connected to the AskMerra shop "%s".', 'askmerra-for-woocommerce'), $shop['shopName'] !== '' ? $shop['shopName'] : $shop['shopId']);

        if (!$isSaved) {
            $text .= ' ' . __('Save the settings to start using this key.', 'askmerra-for-woocommerce');
        }

        return $this->message('success', $text);
    }

    /** A value typed in the form; null when the field is not on the page that asked. */
    private function typed(string $name): ?string
    {
        // check_ajax_referer() ran in handle().
        if (!isset($_POST[$name]) || !is_string($_POST[$name])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return null;
        }

        return trim(sanitize_text_field(wp_unslash($_POST[$name]))); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }

    /** @return array{type: string, text: string} */
    private function message(string $type, string $text): array
    {
        return ['type' => $type, 'text' => $text];
    }
}
