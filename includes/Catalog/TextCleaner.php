<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Catalog;

/**
 * Text as AskMerra wants it. The Push API stores descriptions as they arrive - it does not convert
 * HTML the way its feed import does - so every text is turned into plain text here.
 */
final class TextCleaner
{
    /** Shortcode tags of page builders, also when the builder is no longer active. */
    private const BUILDER_SHORTCODES = '(?:vc|et_pb|fusion|av|cs|ux|mk|tatsu|themify|su|fl|tm|wpb)_[\w-]*';

    /**
     * HTML, block markup and shortcodes to plain text, cut to $maxLength at a word boundary; null
     * when nothing is left. Lists stay "- item" lines.
     */
    public function toPlainText(?string $html, int $maxLength): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $text = $this->stripShortcodes(mb_scrub($html, 'UTF-8'));
        $text = preg_replace('/<!--.*?-->/s', ' ', $text) ?? $text;
        $text = preg_replace('#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $text) ?? $text;
        $text = preg_replace('#<li\b[^>]*>#i', "\n- ", $text) ?? $text;
        $text = preg_replace(
            '#<br\s*/?>|</?(p|div|li|ul|ol|h[1-6]|tr|table|section|article|blockquote|figure|figcaption|header|footer|dd|dt)\b[^>]*>#i',
            "\n",
            $text
        ) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n\s*/u', "\n", $text) ?? $text;
        $text = trim($text);

        return $text === '' ? null : $this->limit($text, $maxLength, true);
    }

    /** One line of text (names, labels): tags removed, whitespace collapsed, cut to $maxLength. */
    public function toLine(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = html_entity_decode(strip_tags(mb_scrub($value, 'UTF-8')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return $text === '' ? null : $this->limit($text, $maxLength);
    }

    /**
     * Cuts text to $maxLength as AskMerra counts it - in UTF-16 code units, like JavaScript - so an
     * emoji counts twice. With $atWord, a cut word is dropped (when a space is near the end).
     */
    public function limit(string $text, int $maxLength, bool $atWord = false): string
    {
        // UTF-8 never takes fewer bytes than UTF-16 code units.
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        $result = mb_substr($text, 0, $maxLength, 'UTF-8');
        $units = $this->units($result);

        // Characters outside the Basic Multilingual Plane count twice: drop them from the end until it fits.
        while ($units > $maxLength) {
            $last = mb_substr($result, -1, null, 'UTF-8');
            $result = mb_substr($result, 0, -1, 'UTF-8');
            $units -= strlen($last) === 4 ? 2 : 1;
        }

        if ($atWord && $result !== $text) {
            $next = mb_substr($text, mb_strlen($result, 'UTF-8'), 1, 'UTF-8');

            if (!preg_match('/^\s$/u', $next) && preg_match('/^(.*)\s\S*$/su', $result, $match) && mb_strlen($match[1], 'UTF-8') >= mb_strlen($result, 'UTF-8') - 100) {
                $result = $match[1];
            }
        }

        return rtrim($result);
    }

    /** The length AskMerra counts: UTF-16 code units. */
    public function units(string $text): int
    {
        return intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * Removes shortcode tags but keeps the text they enclose: strip_shortcodes() would drop whole
     * descriptions built with WPBakery or Divi, which wrap every paragraph in a shortcode.
     */
    private function stripShortcodes(string $text): string
    {
        if (!str_contains($text, '[')) {
            return $text;
        }

        global $shortcode_tags;

        $names = array_map(static fn (string $tag): string => preg_quote($tag, '/'), array_keys((array) $shortcode_tags));
        $names[] = self::BUILDER_SHORTCODES;

        return preg_replace('/\[\[?\/?(?:' . implode('|', $names) . ')(?=[\s\]\/])[^\]]*\]\]?/u', ' ', $text) ?? $text;
    }
}
