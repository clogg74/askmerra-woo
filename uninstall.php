<?php
/**
 * Deleting the plugin (Plugins > Delete, or wp plugin uninstall) removes everything it stored, on
 * every site of a network: the background jobs, the tables, the settings, the feed files, the
 * "Hide from AskMerra" product setting and the mark of reported orders.
 *
 * Products stay in AskMerra: remove them first with `wp askmerra remove` (or "Remove from
 * AskMerra" on the Sync status page), and delete feed sources in the AskMerra dashboard.
 *
 * WordPress includes only this file: the plugin is not booted.
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// The plugin's classes (Install), without the plugin.
spl_autoload_register(static function (string $class): void {
    $prefix = 'AskMerra\\WooCommerce\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = __DIR__ . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

$askmerraUninstallSite = static function (): void {
    global $wpdb;

    // Background jobs (Action Scheduler, group "askmerra"); without WooCommerce, in its tables.
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('', [], 'askmerra');
    } else {
        $actions = $wpdb->prefix . 'actionscheduler_actions';
        $groups = $wpdb->prefix . 'actionscheduler_groups';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $actions)) === $actions) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names
            $wpdb->query($wpdb->prepare("UPDATE {$actions} a INNER JOIN {$groups} g ON g.group_id = a.group_id SET a.status = 'canceled' WHERE g.slug = %s AND a.status = 'pending'", 'askmerra'));
        }
    }

    \AskMerra\WooCommerce\Install::dropTables();

    // Settings, flags and transients.
    $names = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('askmerra_') . '%',
        $wpdb->esc_like('_transient_askmerra_') . '%',
        $wpdb->esc_like('_site_transient_askmerra_') . '%'
    ));

    foreach ($names as $name) {
        if (str_starts_with($name, '_transient_')) {
            delete_transient(substr($name, strlen('_transient_')));
        } elseif (str_starts_with($name, '_site_transient_')) {
            delete_site_transient(substr($name, strlen('_site_transient_')));
        } else {
            delete_option($name);
        }
    }

    // Per-user settings (dismissed notices...).
    $userKeys = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
        $wpdb->esc_like('askmerra_') . '%',
        $wpdb->esc_like('_askmerra_') . '%'
    ));

    foreach ($userKeys as $key) {
        delete_metadata('user', 0, $key, '', true);
    }

    // "Hide from AskMerra" on products.
    delete_post_meta_by_key('_askmerra_exclude');

    // Orders already reported, in both order storages (posts and HPOS).
    delete_post_meta_by_key('_askmerra_reported');
    $ordersMeta = $wpdb->prefix . 'wc_orders_meta';

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ordersMeta)) === $ordersMeta) {
        $wpdb->delete($ordersMeta, ['meta_key' => '_askmerra_reported']); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
    }

    // Feed files: uploads/askmerra.
    $uploads = wp_upload_dir(null, false);
    $base = realpath((string) $uploads['basedir']);
    $directory = $base !== false ? realpath($base . '/askmerra') : false;

    if ($directory !== false && is_dir($directory) && str_starts_with($directory, $base . DIRECTORY_SEPARATOR)) {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname()); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
            } else {
                wp_delete_file($item->getPathname());
            }
        }

        rmdir($directory); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    }
};

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $askmerraSiteId) {
        switch_to_blog((int) $askmerraSiteId);
        $askmerraUninstallSite();
        restore_current_blog();
    }
} else {
    $askmerraUninstallSite();
}
