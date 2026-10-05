<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Sync;

use AskMerra\WooCommerce\StorefrontRepository;

/**
 * Queues changed products for every storefront that syncs. Products are collected during the
 * request and written once when it ends: a product save fires many hooks. A variation is shown
 * through its variable product, and a product through the grouped products listing it, so those
 * are queued with it.
 */
final class Enqueuer
{
    /** @var array<string, array<int, true>> product ids by storefront ids ("*": every storefront that syncs) */
    private array $pending = [];

    /** @var array<int, int> variation id => parent id, remembered before the variation is deleted */
    private array $deletedParents = [];

    /** @var array<int, int[]>|null product id => grouped products listing it */
    private ?array $groupedParents = null;

    private ?bool $syncing = null;

    private bool $flushOnShutdown = false;

    public function __construct(
        private readonly StorefrontRepository $storefronts,
        private readonly Queue $queue
    ) {
    }

    /**
     * Queues products when the request ends (flush() writes them at once). Nothing happens when no
     * storefront syncs.
     *
     * @param int[] $productIds products or variations
     * @param string[]|null $storefrontIds null: every storefront that syncs
     */
    public function enqueue(array $productIds, ?array $storefrontIds = null): void
    {
        $this->syncing ??= (bool) $this->storefronts->syncing();

        if (!$this->syncing) {
            return;
        }

        $key = '*';

        if ($storefrontIds !== null) {
            $storefrontIds = array_values(array_unique(array_map('strval', $storefrontIds)));
            sort($storefrontIds);
            $key = implode("\n", $storefrontIds);
        }

        foreach ($productIds as $productId) {
            $productId = (int) $productId;

            if ($productId > 0) {
                $this->pending[$key][$productId] = true;
            }
        }

        if (!$this->flushOnShutdown && $this->pending) {
            $this->flushOnShutdown = true;
            add_action('shutdown', [$this, 'flush']);
        }
    }

    /** A variation is about to be deleted: its parent is what AskMerra has. */
    public function rememberParent(int $variationId, int $parentId): void
    {
        if ($variationId > 0 && $parentId > 0) {
            $this->deletedParents[$variationId] = $parentId;
        }
    }

    /**
     * Writes the collected products to the queue now.
     *
     * @return int rows queued
     */
    public function flush(): int
    {
        $pending = $this->pending;
        $this->pending = [];
        $this->syncing = null;

        if (!$pending) {
            return 0;
        }

        $syncing = array_map('strval', array_keys($this->storefronts->syncing()));
        $count = 0;

        foreach ($pending as $key => $ids) {
            $storefrontIds = $key === '*' ? $syncing : array_values(array_intersect(explode("\n", (string) $key), $syncing));

            if ($storefrontIds) {
                $count += $this->queue->add($storefrontIds, $this->resolve(array_keys($ids)));
            }
        }

        return $count;
    }

    /** Forgets what is cached for this request (which storefronts sync, grouped products). */
    public function reset(): void
    {
        $this->syncing = null;
        $this->groupedParents = null;
    }

    /**
     * The products AskMerra knows: variations become their parent, grouped products listing a
     * product come along, posts that are not products are dropped. A deleted product stays: the
     * queue removes it from AskMerra.
     *
     * @param int[] $ids
     * @return int[]
     */
    private function resolve(array $ids): array
    {
        global $wpdb;

        $products = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = (array) $wpdb->get_results(
                "SELECT ID, post_type, post_parent FROM {$wpdb->posts} WHERE ID IN (" . Db::ids($chunk) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                ARRAY_A
            );
            $found = [];

            foreach ($rows as $row) {
                $id = (int) $row['ID'];
                $found[$id] = true;

                if ($row['post_type'] === 'product') {
                    $products[$id] = true;
                } elseif ($row['post_type'] === 'product_variation' && (int) $row['post_parent'] > 0) {
                    $products[(int) $row['post_parent']] = true;
                }
            }

            foreach ($chunk as $id) {
                if (!isset($found[$id])) {
                    $products[$this->deletedParents[$id] ?? $id] = true;
                }
            }
        }

        $grouped = $this->getGroupedParents();

        foreach (array_keys($products) as $id) {
            foreach ($grouped[$id] ?? [] as $groupedId) {
                $products[$groupedId] = true;
            }
        }

        return array_keys($products);
    }

    /** @return array<int, int[]> */
    private function getGroupedParents(): array
    {
        global $wpdb;

        if ($this->groupedParents !== null) {
            return $this->groupedParents;
        }

        // Grouped products keep their children as a serialized list of ids.
        $rows = (array) $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_children' AND meta_value LIKE 'a:%'",
            ARRAY_N
        );
        $map = [];

        foreach ($rows as [$groupedId, $value]) {
            $children = @unserialize((string) $value, ['allowed_classes' => false]); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize

            foreach (is_array($children) ? $children : [] as $childId) {
                $map[(int) $childId][] = (int) $groupedId;
            }
        }

        return $this->groupedParents = $map;
    }
}
