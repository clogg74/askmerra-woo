<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Sync;

use AskMerra\WooCommerce\Catalog\ProductBuilder;
use AskMerra\WooCommerce\Storefront;

/**
 * Every 15 minutes: queues the products whose "can be bought" state changed since they were sent -
 * a safety net for stock written without WooCommerce's hooks (ERP or warehouse integrations) - and
 * products back in stock that were left out while out-of-stock products are not sent. A few
 * queries per storefront; only the changed products are rebuilt.
 *
 * The products that can be bought but are not in AskMerra are remembered (option
 * askmerra_stock_missing: storefront id => comma-separated ids, read fresh), and only those newly
 * missing are queued: back in stock, or seen for the first time. A product the shop keeps out
 * (askmerra_product_is_eligible) stays missing and is not queued again every 15 minutes.
 */
final class StockChecker
{
    private const OPTION = 'askmerra_stock_missing';

    public function __construct(
        private readonly ProductBuilder $productBuilder,
        private readonly State $state,
        private readonly Queue $queue
    ) {
    }

    /** @return int products queued */
    public function check(Storefront $storefront): int
    {
        $sent = $this->state->getStockFlags($storefront->id);
        $changed = [];
        $missing = [];

        foreach ($this->productBuilder->getStockFlags($storefront) as $productId => $inStock) {
            if (isset($sent[$productId])) {
                if ($sent[$productId] !== $inStock) {
                    $changed[] = $productId;
                }
            } elseif ($inStock) {
                $missing[] = (int) $productId;
            }
        }

        $records = Db::freshOption(self::OPTION, []);
        $records = is_array($records) ? $records : [];
        $before = (string) ($records[$storefront->id] ?? '');
        $known = $before === '' ? [] : array_flip(array_map('intval', explode(',', $before)));
        $new = array_filter($missing, static fn (int $productId): bool => !isset($known[$productId]));

        if ($new) {
            // Unless the queue has it already (waiting, or rejected by AskMerra and kept until it changes).
            $waiting = array_flip($this->queue->getProductIds($storefront->id));

            foreach ($new as $productId) {
                if (!isset($waiting[$productId])) {
                    $changed[] = $productId;
                }
            }
        }

        $queued = $this->queue->add([$storefront->id], $changed);

        sort($missing);
        $records[$storefront->id] = implode(',', $missing);
        update_option(self::OPTION, $records, false);

        return $queued;
    }
}
