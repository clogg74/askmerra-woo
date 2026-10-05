<?php
// phpcs:ignoreFile -- a standalone PHP built-in server script, not module code
/**
 * A local stand-in for the AskMerra Push API, for development and tests:
 *
 *   php -S 127.0.0.1:8765 dev/mock-askmerra-api.php
 *   wp option update askmerra_api_url http://127.0.0.1:8765
 *
 * - Secret keys: any sk_test_...; sk_test_revoked answers 401 like a revoked key.
 * - Products are validated with the rules of AskMerra's productInputSchema; invalid ones come back
 *   in errors[] like AskMerra answers them.
 * - The catalog it received, per key and language, and a log of every request are kept in
 *   $ASKMERRA_MOCK_DIR (default: the system temp dir + /askmerra-mock).
 * - mode.json in that directory simulates problems:
 *   {"mode": "normal" | "rate_limit" | "server_error" | "reject_first" | "too_large_over:N"}
 */

// Only under the PHP built-in server: a plugin installed from a git checkout must not expose it.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$dir = getenv('ASKMERRA_MOCK_DIR') ?: sys_get_temp_dir() . '/askmerra-mock';

if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$requestId = 'mock_' . bin2hex(random_bytes(4));
header('Content-Type: application/json');

function fail(int $status, string $code, string $message, array $headers = []): never
{
    global $requestId;
    http_response_code($status);
    foreach ($headers as $header) {
        header($header);
    }
    echo json_encode(['error' => ['code' => $code, 'message' => $message, 'requestId' => $requestId]]);
    exit;
}

