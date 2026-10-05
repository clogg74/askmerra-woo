<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Frontend;

/** JSON for inline scripts: "</script>" and "<!--" cannot end or confuse the script element. */
final class Json
{
    public static function encode(array $data): string
    {
        return (string) wp_json_encode(
            $data ?: new \stdClass(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
