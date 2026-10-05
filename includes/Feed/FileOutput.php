<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Feed;

/**
 * Writes to an open feed file and fails loudly: a feed that silently lost its end (disk full) would
 * make AskMerra deactivate every product missing from it.
 */
trait FileOutput
{
    /** @var resource|null */
    private $handle = null;

    private function write(string $data): void
    {
        if ($data === '') {
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streamed, WP_Filesystem cannot
        if ($this->handle === null || fwrite($this->handle, $data) !== strlen($data)) {
            throw new \RuntimeException(__('The feed file could not be written (is the disk full?).', 'askmerra-for-woocommerce'));
        }
    }
}
