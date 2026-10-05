<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Sync;

use AskMerra\WooCommerce\Install;

/**
 * askmerra_state: what each storefront has in AskMerra - id, language, a hash of the product as
 * built, whether it could be bought and, for feed storefronts, the finished feed entry (deflated
 * JSON). A row outlives its product: it is what tells the sync to remove it from AskMerra.
 */
final class State
{
    /**
     * @param int[] $productIds
     * @return array<int, array{product_id: int, external_id: string, locale: ?string, payload_hash: string, in_stock: ?bool}>
     */
    public function get(string $storefrontId, array $productIds): array
    {
        global $wpdb;

        $result = [];

        foreach (array_chunk($productIds, 1000) as $chunk) {
            $rows = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT product_id, external_id, locale, payload_hash, in_stock FROM {$this->table()}
                 WHERE storefront = %s AND product_id IN (" . Db::ids($chunk) . ')',
                $storefrontId
            ), ARRAY_A);

            foreach ($rows as $row) {
                $productId = (int) $row['product_id'];
                $result[$productId] = [
                    'product_id' => $productId,
                    'external_id' => (string) $row['external_id'],
                    'locale' => $row['locale'] === null ? null : (string) $row['locale'],
                    'payload_hash' => (string) $row['payload_hash'],
                    'in_stock' => $row['in_stock'] === null ? null : (bool) $row['in_stock'],
                ];
            }
        }

