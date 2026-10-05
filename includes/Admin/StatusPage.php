<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Admin;

use AskMerra\WooCommerce\Api\ApiException;
use AskMerra\WooCommerce\Catalog\ProductBuilder;
use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Feed\FeedGenerator;
use AskMerra\WooCommerce\Feed\FeedToken;
use AskMerra\WooCommerce\Log\Logger;
use AskMerra\WooCommerce\Plugin;
use AskMerra\WooCommerce\Storefront;
use AskMerra\WooCommerce\StorefrontRepository;
use AskMerra\WooCommerce\Sync\Enqueuer;
use AskMerra\WooCommerce\Sync\Problems;
use AskMerra\WooCommerce\Sync\Queue;
use AskMerra\WooCommerce\Sync\QueueProcessor;
use AskMerra\WooCommerce\Sync\Reconciler;
use AskMerra\WooCommerce\Sync\Remover;
use AskMerra\WooCommerce\Sync\State;
use AskMerra\WooCommerce\Sync\Status;

/**
 * WooCommerce > AskMerra: every storefront's sync, the latest errors, the actions (POSTs to
 * admin-post.php) and a preview of any product as AskMerra receives it.
 */
final class StatusPage
{
    public const ACTION = 'askmerra_status';

    /** Seconds "Send changes now" may work within the admin request. */
    private const PROCESS_SECONDS = 25;

    private ?string $screenId = null;

    public function __construct(
        private readonly Plugin $plugin,
        private readonly Config $config,
        private readonly StorefrontRepository $storefronts,
        private readonly Messages $messages,
        private readonly Logger $logger
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu'], 60);
        add_action('admin_post_' . self::ACTION, [$this, 'handleAction']);
    }

    public function addMenu(): void
    {
        $this->screenId = add_submenu_page(
            'woocommerce',
            __('AskMerra sync status', 'askmerra-for-woocommerce'),
            __('AskMerra', 'askmerra-for-woocommerce'),
            'manage_woocommerce',
            AdminUrls::STATUS_PAGE,
            [$this, 'render']
        ) ?: null;
    }

    /** The screen id of the page, once the menu is built. */
    public function getScreenId(): ?string
    {
        return $this->screenId;
    }

    public function render(): void
    {
        echo '<div class="wrap askmerra-status">';
        echo '<h1>' . esc_html__('AskMerra sync status', 'askmerra-for-woocommerce') . '</h1>';
        echo '<hr class="wp-header-end">';

        try {
            $status = $this->plugin->get(Status::class);
            $rows = $status->getStorefronts();
            $warnings = $status->getWarnings();
        } catch (\Throwable $e) {
            $this->logger->error('AskMerra status page: the sync status could not be read.', ['exception' => $e]);
            /* translators: %s: the error */
            printf('<div class="notice notice-error inline"><p>%s</p></div></div>', esc_html(sprintf(__('The sync status could not be read: %s', 'askmerra-for-woocommerce'), $e->getMessage())));

            return;
        }

        if ($this->isConsentApiMissing()) {
            $warnings[] = __('Orders are not reported to AskMerra: the analytics consent is set to "WP Consent API", but the WP Consent API plugin is not active. Activate it (with a consent plugin that supports it), or choose another option under Settings, Storefront widget.', 'askmerra-for-woocommerce');
        }

        foreach ($warnings as $warning) {
            printf('<div class="notice notice-warning inline"><p>%s</p></div>', esc_html($warning));
        }

        echo '<p>' . esc_html__('Only products whose content changed are rebuilt and sent. Changes are picked up within about a minute by WooCommerce\'s background jobs; every day missing products are added and products that left the catalog are removed.', 'askmerra-for-woocommerce') . ' ';
        printf(
            '<a href="%s">%s</a> | <a href="%s">%s</a> | <a href="%s">%s</a></p>',
            esc_url(AdminUrls::settings()),
            esc_html__('Settings', 'askmerra-for-woocommerce'),
            esc_url(admin_url('admin.php?page=wc-status&tab=action-scheduler&s=askmerra')),
            esc_html__('Background jobs', 'askmerra-for-woocommerce'),
            esc_url(admin_url('admin.php?page=wc-status&tab=logs&source=askmerra')),
            esc_html__('Logs', 'askmerra-for-woocommerce')
        );

        foreach ($rows as $row) {
            $this->renderStorefront($row);
        }

        if (array_filter($rows, static fn (array $row): bool => $row['mode'] === Config::SYNC_FEED)) {
            $this->renderFeedUrlsSection();
        }

        $this->renderPreview($rows);

        echo '</div>';
    }

