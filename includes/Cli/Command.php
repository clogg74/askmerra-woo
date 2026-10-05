<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Cli;

use AskMerra\WooCommerce\Api\ApiException;
use AskMerra\WooCommerce\Api\Client;
use AskMerra\WooCommerce\Catalog\ProductBuilder;
use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Feed\FeedGenerator;
use AskMerra\WooCommerce\Plugin;
use AskMerra\WooCommerce\Storefront;
use AskMerra\WooCommerce\StorefrontRepository;
use AskMerra\WooCommerce\Sync\Enqueuer;
use AskMerra\WooCommerce\Sync\Queue;
use AskMerra\WooCommerce\Sync\QueueProcessor;
use AskMerra\WooCommerce\Sync\Reconciler;
use AskMerra\WooCommerce\Sync\Remover;
use AskMerra\WooCommerce\Sync\Status;
use WP_CLI;

/**
 * Checks the AskMerra connection and runs the catalog sync from the command line.
 *
 * ## EXAMPLES
 *
 *     wp askmerra test
 *     wp askmerra status
 *     wp askmerra sync --rebuild
 *     wp askmerra payload woo-beanie
 */
final class Command
{
    /** Seconds per round of `sync`, so progress is shown regularly. */
    private const ROUND_SECONDS = 15;

    /**
     * Checks the AskMerra keys and the chat widget.
     *
     * ## OPTIONS
     *
     * [--storefront=<ids>]
     * : Storefront ids, comma separated (default: all, when AskMerra is enabled).
     *
     * ## EXAMPLES
     *
     *     wp askmerra test
     */
    public function test(array $args, array $assocArgs): void
    {
        $config = $this->config();
        $storefronts = $this->getStorefronts($assocArgs, $config->isEnabled() ? $this->storefronts()->all() : []);

        if (!$storefronts) {
            WP_CLI::log('AskMerra is not enabled (WooCommerce > Settings > AskMerra).');

            return;
        }

        $ok = true;

        foreach ($storefronts as $storefront) {
            WP_CLI::log(sprintf('%s: %s', $this->label($storefront), $config->getSyncMethod()));

            if ($config->getSecretKey() === '') {
                if ($config->getSyncMethod() === Config::SYNC_FEED) {
                    WP_CLI::log('  No secret key (not needed with a feed).');
                } else {
                    WP_CLI::warning('No secret key: nothing is sent.');
                    $ok = false;
                }
            } else {
                try {
                    $shop = $this->service(Client::class)->ping();
                    WP_CLI::log(sprintf('  Connected to the AskMerra shop "%s" (%s).', $shop['shopName'], $shop['shopId']));
                } catch (ApiException $e) {
                    WP_CLI::warning($e->getMessage());
                    $ok = false;
                }
            }

            WP_CLI::log($config->isWidgetEnabled()
                ? sprintf('  Widget on, site key %s..., language %s.', substr($config->getSiteKey(), 0, 12), $storefront->locale ?? 'shop default')
                : '  Widget off (or no site key).');
        }

        if (!$ok) {
            WP_CLI::halt(1);
        }
    }

    /**
     * Shows the AskMerra sync of every storefront, as the Sync status page does.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp askmerra status
     */
    public function status(array $args, array $assocArgs): void
    {
        $status = $this->service(Status::class);
        $format = (string) ($assocArgs['format'] ?? 'table');
        $rows = [];
        $feeds = [];

        foreach ($status->getStorefronts() as $row) {
            $rows[] = [
                'storefront' => sprintf('%s (%s)', $row['name'], $row['storefront']),
                'sync' => $row['mode'] ?? ($row['missing_key'] ? 'off: no secret key' : 'off'),
                'language' => $row['locale'] ?? '-',
                'in_askmerra' => $row['products'],
                'waiting' => $row['pending'],
                'failed' => $row['failed'],
                'last_sent_utc' => $row['last_synced_at'] ?? '-',
                'problem' => $row['problem']['message'] ?? '',
            ];

            if (!empty($row['feed'])) {
                $feed = $row['feed'];
                $feeds[] = sprintf(
                    'Feed %s: %s (%s)',
                    $row['storefront'],
                    $feed['url'],
                    $feed['exists']
                        ? sprintf('%d KB, written %s UTC', intdiv((int) $feed['bytes'], 1024), $feed['modified_at'])
                        : ($feed['ready'] ? 'not written yet' : 'being prepared')
                );
            }
        }

        \WP_CLI\Utils\format_items($format, $rows, ['storefront', 'sync', 'language', 'in_askmerra', 'waiting', 'failed', 'last_sent_utc', 'problem']);

        if ($format !== 'table') {
            return;
        }

        foreach ($feeds as $line) {
            WP_CLI::log($line);
        }

        foreach ($status->getWarnings() as $warning) {
            WP_CLI::warning($warning);
        }
    }

