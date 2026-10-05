<?php

declare(strict_types=1);

namespace AskMerra\WooCommerce\Api;

use AskMerra\WooCommerce\Config;
use AskMerra\WooCommerce\Log\Logger;

/**
 * The AskMerra Push API: the only server-to-server API AskMerra offers.
 *
 *  GET  /v1/catalog/ping                      -> {ok, shopId, shopName}
 *  POST /v1/catalog/products:batchUpsert      {products: 1-500, locale?} -> {received, created, updated, unchanged, failed, errors[]}
 *  POST /v1/catalog/products:batchDelete      {external_ids: 1-1000, locale?} -> {deleted}
 *
 * Authenticated with the shop's secret key as a bearer token. AskMerra allows 120 requests per
 * minute per key and 10 MB per upsert (1 MB for everything else).
 */
final class Client
{
    /** Largest upsert body we send; AskMerra refuses more than 10 MB. */
    public const MAX_UPSERT_BYTES = 9_500_000;

    public const MAX_DELETE_IDS = 1000;

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /** Bytes of a request or response body written to the debug log. */
    private const LOG_BYTES = 4096;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger
    ) {
    }

    /**
     * Checks a key; the overrides let the admin test values that are not saved yet.
     *
     * @return array{ok: bool, shopId: string, shopName: string}
     * @throws ApiException
     */
    public function ping(?string $secretKey = null, ?string $apiUrl = null): array
    {
        $response = $this->request('GET', '/v1/catalog/ping', null, $secretKey, $apiUrl);

        return [
            'ok' => (bool) ($response['ok'] ?? false),
            'shopId' => (string) ($response['shopId'] ?? ''),
            'shopName' => (string) ($response['shopName'] ?? ''),
        ];
    }

    /**
     * Creates or replaces products. Every field left out is cleared on AskMerra's side, so each
     * product is always sent complete. A body over MAX_UPSERT_BYTES is not sent: the call fails as
     * "payload too large" (413), and the sync splits the batch.
     *
     * @param array[] $products 1-500 products in AskMerra's ProductInput shape
     * @return array{received: int, created: int, updated: int, unchanged: int, failed: int, errors: array}
     * @throws ApiException
     */
    public function batchUpsert(array $products, ?string $locale): array
    {
        if (!$products) {
            return ['received' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'errors' => []];
        }

        $body = ['products' => array_values($products)];

        if ($locale !== null) {
            $body['locale'] = $locale;
        }

        $response = $this->request('POST', '/v1/catalog/products:batchUpsert', $body, null, null, self::MAX_UPSERT_BYTES);

        return [
            'received' => (int) ($response['received'] ?? 0),
            'created' => (int) ($response['created'] ?? 0),
            'updated' => (int) ($response['updated'] ?? 0),
            'unchanged' => (int) ($response['unchanged'] ?? 0),
            'failed' => (int) ($response['failed'] ?? 0),
            'errors' => is_array($response['errors'] ?? null) ? $response['errors'] : [],
        ];
    }

    /**
     * Removes products from the assistant (AskMerra deactivates them; sending them again brings
     * them back). More than MAX_DELETE_IDS ids are sent in several calls.
     *
     * @param string[] $externalIds
     * @return int products AskMerra deactivated
     * @throws ApiException
     */
    public function batchDelete(array $externalIds, ?string $locale): int
    {
        $deleted = 0;

        foreach (array_chunk(array_values(array_map('strval', $externalIds)), self::MAX_DELETE_IDS) as $chunk) {
            $body = ['external_ids' => $chunk];

            if ($locale !== null) {
                $body['locale'] = $locale;
            }

            $response = $this->request('POST', '/v1/catalog/products:batchDelete', $body);
            $deleted += (int) ($response['deleted'] ?? 0);
        }

        return $deleted;
    }

    /**
     * @throws ApiException
     */
    private function request(
        string $method,
        string $path,
        ?array $body,
        ?string $secretKey = null,
        ?string $apiUrl = null,
        ?int $maxBytes = null
    ): array {
        $secretKey = trim((string) $secretKey) ?: $this->config->getSecretKey();
        $url = rtrim(trim((string) $apiUrl) ?: $this->config->getApiUrl(), '/') . $path;
        $requestId = 'wc-' . bin2hex(random_bytes(8));

        if ($secretKey === '') {
            throw new ApiException(
                __('No AskMerra secret key is saved. Enter it under WooCommerce > Settings > AskMerra.', 'askmerra-for-woocommerce'),
                401,
                'missing_api_key',
                $requestId
            );
        }

        $payload = $body === null ? null : json_encode($body, self::JSON_FLAGS);

        if ($payload !== null && $maxBytes !== null && strlen($payload) > $maxBytes) {
            throw new ApiException(
                sprintf(
                    /* translators: 1: request size in bytes, 2: largest size sent */
                    __('The request was not sent: %1$d bytes is more than the %2$d AskMerra accepts in one call.', 'askmerra-for-woocommerce'),
                    strlen($payload),
                    $maxBytes
                ),
                413,
                'payload_too_large',
                $requestId
            );
        }

        $args = [
            'method' => $method,
            'timeout' => $this->config->getTimeout(),
            'redirection' => 0,
            'httpversion' => '1.1',
            'user-agent' => $this->getUserAgent(),
            'headers' => [
                'Authorization' => 'Bearer ' . $secretKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'x-request-id' => $requestId,
                // No "100 Continue" round trip before large bodies.
                'Expect' => '',
            ],
        ];

        if ($payload !== null) {
            $args['body'] = $payload;
        }

        $started = microtime(true);
        // Not wp_safe_remote_request(): the API URL is set by an administrator and points to a local
        // stand-in during development.
        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $exception = ApiException::transport($response->get_error_message(), $requestId);
            $this->logger->error($exception->getMessage(), ['url' => $url]);

            throw $exception;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);

        if ($this->config->isDebug()) {
            $this->logger->debug(sprintf('%s %s -> %d in %d ms', $method, $url, $status, (int) ((microtime(true) - $started) * 1000)), [
                'request_id' => $requestId,
                'request_bytes' => $payload === null ? 0 : strlen($payload),
                'request' => $payload === null ? null : mb_strcut($payload, 0, self::LOG_BYTES, 'UTF-8'),
                'response' => mb_strcut($raw, 0, self::LOG_BYTES, 'UTF-8'),
            ]);
        }

        if ($status === 0 || $status >= 300) {
            $exception = match (true) {
                $status === 0 => ApiException::transport(__('no HTTP status', 'askmerra-for-woocommerce'), $requestId),
                $status < 400 => ApiException::transport(sprintf(
                    /* translators: 1: HTTP status, 2: the address it redirects to */
                    __('the API answered with a redirect (HTTP %1$d to %2$s) - check the API URL', 'askmerra-for-woocommerce'),
                    $status,
                    (string) wp_remote_retrieve_header($response, 'location')
                ), $requestId),
                default => ApiException::fromResponse($status, $decoded, $this->getHeaders($response), $requestId),
            };
            $this->logger->error($exception->getMessage(), ['url' => $url]);

            throw $exception;
        }

        if (!is_array($decoded)) {
            $exception = ApiException::transport(__('the response is not JSON', 'askmerra-for-woocommerce'), $requestId);
            $this->logger->error($exception->getMessage(), ['url' => $url, 'response' => mb_strcut($raw, 0, 500, 'UTF-8')]);

            throw $exception;
        }

        return $decoded;
    }

    /** Response headers as a plain array (WordPress returns a case-insensitive dictionary). */
    private function getHeaders(array $response): array
    {
        $headers = wp_remote_retrieve_headers($response);

        return is_object($headers) && method_exists($headers, 'getAll') ? $headers->getAll() : (array) $headers;
    }

    private function getUserAgent(): string
    {
        return sprintf(
            'AskMerra-WooCommerce/%s WooCommerce/%s WordPress/%s',
            ASKMERRA_WC_VERSION,
            defined('WC_VERSION') ? WC_VERSION : 'unknown',
            get_bloginfo('version')
        );
    }
}