    /** One of the status page's buttons. */
    public function handleAction(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to manage AskMerra.', 'askmerra-for-woocommerce'), 403);
        }

        check_admin_referer(self::ACTION);

        $do = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        $storefrontId = isset($_POST['storefront']) ? sanitize_key(wp_unslash($_POST['storefront'])) : '';
        $productId = isset($_POST['product']) ? absint($_POST['product']) : 0;
        $storefront = $this->storefronts->get($storefrontId);
        $redirect = [];

        try {
            $redirect = match ($do) {
                'process' => $this->process($this->required($storefront)),
                'reconcile' => $this->reconcile($this->required($storefront)),
                'rebuild' => $this->rebuild($this->required($storefront)),
                'retry' => $this->retry($this->required($storefront)),
                'feed' => $this->writeFeed($this->required($storefront)),
                'token' => $this->newFeedUrls(),
                'remove' => $this->remove($this->required($storefront)),
                'queue_product' => $this->queueProduct($this->required($storefront), $productId),
                'clear_problem' => $this->clearProblem($this->required($storefront)),
                default => $this->unknown(),
            };
        } catch (ActionRefused $e) {
            $this->messages->add(Messages::ERROR, $e->getMessage());
        } catch (ApiException $e) {
            $this->messages->add(Messages::ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('AskMerra status page: the action failed.', ['action' => $do, 'exception' => $e]);
            /* translators: %s: the error */
            $this->messages->add(Messages::ERROR, sprintf(__('The action failed: %s', 'askmerra-for-woocommerce'), $e->getMessage()));
        }

        wp_safe_redirect(AdminUrls::status($redirect));
        exit;
    }

    private function process(Storefront $storefront): array
    {
        $this->requireSync($storefront);
        wc_set_time_limit(self::PROCESS_SECONDS + 35);
        $stats = $this->plugin->get(QueueProcessor::class)->run(self::PROCESS_SECONDS, [$storefront->id]);

        if ($stats === null) {
            $this->messages->add(Messages::INFO, __('The background job is sending the queue right now. Check again in a minute.', 'askmerra-for-woocommerce'));

            return [];
        }

        $done = array_map('intval', ($stats[$storefront->id] ?? []) + ['sent' => 0, 'unchanged' => 0, 'removed' => 0, 'failed' => 0]);
        $pending = $this->plugin->get(Queue::class)->countPending($storefront->id);

        if (array_sum($done) === 0 && $pending > 0) {
            // Paused (rate limit, AskMerra not answering, key refused) or waiting to be tried again.
            $this->messages->add(Messages::WARNING, sprintf(
                /* translators: %d: number of products */
                _n(
                    'Nothing could be sent right now: AskMerra asked to wait, did not answer or refused the key. %d product waits and is sent automatically; the latest errors are listed below.',
                    'Nothing could be sent right now: AskMerra asked to wait, did not answer or refused the key. %d products wait and are sent automatically; the latest errors are listed below.',
                    $pending,
                    'askmerra-for-woocommerce'
                ),
                $pending
            ));

            return [];
        }

        if (array_sum($done) === 0) {
            $this->messages->add(Messages::INFO, __('Nothing was waiting to be sent.', 'askmerra-for-woocommerce'));
        } else {
            $this->messages->add(Messages::SUCCESS, sprintf(
                /* translators: 1-4: numbers of products */
                __('Sent or updated: %1$d, unchanged: %2$d, removed: %3$d, failed: %4$d.', 'askmerra-for-woocommerce'),
                $done['sent'],
                $done['unchanged'],
                $done['removed'],
                $done['failed']
            ));
        }

        if ($pending > 0) {
            $this->messages->add(Messages::INFO, sprintf(
                /* translators: %d: number of products */
                _n('%d product is still waiting; the background job continues with it.', '%d products are still waiting; the background job continues with them.', $pending, 'askmerra-for-woocommerce'),
                $pending
            ));
        }

        return [];
    }

