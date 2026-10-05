<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- feed files are streamed; WP_Filesystem cannot stream

declare(strict_types=1);

namespace AskMerra\WooCommerce\Feed;

use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Log\Logger;
use AskMerra\WooCommerce\Plugin;
use AskMerra\WooCommerce\Storefront;
use AskMerra\WooCommerce\StorefrontRepository;
use AskMerra\WooCommerce\Sync\Queue;
use AskMerra\WooCommerce\Sync\Reconciler;
use AskMerra\WooCommerce\Sync\RunLog;
use AskMerra\WooCommerce\Sync\State;

/**
 * Writes each feed storefront's file (uploads/askmerra/feeds/<storefront>-<token>.<json|xml>).
 *
 * Nothing is rebuilt here: the queue keeps every product's finished entry in the askmerra_state
 * table and only rebuilds the products that change. The file is streamed from those entries -
 * seconds for 50,000 products - and only when something changed since it was last written. A
 * storefront's first file waits until its whole catalog is ready: AskMerra removes the products
 * missing from a feed.
 *
 * The sync services are taken from the container when needed: the sync engine uses this class too.
 */
final class FeedGenerator
{
    public const DIRECTORY = 'askmerra/feeds';

    private const PAGE_SIZE = 1000;

    /** A temporary file older than this was left by a run that died. */
    private const STALE_TEMPORARY_SECONDS = 6 * HOUR_IN_SECONDS;

    /** <storefront>-<token>.<json|xml>, and the temporary file it is written as. */
    private const FILE_PATTERN = '/^([A-Za-z0-9_]+)-[a-f0-9]{32}\.(?:json|xml)$/';
    private const TEMPORARY_PATTERN = '/^\.([A-Za-z0-9_]+)-[a-f0-9]{32}\.(?:json|xml)\.[A-Za-z0-9-]+\.tmp$/';

    public function __construct(
        private readonly Config $config,
        private readonly StorefrontRepository $storefronts,
        private readonly FeedFlags $flags,
        private readonly FeedToken $token,
        private readonly Logger $logger,
        private readonly Plugin $plugin
    ) {
    }

    /**
     * Writes the files of every feed storefront that changed, then removes the files no storefront
     * publishes anymore.
     *
     * @return array<string, array> per storefront id, see generate()
     */
    public function generateAll(bool $force = false): array
    {
        $results = [];

        foreach ($this->storefronts->feeding() as $id => $storefront) {
            $results[$id] = $this->generate($storefront, $force);
        }

        try {
            $this->removeUnusedFiles();
        } catch (\Throwable $e) {
            $this->logger->error('Removing unused feed files: ' . $e->getMessage(), ['exception' => $e]);
        }

        return $results;
    }

