<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce;

/**
 * Tables, version upgrades, activation and deactivation.
 *
 * Tables ({prefix} = $wpdb->prefix, times in UTC):
 * - {prefix}askmerra_queue: products waiting to be built and sent, one row per storefront and product;
 *   revision counts how often a row was queued again (Sync\Queue).
 * - {prefix}askmerra_state: what AskMerra has, per storefront and product (hash, id, language, stock,
 *   and for feeds the finished entry, deflated JSON); indexed by id, so the sync finds the product
 *   holding an id.
 * - {prefix}askmerra_run: rebuilds, daily checks, feed files and removals, for the status page.
 *
 * Version 2: askmerra_queue.revision and the askmerra_state id index.
 */
final class Install
{
    public const DB_VERSION = '2';

    private const OPTION_DB_VERSION = 'askmerra_db_version';

    public static function activate(): void
    {
        self::createTables();
        // Autoloaded: maybeUpgrade() reads it on every request.
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION, true);

        if (!preg_match('/^[a-f0-9]{32}$/', (string) get_option(Config::OPTION_FEED_TOKEN, ''))) {
            update_option(Config::OPTION_FEED_TOKEN, bin2hex(random_bytes(16)), false);
        }

        // Background jobs are (re)scheduled on the next request by Sync\Scheduler.
        update_option('askmerra_needs_scheduling', 'yes', false);
    }

    public static function deactivate(): void
    {
        if (class_exists(Sync\Scheduler::class)) {
            Sync\Scheduler::unscheduleAll();
        }
    }

    /** Creates or updates the tables after the plugin was updated. */
    public static function maybeUpgrade(): void
    {
        if (get_option(self::OPTION_DB_VERSION) !== self::DB_VERSION) {
            self::activate();
        }
    }

    public static function table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix . 'askmerra_' . $name;
    }

    public static function createTables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $collate = $wpdb->get_charset_collate();
        $queue = self::table('queue');
        $state = self::table('state');
        $run = self::table('run');

        dbDelta("CREATE TABLE {$queue} (
  queue_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  storefront varchar(32) NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  available_at datetime NOT NULL,
  created_at datetime NOT NULL,
  last_error text NULL,
  revision int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (queue_id),
  UNIQUE KEY storefront_product (storefront,product_id),
  KEY storefront_due (storefront,attempts,available_at)
) {$collate};");

        dbDelta("CREATE TABLE {$state} (
  storefront varchar(32) NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  external_id varchar(255) NOT NULL,
  locale varchar(8) NULL,
  payload_hash varchar(40) NOT NULL,
  in_stock tinyint(1) NULL,
  payload mediumblob NULL,
  synced_at datetime NOT NULL,
  PRIMARY KEY  (storefront,product_id),
  KEY storefront_external (storefront,external_id(191))
) {$collate};");

        dbDelta("CREATE TABLE {$run} (
  run_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  storefront varchar(32) NOT NULL,
  type varchar(16) NOT NULL,
  status varchar(16) NOT NULL,
  started_at datetime NOT NULL,
  finished_at datetime NULL,
  stats text NULL,
  message text NULL,
  PRIMARY KEY  (run_id),
  KEY storefront_type_started (storefront,type,started_at)
) {$collate};");
    }

    /** Drops the tables (uninstall). */
    public static function dropTables(): void
    {
        global $wpdb;

        foreach (['queue', 'state', 'run'] as $name) {
            $wpdb->query('DROP TABLE IF EXISTS ' . self::table($name)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        delete_option(self::OPTION_DB_VERSION);
    }
}