    private function reconcile(Storefront $storefront): array
    {
        $this->requireSync($storefront);
        $stats = $this->plugin->get(Reconciler::class)->reconcile($storefront);
        $this->messages->add(Messages::SUCCESS, sprintf(
            /* translators: 1: missing products, 2: products that left the catalog, 3: products with sale dates */
            __('Daily check done: %1$d missing products and %2$d products that left the catalog were queued, %3$d with sale prices starting or ending today.', 'askmerra-for-woocommerce'),
            $stats['missing'],
            $stats['gone'],
            $stats['price_dates']
        ));

        return [];
    }

    private function rebuild(Storefront $storefront): array
    {
        $this->requireSync($storefront);
        $stats = $this->plugin->get(Reconciler::class)->rebuild($storefront);
        $this->messages->add(Messages::SUCCESS, sprintf(
            /* translators: %d: number of products */
            _n('%d product queued for the rebuild. The background job sends it within the next minutes if its content changed.', '%d products queued for the rebuild. The background job sends those whose content changed within the next minutes.', $stats['queued'], 'askmerra-for-woocommerce'),
            $stats['queued']
        ));

        return [];
    }

    private function retry(Storefront $storefront): array
    {
        $count = $this->plugin->get(Queue::class)->retryFailed($storefront->id);
        /* translators: %d: number of products */
        $this->messages->add(Messages::SUCCESS, sprintf(_n('%d failed product is tried again.', '%d failed products are tried again.', $count, 'askmerra-for-woocommerce'), $count));

        return [];
    }

    private function writeFeed(Storefront $storefront): array
    {
        $result = $this->plugin->get(FeedGenerator::class)->generate($storefront, true);
        $message = (string) ($result['message'] ?? '');

        match ($result['status'] ?? '') {
            /* translators: %d: number of products */
            'written' => $this->messages->add(Messages::SUCCESS, sprintf(_n('The feed was written: %d product.', 'The feed was written: %d products.', (int) ($result['products'] ?? 0), 'askmerra-for-woocommerce'), (int) ($result['products'] ?? 0))),
            'building' => $this->messages->add(Messages::INFO, $message !== '' ? $message : __('The feed is published once the whole catalog is ready.', 'askmerra-for-woocommerce')),
            'unchanged' => $this->messages->add(Messages::INFO, __('The feed is up to date.', 'askmerra-for-woocommerce')),
            /* translators: %s: the reason */
            'failed' => $this->messages->add(Messages::ERROR, sprintf(__('The feed could not be written: %s', 'askmerra-for-woocommerce'), $message)),
            default => $this->messages->add(Messages::INFO, __('This storefront does not send its catalog as a feed.', 'askmerra-for-woocommerce')),
        };

        return [];
    }

    private function newFeedUrls(): array
    {
        $this->plugin->get(FeedToken::class)->regenerate();
        $this->plugin->get(FeedGenerator::class)->generateAll(true);
        $this->messages->add(Messages::SUCCESS, __('The feeds have new URLs. Update the feed sources in the AskMerra dashboard.', 'askmerra-for-woocommerce'));

        return [];
    }