    /**
     * Sends changed products to AskMerra now, instead of waiting for the background job.
     *
     * With a feed, prepares the products for the next feed file.
     *
     * ## OPTIONS
     *
     * [--rebuild]
     * : Queue every product first; only those whose content changed are sent.
     *
     * [--check]
     * : Run the daily check first: missing products, products that left the catalog, sale dates.
     *
     * [--product=<ids>]
     * : Only these products: ids or SKUs, comma separated.
     *
     * [--retry-failed]
     * : Try the failed products again.
     *
     * [--time=<seconds>]
     * : Stop after this many seconds (0: when the queue is empty).
     * ---
     * default: 0
     * ---
     *
     * [--storefront=<ids>]
     * : Storefront ids, comma separated (default: every storefront that syncs).
     *
     * ## EXAMPLES
     *
     *     wp askmerra sync
     *     wp askmerra sync --rebuild
     *     wp askmerra sync --product=woo-beanie,15
     *     wp askmerra sync --retry-failed --time=300
     */
    public function sync(array $args, array $assocArgs): void
    {
        // Products are built as the background job builds them: for a guest.
        wp_set_current_user(0);
        $repository = $this->storefronts();
        $storefronts = $this->getStorefronts($assocArgs, $repository->syncing());

        if (!$storefronts) {
            WP_CLI::error('AskMerra does not sync. Enable it and enter the keys under WooCommerce > Settings > AskMerra.');
        }

        foreach ($storefronts as $storefront) {
            if (!isset($repository->syncing()[$storefront->id])) {
                WP_CLI::error(sprintf('%s does not sync with AskMerra: check its settings and secret key.', $this->label($storefront)));
            }
        }

        $this->prepare($assocArgs, $storefronts);

        $queue = $this->service(Queue::class);
        $processor = $this->service(QueueProcessor::class);
        $limit = max(0, (int) ($assocArgs['time'] ?? 0));
        $deadline = $limit > 0 ? microtime(true) + $limit : null;
        $totals = [];

        while ($deadline === null || microtime(true) < $deadline) {
            $seconds = $deadline === null ? self::ROUND_SECONDS : (int) max(1, min(self::ROUND_SECONDS, $deadline - microtime(true)));
            $stats = $processor->run($seconds, array_keys($storefronts));

            if ($stats === null) {
                WP_CLI::log('The background job is sending the queue right now; waiting for it...');
                sleep(5);
                continue;
            }

            // Nothing was due (postponed products wait for their time).
            if (array_sum(array_map(static fn (array $counts): int => (int) array_sum($counts), $stats)) === 0) {
                break;
            }

            foreach ($stats as $storefrontId => $counts) {
                foreach ($counts as $key => $count) {
                    $totals[$storefrontId][$key] = ($totals[$storefrontId][$key] ?? 0) + $count;
                }

                WP_CLI::log(sprintf(
                    '%s: sent %d, unchanged %d, removed %d, failed %d - %d waiting',
                    $this->label($storefronts[$storefrontId] ?? $repository->get((string) $storefrontId)),
                    $counts['sent'] ?? 0,
                    $counts['unchanged'] ?? 0,
                    $counts['removed'] ?? 0,
                    $counts['failed'] ?? 0,
                    $queue->countPending((string) $storefrontId)
                ));
            }
        }

        WP_CLI::log('');

        foreach ($storefronts as $storefront) {
            $done = ($totals[$storefront->id] ?? []) + ['sent' => 0, 'unchanged' => 0, 'removed' => 0, 'failed' => 0];

            WP_CLI::success(sprintf(
                '%s: %d sent, %d unchanged, %d removed, %d failed. %d still waiting, %d failed for good.',
                $this->label($storefront),
                $done['sent'],
                $done['unchanged'],
                $done['removed'],
                $done['failed'],
                $queue->countPending($storefront->id),
                count($queue->getFailedProductIds($storefront->id))
            ));

            if (isset($repository->feeding()[$storefront->id])) {
                WP_CLI::log('  Feed: run `wp askmerra feed` to write the file now, or wait for the background job.');
            }
        }
    }

