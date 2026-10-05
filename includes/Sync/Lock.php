<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Sync;

/**
 * A MySQL named lock (GET_LOCK): held by the database connection, so it is released even when PHP
 * dies mid-run. Lock names are global to the database server, hence the site's database and table
 * prefix in the name.
 */
final class Lock
{
    public function __construct(private readonly string $name)
    {
    }

    /** Takes the lock without waiting; false when another process holds it. */
    public function acquire(): bool
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $this->key())) === '1';
    }

    public function release(): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->key()));
    }

    private function key(): string
    {
        global $wpdb;

        // At most 64 characters.
        return 'askmerra:' . md5((defined('DB_NAME') ? DB_NAME : '') . '|' . $wpdb->prefix . '|' . $this->name);
    }
}
