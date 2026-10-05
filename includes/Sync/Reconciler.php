<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Sync;

use AskMerra\WooCommerce\Catalog\ProductBuilder;
use AskMerra\WooCommerce\Storefront;

/**
 * Keeps a storefront's AskMerra catalog matching WooCommerce without rebuilding products that did
 * not change - for catalogs of tens of thousands of products.
 *
 * - reconcile() (daily, a few queries): queues products that should be in AskMerra but are not,
 *   products that are in AskMerra but left the catalog, and products whose sale starts or ends
 *   around today (a date passing changes no row, so nothing else notices).
 * - rebuild() (weekly by default, on settings changes, or by hand): queues every product; the queue
 *   still sends only those whose content changed.
 */
final class Reconciler
{
    public function __construct(
        private readonly ProductBuilder $productBuilder,
        private readonly MethodTracker $methodTracker,
        private readonly State $state,
        private readonly Queue $queue,
        private readonly RunLog $runLog
    ) {
    }

    /**
     * Rebuilds a storefront whose catalog was built for something else - another sync method,
     * another AskMerra shop, other shop settings (MethodTracker) - however the setting was changed.
     *
     * @return bool whether it was rebuilt
     */
    public function ensureSyncMethod(Storefront $storefront): bool
    {
        if (!$this->methodTracker->check($storefront)) {
            return false;
        }

        $this->rebuild($storefront);

        return true;
    }

    /** @return array{missing: int, gone: int, price_dates: int} */
    public function reconcile(Storefront $storefront): array
    {
        if ($this->ensureSyncMethod($storefront)) {
            return ['missing' => 0, 'gone' => 0, 'price_dates' => 0];
        }

        $runId = $this->runLog->start($storefront->id, RunLog::TYPE_RECONCILE);
        $candidates = $this->productBuilder->getCandidateIds($storefront);
        $known = $this->state->getProductIds($storefront->id);

        $missing = array_diff($candidates, $known);
        $gone = array_diff($known, $candidates);
        $dated = array_intersect($this->getSaleDateChanges(), $candidates);

        $this->queue->add([$storefront->id], array_merge($missing, $gone, $dated));

        $stats = ['missing' => count($missing), 'gone' => count($gone), 'price_dates' => count($dated)];
        $this->runLog->finish($runId, RunLog::STATUS_SUCCESS, $stats);

        return $stats;
    }

    /** @return array{queued: int} */
    public function rebuild(Storefront $storefront): array
    {
        // A switch or another destination invalidates what AskMerra has before it is queued.
        $this->methodTracker->check($storefront);
        $runId = $this->runLog->start($storefront->id, RunLog::TYPE_REBUILD);
        $candidates = $this->productBuilder->getCandidateIds($storefront);
        $gone = array_diff($this->state->getProductIds($storefront->id), $candidates);

        $queued = $this->queue->add([$storefront->id], array_merge($candidates, $gone));
        $this->methodTracker->rebuilt($storefront);

        $this->runLog->finish($runId, RunLog::STATUS_SUCCESS, ['queued' => $queued]);

        return ['queued' => $queued];
    }

    /**
     * Products (variable products for their variations) whose scheduled sale started or ended
     * between yesterday and tomorrow, site time: WooCommerce shows the sale price from the dates,
     * while the stored price changes only when its daily scheduled-sales job runs.
     *
     * @return int[]
     */
    private function getSaleDateChanges(): array
    {
        global $wpdb;

        $today = (new \DateTimeImmutable('today', wp_timezone()))->getTimestamp();

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT IF(post.post_type = 'product_variation', post.post_parent, post.ID)
             FROM {$wpdb->postmeta} meta
             INNER JOIN {$wpdb->posts} post ON post.ID = meta.post_id
             WHERE meta.meta_key IN ('_sale_price_dates_from', '_sale_price_dates_to')
               AND meta.meta_value <> ''
               AND CAST(meta.meta_value AS UNSIGNED) >= %d
               AND CAST(meta.meta_value AS UNSIGNED) < %d
               AND post.post_type IN ('product', 'product_variation')",
            $today - DAY_IN_SECONDS,
            $today + DAY_IN_SECONDS
        )));
    }
}