    /**
     * @return array{status: string, message?: string, products?: int, bytes?: int, url?: string, exists?: bool, modified_at?: ?string}
     *         status: written, unchanged, building (catalog not ready yet), disabled (the storefront
     *         sends no feed) or failed (message says why; the previous file stays)
     */
    public function generate(Storefront $storefront, bool $force = false): array
    {
        if (!isset($this->storefronts->feeding()[$storefront->id])) {
            return ['status' => 'disabled'];
        }

        try {
            return $this->run($storefront, $force);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Feed of storefront %s: %s', $storefront->id, $e->getMessage()), ['exception' => $e]);

            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    /** @return array{exists: bool, url: string, bytes: int, modified_at: ?string} modified_at in UTC */
    public function getInfo(Storefront $storefront): array
    {
        $path = $this->getDirectory() . '/' . $this->getFileName($storefront);
        clearstatcache(true, $path);
        $exists = is_file($path);

        return [
            'exists' => $exists,
            'url' => $this->getUrl($storefront),
            'bytes' => $exists ? (int) filesize($path) : 0,
            'modified_at' => $exists ? gmdate('Y-m-d H:i:s', (int) filemtime($path)) : null,
        ];
    }

    /** The address AskMerra downloads the storefront's feed from (with the storefront's scheme). */
    public function getUrl(Storefront $storefront): string
    {
        $url = wp_upload_dir(null, false)['baseurl'] . '/' . self::DIRECTORY . '/' . $this->getFileName($storefront);
        $scheme = wp_parse_url($storefront->homeUrl, PHP_URL_SCHEME);

        return set_url_scheme($url, is_string($scheme) ? $scheme : null);
    }

    public function getFileName(Storefront $storefront): string
    {
        return sprintf(
            '%s-%s.%s',
            $this->getFileKey($storefront->id),
            $this->token->get(),
            $this->config->getFeedFormat() === Config::FEED_GOOGLE_XML ? 'xml' : 'json'
        );
    }

    /** Removes a storefront's feed files, whatever their token or format. */
    public function deleteFiles(Storefront $storefront): void
    {
        $this->removeFiles($this->getFileKey($storefront->id), [], true);
    }

    /**
     * Removes the files no storefront publishes anymore: storefronts switched to the Push API or
     * turned off (AskMerra would keep importing their outdated file), old tokens and formats, and
     * temporary files of runs that died.
     *
     * @return int files removed
     */
    public function removeUnusedFiles(): int
    {
        $keep = [];

        foreach ($this->storefronts->feeding() as $storefront) {
            $keep[] = $this->getFileName($storefront);
        }

        return $this->removeFiles(null, $keep, false);
    }

    private function run(Storefront $storefront, bool $force): array
    {
        $id = $storefront->id;
        $this->reconciler()->ensureSyncMethod($storefront);

        if (!$this->flags->isReady($id)) {
            $pending = $this->queue()->countPending($id);

            if ($pending > 0 || $this->state()->countPayloads($id) === 0) {
                return [
                    'status' => 'building',
                    'message' => $pending > 0
                        ? sprintf(
                            /* translators: %d: products still waiting in the queue */
                            _n(
                                'The catalog is being prepared (%d product to go); the feed is published once all of it is ready.',
                                'The catalog is being prepared (%d products to go); the feed is published once all of it is ready.',
                                $pending,
                                'askmerra-for-woocommerce'
                            ),
                            $pending
                        )
                        : __('The catalog is being prepared; the feed is published once all of it is ready.', 'askmerra-for-woocommerce'),
                ];
            }

            $this->flags->markReady($id);
        }

        $info = $this->getInfo($storefront);

        if (!$force && $info['exists'] && !$this->flags->isDirty($id)) {
            return ['status' => 'unchanged'] + $info;
        }

        $runId = $this->runLog()->start($id, RunLog::TYPE_FEED);
        // Recorded once the file is in place: a write that fails or dies leaves the storefront
        // dirty, and so does a product that changes while the file is written.
        $version = $this->flags->getVersion($id);

        try {
            $result = $this->write($storefront);
        } catch (\Throwable $e) {
            $this->runLog()->finish($runId, RunLog::STATUS_FAILED, [], $e->getMessage());

            throw $e;
        }

        $this->flags->markWritten($id, $version);
        $this->runLog()->finish($runId, RunLog::STATUS_SUCCESS, ['products' => $result['products'], 'bytes' => $result['bytes']]);

        return ['status' => 'written'] + $result;
    }

    /** @return array{products: int, bytes: int, url: string} */
    private function write(Storefront $storefront): array
    {
        $directory = $this->prepareDirectory();
        $fileName = $this->getFileName($storefront);
        $target = $directory . '/' . $fileName;
        $temporary = sprintf('%s/.%s.%d-%s.tmp', $directory, $fileName, (int) getmypid(), bin2hex(random_bytes(3)));
        $isXml = $this->config->getFeedFormat() === Config::FEED_GOOGLE_XML;
        $writer = $isXml ? new GoogleXmlWriter() : new JsonWriter();
        $count = 0;

        $handle = fopen($temporary, 'wb');

        if ($handle === false) {
            throw new \RuntimeException(sprintf(
                /* translators: %s: file path */
                __('The feed file %s cannot be created.', 'askmerra-for-woocommerce'),
                $temporary
            ));
        }

        try {
            $writer->start($handle, $this->getMeta($storefront, $isXml));
            $after = 0;
            $damaged = [];

            while ($page = $this->state()->getPayloadPage($storefront->id, $after, self::PAGE_SIZE)) {
                if ((int) array_key_last($page) <= $after) {
                    throw new \LogicException('The stored products are not paged in product id order.');
                }

                foreach ($page as $productId => $json) {
                    $after = (int) $productId;

                    if ($json === null || !$this->isProductJson($json)) {
                        $damaged[] = $after;
                        continue;
                    }

                    $writer->add($json);
                    $count++;
                }
            }

            if ($damaged) {
                // Never publish a feed with products missing - AskMerra would deactivate them. The
                // queue rebuilds them and the file is written at the next run.
                $this->state()->invalidate($storefront->id, $damaged);
                $this->queue()->add([$storefront->id], $damaged);

                throw new \RuntimeException(sprintf(
                    /* translators: %d: number of damaged products */
                    _n(
                        '%d stored product was damaged and is being rebuilt; the previous feed file stays until then.',
                        '%d stored products were damaged and are being rebuilt; the previous feed file stays until then.',
                        count($damaged),
                        'askmerra-for-woocommerce'
                    ),
                    count($damaged)
                ));
            }

            $writer->finish($count);
            $closed = fclose($handle);
            $handle = null;

            if (!$closed) {
                throw new \RuntimeException(__('The feed file could not be written (is the disk full?).', 'askmerra-for-woocommerce'));
            }

            // Readable like an uploaded file.
            $stat = stat($directory);
            chmod($temporary, $stat ? ($stat['mode'] & 0666) : 0644);

            if (!rename($temporary, $target)) {
                throw new \RuntimeException(sprintf(
                    /* translators: %s: file path */
                    __('The feed file %s cannot be replaced.', 'askmerra-for-woocommerce'),
                    $target
                ));
            }
        } catch (\Throwable $e) {
            if ($handle !== null) {
                fclose($handle);
            }

            wp_delete_file($temporary);

            throw $e;
        }

        $this->removeFiles($this->getFileKey($storefront->id), [$fileName], false);
        clearstatcache(true, $target);

        return [
            'products' => $count,
            'bytes' => (int) filesize($target),
            'url' => $this->getUrl($storefront),
        ];
    }

    private function getMeta(Storefront $storefront, bool $isXml): array
    {
        $meta = [
            'generator' => 'AskMerra for WooCommerce ' . ASKMERRA_WC_VERSION,
            'generated_at' => gmdate(DATE_ATOM),
            'store' => $storefront->id,
            'store_name' => html_entity_decode($storefront->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'store_url' => $storefront->homeUrl,
            'locale' => $storefront->locale,
            'currency' => $storefront->currency,
        ];

        if ($isXml) {
            $meta['attribute_map'] = $this->getGoogleAttributeMap();
        }

        return $meta;
    }

    /**
     * Global attributes whose slug is one of the attribute names AskMerra knows in a Google feed,
     * keyed by the label products are sent with (e.g. "Culoare" => "color") - the selected
     * attributes and the variant options alike. Other labels are matched by their own name.
     *
     * @return array<string, string>
     */
    private function getGoogleAttributeMap(): array
    {
        $map = [];

        foreach (wc_get_attribute_taxonomies() as $attribute) {
            $name = GoogleXmlWriter::normalize((string) $attribute->attribute_name);

            if (!in_array($name, GoogleXmlWriter::KNOWN_ATTRIBUTES, true)) {
                continue;
            }

            $label = (string) wc_attribute_label(wc_attribute_taxonomy_name((string) $attribute->attribute_name));

            foreach ([$label, html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8')] as $key) {
                $map[$key] ??= $name;
            }
        }

        return $map;
    }

    /** A stored entry is a product object; raw DEFLATE has no checksum, so damage can inflate to garbage. */
    private function isProductJson(string $json): bool
    {
        if (!str_starts_with(ltrim($json), '{')) {
            return false;
        }

        return function_exists('json_validate') ? json_validate($json) : is_array(json_decode($json, true));
    }

    private function getDirectory(): string
    {
        return wp_upload_dir(null, false)['basedir'] . '/' . self::DIRECTORY;
    }

    /**
     * Creates the folder, with index files against folder listings: the file names hold the secret,
     * the files themselves stay downloadable. No .htaccess: "Options -Indexes" answers 500 for the
     * whole folder on Apache hosts that do not allow Options overrides.
     */
    private function prepareDirectory(): string
    {
        $directory = $this->getDirectory();

        if (!wp_mkdir_p($directory) || !is_writable($directory)) {
            throw new \RuntimeException(sprintf(
                /* translators: %s: folder path */
                __('The feed folder %s cannot be created or written to.', 'askmerra-for-woocommerce'),
                $directory
            ));
        }

        $files = [
            dirname($directory) . '/index.php' => "<?php\n// Silence is golden.\n",
            $directory . '/index.php' => "<?php\n// Silence is golden.\n",
            $directory . '/index.html' => '',
        ];

        foreach ($files as $path => $content) {
            if (!is_file($path)) {
                file_put_contents($path, $content);
            }
        }

        return $directory;
    }

    /** Storefront ids as they appear in file names (letters, digits and _). */
    private function getFileKey(string $storefrontId): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_]+/', '_', $storefrontId);
    }

    /**
     * Removes feed files - of one storefront ($fileKey) or of all - except $keep. Temporary files
     * are removed only with $temporary or once stale: another run may be writing them.
     *
     * @param string[] $keep file names
     */
    private function removeFiles(?string $fileKey, array $keep, bool $temporary): int
    {
        $directory = $this->getDirectory();

        if (!is_dir($directory)) {
            return 0;
        }

        $removed = 0;
        $staleBefore = time() - self::STALE_TEMPORARY_SECONDS;

        foreach ((array) scandir($directory) as $name) {
            $name = (string) $name;
            $path = $directory . '/' . $name;

            if (preg_match(self::FILE_PATTERN, $name, $match)) {
                $remove = !in_array($name, $keep, true);
            } elseif (preg_match(self::TEMPORARY_PATTERN, $name, $match)) {
                clearstatcache(true, $path);
                $remove = $temporary || (int) filemtime($path) < $staleBefore;
            } else {
                // The index files and anything else not written here.
                continue;
            }

            if ($remove && ($fileKey === null || $match[1] === $fileKey) && is_file($path)) {
                wp_delete_file($path);
                $removed++;
            }
        }

        return $removed;
    }

    private function state(): State
    {
        return $this->plugin->get(State::class);
    }

    private function queue(): Queue
    {
        return $this->plugin->get(Queue::class);
    }

    private function runLog(): RunLog
    {
        return $this->plugin->get(RunLog::class);
    }

    private function reconciler(): Reconciler
    {
        return $this->plugin->get(Reconciler::class);
    }
}