    /**
     * Writes the AskMerra feed files of the storefronts that send a feed.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Write them even when no product changed.
     *
     * [--storefront=<ids>]
     * : Storefront ids, comma separated (default: every storefront that sends a feed).
     *
     * ## EXAMPLES
     *
     *     wp askmerra feed
     *     wp askmerra feed --force
     */
    public function feed(array $args, array $assocArgs): void
    {
        wp_set_current_user(0);
        $repository = $this->storefronts();
        $storefronts = array_filter(
            $this->getStorefronts($assocArgs, $repository->feeding()),
            static fn (Storefront $storefront): bool => isset($repository->feeding()[$storefront->id])
        );

        if (!$storefronts) {
            WP_CLI::log('No storefront sends its catalog as a feed ("How AskMerra gets the catalog": Product feed).');

            return;
        }

        $generator = $this->service(FeedGenerator::class);
        $failed = false;

        foreach ($storefronts as $storefront) {
            try {
                $result = $generator->generate($storefront, (bool) \WP_CLI\Utils\get_flag_value($assocArgs, 'force', false));
            } catch (\Throwable $e) {
                WP_CLI::warning(sprintf('%s: %s', $this->label($storefront), $e->getMessage()));
                $failed = true;
                continue;
            }

            $line = match ($result['status'] ?? '') {
                'written' => sprintf('written, %d products, %d KB', $result['products'] ?? 0, intdiv((int) ($result['bytes'] ?? 0), 1024)),
                'unchanged' => 'unchanged since it was last written (--force writes it anyway)',
                'building' => trim(($result['message'] ?? '') . ' Run `wp askmerra sync` to prepare it now.'),
                default => trim(($result['status'] ?? '') . ' ' . ($result['message'] ?? '')),
            };

            $failed = $failed || ($result['status'] ?? '') === 'failed';
            WP_CLI::log(sprintf('%s: %s', $this->label($storefront), $line));
            WP_CLI::log('  ' . $generator->getUrl($storefront));
        }

        $removed = $generator->removeUnusedFiles();

        if ($removed > 0) {
            WP_CLI::log(sprintf('%d feed files no storefront publishes anymore were removed.', $removed));
        }

        if ($failed) {
            WP_CLI::halt(1);
        }
    }

