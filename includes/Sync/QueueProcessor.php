<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Sync;

use AskMerra\WooCommerce\Api\ApiException;
use AskMerra\WooCommerce\Api\Client;
use AskMerra\WooCommerce\Catalog\ProductBuilder;
use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Feed\FeedFlags;
use AskMerra\WooCommerce\Log\Logger;
use AskMerra\WooCommerce\Storefront;
use AskMerra\WooCommerce\StorefrontRepository;

/**
 * Works through the queue: builds the queued products and keeps only those whose content changed.
 *
 * - Push storefronts send them to AskMerra (batchUpsert) and remove products that left the catalog
 *   (batchDelete) - removals first, so a product sent under a removed product's id keeps it.
 * - Feed storefronts keep the finished entries in askmerra_state; the feed file is written from
 *   there (Feed\FeedGenerator).
 *
 * An id belongs to one product: a product taking an id another product still has fails with a
 * message, and a copy stays in AskMerra while another product has it.
 *
 * Storefronts take turns batch by batch, so one large catalog does not hold up the others. A rate
 * limit, a refused key or AskMerra out of reach pauses a storefront as a whole, without counting
 * failures against its products. One run at a time (a database lock).
 */
final class QueueProcessor
{
    public const LOCK = 'askmerra_queue';

    /** A run's default length: it fits in a one-minute schedule. */
    public const DEFAULT_SECONDS = 50;

    /** Seconds before a storefront whose key was rejected is tried again. */
    private const AUTH_PAUSE = 600;

    /**
     * Outages in a row by storefront (no answer, a timeout, a server error): the storefront waits
     * 1 minute after the first, doubling up to 6 hours, until AskMerra answers again.
     */
    private const OPTION_OUTAGES = 'askmerra_outages';

    private const OUTAGE_PAUSE = 60;

    private const MAX_OUTAGE_PAUSE = 21600;

    /** @var array<string, true> storefronts paused for the rest of the run */
    private array $paused = [];

    /** @var array<int, true> the products of the batch being processed */
    private array $batch = [];

    /** @var array<int, true> products of the batch still without an outcome (dequeue(), retryLater(), reject()) */
    private array $open = [];

