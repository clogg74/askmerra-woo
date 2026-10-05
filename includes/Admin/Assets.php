<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Admin;

/** The admin script and styles, only on the AskMerra settings, the status page and the products list. */
final class Assets
{
    public function __construct(private readonly StatusPage $statusPage)
    {
    }

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $screenId = $screen instanceof \WP_Screen ? $screen->id : '';
        $settings = AdminUrls::isSettingsScreen();
        $products = $screenId === 'edit-product';

        if (!$settings && !$products && ($screenId === '' || $screenId !== $this->statusPage->getScreenId())) {
            return;
        }

        wp_enqueue_style('askmerra-admin', ASKMERRA_WC_URL . 'assets/admin/admin.css', [], ASKMERRA_WC_VERSION);
        wp_enqueue_script(
            'askmerra-admin',
            ASKMERRA_WC_URL . 'assets/admin/admin.js',
            $products ? ['jquery', 'inline-edit-post'] : ['jquery'],
            ASKMERRA_WC_VERSION,
            ['in_footer' => true]
        );
        wp_localize_script('askmerra-admin', 'AskMerraAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'testAction' => ConnectionTest::ACTION,
            'testNonce' => $settings ? wp_create_nonce(ConnectionTest::ACTION) : '',
            'i18n' => [
                'checking' => __('Checking...', 'askmerra-for-woocommerce'),
                'failed' => __('The check could not run. Reload the page and try again.', 'askmerra-for-woocommerce'),
                'copied' => __('Copied', 'askmerra-for-woocommerce'),
            ],
        ]);
    }
}