    /**
     * Shows a product exactly as AskMerra receives it (sends nothing).
     *
     * ## OPTIONS
     *
     * <product>
     * : Product id or SKU. A variation shows its variable product.
     *
     * [--storefront=<id>]
     * : Storefront id (default: the first storefront that syncs).
     *
     * ## EXAMPLES
     *
     *     wp askmerra payload 15
     *     wp askmerra payload woo-beanie
     */
    public function payload(array $args, array $assocArgs): void
    {
        wp_set_current_user(0);
        $value = trim((string) ($args[0] ?? ''));
        $productId = $this->productIdFor($value);

        if ($productId <= 0) {
            WP_CLI::error(sprintf('No product with the id or SKU "%s".', $value));
        }

        // Variations are sent as part of their product.
        if (get_post_type($productId) === 'product_variation') {
            $parentId = (int) wp_get_post_parent_id($productId);
            WP_CLI::log(sprintf('Product %d is a variation of product %d, which AskMerra receives:', $productId, $parentId));
            $productId = $parentId;
        }

        $repository = $this->storefronts();
        $storefronts = $this->getStorefronts($assocArgs, $repository->syncing());
        $storefront = reset($storefronts) ?: $repository->getDefault();
        $built = $this->service(ProductBuilder::class)->build($storefront, [$productId]);

        if (isset($built['payloads'][$productId])) {
            WP_CLI::line((string) wp_json_encode($built['payloads'][$productId], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        WP_CLI::warning(sprintf(
            'Not sent in %s: %s',
            $this->label($storefront),
            $built['ineligible'][$productId] ?? $built['errors'][$productId] ?? 'unknown reason'
        ));
    }

    /**
     * Removes every product of a storefront from AskMerra.
     *
     * E.g. before deleting the plugin. Turn AskMerra off first (WooCommerce > Settings > AskMerra),
     * or the products are sent again.
     *
     * ## OPTIONS
     *
     * [--storefront=<ids>]
     * : Storefront ids, comma separated (default: all).
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * ## EXAMPLES
     *
     *     wp askmerra remove
     */
    public function remove(array $args, array $assocArgs): void
    {
        $repository = $this->storefronts();
        $storefronts = $this->getStorefronts($assocArgs, $repository->all());

        foreach ($storefronts as $storefront) {
            if (isset($repository->syncing()[$storefront->id])) {
                WP_CLI::error(sprintf(
                    '%s still syncs: turn AskMerra off first (WooCommerce > Settings > AskMerra), or its products are sent again at the next daily check.',
                    $this->label($storefront)
                ));
            }
        }

        WP_CLI::confirm(
            sprintf('Remove every product of %s from AskMerra?', implode(', ', array_map(fn (Storefront $storefront): string => $this->label($storefront), $storefronts))),
            $assocArgs
        );

        $remover = $this->service(Remover::class);

        foreach ($storefronts as $storefront) {
            try {
                WP_CLI::log(sprintf('%s: %d products removed.', $this->label($storefront), $remover->removeAll($storefront)));
            } catch (ApiException $e) {
                WP_CLI::error(sprintf('%s: %s', $this->label($storefront), $e->getMessage()));
            }
        }

        WP_CLI::success("Done. A feed source must also be deleted in the AskMerra dashboard: AskMerra keeps a feed's products when the file disappears.");
    }

    /** Products of --product (ids or SKUs) queued, then the daily check or the rebuild when asked. */
    private function prepare(array $assocArgs, array $storefronts): void
    {
        $storefrontIds = array_keys($storefronts);

        if (\WP_CLI\Utils\get_flag_value($assocArgs, 'retry-failed', false)) {
            $queue = $this->service(Queue::class);

            foreach ($storefronts as $storefront) {
                WP_CLI::log(sprintf('%s: %d failed products tried again.', $this->label($storefront), $queue->retryFailed($storefront->id)));
            }
        }

        $products = trim((string) ($assocArgs['product'] ?? ''));

        if ($products !== '') {
            $ids = [];

            foreach (array_filter(array_map('trim', explode(',', $products))) as $value) {
                $id = $this->productIdFor($value);

                if ($id > 0) {
                    $ids[] = $id;
                } else {
                    WP_CLI::warning(sprintf('No product with the SKU "%s".', $value));
                }
            }

            $enqueuer = $this->service(Enqueuer::class);
            $enqueuer->enqueue($ids, $storefrontIds);
            $enqueuer->flush();
            WP_CLI::log(sprintf('%d products queued, with their variable or grouped products.', count($ids)));
        }

        $reconciler = $this->service(Reconciler::class);

        foreach ($storefronts as $storefront) {
            if (\WP_CLI\Utils\get_flag_value($assocArgs, 'rebuild', false)) {
                WP_CLI::log(sprintf('%s: %d products queued for the rebuild.', $this->label($storefront), $reconciler->rebuild($storefront)['queued']));
            } elseif (\WP_CLI\Utils\get_flag_value($assocArgs, 'check', false)) {
                $stats = $reconciler->reconcile($storefront);
                WP_CLI::log(sprintf(
                    '%s: %d missing, %d left the catalog, %d sale dates.',
                    $this->label($storefront),
                    $stats['missing'],
                    $stats['gone'],
                    $stats['price_dates']
                ));
            }
        }
    }

    /**
     * The storefronts of --storefront (ids, comma separated), or the default list.
     *
     * @param Storefront[] $default keyed by id
     * @return Storefront[] keyed by id
     */
    private function getStorefronts(array $assocArgs, array $default): array
    {
        $option = trim((string) ($assocArgs['storefront'] ?? ''));

        if ($option === '') {
            return $default;
        }

        $repository = $this->storefronts();
        $storefronts = [];

        foreach (array_filter(array_map('trim', explode(',', $option))) as $id) {
            $storefront = $repository->get($id);

            if ($storefront === null) {
                WP_CLI::error(sprintf('No storefront "%s". Storefronts: %s.', $id, implode(', ', array_keys($repository->all()))));
            }

            $storefronts[$storefront->id] = $storefront;
        }

        return $storefronts;
    }

    private function label(?Storefront $storefront): string
    {
        return $storefront !== null ? sprintf('%s (%s)', $storefront->name, $storefront->id) : '?';
    }

    private function config(): Config
    {
        return $this->service(Config::class);
    }

    private function storefronts(): StorefrontRepository
    {
        return $this->service(StorefrontRepository::class);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class): object
    {
        return Plugin::instance()->get($class);
    }

    /** A product id for an id, an AskMerra id of a product without a SKU ("id:<n>") or a SKU; 0 when none. */
    private function productIdFor(string $value): int
    {
        if (preg_match('/^(?:id:)?(\d+)$/i', $value, $match)) {
            return (int) $match[1];
        }

        return (int) wc_get_product_id_by_sku($value);
    }
}
