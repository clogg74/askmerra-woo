<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Sync;

use AskMerra\WooCommerce\Install;

/** askmerra_run: rebuilds, daily checks, feed files and removals, for the status page. */
final class RunLog
{
    public const TYPE_REBUILD = 'rebuild';
    public const TYPE_RECONCILE = 'reconcile';
    public const TYPE_FEED = 'feed';
    public const TYPE_REMOVE_ALL = 'remove_all';

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REPLACED = 'replaced';

    public function start(string $storefrontId, string $type, array $stats = []): int
    {
        global $wpdb;

        $wpdb->insert($this->table(), [
            'storefront' => $storefrontId,
            'type' => $type,
            'status' => self::STATUS_RUNNING,
            'started_at' => gmdate('Y-m-d H:i:s'),
            'stats' => (string) wp_json_encode($stats),
        ]);

        return (int) $wpdb->insert_id;
    }

    public function finish(int $runId, string $status, array $stats = [], ?string $message = null): void
    {
        global $wpdb;

        $current = $this->get($runId);

        $wpdb->update($this->table(), [
            'status' => $status,
            'finished_at' => gmdate('Y-m-d H:i:s'),
            'stats' => (string) wp_json_encode(array_merge($current['stats'] ?? [], $stats)),
            'message' => $message === null ? null : Db::text($message, 4000),
        ], ['run_id' => $runId]);
    }

    public function get(int $runId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE run_id = %d", $runId), ARRAY_A);

        return $row ? $this->decode($row) : null;
    }

    /** The run of this type still going in a storefront. */
    public function getRunning(string $storefrontId, string $type): ?array
    {
        return $this->fetchLatest($storefrontId, $type, self::STATUS_RUNNING);
    }

    public function getLast(string $storefrontId, string $type): ?array
    {
        return $this->fetchLatest($storefrontId, $type, null);
    }

    /** Forgets runs older than $days days. */
    public function cleanup(int $days = 30): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE started_at < %s AND status <> %s",
            gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS),
            self::STATUS_RUNNING
        ));
    }

    private function fetchLatest(string $storefrontId, string $type, ?string $status): ?array
    {
        global $wpdb;

        $row = $status === null
            ? $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE storefront = %s AND type = %s ORDER BY run_id DESC LIMIT 1",
                $storefrontId,
                $type
            ), ARRAY_A)
            : $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE storefront = %s AND type = %s AND status = %s ORDER BY run_id DESC LIMIT 1",
                $storefrontId,
                $type,
                $status
            ), ARRAY_A);

        return $row ? $this->decode($row) : null;
    }

    /**
     * @return array{run_id: int, storefront: string, type: string, status: string, started_at: string, finished_at: ?string, stats: array, message: ?string}
     */
    private function decode(array $row): array
    {
        $stats = json_decode((string) $row['stats'], true);

        return [
            'run_id' => (int) $row['run_id'],
            'storefront' => (string) $row['storefront'],
            'type' => (string) $row['type'],
            'status' => (string) $row['status'],
            'started_at' => (string) $row['started_at'],
            'finished_at' => $row['finished_at'] === null ? null : (string) $row['finished_at'],
            'stats' => is_array($stats) ? $stats : [],
            'message' => $row['message'] === null ? null : (string) $row['message'],
        ];
    }

    private function table(): string
    {
        return Install::table('run');
    }
}
