<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Sync;

use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Feed\FeedFlags;
use AskMerra\WooCommerce\Feed\FeedGenerator;
use AskMerra\WooCommerce\Storefront;

/**
 * Remembers what each storefront's catalog was built for and notices - however the settings were
 * changed, also while the storefront was turned off - when that changed:
 *
 * - The sync method: the whole catalog is sent again the new way. A feed needs every product's
 *   entry, and pushed products must not count on what an old feed delivered. A storefront that
 *   switched to a feed does not publish it before it is complete; one that switched to the Push API
 *   stops publishing its file at once (AskMerra would keep importing it over the pushed catalog).
 * - Where a storefront pushes to (API URL and secret key, e.g. a test key replaced by the live
 *   one): the whole catalog is sent again, as the other AskMerra shop has none of it.
 * - Shop settings every product shows (language, currency, prices, links...): the catalog is
 *   rebuilt, and only the products whose content changed are sent.
 *
 * A change keeps asking for a rebuild until Reconciler::rebuild() has queued the catalog
 * (rebuilt()), so a run that dies in between leaves it to the next check.
 *
 * Option askmerra_sync_methods, read fresh: storefront id => {method, destination (a keyed hash,
 * never the key), context (a hash), rebuild (pending: 'catalog', or 'feed' to reset the feed's
 * "ready" after it)}.
 */
final class MethodTracker
{
    private const OPTION = 'askmerra_sync_methods';

    private const REBUILD_CATALOG = 'catalog';
    private const REBUILD_FEED = 'feed';

    public function __construct(
        private readonly Config $config,
        private readonly State $state,
        private readonly FeedFlags $feedFlags,
        private readonly FeedGenerator $feedGenerator
    ) {
    }

    /**
     * The first check only records what the catalog is built for, and so does the first check of a
     * destination or context recorded by no earlier one: a storefront that starts syncing is rebuilt
     * by the settings change (Jobs::onSettingsSaved) or, when the options were set without the
     * settings page (WP-CLI), by the schedule change it causes (Jobs::onRescheduled).
     *
     * @return bool whether the storefront's catalog must be rebuilt: it changed since its catalog
     *              was last checked, or a rebuild it asked for was not queued yet
     */
    public function check(Storefront $storefront): bool
    {
        $records = $this->read();
        $previous = $this->getRecord($records, $storefront->id);
        $method = $this->config->getSyncMethod();
        $current = [
            'method' => $method,
            // Kept while the storefront does not push: a key entered later is compared with the last one.
            'destination' => $this->getDestination($method) ?? $previous['destination'] ?? null,
            'context' => $this->getContext($storefront),
            'rebuild' => $previous['rebuild'] ?? null,
        ];

        if ($previous === null) {
            $this->save($records, $storefront->id, $current);

            return false;
        }

        $isFeed = $method === Config::SYNC_FEED;
        $switched = $previous['method'] !== $method;
        $moved = !$switched && !$isFeed
            && $previous['destination'] !== null && $previous['destination'] !== $current['destination'];
        $changed = $previous['context'] !== null && $previous['context'] !== $current['context'];

        if ($switched || $moved) {
            // Every product is sent again; the rows stay, they say what to remove.
            $this->state->invalidateStorefront($storefront->id, $isFeed);
        }

        if ($switched) {
            if (!$isFeed) {
                $this->feedGenerator->deleteFiles($storefront);
            }

            $current['rebuild'] = $isFeed ? self::REBUILD_FEED : self::REBUILD_CATALOG;
        } elseif ($moved || $changed) {
            $current['rebuild'] ??= self::REBUILD_CATALOG;
        }

        if ($current !== $previous) {
            $this->save($records, $storefront->id, $current);
        }

        return $current['rebuild'] !== null;
    }

    /**
     * Reconciler::rebuild() queued the whole catalog: the rebuild a change asked for is done. A
     * storefront that switched to a feed waits for that catalog: "ready" is reset now, after the
     * queue has it, as a queue run that emptied the queue just before may have set it.
     */
    public function rebuilt(Storefront $storefront): void
    {
        $records = $this->read();
        $record = $this->getRecord($records, $storefront->id);

        if ($record === null || $record['rebuild'] === null) {
            return;
        }

        if ($record['rebuild'] === self::REBUILD_FEED) {
            $this->feedFlags->resetReady($storefront->id);
        }

        $record['rebuild'] = null;
        $this->save($records, $storefront->id, $record);
    }

    /**
     * Where a storefront pushes to: a keyed hash of the API URL and the secret key. Null for a feed,
     * or without a key.
     */
    private function getDestination(string $method): ?string
    {
        $secretKey = $this->config->getSecretKey();

        if ($method !== Config::SYNC_PUSH || $secretKey === '') {
            return null;
        }

        return hash_hmac('sha256', $this->config->getApiUrl() . '|' . $secretKey, wp_salt('auth'));
    }

    /**
     * The shop settings every product is built with: language, currency, prices (taxes, the guest's
     * tax location, decimals), links (site address, permalinks), the out-of-stock variations hidden
     * and the default category left out of category paths. The site address is the setting:
     * home_url() takes the scheme of the request, which differs between admin and cron on some sites.
     */
    private function getContext(Storefront $storefront): string
    {
        $permalinks = get_option('woocommerce_permalinks');

        return sha1((string) wp_json_encode([
            $storefront->locale,
            $storefront->currency,
            get_option('home'),
            get_option('permalink_structure'),
            is_array($permalinks) ? ($permalinks['product_base'] ?? '') : '',
            get_option('woocommerce_calc_taxes'),
            get_option('woocommerce_prices_include_tax'),
            get_option('woocommerce_tax_display_shop'),
            get_option('woocommerce_tax_based_on'),
            get_option('woocommerce_default_customer_address'),
            get_option('woocommerce_default_country'),
            get_option('woocommerce_price_num_decimals'),
            get_option('woocommerce_hide_out_of_stock_items'),
            get_option('default_product_cat'),
        ]));
    }

    /** @return array{method: string, destination: ?string, context: ?string, rebuild: ?string}|null */
    private function getRecord(array $records, string $storefrontId): ?array
    {
        $record = $records[$storefrontId] ?? null;

        if ($record === null) {
            return null;
        }

        // Earlier releases recorded the method alone.
        $record = is_array($record) ? $record : ['method' => $record];

        return [
            'method' => (string) ($record['method'] ?? ''),
            'destination' => isset($record['destination']) ? (string) $record['destination'] : null,
            'context' => isset($record['context']) ? (string) $record['context'] : null,
            'rebuild' => isset($record['rebuild']) ? (string) $record['rebuild'] : null,
        ];
    }

    private function read(): array
    {
        $records = Db::freshOption(self::OPTION, []);

        return is_array($records) ? $records : [];
    }

    private function save(array $records, string $storefrontId, array $record): void
    {
        $records[$storefrontId] = $record;
        update_option(self::OPTION, $records, false);
    }
}
