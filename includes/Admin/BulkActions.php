<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Admin;

use AskMerra\WooCommerce\Plugin;
use AskMerra\WooCommerce\StorefrontRepository;
use AskMerra\WooCommerce\Sync\Enqueuer;

/**
 * Products > Bulk actions > "Send to AskMerra now": queues the selected products in every
 * storefront that syncs, failed ones included. The background job sends them within a minute.
 */
final class BulkActions
{
    private const ACTION = 'askmerra_sync';

    public function __construct(
        private readonly Plugin $plugin,
        private readonly StorefrontRepository $storefronts,
        private readonly Messages $messages
    ) {
    }

    public function register(): void
    {
        add_filter('bulk_actions-edit-product', [$this, 'addAction']);
        add_filter('handle_bulk_actions-edit-product', [$this, 'handle'], 10, 3);
    }

    /** @param array<string, string> $actions */
    public function addAction(array $actions): array
    {
        if (current_user_can('manage_woocommerce')) {
            $actions[self::ACTION] = __('Send to AskMerra now', 'askmerra-for-woocommerce');
        }

        return $actions;
    }

    /**
     * WordPress checked the list's nonce before this filter runs.
     *
     * @param int[] $postIds
     */
    public function handle(string $redirect, string $action, array $postIds): string
    {
        if ($action !== self::ACTION || !current_user_can('manage_woocommerce')) {
            return $redirect;
        }

        if (!$this->storefronts->syncing()) {
            $this->messages->add(Messages::ERROR, __('AskMerra does not sync the catalog yet. Check its settings.', 'askmerra-for-woocommerce'));

            return $redirect;
        }

        $productIds = array_values(array_filter(array_map('absint', $postIds)));
        $enqueuer = $this->plugin->get(Enqueuer::class);
        $enqueuer->enqueue($productIds);
        $enqueuer->flush();

        $this->messages->add(Messages::SUCCESS, sprintf(
            /* translators: %d: number of products */
            _n(
                '%d product was queued for AskMerra; it is sent within a minute (only if its content changed).',
                '%d products were queued for AskMerra; they are sent within a minute (only those whose content changed).',
                count($productIds),
                'askmerra-for-woocommerce'
            ),
            count($productIds)
        ));

        return $redirect;
    }
}
