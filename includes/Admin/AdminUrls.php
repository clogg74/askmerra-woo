<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Admin;

/** Addresses of the AskMerra admin screens. */
final class AdminUrls
{
    public const SETTINGS_TAB = 'askmerra';
    public const STATUS_PAGE = 'askmerra-status';

    /** WooCommerce > Settings > AskMerra, at a section ('' = Connection). */
    public static function settings(string $section = ''): string
    {
        $args = ['page' => 'wc-settings', 'tab' => self::SETTINGS_TAB];

        if ($section !== '') {
            $args['section'] = $section;
        }

        return add_query_arg($args, admin_url('admin.php'));
    }

    /** WooCommerce > AskMerra (the sync status page). */
    public static function status(array $args = []): string
    {
        return add_query_arg(['page' => self::STATUS_PAGE] + $args, admin_url('admin.php'));
    }

    public static function product(int $productId): string
    {
        return (string) (get_edit_post_link($productId, 'raw') ?: admin_url('post.php?post=' . $productId . '&action=edit'));
    }

    /** Whether this request shows one of the AskMerra settings sections. */
    public static function isSettingsScreen(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only decides which screen this is
        return is_admin() && ($_GET['page'] ?? '') === 'wc-settings' && ($_GET['tab'] ?? '') === self::SETTINGS_TAB;
    }
}
