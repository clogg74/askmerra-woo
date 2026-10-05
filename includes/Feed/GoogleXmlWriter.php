<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Feed;

/**
 * The feed as Google Shopping XML (RSS 2.0, g: namespace) - also accepted by Google Merchant
 * Center. AskMerra reads the standard fields; of the attributes it keeps only those with a name it
 * knows (KNOWN_ATTRIBUTES, as in AskMerra's packages/catalog/src/mapping.ts) - the JSON feed keeps
 * them all.
 */
final class GoogleXmlWriter implements FeedWriterInterface
{
    use FileOutput;

    public const KNOWN_ATTRIBUTES = [
        'color', 'colour', 'size', 'material', 'gender', 'age_group', 'pattern', 'condition', 'size_type', 'volume',
        'weight', 'shipping_weight', 'gtin', 'ean', 'skin_type', 'ingredients', 'capacity',
    ];

    /** Google's limit for a description. */
    private const MAX_DESCRIPTION = 5000;

    private const MAX_ADDITIONAL_IMAGES = 10;

    private ?\XMLWriter $xml = null;

    /** @var array<string, string> attribute label => Google element name */
    private array $attributeMap = [];

    public function getExtension(): string
    {
        return 'xml';
    }

    public function start($handle, array $meta): void
    {
        $this->handle = $handle;
        $this->attributeMap = (array) ($meta['attribute_map'] ?? []);

        $this->xml = new \XMLWriter();
        $this->xml->openMemory();
        $this->xml->setIndent(true);
        $this->xml->startDocument('1.0', 'UTF-8');
        $this->xml->startElement('rss');
        $this->xml->writeAttribute('version', '2.0');
        $this->xml->writeAttribute('xmlns:g', 'http://base.google.com/ns/1.0');
        $this->xml->startElement('channel');
        $this->xml->writeElement('title', $this->clean((string) ($meta['store_name'] ?? $meta['store'] ?? 'Products')));
        $this->xml->writeElement('link', $this->clean((string) ($meta['store_url'] ?? '')));
        $this->xml->writeElement('description', $this->clean((string) ($meta['generator'] ?? 'AskMerra product feed')));
        $this->flush();
    }

    public function add(string $productJson): void
    {
        $product = json_decode($productJson, true);

        if (!is_array($product) || !$this->xml) {
            return;
        }

        $xml = $this->xml;
        $xml->startElement('item');
        $this->element('g:id', $product['external_id'] ?? null);
        $this->element('title', $product['name'] ?? null);
        $this->element('description', isset($product['description'])
            ? mb_substr((string) $product['description'], 0, self::MAX_DESCRIPTION)
            : null);
        $this->element('link', $product['url'] ?? null);

        $images = array_values((array) ($product['image_urls'] ?? []));
        $this->element('g:image_link', $images[0] ?? null);

        foreach (array_slice($images, 1, self::MAX_ADDITIONAL_IMAGES) as $image) {
            $this->element('g:additional_image_link', $image);
        }

        $currency = (string) ($product['currency'] ?? '');

        if (isset($product['price'])) {
            $this->element('g:price', trim(number_format((float) $product['price'], 2, '.', '') . ' ' . $currency));
        }

        if (isset($product['sale_price'])) {
            $this->element('g:sale_price', trim(number_format((float) $product['sale_price'], 2, '.', '') . ' ' . $currency));
        }

        $this->element('g:availability', ($product['in_stock'] ?? true) ? 'in_stock' : 'out_of_stock');
        $this->element('g:brand', $product['brand'] ?? null);
        // AskMerra reads <g:mpn> as the SKU.
        $this->element('g:mpn', $product['sku'] ?? null);
        $this->element('g:item_group_id', $product['parent_external_id'] ?? null);

        foreach ((array) ($product['categories'] ?? []) as $category) {
            $this->element('g:product_type', $category);
        }

        if (isset($product['stock_qty'])) {
            $this->element('g:quantity', (string) $product['stock_qty']);
        }

        $this->element('g:content_language', $product['locale'] ?? null);

        foreach ((array) ($product['attributes'] ?? []) as $label => $value) {
            $name = $this->attributeMap[$label] ?? self::normalize((string) $label);

            if (in_array($name, self::KNOWN_ATTRIBUTES, true)) {
                $this->element('g:' . $name, is_array($value) ? implode(', ', $value) : (is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value));
            }
        }

        $xml->endElement();
        $this->flush();
    }

    public function finish(int $count): void
    {
        if (!$this->xml) {
            return;
        }

        $this->xml->endElement();
        $this->xml->endElement();
        $this->xml->endDocument();
        $this->flush();
        $this->xml = null;
        $this->handle = null;
    }

    /** "Skin Type" or "g:Skin type" -> "skin_type", the way AskMerra matches column names. */
    public static function normalize(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/^g:/', '', $name) ?? $name;

        if (class_exists(\Normalizer::class)) {
            $name = (string) \Normalizer::normalize($name, \Normalizer::FORM_KD);
            $name = preg_replace('/\p{Mn}+/u', '', $name) ?? $name;
        }

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $name), '_');
    }

    private function element(string $name, mixed $value): void
    {
        if ($value === null || $value === '' || !$this->xml) {
            return;
        }

        $this->xml->writeElement($name, $this->clean((string) $value));
    }

    /** XML 1.0 has no place for control characters. */
    private function clean(string $value): string
    {
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
    }

    private function flush(): void
    {
        if ($this->xml) {
            $this->write($this->xml->flush());
        }
    }
}
