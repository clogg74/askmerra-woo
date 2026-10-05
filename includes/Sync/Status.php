<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Sync;

use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Feed\FeedFlags;
use AskMerra\WooCommerce\Feed\FeedGenerator;
use AskMerra\WooCommerce\StorefrontRepository;

/**
 * Everything the status page and `wp askmerra status` show about each storefront.
 */
final class Status
{
    public function __construct(
        private readonly Config $config,
        private readonly StorefrontRepository $storefronts,
        private readonly State $state,
        private readonly Queue $queue,
        private readonly RunLog $runLog,
        private readonly Problems $problems,
        private readonly FeedGenerator $feedGenerator,
        private readonly FeedFlags $feedFlags,
        private readonly Scheduler $scheduler
    ) {
    }

    /** @return array[] one entry per storefront (times in UTC) */
    public function getStorefronts(): array
    {
        $problems = $this->problems->all();
        $pushing = $this->storefronts->pushing();
        $feeding = $this->storefronts->feeding();
        $rows = [];

        foreach ($this->storefronts->all() as $id => $storefront) {
            $id = (string) $id;
            $mode = match (true) {
                isset($pushing[$id]) => Config::SYNC_PUSH,
                isset($feeding[$id]) => Config::SYNC_FEED,
                default => null,
            };

            $row = [
                'storefront' => $id,
                'name' => $storefront->name,
                'enabled' => $this->config->isEnabled(),
                'mode' => $mode,
                'missing_key' => $this->config->isEnabled()
                    && $this->config->getSyncMethod() === Config::SYNC_PUSH
                    && $this->config->getSecretKey() === '',
                'widget' => $this->config->isWidgetEnabled(),
                'locale' => $storefront->locale,
                'currency' => $storefront->currency,
                'products' => $this->state->count($id),
                'pending' => $this->queue->countPending($id),
                'failed' => count($this->queue->getFailedProductIds($id)),
                'errors' => $this->queue->getErrors($id, 10),
                'last_synced_at' => $this->state->getLastSyncedAt($id),
                'last_reconcile' => $this->runLog->getLast($id, RunLog::TYPE_RECONCILE),
                'last_rebuild' => $this->runLog->getLast($id, RunLog::TYPE_REBUILD),
                'problem' => $problems[$id] ?? null,
                'feed' => null,
            ];

            if ($mode === Config::SYNC_FEED) {
                $row['feed'] = $this->feedGenerator->getInfo($storefront) + [
                    'ready' => $this->feedFlags->isReady($id),
                    'dirty' => $this->feedFlags->isDirty($id),
                    'format' => $this->config->getFeedFormat(),
                    'last_run' => $this->runLog->getLast($id, RunLog::TYPE_FEED),
                ];
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Warnings about the installation itself.
     *
     * @return string[]
     */
    public function getWarnings(): array
    {
        $syncing = $this->storefronts->syncing();
        $warnings = [];

        if (!$syncing) {
            return $warnings;
        }

        if (!$this->scheduler->isRunning()) {
            $warnings[] = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON
                ? __('The AskMerra background jobs have not run for 15 minutes and WP-Cron is turned off on this site (DISABLE_WP_CRON): nothing is sent. Have the server run wp-cron.php, or "wp action-scheduler run", every minute.', 'askmerra-for-woocommerce')
                : __('The AskMerra background jobs have not run for 15 minutes: nothing is sent. WordPress runs them when the site gets visits (WP-Cron); on a quiet site, or behind a full-page cache, have the server run wp-cron.php or "wp action-scheduler run" every minute. Details: WooCommerce > Status > Scheduled Actions, group "askmerra".', 'askmerra-for-woocommerce');
        }

        $failed = $this->countFailedJobs();

        if ($failed > 0) {
            $warnings[] = sprintf(
                /* translators: %d: number of failed background jobs */
                _n(
                    '%d AskMerra background job failed in the last day. See WooCommerce > Status > Scheduled Actions (group "askmerra") and WooCommerce > Status > Logs (source "askmerra").',
                    '%d AskMerra background jobs failed in the last day. See WooCommerce > Status > Scheduled Actions (group "askmerra") and WooCommerce > Status > Logs (source "askmerra").',
                    $failed,
                    'askmerra-for-woocommerce'
                ),
                $failed
            );
        }

        foreach ($syncing as $storefront) {
            if ($storefront->locale === null) {
                $warnings[] = sprintf(
                    /* translators: %s: the WordPress site language, e.g. pt_BR */
                    __('The site language (%s) is not one AskMerra serves: choose the language to send under WooCommerce > Settings > AskMerra > Connection.', 'askmerra-for-woocommerce'),
                    $storefront->wpLocale
                );
            }
        }

        return $warnings;
    }

    private function countFailedJobs(): int
    {
        if (!function_exists('as_get_scheduled_actions')) {
            return 0;
        }

        $ids = as_get_scheduled_actions([
            'group' => Scheduler::GROUP,
            'status' => \ActionScheduler_Store::STATUS_FAILED,
            'modified' => time() - DAY_IN_SECONDS,
            'modified_compare' => '>=',
            'per_page' => 100,
            'orderby' => 'none',
        ], 'ids');

        return is_array($ids) ? count($ids) : 0;
    }
}