    private function remove(Storefront $storefront): array
    {
        if (isset($this->storefronts->syncing()[$storefront->id])) {
            $this->messages->add(Messages::ERROR, __('Turn AskMerra off first (Settings, Connection): otherwise the products are sent again at the next daily check.', 'askmerra-for-woocommerce'));

            return [];
        }

        $removed = $this->plugin->get(Remover::class)->removeAll($storefront);
        /* translators: %d: number of products */
        $this->messages->add(Messages::SUCCESS, sprintf(_n('%d product was removed from AskMerra.', '%d products were removed from AskMerra.', $removed, 'askmerra-for-woocommerce'), $removed));

        return [];
    }

    /** "Send this product now" under a preview: queue it and work the queue, then preview it again. */
    private function queueProduct(Storefront $storefront, int $productId): array
    {
        if ($productId <= 0) {
            throw new ActionRefused(__('No product was given.', 'askmerra-for-woocommerce'));
        }

        $this->requireSync($storefront);

        $enqueuer = $this->plugin->get(Enqueuer::class);
        $enqueuer->enqueue([$productId], [$storefront->id]);
        $enqueuer->flush();

        wc_set_time_limit(self::PROCESS_SECONDS + 35);
        $stats = $this->plugin->get(QueueProcessor::class)->run(self::PROCESS_SECONDS, [$storefront->id]);

        $this->messages->add(
            $stats === null ? Messages::INFO : Messages::SUCCESS,
            $stats === null
                /* translators: %d: product ID */
                ? sprintf(__('Product %d is queued: the background job is sending right now and sends it within a minute.', 'askmerra-for-woocommerce'), $productId)
                /* translators: %d: product ID */
                : sprintf(__('Product %d was processed. Its preview below shows the result.', 'askmerra-for-woocommerce'), $productId)
        );

        return ['askmerra_preview' => (string) $productId, 'askmerra_storefront' => $storefront->id];
    }

    private function clearProblem(Storefront $storefront): array
    {
        $this->plugin->get(Problems::class)->clear($storefront->id);

        return [];
    }

    private function unknown(): array
    {
        $this->messages->add(Messages::ERROR, __('Unknown action.', 'askmerra-for-woocommerce'));

        return [];
    }

    /** Orders are reported through the WP Consent API, but no plugin provides it: none ever is. */
    private function isConsentApiMissing(): bool
    {
        return $this->config->getConsentMode() === Config::CONSENT_WP_CONSENT_API
            && $this->config->isWidgetEnabled()
            && $this->config->isPurchaseTrackingEnabled()
            && !function_exists('wp_has_consent');
    }

    private function required(?Storefront $storefront): Storefront
    {
        return $storefront ?? throw new ActionRefused(__('Unknown storefront.', 'askmerra-for-woocommerce'));
    }

    private function requireSync(Storefront $storefront): void
    {
        if (!isset($this->storefronts->syncing()[$storefront->id])) {
            throw new ActionRefused(__('AskMerra does not sync this storefront. Check its settings.', 'askmerra-for-woocommerce'));
        }
    }

