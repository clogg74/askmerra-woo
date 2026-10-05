<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Sync;

use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Feed\FeedFlags;
use AskMerra\WooCommerce\Feed\FeedGenerator;
use AskMerra\WooCommerce\Log\Logger;
use AskMerra\WooCommerce\StorefrontRepository;

/**
 * What the background jobs (Scheduler) and the settings changes do. A job never throws: Action
 * Scheduler stops repeating a job that keeps failing.
 */
final class Jobs
{
    /**
     * Seconds a background queue run takes at most: Action Scheduler runs other plugins' jobs
     * (e-mails, webhooks) in the same batch. Filter: askmerra_queue_run_seconds.
     */
    public const QUEUE_SECONDS = 25;

    private const OPTION_LAST_MODIFIED_CHECK = 'askmerra_last_modified_check';

    /** Products and variations edited this long before the last check are looked at again (clock skew, slow saves). */
    private const MODIFIED_OVERLAP_SECONDS = 120;

    /** @var array<string, true> storefronts rebuilt while the current settings change is handled */
    private array $rebuilt = [];

    public function __construct(
        private readonly Config $config,
        private readonly StorefrontRepository $storefronts,
        private readonly QueueProcessor $queueProcessor,
        private readonly Queue $queue,
        private readonly Reconciler $reconciler,
        private readonly StockChecker $stockChecker,
        private readonly Enqueuer $enqueuer,
        private readonly RunLog $runLog,
        private readonly Scheduler $scheduler,
        private readonly FeedGenerator $feedGenerator,
        private readonly FeedFlags $feedFlags,
        private readonly Logger $logger
    ) {
    }

