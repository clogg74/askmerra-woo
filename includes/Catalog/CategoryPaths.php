<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Catalog;

/**
 * Product category paths as shoppers see them ("Face > Serums"), and the "excluded categories"
 * setting. The category tree is read once and kept until reset() (ProductBuilder resets it for
 * every batch, so long-running processes see new categories).
 */
final class CategoryPaths
{
    /** AskMerra keeps at most 50 categories of up to 500 characters per product. */
    private const MAX_CATEGORIES = 50;

    private const MAX_LENGTH = 500;

    /** @var array<int, array{name: string, parent: int}>|null */
    private ?array $tree = null;

    /** @var array<int, int[]>|null parent id => child ids */
    private ?array $children = null;

    public function __construct(private readonly TextCleaner $text)
    {
    }

    /**
     * @param int[] $termIds the product's product_cat terms
     * @return string[] most specific first, without the default category ("Uncategorized")
     */
    public function getPaths(array $termIds): array
    {
        $tree = $this->getTree();
        $default = (int) get_option('default_product_cat', 0);
        $paths = [];

        foreach ($termIds as $termId) {
            $termId = (int) $termId;

            if ($termId === $default || !isset($tree[$termId])) {
                continue;
            }

            $names = [];
            $seen = [];

            // Up to the top; $seen guards against a broken tree that loops.
            for ($id = $termId; $id > 0 && isset($tree[$id]) && !isset($seen[$id]); $id = $tree[$id]['parent']) {
                $seen[$id] = true;

                // "|" and ";" separate categories in AskMerra's feed import.
                array_unshift($names, str_replace(['|', ';'], '/', $tree[$id]['name']));
            }

            $names = array_filter($names, static fn (string $name): bool => $name !== '');

            if ($names) {
                $paths[implode(' > ', $names)] = count($names);
            }
        }

        // Deepest first; equal depths keep the order of the product's categories.
        uksort($paths, static fn (string $a, string $b): int => $paths[$b] <=> $paths[$a]);

        return array_map(
            fn (string $path): string => $this->text->limit($path, self::MAX_LENGTH),
            array_slice(array_keys($paths), 0, self::MAX_CATEGORIES)
        );
    }

    /**
     * Whether a product is in an excluded category or below one.
     *
     * @param int[] $termIds
     * @param int[] $excludedIds
     */
    public function isExcluded(array $termIds, array $excludedIds): bool
    {
        if (!$termIds || !$excludedIds) {
            return false;
        }

        return (bool) array_intersect(array_map('intval', $termIds), $this->getDescendantIds($excludedIds));
    }

    /**
     * The categories and all their subcategories.
     *
     * @param int[] $termIds
     * @return int[]
     */
    public function getDescendantIds(array $termIds): array
    {
        if (!$termIds) {
            return [];
        }

        $this->getTree();
        $result = [];
        $pending = array_map('intval', $termIds);

        while ($pending) {
            $id = array_pop($pending);

            if (isset($result[$id])) {
                continue;
            }

            $result[$id] = $id;
            array_push($pending, ...($this->children[$id] ?? []));
        }

        return array_values($result);
    }

    /** Forgets the tree, e.g. between batches of a long-running sync. */
    public function reset(): void
    {
        $this->tree = null;
        $this->children = null;
    }

    /** @return array<int, array{name: string, parent: int}> */
    private function getTree(): array
    {
        if ($this->tree === null) {
            $this->tree = [];
            $this->children = [];
            $terms = get_terms([
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
                'update_term_meta_cache' => false,
            ]);

            foreach (is_array($terms) ? $terms : [] as $term) {
                $id = (int) $term->term_id;
                $parent = (int) $term->parent;
                $this->tree[$id] = [
                    'name' => (string) $this->text->toLine((string) $term->name, self::MAX_LENGTH),
                    'parent' => $parent,
                ];
                $this->children[$parent][] = $id;
            }
        }

        return $this->tree;
    }
}
