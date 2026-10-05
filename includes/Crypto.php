<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce;

/**
 * Encrypts the AskMerra secret key at rest (AES-256-GCM), with a key derived from the site's
 * AUTH_KEY and SECURE_AUTH_SALT. If those salts change, the stored key cannot be read anymore and
 * has to be entered again.
 */
final class Crypto
{
    private const PREFIX = 'askmerra:v1:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    public function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES);

        return $cipher === false ? '' : self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /** The plain value; a value that was never encrypted (set with WP-CLI) is returned as it is, a damaged one as ''. */
    public function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) <= self::IV_BYTES + self::TAG_BYTES) {
            return '';
        }

        $plain = openssl_decrypt(
            substr($raw, self::IV_BYTES + self::TAG_BYTES),
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_BYTES),
            substr($raw, self::IV_BYTES, self::TAG_BYTES)
        );

        return $plain === false ? '' : $plain;
    }

    public function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    private function key(): string
    {
        $material = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '') . 'askmerra';

        return hash('sha256', $material, true);
    }
}