    /** askmerra_process_queue: sends what is due, then schedules the next run while products wait. */
    public function processQueue(): void
    {
        update_option(Scheduler::OPTION_LAST_QUEUE_RUN, time(), false);
        $seconds = max(5, (int) apply_filters('askmerra_queue_run_seconds', self::QUEUE_SECONDS));
        $result = null;

        try {
            wc_set_time_limit($seconds + 60);
            $result = $this->queueProcessor->run($seconds);
        } catch (\Throwable $e) {
            $this->logger->error('Queue run: ' . $e->getMessage(), ['exception' => $e]);
        }

        try {
            // Another run holds the lock (WP-CLI, the status page): look again in a minute.
            $this->scheduler->scheduleNextRun($result === null ? time() + 60 : 0);
        } catch (\Throwable $e) {
            $this->logger->error('Scheduling the next queue run: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * askmerra_stock_check, every 15 minutes: products that sold out or came back without a hook,
     * and a catalog built for other shop settings (language, currency...) - an idle shop runs no
     * queue that would notice them.
     */
    public function checkStock(): void
    {
        update_option(Scheduler::OPTION_LAST_CHECK_RUN, time(), false);

        foreach ($this->storefronts->syncing() as $id => $storefront) {
            try {
                // A rebuild queues every product: nothing left to check.
                if (!$this->reconciler->ensureSyncMethod($storefront)) {
                    $this->stockChecker->check($storefront);
                }
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('Stock check of storefront %s: %s', $id, $e->getMessage()), ['exception' => $e]);
            }
        }

        try {
            // A safety net: products waiting with no run scheduled (a run that died).
            $this->scheduler->scheduleNextRun();
        } catch (\Throwable $e) {
            $this->logger->error('Scheduling the next queue run: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * askmerra_modified_check, every 15 minutes: products and variations edited since the last
     * check - imports and integrations that write the database without WooCommerce's hooks.
     *
     * @return int products queued
     */
    public function checkModified(): int
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');
        $since = (string) get_option(self::OPTION_LAST_MODIFIED_CHECK, '');
        $queued = 0;

        try {
            if ($since !== '' && $this->storefronts->syncing()) {
                $ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_type IN ('product', 'product_variation') AND post_modified_gmt >= %s",
                    gmdate('Y-m-d H:i:s', (int) strtotime($since . ' UTC') - self::MODIFIED_OVERLAP_SECONDS)
                ));

                if ($ids) {
                    $this->enqueuer->enqueue(array_map('intval', $ids));
                    $queued = $this->enqueuer->flush();
                }
            }

            // The first check only sets the starting point: the catalog was just rebuilt.
            update_option(self::OPTION_LAST_MODIFIED_CHECK, $now, false);
        } catch (\Throwable $e) {
            $this->logger->error('Modified products check: ' . $e->getMessage(), ['exception' => $e]);
        }

        return $queued;
    }

    /**
     * askmerra_daily: reconciles every storefront that syncs (a few queries), or rebuilds them on
     * the days the "Full rebuild" setting asks for, and tidies the queue and the run history.
     */
    public function daily(): void
    {
        $rebuild = $this->isRebuildDay();
        $syncing = $this->storefronts->syncing();

        foreach ($syncing as $id => $storefront) {
            try {
                $rebuild ? $this->reconciler->rebuild($storefront) : $this->reconciler->reconcile($storefront);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('Daily check of storefront %s: %s', $id, $e->getMessage()), ['exception' => $e]);
            }
        }

        try {
            // Storefronts that stopped syncing keep nothing in the queue.
            $this->queue->removeStorefrontsExcept(array_map('strval', array_keys($syncing)));
            $this->runLog->cleanup();
        } catch (\Throwable $e) {
            $this->logger->error('Daily tidying: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /** askmerra_feed_generate: rewrites the feed files whose products changed. */
    public function generateFeeds(): void
    {
        try {
            $this->feedGenerator->generateAll();
        } catch (\Throwable $e) {
            $this->logger->error('Feed files: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * The schedule changed (Scheduler::reschedule()): storefronts that start syncing send their
     * catalog; when one stops, its queue goes and a feed file it published is removed.
     *
     * @param string[] $started
     * @param string[] $stopped
     */
    public function onRescheduled(array $started, array $stopped): void
    {
        $syncing = $this->storefronts->syncing();

        foreach ($started as $id) {
            if (isset($syncing[$id]) && !isset($this->rebuilt[$id])) {
                $this->reconciler->rebuild($syncing[$id]);
                $this->rebuilt[$id] = true;
            }
        }

        if ($stopped) {
            $this->queue->removeStorefrontsExcept(array_map('strval', array_keys($syncing)));
            $this->feedGenerator->removeUnusedFiles();
        }

        foreach ($stopped as $id) {
            // Products change unnoticed while it is off: a feed it publishes again waits for the
            // catalog rebuilt when it starts. Its file is gone, nothing is behind.
            $this->feedFlags->resetReady((string) $id);
            $this->feedFlags->clearDirty((string) $id);
        }
    }

    /**
     * After the AskMerra settings are saved: when the change alters what is sent (keys, language,
     * catalog settings, sync method), every storefront that syncs rebuilds its catalog - still only
     * products whose content changed are sent. A feed format change rewrites the files; schedule
     * settings take effect at once.
     *
     * @param string[] $changed option names
     */
    public function onSettingsSaved(array $changed): void
    {
        $this->storefronts->reset();
        $this->enqueuer->reset();
        $this->rebuilt = [];

        // Starts, stops and reschedules the jobs as the new settings ask; storefronts that start
        // syncing are rebuilt there (onRescheduled()).
        $this->scheduler->ensureScheduled();

        if (in_array(Config::OPTION_FEED_FORMAT, $changed, true)) {
            foreach (array_keys($this->storefronts->feeding()) as $id) {
                $this->feedFlags->markDirty((string) $id);
            }
        }

        if (!array_intersect($changed, Config::CATALOG_OPTIONS)) {
            return;
        }

        foreach ($this->storefronts->syncing() as $id => $storefront) {
            if (!isset($this->rebuilt[$id])) {
                // Also notices a switch between Push API and feed (MethodTracker).
                $this->reconciler->rebuild($storefront);
                $this->rebuilt[$id] = true;
            }
        }
    }

    private function isRebuildDay(): bool
    {
        $today = new \DateTimeImmutable('now', wp_timezone());

        return match ($this->config->getRebuildFrequency()) {
            'daily' => true,
            'weekly' => $today->format('w') === '0',
            'monthly' => $today->format('j') === '1',
            default => false,
        };
    }
}