    private function renderStorefront(array $row): void
    {
        $storefrontId = (string) $row['storefront'];
        $mode = $row['mode'];
        $modes = [
            Config::SYNC_PUSH => __('Push API', 'askmerra-for-woocommerce'),
            Config::SYNC_FEED => __('Product feed', 'askmerra-for-woocommerce'),
        ];
        $modeLabel = match (true) {
            $mode !== null => $modes[$mode] ?? $mode,
            (bool) $row['missing_key'] => __('Not syncing: no secret key', 'askmerra-for-woocommerce'),
            default => __('Off', 'askmerra-for-woocommerce'),
        };

        echo '<div class="askmerra-storefront">';
        printf(
            '<h2>%s &mdash; %s <a class="askmerra-heading-link" href="%s">%s</a></h2>',
            esc_html((string) $row['name']),
            esc_html($modeLabel),
            esc_url(AdminUrls::settings()),
            esc_html__('Settings', 'askmerra-for-woocommerce')
        );

        if (!empty($row['problem'])) {
            echo '<div class="notice notice-error inline askmerra-problem"><p>';
            echo esc_html(sprintf(
                /* translators: 1: date and time, 2: AskMerra's message */
                __('AskMerra refuses this storefront\'s key since %1$s: %2$s', 'askmerra-for-woocommerce'),
                Format::utc($row['problem']['since'] ?? null),
                (string) ($row['problem']['message'] ?? '')
            ));
            echo '</p>' . $this->button('clear_problem', __('Hide until it happens again', 'askmerra-for-woocommerce'), $storefrontId) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped
        }

        if ($mode === null && (int) $row['products'] === 0) {
            echo '</div>';

            return;
        }

        $tableRows = [
            [__('Language / currency', 'askmerra-for-woocommerce'), esc_html(($row['locale'] ?? __('shop default', 'askmerra-for-woocommerce')) . ' / ' . $row['currency'])],
            [__('Products in AskMerra', 'askmerra-for-woocommerce'), esc_html(number_format_i18n((int) $row['products']))],
            [__('Waiting to be sent', 'askmerra-for-woocommerce'), esc_html(number_format_i18n((int) $row['pending']))],
            [__('Failed', 'askmerra-for-woocommerce'), esc_html(number_format_i18n((int) $row['failed']))],
            [__('Last product sent', 'askmerra-for-woocommerce'), esc_html(Format::utc($row['last_synced_at']))],
            [__('Daily check', 'askmerra-for-woocommerce'), $this->runCell($row['last_reconcile'])],
            [__('Full rebuild', 'askmerra-for-woocommerce'), $this->runCell($row['last_rebuild'])],
        ];

        if (!empty($row['feed'])) {
            $tableRows[] = [__('Feed URL', 'askmerra-for-woocommerce'), $this->feedUrlCell($storefrontId, (string) $row['feed']['url'])];
            $tableRows[] = [__('Feed file', 'askmerra-for-woocommerce'), esc_html($this->feedFileText($row))];
        }

        echo '<table class="widefat striped askmerra-status-table"><tbody>';

        foreach ($tableRows as [$label, $html]) {
            printf('<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html($label), $html); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cells are escaped above
        }

        echo '</tbody></table>';
        echo '<div class="askmerra-actions">';

        if ($mode !== null) {
            echo $this->button('process', __('Send changes now', 'askmerra-for-woocommerce'), $storefrontId, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

            if ($mode === Config::SYNC_FEED) {
                echo $this->button('feed', __('Write the feed now', 'askmerra-for-woocommerce'), $storefrontId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }

            if ((int) $row['failed'] > 0) {
                echo $this->button('retry', __('Retry failed products', 'askmerra-for-woocommerce'), $storefrontId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }

            echo $this->button('reconcile', __('Run the daily check now', 'askmerra-for-woocommerce'), $storefrontId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $this->button( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'rebuild',
                __('Full rebuild', 'askmerra-for-woocommerce'),
                $storefrontId,
                false,
                __('Rebuild every product of this storefront? Only products whose content changed are sent.', 'askmerra-for-woocommerce')
            );
        } else {
            printf(
                '<p>%s</p>',
                esc_html(sprintf(
                    /* translators: %d: number of products */
                    _n('This storefront no longer syncs, but AskMerra still has %d of its products.', 'This storefront no longer syncs, but AskMerra still has %d of its products.', (int) $row['products'], 'askmerra-for-woocommerce'),
                    (int) $row['products']
                ))
            );
            echo $this->button( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'remove',
                __('Remove its products from AskMerra', 'askmerra-for-woocommerce'),
                $storefrontId,
                false,
                __('Remove every product of this storefront from AskMerra? The assistant stops recommending them. A feed source must also be deleted in the AskMerra dashboard.', 'askmerra-for-woocommerce')
            );
        }

        echo '</div>';

        if (!empty($row['errors'])) {
            $this->renderErrors($row['errors']);
        }

        echo '</div>';
    }

    private function renderErrors(array $errors): void
    {
        echo '<h3>' . esc_html__('Latest errors', 'askmerra-for-woocommerce') . '</h3>';
        echo '<table class="widefat striped askmerra-errors"><thead><tr>';

        foreach ([__('Product', 'askmerra-for-woocommerce'), __('Attempts', 'askmerra-for-woocommerce'), __('Next attempt', 'askmerra-for-woocommerce'), __('Error', 'askmerra-for-woocommerce')] as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }

        echo '</tr></thead><tbody>';

        foreach ($errors as $error) {
            $productId = (int) $error['product_id'];
            $title = get_the_title($productId);
            $attempts = (int) $error['attempts'];

            printf(
                '<tr><td><a href="%s">#%d</a> %s</td><td>%d</td><td>%s</td><td>%s</td></tr>',
                esc_url(AdminUrls::product($productId)),
                $productId, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integer
                esc_html($title),
                $attempts, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integer
                esc_html($attempts >= Queue::MAX_ATTEMPTS ? __('none: retry, or change the product', 'askmerra-for-woocommerce') : Format::utc($error['available_at'] ?? null)),
                esc_html((string) ($error['last_error'] ?? ''))
            );
        }

        echo '</tbody></table>';
    }

    private function renderFeedUrlsSection(): void
    {
        echo '<div class="askmerra-storefront">';
        echo '<h2>' . esc_html__('Feed URLs', 'askmerra-for-woocommerce') . '</h2>';
        echo '<p>' . esc_html__('The feed URLs hold a secret part so only AskMerra can find them. If a URL was shared by mistake, give every feed a new one - then update the feed sources in the AskMerra dashboard.', 'askmerra-for-woocommerce') . '</p>';
        echo $this->button( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            'token',
            __('Give the feeds new URLs', 'askmerra-for-woocommerce'),
            null,
            false,
            __('Every feed gets a new URL and the old ones stop working. Update the feed sources in AskMerra afterwards. Continue?', 'askmerra-for-woocommerce')
        );
        echo '</div>';
    }

    private function renderPreview(array $rows): void
    {
        // A read-only GET form, like a search: the page capability is the guard.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $query = isset($_GET['askmerra_preview']) ? trim(sanitize_text_field(wp_unslash($_GET['askmerra_preview']))) : '';
        $requested = isset($_GET['askmerra_storefront']) ? sanitize_key(wp_unslash($_GET['askmerra_storefront'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $syncing = $this->storefronts->syncing();
        $storefront = $this->storefronts->get($requested) ?? ($syncing ? reset($syncing) : $this->storefronts->getDefault());

        echo '<div class="askmerra-storefront askmerra-preview">';
        echo '<h2>' . esc_html__('Preview a product', 'askmerra-for-woocommerce') . '</h2>';
        printf('<form method="get" action="%s">', esc_url(admin_url('admin.php')));
        printf('<input type="hidden" name="page" value="%s" />', esc_attr(AdminUrls::STATUS_PAGE));
        printf(
            '<input type="text" name="askmerra_preview" value="%s" placeholder="%s" class="regular-text" /> ',
            esc_attr($query),
            esc_attr__('Product ID or SKU', 'askmerra-for-woocommerce')
        );

        if (count($rows) > 1) {
            echo '<select name="askmerra_storefront">';

            foreach ($rows as $row) {
                printf('<option value="%s"%s>%s</option>', esc_attr((string) $row['storefront']), selected((string) $row['storefront'], $storefront->id, false), esc_html((string) $row['name']));
            }

            echo '</select> ';
        } else {
            printf('<input type="hidden" name="askmerra_storefront" value="%s" />', esc_attr($storefront->id));
        }

        echo '<button type="submit" class="button">' . esc_html__('Preview', 'askmerra-for-woocommerce') . '</button>';
        echo '</form>';

        if ($query !== '') {
            try {
                $this->renderPreviewResult($this->buildPreview($query, $storefront), $storefront);
            } catch (\Throwable $e) {
                $this->logger->error('AskMerra status page: the preview failed.', ['query' => $query, 'exception' => $e]);
                /* translators: %s: the error */
                printf('<div class="notice notice-error inline"><p>%s</p></div>', esc_html(sprintf(__('The preview failed: %s', 'askmerra-for-woocommerce'), $e->getMessage())));
            }
        }

        echo '</div>';
    }

    /**
     * What the product looks like to AskMerra in a storefront, built now: the payload, or why it is
     * not sent; and whether AskMerra has that version.
     *
     * @return array{title: string, notes: string[], json?: string, product_id?: ?int}
     */
    private function buildPreview(string $query, Storefront $storefront): array
    {
        $productId = $this->findProduct($query);

        if ($productId === null) {
            /* translators: %s: what was searched */
            return ['title' => sprintf(__('No product with the ID or SKU "%s".', 'askmerra-for-woocommerce'), $query), 'notes' => []];
        }

        $notes = [];

        if (get_post_type($productId) === 'product_variation') {
            $parentId = (int) wp_get_post_parent_id($productId);
            /* translators: 1: variation ID, 2: product ID */
            $notes[] = sprintf(__('%1$d is a variation of product %2$d: AskMerra receives the product, with its options.', 'askmerra-for-woocommerce'), $productId, $parentId);
            $productId = $parentId;
        }

        $built = $this->plugin->get(ProductBuilder::class)->build($storefront, [$productId]);
        $sent = $this->plugin->get(State::class)->get($storefront->id, [$productId])[$productId] ?? null;
        $syncing = isset($this->storefronts->syncing()[$storefront->id]);

        if (isset($built['payloads'][$productId])) {
            $payload = $built['payloads'][$productId];
            $notes[] = match (true) {
                $sent === null => __('Not in AskMerra yet.', 'askmerra-for-woocommerce'),
                $sent['payload_hash'] === QueueProcessor::hash($payload) => __('AskMerra has this version.', 'askmerra-for-woocommerce'),
                default => __('AskMerra has an older version; the change is sent by the queue.', 'askmerra-for-woocommerce'),
            };

            return [
                /* translators: %d: product ID */
                'title' => sprintf(__('Product %d as AskMerra receives it:', 'askmerra-for-woocommerce'), $productId),
                'notes' => $notes,
                'json' => (string) wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'product_id' => $syncing ? $productId : null,
            ];
        }

        $reason = (string) ($built['ineligible'][$productId] ?? $built['errors'][$productId] ?? '');

        if ($reason !== '') {
            $notes[] = $reason;
        }

        if ($sent !== null) {
            $notes[] = __('It is still in AskMerra and is removed by the queue.', 'askmerra-for-woocommerce');
        }

        return [
            /* translators: %d: product ID */
            'title' => sprintf(__('Product %d is not sent to AskMerra.', 'askmerra-for-woocommerce'), $productId),
            'notes' => $notes,
            'product_id' => $sent !== null && $syncing ? $productId : null,
        ];
    }

    private function renderPreviewResult(array $preview, Storefront $storefront): void
    {
        echo '<div class="askmerra-preview-result">';
        echo '<p><strong>' . esc_html($preview['title']) . '</strong></p>';

        foreach ($preview['notes'] as $note) {
            echo '<p>' . esc_html($note) . '</p>';
        }

        if (!empty($preview['json'])) {
            echo '<pre class="askmerra-json">' . esc_html($preview['json']) . '</pre>';
        }

        if (!empty($preview['product_id'])) {
            echo $this->button('queue_product', __('Send this product now', 'askmerra-for-woocommerce'), $storefront->id, false, null, ['product' => (int) $preview['product_id']]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        echo '</div>';
    }

    /** A product (or variation) id for an ID, an AskMerra id or a SKU; ids AskMerra still holds count even when deleted. */
    private function findProduct(string $query): ?int
    {
        // The AskMerra id of a product without a SKU, with the SKU identifier: id:<product id>.
        if (preg_match('/^id:(\d+)$/i', $query, $match)) {
            $query = $match[1];
        }

        if (ctype_digit($query)) {
            $productId = (int) $query;

            if (in_array(get_post_type($productId), ['product', 'product_variation'], true)) {
                return $productId;
            }

            foreach (array_keys($this->storefronts->all()) as $storefrontId) {
                if ($this->plugin->get(State::class)->get((string) $storefrontId, [$productId])) {
                    return $productId;
                }
            }
        }

        $productId = (int) wc_get_product_id_by_sku($query);

        if ($productId === 0) {
            // WooCommerce looks SKUs up in wc_product_meta_lookup, which misses products some imports write.
            global $wpdb;

            $productId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT meta.post_id FROM {$wpdb->postmeta} meta
                INNER JOIN {$wpdb->posts} posts ON posts.ID = meta.post_id
                WHERE meta.meta_key = '_sku' AND meta.meta_value = %s
                AND posts.post_type IN ('product', 'product_variation') AND posts.post_status <> 'trash'
                LIMIT 1",
                $query
            ));
        }

        return $productId > 0 ? $productId : null;
    }

    private function runCell(?array $run): string
    {
        if (!$run) {
            return esc_html__('never', 'askmerra-for-woocommerce');
        }

        return esc_html(Format::utc($run['started_at'] ?? null)) . ' <small>' . esc_html(Format::runStats($run)) . '</small>';
    }

    private function feedUrlCell(string $storefrontId, string $url): string
    {
        $id = 'askmerra-status-feed-url-' . $storefrontId;

        return sprintf(
            '<input type="text" readonly="readonly" class="large-text code" id="%s" value="%s" /> <button type="button" class="button" data-askmerra-copy="%s">%s</button>',
            esc_attr($id),
            esc_attr($url),
            esc_attr($id),
            esc_html__('Copy', 'askmerra-for-woocommerce')
        );
    }

    private function feedFileText(array $row): string
    {
        $feed = $row['feed'];

        if (!empty($feed['exists'])) {
            $text = sprintf(
                /* translators: 1: JSON or XML, 2: size in KB, 3: date and time */
                __('%1$s, %2$s KB, %3$s', 'askmerra-for-woocommerce'),
                ($feed['format'] ?? '') === Config::FEED_GOOGLE_XML ? 'XML' : 'JSON',
                number_format_i18n((int) $feed['bytes'] / 1024),
                Format::utc($feed['modified_at'] ?? null)
            );

            return !empty($feed['dirty'])
                ? $text . ' - ' . __('products changed since; rewritten at the next scheduled run', 'askmerra-for-woocommerce')
                : $text;
        }

        if (empty($feed['ready']) && (int) $row['pending'] > 0) {
            return sprintf(
                /* translators: %d: number of products */
                _n('Being prepared: it is published once the whole catalog is ready (%d product to go).', 'Being prepared: it is published once the whole catalog is ready (%d products to go).', (int) $row['pending'], 'askmerra-for-woocommerce'),
                (int) $row['pending']
            );
        }

        return __('Not written yet: it is written at the next scheduled run.', 'askmerra-for-woocommerce');
    }

    /** A POST button for one action of the page. */
    private function button(string $do, string $label, ?string $storefrontId = null, bool $primary = false, ?string $confirm = null, array $extra = []): string
    {
        // No wp_nonce_field(): its id would repeat in every form of the page.
        $fields = '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(self::ACTION)) . '" />'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />'
            . '<input type="hidden" name="do" value="' . esc_attr($do) . '" />';

        if ($storefrontId !== null) {
            $fields .= '<input type="hidden" name="storefront" value="' . esc_attr($storefrontId) . '" />';
        }

        foreach ($extra as $name => $value) {
            $fields .= '<input type="hidden" name="' . esc_attr((string) $name) . '" value="' . esc_attr((string) $value) . '" />';
        }

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="askmerra-action"'
            . ($confirm !== null ? ' data-askmerra-confirm="' . esc_attr($confirm) . '"' : '') . '>'
            . $fields
            . '<button type="submit" class="button' . ($primary ? ' button-primary' : '') . '">' . esc_html($label) . '</button>'
            . '</form>';
    }
}
