<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Catalog;

/**
 * Multilingual shops: WPML and Polylang keep every translation of a product as a product of its
 * own. Version 1 has one storefront, in the shop's default language, so products in another
 * language are left out: each would be one more AskMerra product in the storefront's language
 * (under the same SKU with WooCommerce Multilingual, which copies SKUs). Products without a
 * language - post types the plugin does not translate - are sent. Without WPML or Polylang nothing
 * is left out.
 */
final class Translations
{
    private const WPML = 'wpml';

    private const POLYLANG = 'polylang';

    /**
     * SQL that leaves products in another language out of a query on the posts table: a LEFT JOIN
     * and a WHERE condition (starting with AND), both empty without WPML or Polylang.
     *
     * @param string $idColumn the product id column of the query, e.g. p.ID
     * @return array{join: string, where: string}
     */
    public function getSqlFilter(string $idColumn): array
    {
        global $wpdb;

        $source = $this->getSource();

        if ($source === null) {
            return ['join' => '', 'where' => ''];
        }

        if ($source['plugin'] === self::WPML) {
            return [
                'join' => $wpdb->prepare(
                    " LEFT JOIN {$source['table']} askmerra_lang ON askmerra_lang.element_id = {$idColumn}
                    AND askmerra_lang.element_type = 'post_product' AND askmerra_lang.language_code <> %s",
                    $source['language']
                ),
                'where' => ' AND askmerra_lang.element_id IS NULL',
            ];
        }

        return [
            'join' => $wpdb->prepare(
                " LEFT JOIN {$wpdb->term_relationships} askmerra_lang ON askmerra_lang.object_id = {$idColumn}
                AND askmerra_lang.term_taxonomy_id IN (" . $this->placeholders(count($source['others'])) . ')',
                ...array_keys($source['others'])
            ),
            'where' => ' AND askmerra_lang.object_id IS NULL',
        ];
    }

    /**
     * The language of each of these products that is in another language than the default.
     *
     * @param int[] $productIds
     * @return array<int, string> language code by product id
     */
    public function getOtherLanguages(array $productIds): array
    {
        global $wpdb;

        $source = $productIds ? $this->getSource() : null;

        if ($source === null) {
            return [];
        }

        $languages = [];

        if ($source['plugin'] === self::WPML) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT element_id, language_code FROM {$source['table']}
                WHERE element_type = 'post_product' AND language_code <> %s
                AND element_id IN (" . $this->placeholders(count($productIds)) . ')',
                ...[$source['language'], ...$productIds]
            ), ARRAY_N);

            foreach ($rows as [$productId, $language]) {
                $languages[(int) $productId] = (string) $language;
            }

            return $languages;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT object_id, term_taxonomy_id FROM {$wpdb->term_relationships}
            WHERE term_taxonomy_id IN (" . $this->placeholders(count($source['others'])) . ')
            AND object_id IN (' . $this->placeholders(count($productIds)) . ')',
            ...[...array_keys($source['others']), ...$productIds]
        ), ARRAY_N);

        foreach ($rows as [$productId, $termTaxonomyId]) {
            $languages[(int) $productId] ??= $source['others'][(int) $termTaxonomyId];
        }

        return $languages;
    }

    /**
     * The multilingual plugin in use and its default language, read on each call so long runs see
     * changes: WPML's icl_translations table (a row per post: element_type 'post_product',
     * element_id, language_code), or Polylang's "language" taxonomy (a term per language, its slug
     * the language code, related to each post), with the term_taxonomy_id => code of the other
     * languages. Null without either, or while the plugin is not set up.
     *
     * @return array{plugin: string, language: string, table?: string, others?: array<int, string>}|null
     */
    private function getSource(): ?array
    {
        global $wpdb;

        if (defined('ICL_SITEPRESS_VERSION')) {
            $language = apply_filters('wpml_default_language', null);
            $table = $wpdb->prefix . 'icl_translations';

            if (is_string($language) && $language !== ''
                && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table
            ) {
                return ['plugin' => self::WPML, 'language' => $language, 'table' => $table];
            }
        }

        if (function_exists('pll_default_language') && taxonomy_exists('language')) {
            $language = pll_default_language('slug');

            if (is_string($language) && $language !== '') {
                $others = [];

                foreach ($wpdb->get_results(
                    "SELECT tt.term_taxonomy_id, t.slug FROM {$wpdb->term_taxonomy} tt
                    INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                    WHERE tt.taxonomy = 'language'"
                ) as $row) {
                    if ((string) $row->slug !== $language) {
                        $others[(int) $row->term_taxonomy_id] = (string) $row->slug;
                    }
                }

                // A single language: no product is in another one.
                if ($others) {
                    return ['plugin' => self::POLYLANG, 'language' => $language, 'others' => $others];
                }
            }
        }

        return null;
    }

    private function placeholders(int $count): string
    {
        return implode(',', array_fill(0, $count, '%d'));
    }
}
