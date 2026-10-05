<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Admin;

/** "Settings" and "Sync status" under the plugin's name on the Plugins screen. */
final class PluginLinks
{
    public function register(): void
    {
        add_filter('plugin_action_links_' . plugin_basename(ASKMERRA_WC_FILE), [$this, 'addLinks']);
    }

    /** @param array<string, string> $links */
    public function addLinks(array $links): array
    {
        if (!current_user_can('manage_woocommerce')) {
            return $links;
        }

        return [
            'askmerra-settings' => sprintf('<a href="%s">%s</a>', esc_url(AdminUrls::settings()), esc_html__('Settings', 'askmerra-for-woocommerce')),
            'askmerra-status' => sprintf('<a href="%s">%s</a>', esc_url(AdminUrls::status()), esc_html__('Sync status', 'askmerra-for-woocommerce')),
        ] + $links;
    }
}
