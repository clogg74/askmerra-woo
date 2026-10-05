<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Sync;

use AskMerra\WooCommerce\Api\ApiException;
use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Feed\FeedFlags;
use AskMerra\WooCommerce\Feed\FeedGenerator;
use AskMerra\WooCommerce\Storefront;

/**
 * Takes a storefront's catalog out of AskMerra: every product it pushed is removed (AskMerra
 * deactivates them), and its queue, state and feed file are cleared. A feed source has to be
 * removed in the AskMerra dashboard as well - AskMerra keeps a feed's products when the feed
 * comes back empty.
 */
final class Remover
{
    public function __construct(
        private readonly Config $config,
        private readonly State $state,
        private readonly Queue $queue,
        private readonly RunLog $runLog,
        private readonly QueueProcessor $queueProcessor,
        private readonly FeedGenerator $feedGenerator,
        private readonly FeedFlags $feedFlags
    ) {
    }

    /**
     * @return int products removed
     * @throws ApiException when AskMerra cannot be reached, or no secret key is saved while AskMerra
     *                      has products (nothing is removed then); what was removed so far stays removed
     */
    public function removeAll(Storefront $storefront): int
    {
        if ($this->config->getSecretKey() === '' && $this->state->count($storefront->id) > 0) {
            // Only the Push API removes products; forgetting them here would leave them in AskMerra for good.
            throw new ApiException(
                __('Nothing was removed: AskMerra removes products only with the secret key. Enter it under WooCommerce > Settings > AskMerra > Connection (AskMerra can stay turned off), then try again.', 'askmerra-for-woocommerce'),
                401,
                'missing_api_key',
                ''
            );
        }

        $runId = $this->runLog->start($storefront->id, RunLog::TYPE_REMOVE_ALL);
        $removed = 0;

        try {
            while ($rows = $this->state->getPage($storefront->id, 0, 1000)) {
                $this->queueProcessor->deleteRemote($storefront->id, $rows);
                $this->state->delete($storefront->id, array_column($rows, 'product_id'));
                $removed += count($rows);
            }
        } catch (ApiException $e) {
            $this->runLog->finish($runId, RunLog::STATUS_FAILED, ['removed' => $removed], $e->getMessage());

            throw $e;
        }

        $this->queue->clear($storefront->id);
        $this->feedGenerator->deleteFiles($storefront);
        $this->feedFlags->resetReady($storefront->id);
        $this->feedFlags->clearDirty($storefront->id);
        $this->runLog->finish($runId, RunLog::STATUS_SUCCESS, ['removed' => $removed]);

        return $removed;
    }
}
