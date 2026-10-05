<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Feed;

/** Streams a feed file product by product, so its size never matters to PHP's memory. */
interface FeedWriterInterface
{
    public function getExtension(): string;

    /**
     * @param resource $handle the open file
     * @param array $meta generator, generated_at, store, store_name, store_url, locale, currency; for
     *                    Google XML also attribute_map (attribute label => Google element name)
     * @throws \RuntimeException when the file cannot be written
     */
    public function start($handle, array $meta): void;

    /**
     * @param string $productJson one product, as built for the Push API
     * @throws \RuntimeException when the file cannot be written
     */
    public function add(string $productJson): void;

    /** @throws \RuntimeException when the file cannot be written */
    public function finish(int $count): void;
}