        return $result;
    }

    /**
     * The products a storefront has in AskMerra under these ids, in any language.
     *
     * @param string[] $externalIds
     * @return array<int, array{product_id: int, external_id: string, locale: ?string}> by product id
     */
    public function getByExternalIds(string $storefrontId, array $externalIds): array
    {
        global $wpdb;

        $result = [];

        foreach (array_chunk(array_values(array_unique(array_map('strval', $externalIds))), 500) as $chunk) {
            $wanted = array_flip($chunk);
            $rows = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT product_id, external_id, locale FROM {$this->table()}
                 WHERE storefront = %s AND external_id IN (" . implode(', ', array_fill(0, count($chunk), '%s')) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                array_merge([$storefrontId], $chunk)
            ), ARRAY_A);

            foreach ($rows as $row) {
                // The column ignores case and trailing spaces; AskMerra's ids do not.
                if (!isset($wanted[(string) $row['external_id']])) {
                    continue;
                }

                $productId = (int) $row['product_id'];
                $result[$productId] = [
                    'product_id' => $productId,
                    'external_id' => (string) $row['external_id'],
                    'locale' => $row['locale'] === null ? null : (string) $row['locale'],
                ];
            }
        }

        return $result;
    }

    /**
     * Records products as sent or rebuilt now.
     *
     * @param array[] $rows each: product_id, external_id, locale, payload_hash, in_stock, and payload
     *                      (the product's JSON) for feed storefronts
     */
    public function save(string $storefrontId, array $rows): void
    {
        if (!$rows) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $data = [];

        foreach ($rows as $row) {
            $payload = $row['payload'] ?? null;
            $data[] = [
                $storefrontId,
                (int) $row['product_id'],
                (string) $row['external_id'],
                isset($row['locale']) && $row['locale'] !== '' ? (string) $row['locale'] : null,
                (string) $row['payload_hash'],
                isset($row['in_stock']) ? (int) (bool) $row['in_stock'] : null,
                $payload === null ? null : gzdeflate((string) $payload, 6),
                $now,
            ];
        }

        Db::upsert(
            $this->table(),
            ['storefront', 'product_id', 'external_id', 'locale', 'payload_hash', 'in_stock', 'payload', 'synced_at'],
            $data,
            ['external_id', 'locale', 'payload_hash', 'in_stock', 'payload', 'synced_at'],
            ['payload']
        );
    }

    /**
     * Makes the queue rebuild these products even when their content did not change (their stored
     * entry is damaged).
     *
     * @param int[] $productIds
     */
    public function invalidate(string $storefrontId, array $productIds): void
    {
        global $wpdb;

        foreach (array_chunk($productIds, 1000) as $chunk) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table()} SET payload_hash = '' WHERE storefront = %s AND product_id IN (" . Db::ids($chunk) . ')',
                $storefrontId
            ));
        }
    }

    /**
     * Makes the queue rebuild and send every product of a storefront, e.g. after it switched between
     * Push API and feed. The rows stay: they still say what AskMerra has, so leftovers are removed.
     */
    public function invalidateStorefront(string $storefrontId, bool $keepPayloads): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            $keepPayloads
                ? "UPDATE {$this->table()} SET payload_hash = '' WHERE storefront = %s"
                : "UPDATE {$this->table()} SET payload_hash = '', payload = NULL WHERE storefront = %s",
            $storefrontId
        ));
    }

    /** @param int[] $productIds */
    public function delete(string $storefrontId, array $productIds): void
    {
        global $wpdb;

        foreach (array_chunk($productIds, 1000) as $chunk) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$this->table()} WHERE storefront = %s AND product_id IN (" . Db::ids($chunk) . ')',
                $storefrontId
            ));
        }
    }

    /** Forgets everything a storefront sent. */
    public function clear(string $storefrontId): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare("DELETE FROM {$this->table()} WHERE storefront = %s", $storefrontId));
    }

    /** @return int[] every product a storefront has in AskMerra */
    public function getProductIds(string $storefrontId): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT product_id FROM {$this->table()} WHERE storefront = %s",
            $storefrontId
        )));
    }

    /** @return array<int, bool> product id => could be bought when last sent, where it is known */
    public function getStockFlags(string $storefrontId): array
    {
        global $wpdb;

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, in_stock FROM {$this->table()} WHERE storefront = %s AND in_stock IS NOT NULL",
            $storefrontId
        ), ARRAY_N);
        $flags = [];

        foreach ($rows as [$productId, $inStock]) {
            $flags[(int) $productId] = (bool) (int) $inStock;
        }

        return $flags;
    }

    /**
     * A page of everything a storefront sent, in product id order.
     *
     * @return array<int, array{product_id: int, external_id: string, locale: ?string}>
     */
    public function getPage(string $storefrontId, int $afterProductId, int $limit): array
    {
        global $wpdb;

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, external_id, locale FROM {$this->table()}
             WHERE storefront = %s AND product_id > %d ORDER BY product_id ASC LIMIT %d",
            $storefrontId,
            $afterProductId,
            max(1, $limit)
        ), ARRAY_A);

        return array_map(static fn (array $row): array => [
            'product_id' => (int) $row['product_id'],
            'external_id' => (string) $row['external_id'],
            'locale' => $row['locale'] === null ? null : (string) $row['locale'],
        ], $rows);
    }

    /**
     * A page of finished feed entries, in product id order.
     *
     * @return array<int, ?string> product id => the product's JSON, null when the entry is damaged
     */
    public function getPayloadPage(string $storefrontId, int $afterProductId, int $limit): array
    {
        global $wpdb;

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, payload FROM {$this->table()}
             WHERE storefront = %s AND product_id > %d AND payload IS NOT NULL
             ORDER BY product_id ASC LIMIT %d",
            $storefrontId,
            $afterProductId,
            max(1, $limit)
        ), ARRAY_N);
        $result = [];

        foreach ($rows as [$productId, $payload]) {
            $json = @gzinflate((string) $payload); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- damaged data is reported as null
            $result[(int) $productId] = $json === false ? null : $json;
        }

        return $result;
    }

    public function count(string $storefrontId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table()} WHERE storefront = %s", $storefrontId));
    }

    /** Products whose finished feed entry is stored (feed storefronts). */
    public function countPayloads(string $storefrontId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE storefront = %s AND payload IS NOT NULL",
            $storefrontId
        ));
    }

    /** @return string|null UTC */
    public function getLastSyncedAt(string $storefrontId): ?string
    {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare("SELECT MAX(synced_at) FROM {$this->table()} WHERE storefront = %s", $storefrontId));

        return $value ? (string) $value : null;
    }

    private function table(): string
    {
        return Install::table('state');
    }
}
