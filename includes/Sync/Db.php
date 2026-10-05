<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Sync;

/**
 * $wpdb helpers for the sync tables: multi-row upserts (with NULLs and binary values) and integer
 * IN lists.
 */
final class Db
{
    /** Rows per INSERT statement. */
    private const MAX_ROWS = 500;

    /** Bytes of values per INSERT statement, well under MySQL's max_allowed_packet. */
    private const MAX_BYTES = 4_000_000;

    /**
     * INSERT ... ON DUPLICATE KEY UPDATE of many rows, in chunks. Values: null => NULL, int and bool
     * => %d, other values => %s. Binary columns are sent as UNHEX('...'): WordPress refuses a query
     * holding bytes that are not valid text.
     *
     * @param string[] $columns
     * @param array[] $rows values in column order
     * @param array<int|string, string> $update columns that take the new value when the row exists, and
     *                                          column => SQL expression for those computed from the row
     *                                          (e.g. 'revision' => 'revision + 1')
     * @param string[] $binary columns holding binary strings
     */
    public static function upsert(string $table, array $columns, array $rows, array $update, array $binary = []): void
    {
        global $wpdb;

        $prefix = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES ';
        $suffix = ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(
            static fn (int|string $key, string $value): string => is_int($key)
                ? $value . ' = VALUES(' . $value . ')'
                : $key . ' = ' . $value,
            array_keys($update),
            $update
        ));
        $binaryIndexes = array_flip(array_keys(array_intersect($columns, $binary)));
        $tuples = [];
        $args = [];
        $bytes = 0;

        foreach ($rows as $row) {
            $placeholders = [];

            foreach (array_values($row) as $index => $value) {
                if ($value === null) {
                    $placeholders[] = 'NULL';
                } elseif (isset($binaryIndexes[$index])) {
                    $placeholders[] = 'UNHEX(%s)';
                    $args[] = bin2hex((string) $value);
                    $bytes += 2 * strlen((string) $value);
                } elseif (is_int($value) || is_bool($value)) {
                    $placeholders[] = '%d';
                    $args[] = (int) $value;
                } else {
                    $placeholders[] = '%s';
                    $args[] = (string) $value;
                    $bytes += strlen((string) $value);
                }
            }

            $tuples[] = '(' . implode(', ', $placeholders) . ')';

            if (count($tuples) >= self::MAX_ROWS || $bytes >= self::MAX_BYTES) {
                $wpdb->query($wpdb->prepare($prefix . implode(', ', $tuples) . $suffix, $args)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $tuples = [];
                $args = [];
                $bytes = 0;
            }
        }

        if ($tuples) {
            $wpdb->query($wpdb->prepare($prefix . implode(', ', $tuples) . $suffix, $args)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    /**
     * A comma-separated list of integers for an IN (...) clause; callers make sure it is not empty.
     *
     * @param array<int|string> $ids
     */
    public static function ids(array $ids): string
    {
        return implode(',', array_map('intval', $ids));
    }

    /**
     * An option as the database has it now, not as this request cached it: a queue run lasts long
     * enough for the admin or another run to change it in between.
     */
    public static function freshOption(string $option, mixed $default): mixed
    {
        wp_cache_delete($option, 'options');
        $missing = wp_cache_get('notoptions', 'options');

        if (is_array($missing) && isset($missing[$option])) {
            unset($missing[$option]);
            wp_cache_set('notoptions', $missing, 'options');
        }

        return get_option($option, $default);
    }

    /** Text safe to store in a utf8mb4 column: invalid bytes dropped, at most $length characters. */
    public static function text(string $value, int $length): string
    {
        return mb_substr(wp_check_invalid_utf8($value, true), 0, $length);
    }
}
