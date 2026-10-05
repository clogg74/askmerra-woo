<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Sync;

use AskMerra\WooCommerce\Install;

/**
 * askmerra_queue: products waiting to be sent, one row per storefront and product. A product that
 * changes again while waiting stays one row. Failures are retried with growing pauses (1 minute,
 * 2, 4... up to 6 hours); after MAX_ATTEMPTS the row stays as "failed" for the status page.
 *
 * Queuing a product again counts up its row's revision. The rows fetchDue() returned are removed
 * or updated only while they keep that revision: a product edited while a run was sending it stays
 * queued, with the edit.
 *
 * Adding rows fires 'askmerra_queue_changed', so the background run is scheduled (Scheduler).
 */
final class Queue
{
    public const MAX_ATTEMPTS = 8;

    private const MAX_BACKOFF_SECONDS = 21600;

    /** @var array<string, array<int, array{0: int, 1: int}>> queue id and revision of the rows fetchDue() last returned, by storefront and product id */
    private array $fetched = [];

    /**
     * Queues products in storefronts. A product queued again is due at once and its failure count
     * starts over - the change may be the fix.
     *
     * @param string[] $storefrontIds
     * @param int[] $productIds
     * @return int rows queued
     */
    public function add(array $storefrontIds, array $productIds): int
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        $storefrontIds = array_values(array_unique(array_map('strval', $storefrontIds)));

        if (!$storefrontIds || !$productIds) {
            return 0;
        }

        $now = $this->now();
        $rows = [];

        foreach ($storefrontIds as $storefrontId) {
            foreach ($productIds as $productId) {
                $rows[] = [$storefrontId, $productId, 0, $now, $now, null];
            }
        }

        Db::upsert(
            $this->table(),
            ['storefront', 'product_id', 'attempts', 'available_at', 'created_at', 'last_error'],
            $rows,
            ['attempts', 'available_at', 'last_error', 'revision' => 'revision + 1']
        );

        do_action('askmerra_queue_changed', $storefrontIds);

