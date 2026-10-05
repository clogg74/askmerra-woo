<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Admin;

use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Plugin;
use AskMerra\WooCommerce\StorefrontRepository;
use AskMerra\WooCommerce\Sync\Problems;

/**
 * Admin notices: the results of AskMerra actions after their redirect, a warning while AskMerra
 * refuses the catalog (key refused, shop suspended), and an invitation to connect after install.
 */
final class Notices
{
    private const DISMISS_ACTION = 'askmerra_dismiss_connect';
    private const DISMISSED_META = 'askmerra_connect_notice_dismissed';

    public function __construct(
        private readonly Plugin $plugin,
        private readonly Config $config,
        private readonly StorefrontRepository $storefronts,
        private readonly Messages $messages
    ) {
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
        add_action('admin_post_' . self::DISMISS_ACTION, [$this, 'dismissConnect']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        Messages::render($this->messages->pull());

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $screenId = $screen instanceof \WP_Screen ? $screen->id : '';

        if (!$this->isShopScreen($screenId)) {
            return;
        }

        // The status page shows each storefront's problem itself.
        if (!str_ends_with($screenId, '_page_' . AdminUrls::STATUS_PAGE)) {
            $this->renderProblems();
        }

        $this->renderConnect();
    }

    public function dismissConnect(): void
    {
        check_admin_referer(self::DISMISS_ACTION);

        if (current_user_can('manage_woocommerce')) {
            update_user_meta(get_current_user_id(), self::DISMISSED_META, '1');
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    private function renderProblems(): void
    {
        try {
            $problems = $this->plugin->get(Problems::class)->all();
        } catch (\Throwable) {
            return;
        }

        if (!$problems) {
            return;
        }

        $names = [];

        foreach (array_keys($problems) as $storefrontId) {
            $names[] = $this->storefronts->get((string) $storefrontId)?->name ?? '#' . $storefrontId;
        }

        printf(
            '<div class="notice notice-error askmerra-notice"><p>%s <a href="%s">%s</a></p></div>',
            esc_html(sprintf(
                /* translators: %s: storefront names */
                __('AskMerra does not accept the catalog of %s: the secret key was refused or the AskMerra shop is suspended. Changes are kept and sent once fixed.', 'askmerra-for-woocommerce'),
                implode(', ', $names)
            )),
            esc_url(AdminUrls::status()),
            esc_html__('See the sync status', 'askmerra-for-woocommerce')
        );
    }

    /** Shown until a key is entered or the user dismisses it. */
    private function renderConnect(): void
    {
        if ((string) get_option(Config::OPTION_SECRET_KEY, '') !== ''
            || $this->config->getSiteKey() !== ''
            || AdminUrls::isSettingsScreen()
            || get_user_meta(get_current_user_id(), self::DISMISSED_META, true)
        ) {
            return;
        }

        printf(
            '<div class="notice notice-info askmerra-notice"><p><strong>%s</strong> %s</p><p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p></div>',
            esc_html__('AskMerra is installed.', 'askmerra-for-woocommerce'),
            esc_html__('Connect it to your AskMerra shop with the keys from the AskMerra dashboard (API keys): the assistant then learns your catalog and appears on the storefront.', 'askmerra-for-woocommerce'),
            esc_url(AdminUrls::settings()),
            esc_html__('Connect AskMerra', 'askmerra-for-woocommerce'),
            esc_url(wp_nonce_url(add_query_arg('action', self::DISMISS_ACTION, admin_url('admin-post.php')), self::DISMISS_ACTION)),
            esc_html__('Dismiss', 'askmerra-for-woocommerce')
        );
    }

    /** The dashboard, the plugins list, products and the WooCommerce screens. */
    private function isShopScreen(string $screenId): bool
    {
        return in_array($screenId, ['dashboard', 'plugins', 'edit-product', 'product', 'toplevel_page_woocommerce'], true)
            || str_starts_with($screenId, 'woocommerce_page_');
    }
}
