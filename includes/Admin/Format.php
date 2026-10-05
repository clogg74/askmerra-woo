<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Admin;

/** How times and run results are shown in the admin. */
final class Format
{
    /** A time stored in UTC, in the site's time zone and date format; "never" when empty. */
    public static function utc(?string $utc): string
    {
        if ($utc === null || $utc === '' || str_starts_with($utc, '0000-00-00')) {
            return __('never', 'askmerra-for-woocommerce');
        }

        try {
            $time = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return $utc;
        }

        return (string) wp_date(get_option('date_format') . ' ' . get_option('time_format'), $time->getTimestamp());
    }

    /** "key: value" pairs of a run's statistics, then its message. */
    public static function runStats(?array $run): string
    {
        if (!$run) {
            return '';
        }

        $parts = [];

        foreach ((array) ($run['stats'] ?? []) as $key => $value) {
            $parts[] = $key . ': ' . (is_scalar($value) ? (string) $value : (string) wp_json_encode($value));
        }

        $text = implode(', ', $parts);

        if (!empty($run['message'])) {
            $text .= ($text !== '' ? ' - ' : '') . $run['message'];
        }

        return $text;
    }
}