    public function __construct(
        private readonly Config $config,
        private readonly StorefrontRepository $storefronts,
        private readonly Queue $queue,
        private readonly State $state,
        private readonly Problems $problems,
        private readonly ProductBuilder $productBuilder,
        private readonly Client $client,
        private readonly FeedFlags $feedFlags,
        private readonly Reconciler $reconciler,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param string[]|null $storefrontIds null: every storefront that syncs
     * @return array<string, array{sent: int, unchanged: int, removed: int, failed: int}>|null per
     *         storefront that syncs; null when another run holds the lock
     */
    public function run(int $seconds = self::DEFAULT_SECONDS, ?array $storefrontIds = null): ?array
    {
        $lock = new Lock(self::LOCK);

        if (!$lock->acquire()) {
            return null;
        }

        try {
            $syncing = $this->storefronts->syncing();
            $storefronts = $storefrontIds === null
                ? $syncing
                : array_intersect_key($syncing, array_flip(array_map('strval', $storefrontIds)));
            $deadline = microtime(true) + $seconds;
            $stats = [];
            $this->paused = [];

            foreach ($storefronts as $id => $storefront) {
                $stats[$id] = ['sent' => 0, 'unchanged' => 0, 'removed' => 0, 'failed' => 0];
                $this->reconciler->ensureSyncMethod($storefront);
            }

            do {
                $worked = false;

                foreach ($storefronts as $id => $storefront) {
                    if (microtime(true) >= $deadline) {
                        break 2;
                    }

                    if (isset($this->paused[$id])) {
                        continue;
                    }

                    $result = $this->processNextBatch($storefront);

                    if ($result !== null) {
                        $worked = true;

                        foreach ($result as $key => $count) {
                            $stats[$id][$key] += $count;
                        }
                    }
                }
            } while ($worked && microtime(true) < $deadline);

            foreach ($this->storefronts->feeding() as $id => $storefront) {
                if (isset($storefronts[$id])) {
                    $this->markFeedReady($storefront);
                }
            }

            return $stats;
        } finally {
            $lock->release();
        }
    }

    /**
     * Removes products from AskMerra, grouped by the language they were sent in.
     *
     * @param array[] $rows each: external_id, locale
     * @throws ApiException
     */
    public function deleteRemote(string $storefrontId, array $rows): void
    {
        $byLocale = [];

        foreach ($rows as $row) {
            $byLocale[(string) ($row['locale'] ?? '')][] = (string) $row['external_id'];
        }

        foreach ($byLocale as $locale => $externalIds) {
            foreach (array_chunk(array_values(array_unique($externalIds)), Client::MAX_DELETE_IDS) as $chunk) {
                $this->client->batchDelete($chunk, $locale === '' ? null : (string) $locale);
                $this->answered($storefrontId);
            }
        }
    }

    public static function encode(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }

    /** What a product looks like to AskMerra; source_updated_at alone is no reason to send it again. */
    public static function hash(array $payload): string
    {
        unset($payload['source_updated_at']);

        return sha1(self::encode($payload));
    }

    /** @return array{sent: int, unchanged: int, removed: int, failed: int}|null null: nothing was due */
    private function processNextBatch(Storefront $storefront): ?array
    {
        $isPush = isset($this->storefronts->pushing()[$storefront->id]);

        if (!$isPush && !isset($this->storefronts->feeding()[$storefront->id])) {
            return null;
        }

        $productIds = $this->queue->fetchDue($storefront->id, $this->config->getBatchSize());

        if (!$productIds) {
            return null;
        }

        $stats = ['sent' => 0, 'unchanged' => 0, 'removed' => 0, 'failed' => 0];
        $this->batch = array_fill_keys($productIds, true);
        $this->open = $this->batch;

        try {
            $this->processBatch($storefront, $productIds, $isPush, $stats);
        } catch (ApiException $e) {
            $this->onApiError($storefront->id, $e, $stats);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Storefront %s: %s', $storefront->id, $e->getMessage()), ['exception' => $e]);
            $stats['failed'] += $this->retryOpen($storefront->id, $e->getMessage());
        }

        return $stats;
    }

    /**
     * A call failed as a whole. A rate limit, a refused key or an outage is the storefront's, not
     * its products': everything it has waiting is paused without counting a failure, and the rest
     * of the run leaves it alone. Any other error is a failed attempt of the batch's open products.
     */
    private function onApiError(string $storefrontId, ApiException $e, array &$stats): void
    {
        if ($e->isAuthError()) {
            $this->problems->set($storefrontId, $e->getMessage());
            $this->pause($storefrontId, self::AUTH_PAUSE, $e->getMessage());
        } elseif ($e->isRateLimited()) {
            $this->pause($storefrontId, $e->getRetryAfter());
        } elseif ($e->isRetryable()) {
            // No answer, a timeout or a server error.
            $this->pause($storefrontId, $this->countOutage($storefrontId), $e->getMessage());
        } else {
            $stats['failed'] += $this->retryOpen($storefrontId, $e->getMessage());
        }
    }

    private function pause(string $storefrontId, int $seconds, ?string $reason = null): void
    {
        $this->queue->postponeStorefront($storefrontId, $seconds, $reason);
        $this->paused[$storefrontId] = true;
    }

    /** @return int seconds the storefront waits after this outage */
    private function countOutage(string $storefrontId): int
    {
        $outages = $this->outages();
        $count = (int) ($outages[$storefrontId] ?? 0) + 1;
        $outages[$storefrontId] = $count;
        update_option(self::OPTION_OUTAGES, $outages, false);

        return min(self::OUTAGE_PAUSE * 2 ** min($count - 1, 16), self::MAX_OUTAGE_PAUSE);
    }

    /** AskMerra answered: the key works (the admin warning goes) and no outage is going on. */
    private function answered(string $storefrontId): void
    {
        $this->problems->clear($storefrontId);
        $outages = $this->outages();

        if (isset($outages[$storefrontId])) {
            unset($outages[$storefrontId]);
            update_option(self::OPTION_OUTAGES, $outages, false);
        }
    }

    /** @return array<string, int> read fresh: the previous run may have been another process */
    private function outages(): array
    {
        $outages = Db::freshOption(self::OPTION_OUTAGES, []);

        return is_array($outages) ? $outages : [];
    }

    /**
     * @param int[] $productIds
     * @throws ApiException
     */
    private function processBatch(Storefront $storefront, array $productIds, bool $isPush, array &$stats): void
    {
        $built = $this->productBuilder->build($storefront, $productIds);
        $states = $this->state->get($storefront->id, $productIds);
        $locale = $storefront->locale;

        foreach ($built['errors'] as $productId => $message) {
            $this->retryLater($storefront->id, [(int) $productId], (string) $message);
            $stats['failed']++;
        }

        [$owners, $taken] = $this->claimIds($storefront, $built, $states);

        foreach ($taken as $productId => $message) {
            $this->reject($storefront->id, $productId, $message);
            unset($built['payloads'][$productId]);
            $stats['failed']++;
        }

        $changed = [];
        $unchanged = [];

        foreach ($built['payloads'] as $productId => $payload) {
            $json = self::encode($payload);
            $hash = self::hash($payload);
            $previous = $states[$productId] ?? null;

            if ($previous !== null
                && $previous['payload_hash'] === $hash
                && $previous['external_id'] === (string) $payload['external_id']
                && (string) $previous['locale'] === (string) $locale
            ) {
                $unchanged[] = (int) $productId;
                continue;
            }

            $changed[(int) $productId] = ['payload' => $payload, 'json' => $json, 'hash' => $hash];
        }

        if ($unchanged) {
            $this->dequeue($storefront->id, $unchanged);
            $stats['unchanged'] += count($unchanged);
        }

        $ineligible = array_map('intval', array_keys($built['ineligible']));
        $known = array_intersect_key($states, array_flip($ineligible));
        $handedOver = [];

        if ($isPush) {
            // A copy under an id that a product of the batch sends is left to that product: its
            // entry goes once that product is recorded (record()), or below when it was not sent.
            foreach ($known as $productId => $row) {
                if ((string) $row['locale'] === (string) $locale && isset($changed[$owners[$row['external_id']] ?? 0])) {
                    $handedOver[$productId] = $row;
                }
            }
        }

        $this->removeKnown($storefront->id, array_diff_key($known, $handedOver), $isPush, $locale, $stats);

        if ($ineligible) {
            $this->dequeue($storefront->id, $ineligible);
        }

        if ($changed) {
            $isPush
                ? $this->push($storefront->id, $changed, $states, $locale, $stats)
                : $this->cache($storefront->id, $changed, $locale, $stats);
        }

        if ($handedOver) {
            // Entries still there: the product taking the id over was rejected.
            $left = array_intersect_key($handedOver, $this->state->get($storefront->id, array_keys($handedOver)));
            $this->removeKnown($storefront->id, $left, $isPush, $locale, $stats);
        }
    }

    /**
     * Removes products that left the catalog: their copies that no other product has from AskMerra
     * (or the feed), and their entries.
     *
     * @param array[] $known their entries, by product id
     * @throws ApiException
     */
    private function removeKnown(string $storefrontId, array $known, bool $isPush, ?string $locale, array &$stats): void
    {
        if (!$known) {
            return;
        }

        if ($isPush) {
            $this->deleteRemote($storefrontId, $this->unowned($storefrontId, $known, [], $locale));
        } else {
            $this->feedFlags->markDirty($storefrontId);
        }

        $this->state->delete($storefrontId, array_keys($known));
        $stats['removed'] += count($known);
    }

    /**
     * Gives each external id of the batch to one product, and finds the products that cannot have
     * theirs - sending them would overwrite another product in AskMerra:
     * - a product AskMerra has under its id keeps it (the first one, should two have it);
     * - a product taking a new id gets it, unless another product of the batch has it, or a product
     *   AskMerra has under it still has it (built now; one that cannot be built is taken to). A
     *   product that is gone or has another id now gives way.
     *
     * @return array{0: array<string, int>, 1: array<int, string>} external id => the product that
     *         has it, and product id => why it is not sent
     */
    private function claimIds(Storefront $storefront, array $built, array $states): array
    {
        $owners = [];
        $newcomers = [];
        $taken = [];

        foreach ($built['payloads'] as $productId => $payload) {
            $externalId = (string) $payload['external_id'];

            if (($states[$productId]['external_id'] ?? null) !== $externalId) {
                $newcomers[$productId] = $externalId;
            } elseif (isset($owners[$externalId])) {
                $taken[$productId] = [$owners[$externalId], $externalId];
            } else {
                $owners[$externalId] = $productId;
            }
        }

        $holders = $newcomers ? $this->getHolders($storefront, $newcomers, $built) : [];

        foreach ($newcomers as $productId => $externalId) {
            $other = $owners[$externalId] ?? $holders[$externalId] ?? null;

            if ($other === null) {
                $owners[$externalId] = $productId;
            } else {
                $taken[$productId] = [$other, $externalId];
            }
        }

        $reasons = [];

        foreach ($taken as $productId => [$otherId, $externalId]) {
            $reasons[$productId] = sprintf(
                /* translators: 1: product id, 2: id of the product with the same identifier, 3: the identifier (SKU) */
                __('Product %1$d has the same SKU as product %2$d (%3$s); AskMerra needs unique product identifiers.', 'askmerra-for-woocommerce'),
                $productId,
                $otherId,
                $externalId
            );
        }

        return [$owners, $reasons];
    }

    /**
     * The products AskMerra has under ids that products of the batch take, and that still have
     * them. The batch's own products are known from its build: those still sending the id own it
     * already, those that left or changed their id are gone from it.
     *
     * @param array<int, string> $newcomers product id => the id it takes
     * @return array<string, int> external id => the product that has it
     */
    private function getHolders(Storefront $storefront, array $newcomers, array $built): array
    {
        $holders = [];
        $outside = [];

        foreach ($this->state->getByExternalIds($storefront->id, $newcomers) as $productId => $row) {
            if (isset($built['errors'][$productId])) {
                $holders[$row['external_id']] ??= $productId;
            } elseif (!isset($this->batch[$productId])) {
                $outside[$productId] = $row['external_id'];
            }
        }

        if ($outside) {
            $now = $this->productBuilder->build($storefront, array_keys($outside));

            foreach ($outside as $productId => $externalId) {
                if (isset($now['errors'][$productId])
                    || (string) ($now['payloads'][$productId]['external_id'] ?? '') === $externalId
                ) {
                    $holders[$externalId] ??= $productId;
                }
            }
        }

        return $holders;
    }

    /**
     * The copies no other product has in AskMerra: another product's entry with the same id and
     * language keeps the copy, and so does a product just sent under that id.
     *
     * @param array[] $rows entries going (product_id, external_id, locale), by product id
     * @param string[] $sentIds ids just sent, in the storefront's language
     * @return array[] those whose copy can be removed
     */
    private function unowned(string $storefrontId, array $rows, array $sentIds, ?string $locale): array
    {
        $kept = [];

        foreach ($sentIds as $externalId) {
            $kept[$externalId . "\n" . $locale] = true;
        }

        foreach ($this->state->getByExternalIds($storefrontId, array_column($rows, 'external_id')) as $productId => $row) {
            if (!isset($rows[$productId])) {
                $kept[$row['external_id'] . "\n" . $row['locale']] = true;
            }
        }

        return array_filter($rows, static fn (array $row): bool => !isset($kept[$row['external_id'] . "\n" . $row['locale']]));
    }

    /** @throws ApiException */
    private function push(string $storefrontId, array $changed, array $states, ?string $locale, array &$stats): void
    {
        $chunk = [];
        $bytes = 0;

        foreach ($changed as $productId => $item) {
            $size = strlen($item['json']) + 1;

            if ($chunk && $bytes + $size > Client::MAX_UPSERT_BYTES) {
                $this->pushChunk($storefrontId, $chunk, $states, $locale, $stats);
                $chunk = [];
                $bytes = 0;
            }

            $chunk[$productId] = $item;
            $bytes += $size;
        }

        if ($chunk) {
            $this->pushChunk($storefrontId, $chunk, $states, $locale, $stats);
        }
    }

    /** @throws ApiException */
    private function pushChunk(string $storefrontId, array $chunk, array $states, ?string $locale, array &$stats): void
    {
        try {
            $response = $this->client->batchUpsert(array_column($chunk, 'payload'), $locale);
            $this->answered($storefrontId);
        } catch (ApiException $e) {
            if (!$e->isPayloadTooLarge()) {
                throw $e;
            }

            // Too large after all: send it in halves. A product too large on its own stays out.
            if (count($chunk) > 1) {
                foreach (array_chunk($chunk, (int) ceil(count($chunk) / 2), true) as $half) {
                    $this->pushChunk($storefrontId, $half, $states, $locale, $stats);
                }
            } else {
                $this->reject($storefrontId, (int) array_key_first($chunk), sprintf(
                    /* translators: %s: the error, with the size */
                    __('The product is too large to send to AskMerra; shorten its description or attributes. %s', 'askmerra-for-woocommerce'),
                    $e->getMessage()
                ));
                $stats['failed']++;
            }

            return;
        }

        $rejected = [];

        foreach ($response['errors'] as $error) {
            if (is_array($error)) {
                $rejected[(int) ($error['index'] ?? -1)] = (string) ($error['message'] ?? 'rejected');
            }
        }

        $sent = [];
        $sentIds = [];
        $stale = [];
        $index = 0;

        foreach ($chunk as $productId => $item) {
            if (isset($rejected[$index])) {
                $this->reject($storefrontId, $productId, sprintf(
                    /* translators: %s: AskMerra's message */
                    __('AskMerra rejected the product: %s', 'askmerra-for-woocommerce'),
                    $rejected[$index]
                ));
                $stats['failed']++;
            } else {
                $sent[$productId] = $item;
                $sentIds[] = (string) $item['payload']['external_id'];
                $previous = $states[$productId] ?? null;

                // The product was in AskMerra under another id or language: that copy goes.
                if ($previous !== null
                    && ($previous['external_id'] !== (string) $item['payload']['external_id'] || (string) $previous['locale'] !== (string) $locale)
                ) {
                    $stale[$productId] = $previous;
                }
            }

            $index++;
        }

        $this->recordSent($storefrontId, array_diff_key($sent, $stale), $locale, $stats);

        if ($stale) {
            // Old copies go before their products are recorded: the entry is all that knows about
            // them. When removing fails, those products stay queued with their entry as it was, and
            // sending them again is harmless.
            $this->deleteRemote($storefrontId, $this->unowned($storefrontId, $stale, $sentIds, $locale));
            $this->recordSent($storefrontId, array_intersect_key($sent, $stale), $locale, $stats);
        }
    }

    /** Records sent products as AskMerra has them now, and takes them out of the queue. */
    private function recordSent(string $storefrontId, array $items, ?string $locale, array &$stats): void
    {
        if (!$items) {
            return;
        }

        $rows = [];

        foreach ($items as $productId => $item) {
            $rows[] = [
                'product_id' => $productId,
                'external_id' => (string) $item['payload']['external_id'],
                'locale' => $locale,
                'payload_hash' => $item['hash'],
                'in_stock' => $item['payload']['in_stock'] ?? null,
            ];
        }

        $this->record($storefrontId, $rows, $locale);
        $this->dequeue($storefrontId, array_keys($items));
        $stats['sent'] += count($items);
    }

    private function cache(string $storefrontId, array $changed, ?string $locale, array &$stats): void
    {
        $rows = [];

        foreach ($changed as $productId => $item) {
            $rows[] = [
                'product_id' => $productId,
                'external_id' => (string) $item['payload']['external_id'],
                'locale' => $locale,
                'payload_hash' => $item['hash'],
                'in_stock' => $item['payload']['in_stock'] ?? null,
                'payload' => $item['json'],
            ];
        }

        // Dirty first: a process stopping after the save must not leave the file behind the entries.
        $this->feedFlags->markDirty($storefrontId);
        $this->record($storefrontId, $rows, $locale);
        $this->dequeue($storefrontId, array_keys($changed));
        $stats['sent'] += count($rows);
    }

    /**
     * Saves entries. Another product's entry under one of their ids in this language described the
     * copy just replaced - that product left the catalog or has another id now (claimIds()): the
     * entry goes, and the product is queued to be sent under its new id or removed.
     */
    private function record(string $storefrontId, array $rows, ?string $locale): void
    {
        $this->state->save($storefrontId, $rows);

        $saved = array_column($rows, 'product_id', 'external_id');
        $replaced = [];

        foreach ($this->state->getByExternalIds($storefrontId, array_keys($saved)) as $productId => $row) {
            if ($productId !== $saved[$row['external_id']] && (string) $row['locale'] === (string) $locale) {
                $replaced[$productId] = true;
            }
        }

        if ($replaced) {
            $this->state->delete($storefrontId, array_keys($replaced));
            // The batch's own products are being handled.
            $this->queue->add([$storefrontId], array_keys(array_diff_key($replaced, $this->batch)));
        }
    }

    /** @param int[] $productIds done: out of the queue */
    private function dequeue(string $storefrontId, array $productIds): void
    {
        $this->queue->remove($storefrontId, $productIds);
        $this->close($productIds);
    }

    /** @param int[] $productIds a failed attempt: tried again later */
    private function retryLater(string $storefrontId, array $productIds, string $error): void
    {
        $this->queue->fail($storefrontId, $productIds, $error);
        $this->close($productIds);
    }

    /** Failed for good: tried again when it changes, or with "Retry failed". */
    private function reject(string $storefrontId, int $productId, string $error): void
    {
        $this->queue->failPermanently($storefrontId, [$productId], $error);
        $this->close([$productId]);
    }

    /** @return int products of the batch that failed this attempt */
    private function retryOpen(string $storefrontId, string $error): int
    {
        $open = array_keys($this->open);
        $this->retryLater($storefrontId, $open, $error);

        return count($open);
    }

    /** @param int[] $productIds */
    private function close(array $productIds): void
    {
        foreach ($productIds as $productId) {
            unset($this->open[(int) $productId]);
        }
    }

    /**
     * A feed storefront whose queue drained holds its whole catalog: its file may be written (the
     * same rule as Feed\FeedGenerator, which checks it again before writing).
     */
    private function markFeedReady(Storefront $storefront): void
    {
        if (!$this->feedFlags->isReady($storefront->id)
            && $this->queue->countPending($storefront->id) === 0
            && $this->state->countPayloads($storefront->id) > 0
        ) {
            $this->feedFlags->markReady($storefront->id);
        }
    }
}
