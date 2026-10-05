<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Feed;

/**
 * Per storefront: whether its feed file is behind its stored products ("dirty"), and whether the
 * store holds the whole catalog yet ("ready"). A feed is never written before it is ready: AskMerra
 * removes every product missing from a feed, so a half-built feed would empty the assistant.
 *
 * Dirty is a change count: each change raises the storefront's version, and a written file records
 * the version it was streamed from once it replaced the previous one. A change made while a file is
 * written, or a write that dies before the file is replaced, leaves the storefront dirty.
 *
 * Options askmerra_feed_dirty (storefront id => {version, written}) and askmerra_feed_ready
 * (storefront id => bool), always read from the database: WordPress caches options for the whole
 * request, and a queue run marking a product while the feed job writes must not work from a value
 * read a minute earlier.
 */
final class FeedFlags
{
    private const DIRTY = 'askmerra_feed_dirty';
    private const READY = 'askmerra_feed_ready';

    public function markDirty(string $storefrontId): void
    {
        $data = $this->read(self::DIRTY);
        [$version, $written] = $this->versions($data, $storefrontId);
        $this->saveVersions($data, $storefrontId, $version + 1, $written);
    }

    /** The file holds everything stored, or there is no file to keep up to date. */
    public function clearDirty(string $storefrontId): void
    {
        $data = $this->read(self::DIRTY);
        [$version] = $this->versions($data, $storefrontId);
        $this->saveVersions($data, $storefrontId, $version, $version);
    }

    public function isDirty(string $storefrontId): bool
    {
        [$version, $written] = $this->versions($this->read(self::DIRTY), $storefrontId);

        return $version > $written;
    }

    /** The changes a file streamed from now on holds: read before the stored products are. */
    public function getVersion(string $storefrontId): int
    {
        return $this->versions($this->read(self::DIRTY), $storefrontId)[0];
    }

    /** A file holding the changes up to $version (getVersion()) replaced the previous one. */
    public function markWritten(string $storefrontId, int $version): void
    {
        $data = $this->read(self::DIRTY);
        [$current, $written] = $this->versions($data, $storefrontId);
        $this->saveVersions($data, $storefrontId, $current, max($written, min($version, $current)));
    }

    public function markReady(string $storefrontId): void
    {
        $this->setReady($storefrontId, true);
    }

    /** The store must be filled again (the storefront switched to a feed, or stopped syncing). */
    public function resetReady(string $storefrontId): void
    {
        $this->setReady($storefrontId, false);
    }

    public function isReady(string $storefrontId): bool
    {
        return !empty($this->read(self::READY)[$storefrontId]);
    }

    /**
     * @return array{0: int, 1: int} the version and the version written; a flag stored as a bool
     *         by an earlier release counts as one change
     */
    private function versions(array $data, string $storefrontId): array
    {
        $entry = $data[$storefrontId] ?? null;

        if (!is_array($entry)) {
            return [$entry ? 1 : 0, 0];
        }

        return [(int) ($entry['version'] ?? 0), (int) ($entry['written'] ?? 0)];
    }

    private function saveVersions(array $data, string $storefrontId, int $version, int $written): void
    {
        $data[$storefrontId] = ['version' => $version, 'written' => $written];
        update_option(self::DIRTY, $data, false);
    }

    private function setReady(string $storefrontId, bool $value): void
    {
        $data = $this->read(self::READY);

        if ((bool) ($data[$storefrontId] ?? false) === $value) {
            return;
        }

        $data[$storefrontId] = $value;
        update_option(self::READY, $data, false);
    }

    private function read(string $option): array
    {
        wp_cache_delete($option, 'options');
        $missing = wp_cache_get('notoptions', 'options');

        if (is_array($missing) && isset($missing[$option])) {
            unset($missing[$option]);
            wp_cache_set('notoptions', $missing, 'options');
        }

        $data = get_option($option, []);

        return is_array($data) ? $data : [];
    }
}