        return count($rows);
    }

    /**
     * @return int[] product ids due now in a storefront, oldest first. Their rows are remembered:
     *               remove(), fail(), failPermanently() and postpone() change them only while nobody
     *               queued the product again.
     */
    public function fetchDue(string $storefrontId, int $limit): array
    {
        global $wpdb;

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, queue_id, revision FROM {$this->table()}
             WHERE storefront = %s AND attempts < %d AND available_at <= %s
             ORDER BY available_at ASC, queue_id ASC LIMIT %d",
            $storefrontId,
            self::MAX_ATTEMPTS,
            $this->now(),
            max(1, $limit)
        ), ARRAY_N);

        // The storefront's previous batch is finished: only this one is remembered.
        $this->fetched[$storefrontId] = [];

        foreach ($rows as [$productId, $queueId, $revision]) {
            $this->fetched[$storefrontId][(int) $productId] = [(int) $queueId, (int) $revision];
        }

        return array_keys($this->fetched[$storefrontId]);
    }

    /** @param int[] $productIds */
    public function remove(string $storefrontId, array $productIds): void
    {
        global $wpdb;

        foreach ($this->rows($storefrontId, $productIds) as [$where, $args]) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$this->table()} WHERE {$where}", $args)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
    }

    /**
     * Counts a failed attempt and pauses the products: 1 minute after the first failure, doubling
     * up to 6 hours.
     *
     * @param int[] $productIds
     */
    public function fail(string $storefrontId, array $productIds, string $error): void
    {
        global $wpdb;

        foreach ($this->rows($storefrontId, $productIds) as [$where, $args]) {
            // available_at is set first, from the attempts counted so far.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table()}
                 SET available_at = DATE_ADD(%s, INTERVAL LEAST(60 * POW(2, attempts), %d) SECOND),
                     attempts = attempts + 1,
                     last_error = %s
                 WHERE {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                array_merge([$this->now(), self::MAX_BACKOFF_SECONDS, Db::text($error, 2000)], $args)
            ));
        }
    }

    /**
     * Marks products failed for good - AskMerra rejected them as they are. They are tried again when
     * they change, or with "Retry failed".
     *
     * @param int[] $productIds
     */
    public function failPermanently(string $storefrontId, array $productIds, string $error): void
    {
        global $wpdb;

        foreach ($this->rows($storefrontId, $productIds) as [$where, $args]) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table()} SET attempts = %d, last_error = %s WHERE {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                array_merge([self::MAX_ATTEMPTS, Db::text($error, 2000)], $args)
            ));
        }
    }

    /**
     * Pauses products without counting a failure.
     *
     * @param int[] $productIds
     */
    public function postpone(string $storefrontId, array $productIds, int $seconds, ?string $reason = null): void
    {
        global $wpdb;

        [$set, $values] = $this->pause($seconds, $reason);

        foreach ($this->rows($storefrontId, $productIds) as [$where, $args]) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table()} SET {$set} WHERE {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                array_merge($values, $args)
            ));
        }
    }

    /**
     * Pauses a whole storefront without counting a failure - a rate limit, a refused key, AskMerra
     * out of reach: none of its waiting products is tried before $seconds from now.
     *
     * @return int products postponed
     */
    public function postponeStorefront(string $storefrontId, int $seconds, ?string $reason = null): int
    {
        global $wpdb;

        [$set, $values, $availableAt] = $this->pause($seconds, $reason);

        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table()} SET {$set} WHERE storefront = %s AND attempts < %d AND available_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            array_merge($values, [$storefrontId, self::MAX_ATTEMPTS, $availableAt])
        ));
    }

    /** Makes failed products due again. */
    public function retryFailed(?string $storefrontId = null): int
    {
        global $wpdb;

        $sql = "UPDATE {$this->table()} SET attempts = 0, available_at = %s, last_error = NULL WHERE attempts >= %d";
        $args = [$this->now(), self::MAX_ATTEMPTS];

        if ($storefrontId !== null) {
            $sql .= ' AND storefront = %s';
            $args[] = $storefrontId;
        }

        $count = (int) $wpdb->query($wpdb->prepare($sql, $args)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($count > 0) {
            do_action('askmerra_queue_changed', $storefrontId === null ? [] : [$storefrontId]);
        }

        return $count;
    }

    /** @return int[] every product in a storefront's queue, failed ones included */
    public function getProductIds(string $storefrontId): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT product_id FROM {$this->table()} WHERE storefront = %s",
            $storefrontId
        )));
    }

    /** Products still to be sent, including those waiting after a failure. */
    public function countPending(string $storefrontId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE storefront = %s AND attempts < %d",
            $storefrontId,
            self::MAX_ATTEMPTS
        ));
    }

    /** @return int[] products that failed MAX_ATTEMPTS times */
    public function getFailedProductIds(string $storefrontId): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT product_id FROM {$this->table()} WHERE storefront = %s AND attempts >= %d",
            $storefrontId,
            self::MAX_ATTEMPTS
        )));
    }

    /**
     * The latest errors of a storefront, for the status page.
     *
     * @return array<int, array{product_id: int, attempts: int, available_at: string, last_error: string}>
     */
    public function getErrors(string $storefrontId, int $limit = 20): array
    {
        global $wpdb;

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, attempts, available_at, last_error FROM {$this->table()}
             WHERE storefront = %s AND last_error IS NOT NULL
             ORDER BY attempts DESC, available_at DESC LIMIT %d",
            $storefrontId,
            max(1, $limit)
        ), ARRAY_A);

        return array_map(static fn (array $row): array => [
            'product_id' => (int) $row['product_id'],
            'attempts' => (int) $row['attempts'],
            'available_at' => (string) $row['available_at'],
            'last_error' => (string) $row['last_error'],
        ], $rows);
    }

    /**
     * When the next queued product of these storefronts is due (a failed product waits; products that
     * failed for good are not counted), or null when nothing waits.
     *
     * @param string[] $storefrontIds
     * @return int|null Unix time
     */
    public function getNextDueAt(array $storefrontIds): ?int
    {
        global $wpdb;

        if (!$storefrontIds) {
            return null;
        }

        $placeholders = implode(', ', array_fill(0, count($storefrontIds), '%s'));
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(available_at) FROM {$this->table()} WHERE attempts < %d AND storefront IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            array_merge([self::MAX_ATTEMPTS], array_values($storefrontIds))
        ));

        return $value ? (int) strtotime($value . ' UTC') : null;
    }

    /** Empties a storefront's queue, or the whole queue. */
    public function clear(?string $storefrontId = null): void
    {
        global $wpdb;

        $storefrontId === null
            ? $wpdb->query("DELETE FROM {$this->table()}") // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            : $wpdb->query($wpdb->prepare("DELETE FROM {$this->table()} WHERE storefront = %s", $storefrontId));
    }

    /**
     * Drops the queue of storefronts that no longer sync.
     *
     * @param string[] $storefrontIds the storefronts to keep
     * @return int rows removed
     */
    public function removeStorefrontsExcept(array $storefrontIds): int
    {
        global $wpdb;

        if (!$storefrontIds) {
            return (int) $wpdb->query("DELETE FROM {$this->table()}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        $placeholders = implode(', ', array_fill(0, count($storefrontIds), '%s'));

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE storefront NOT IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            array_values($storefrontIds)
        ));
    }

    /**
     * WHERE conditions, with their values, for products of a storefront, in chunks. A row fetchDue()
     * returned matches only while it has the revision it was fetched with: a product queued again
     * meanwhile keeps its row. The queue id also tells a row deleted and added again since.
     *
     * @param int[] $productIds
     * @return array<int, array{0: string, 1: array}>
     */
    private function rows(string $storefrontId, array $productIds): array
    {
        $fetched = [];
        $other = [];

        foreach ($productIds as $productId) {
            $row = $this->fetched[$storefrontId][(int) $productId] ?? null;

            if ($row === null) {
                $other[] = (int) $productId;
            } else {
                // Grouped by revision: one statement per revision, mostly a single one.
                $fetched[$row[1]][] = $row[0];
            }
        }

        $conditions = [];

        foreach (array_chunk($other, 1000) as $chunk) {
            $conditions[] = ['storefront = %s AND product_id IN (' . Db::ids($chunk) . ')', [$storefrontId]];
        }

        foreach ($fetched as $revision => $queueIds) {
            foreach (array_chunk($queueIds, 1000) as $chunk) {
                $conditions[] = ['queue_id IN (' . Db::ids($chunk) . ') AND revision = %d', [$revision]];
            }
        }

        return $conditions;
    }

    /**
     * The SET clause and its values pausing rows for $seconds (with the reason as their error when
     * given), and the time they become due.
     *
     * @return array{0: string, 1: array, 2: string}
     */
    private function pause(int $seconds, ?string $reason): array
    {
        $availableAt = gmdate('Y-m-d H:i:s', time() + max(1, $seconds));

        return $reason === null
            ? ['available_at = %s', [$availableAt], $availableAt]
            : ['available_at = %s, last_error = %s', [$availableAt, Db::text($reason, 2000)], $availableAt];
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function table(): string
    {
        return Install::table('queue');
    }
}