function units(string $s): int
{
    return intdiv(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
}

function httpUrl($v): bool
{
    return is_string($v) && units(trim($v)) <= 2048 && preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', trim($v));
}

function optString($p, string $key, int $max, bool $trim = true): ?string
{
    if (!property_exists($p, $key) || $p->$key === null) {
        return null;
    }
    $v = $p->$key;
    if (!is_string($v)) {
        return "$key: expected string";
    }
    return units($trim ? trim($v) : $v) > $max ? "$key: longer than $max" : null;
}

function validate($p): array
{
    $issues = [];
    if (!is_object($p)) {
        return ['product: expected object'];
    }
    if (!isset($p->external_id) || !is_string($p->external_id) || units(trim($p->external_id)) < 1 || units(trim($p->external_id)) > 255) {
        $issues[] = 'external_id: required, 1-255';
    }
    if (!isset($p->name) || !is_string($p->name) || units(trim($p->name)) < 1 || units(trim($p->name)) > 500) {
        $issues[] = 'name: required, 1-500';
    }
    foreach ([['sku', 255, true], ['parent_external_id', 255, true], ['brand', 255, true], ['description', 20000, false]] as [$k, $max, $trim]) {
        if ($e = optString($p, $k, $max, $trim)) {
            $issues[] = $e;
        }
    }
    if (property_exists($p, 'url') && $p->url !== null && !httpUrl($p->url)) {
        $issues[] = 'url: must be an http(s) URL';
    }
    if (property_exists($p, 'image_urls')) {
        if (!is_array($p->image_urls) || count($p->image_urls) > 20) {
            $issues[] = 'image_urls: array of at most 20';
        } else {
            foreach ($p->image_urls as $i => $u) {
                if (!httpUrl($u)) {
                    $issues[] = "image_urls[$i]: must be an http(s) URL";
                }
            }
        }
    }
    foreach (['price', 'sale_price'] as $k) {
        if (property_exists($p, $k) && $p->$k !== null && (!(is_int($p->$k) || is_float($p->$k)) || $p->$k < 0 || $p->$k > 100000000)) {
            $issues[] = "$k: number 0-100000000";
        }
    }
    if (property_exists($p, 'currency') && $p->currency !== null && (!is_string($p->currency) || !preg_match('/^[A-Za-z]{3}$/', trim($p->currency)))) {
        $issues[] = 'currency: 3-letter code';
    }
    if (property_exists($p, 'in_stock') && !is_bool($p->in_stock)) {
        $issues[] = 'in_stock: boolean';
    }
    if (property_exists($p, 'stock_qty') && $p->stock_qty !== null && (!is_int($p->stock_qty) || $p->stock_qty < 0)) {
        $issues[] = 'stock_qty: integer >= 0';
    }
    if (property_exists($p, 'categories')) {
        if (!is_array($p->categories) || count($p->categories) > 50) {
            $issues[] = 'categories: array of at most 50';
        } else {
            foreach ($p->categories as $i => $c) {
                if (!is_string($c) || units(trim($c)) < 1 || units(trim($c)) > 500) {
                    $issues[] = "categories[$i]: 1-500";
                }
            }
        }
    }
    if (property_exists($p, 'attributes')) {
        if (!is_object($p->attributes)) {
            $issues[] = 'attributes: expected record (object), got ' . gettype($p->attributes);
        } else {
            foreach (get_object_vars($p->attributes) as $k => $v) {
                if (units((string) $k) > 100) {
                    $issues[] = "attributes key $k: longer than 100";
                }
                $ok = (is_string($v) && units($v) <= 2000) || is_int($v) || is_float($v) || is_bool($v)
                    || (is_array($v) && count($v) <= 50 && array_reduce($v, fn ($c, $x) => $c && is_string($x) && units($x) <= 500, true));
                if (!$ok) {
                    $issues[] = "attributes.$k: invalid value";
                }
            }
        }
    }
    if (property_exists($p, 'locale') && $p->locale !== null && !in_array($p->locale, ['en', 'ro', 'it', 'fr', 'de', 'es'], true)) {
        $issues[] = 'locale: unsupported';
    }
    if (property_exists($p, 'source_updated_at') && $p->source_updated_at !== null
        && (!is_string($p->source_updated_at) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/', $p->source_updated_at))) {
        $issues[] = 'source_updated_at: ISO datetime with offset';
    }
    return $issues;
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$raw = (string) file_get_contents('php://input');
file_put_contents("$dir/requests.jsonl", json_encode([
    'at' => gmdate('c'),
    'method' => $method,
    'path' => $path,
    'auth' => substr($auth, 0, 20),
    'bytes' => strlen($raw),
    'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? '',
]) . "\n", FILE_APPEND);

if (!preg_match('/^Bearer (sk_test_\w+)$/', $auth, $m)) {
    fail(401, 'unauthorized', 'Missing or invalid API key');
}
$key = $m[1];
if ($key === 'sk_test_revoked') {
    fail(401, 'invalid_api_key', 'This API key was revoked');
}

$mode = json_decode((string) @file_get_contents("$dir/mode.json"), true)['mode'] ?? 'normal';
if ($mode === 'rate_limit') {
    fail(429, 'rate_limited', 'Too many requests', ['Retry-After: 30']);
}
if ($mode === 'server_error') {
    fail(500, 'internal_error', 'Something went wrong');
}

if ($path === '/v1/catalog/ping' && $method === 'GET') {
    echo json_encode(['ok' => true, 'shopId' => 'shop_' . substr($key, 8), 'shopName' => 'Mock shop ' . substr($key, 8)]);
    exit;
}

$body = json_decode($raw);
if ($method !== 'POST' || !is_object($body)) {
    fail(404, 'not_found', 'Route not found');
}

$locale = $body->locale ?? null;
if ($locale !== null && !in_array($locale, ['en', 'ro', 'it', 'fr', 'de', 'es'], true)) {
    fail(400, 'validation_error', 'locale: unsupported');
}
$file = sprintf('%s/catalog-%s-%s.json', $dir, $key, $locale ?? 'default');
$catalog = json_decode((string) @file_get_contents($file), true) ?: [];

if ($path === '/v1/catalog/products:batchUpsert') {
    if (strlen($raw) > 10 * 1024 * 1024) {
        fail(413, 'payload_too_large', 'Body larger than 10 MB');
    }
    $products = $body->products ?? null;
    if (!is_array($products) || count($products) < 1 || count($products) > 500) {
        fail(400, 'validation_error', 'products: 1-500 items');
    }
    if (str_starts_with($mode, 'too_large_over:') && count($products) > (int) substr($mode, 15)) {
        fail(413, 'payload_too_large', 'Too large (mock)');
    }
    $result = ['received' => count($products), 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'errors' => []];
    $seen = [];
    foreach ($products as $index => $product) {
        $issues = validate($product);
        if ($mode === 'reject_first' && $index === 0) {
            $issues[] = 'rejected by mock';
        }
        $externalId = is_object($product) ? ($product->external_id ?? null) : null;
        if (!$issues && isset($seen[$externalId])) {
            $issues[] = 'Duplicate external_id in this batch';
        }
        if ($issues) {
            $result['errors'][] = ['index' => $index, 'external_id' => $externalId, 'message' => implode('; ', $issues)];
            $result['failed']++;
            continue;
        }
        $seen[$externalId] = true;
        $copy = json_decode(json_encode($product), true);
        unset($copy['source_updated_at']);
        $hash = sha1(json_encode($copy));
        if (!isset($catalog[$externalId]) || !empty($catalog[$externalId]['deleted'])) {
            $result['created']++;
        } elseif ($catalog[$externalId]['hash'] === $hash) {
            $result['unchanged']++;
        } else {
            $result['updated']++;
        }
        $catalog[$externalId] = ['hash' => $hash, 'product' => $copy, 'deleted' => false];
    }
    file_put_contents($file, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo json_encode($result);
    exit;
}

if ($path === '/v1/catalog/products:batchDelete') {
    $ids = $body->external_ids ?? null;
    if (!is_array($ids) || count($ids) < 1 || count($ids) > 1000) {
        fail(400, 'validation_error', 'external_ids: 1-1000 items');
    }
    $deleted = 0;
    foreach ($ids as $id) {
        if (isset($catalog[$id]) && empty($catalog[$id]['deleted'])) {
            $catalog[$id]['deleted'] = true;
            $deleted++;
        }
    }
    file_put_contents($file, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo json_encode(['deleted' => $deleted]);
    exit;
}

fail(404, 'not_found', 'Route not found');
