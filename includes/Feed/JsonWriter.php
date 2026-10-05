<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Feed;

/**
 * The JSON feed: {"generator": ..., "products": [...], "count": n} with every product exactly as the
 * Push API would receive it. AskMerra maps these field names directly and merges the nested
 * "attributes" object as it is, so every selected attribute arrives.
 */
final class JsonWriter implements FeedWriterInterface
{
    use FileOutput;

    private bool $first = true;

    public function getExtension(): string
    {
        return 'json';
    }

    public function start($handle, array $meta): void
    {
        $this->handle = $handle;
        $this->first = true;

        $header = json_encode(
            $meta ?: new \stdClass(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        // {"generator":...,"currency":"EUR" then the products
        $this->write(($meta ? substr($header, 0, -1) . ',' : '{') . '"products":[');
    }

    public function add(string $productJson): void
    {
        $this->write(($this->first ? "\n" : ",\n") . $productJson);
        $this->first = false;
    }

    public function finish(int $count): void
    {
        $this->write("\n],\"count\":" . $count . "}\n");
        $this->handle = null;
    }
}
