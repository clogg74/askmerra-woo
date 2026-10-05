# AskMerra for WooCommerce - architecture and module contract

The WooCommerce port of the AskMerra Magento module (`~/Sites/Merra/askmerra-magento2`, read it for
behaviour details; its `docs/CONNECTOR_SPEC.md` is the platform-independent spec and section 9 maps
it to WooCommerce). Same features: catalog sync by **Push API or feed**, incremental for 50,000+
products, the chat widget in the page head, add to cart from the chat into the WooCommerce cart,
order reporting with consent, admin settings with an attribute picker, a sync status page with
actions and a product preview, a product-level "Hide from AskMerra", a bulk action, WP-CLI commands,
notices, uninstall.

This file is the contract between the parts of the plugin. Public classes and methods listed here
must exist exactly as written; everything else is internal to its module.

## Conventions

- PHP 8.1+, `declare(strict_types=1);`, namespace `AskMerra\WooCommerce\` = `includes/` (PSR-4,
  autoloaded by the main file). No Composer runtime dependencies.
- WordPress 6.3+, WooCommerce 8.0+ (HPOS and cart/checkout blocks compatible; never read orders with
  `get_post_meta` - use `wc_get_order()`).
- Text domain `askmerra-for-woocommerce`; every user-facing string translatable (`__()`, `esc_html__()`...).
- Escape all output (`esc_html`, `esc_attr`, `esc_url`, `wp_json_encode` + `wp_print_inline_script_tag`),
  check capabilities (`manage_woocommerce`) and nonces on every admin action and AJAX call, sanitize
  input, `$wpdb->prepare()` for every query with values.
- Times stored in the database are UTC (`gmdate('Y-m-d H:i:s')`); shown in the site time zone.
- Comments say what and why, short, like the Magento module. No dead code.
- Logging only through `Log\Logger` (WooCommerce > Status > Logs, source `askmerra`).

## Core

| File | What |
| --- | --- |
| `askmerra-for-woocommerce.php` | Header, constants `ASKMERRA_WC_VERSION`, `ASKMERRA_WC_FILE`, `ASKMERRA_WC_DIR`, `ASKMERRA_WC_URL`, autoloader, HPOS/blocks compatibility, activation hooks, boots `Plugin` on `plugins_loaded` when WooCommerce is active |
| `includes/Plugin.php` | Service container: `Plugin::instance()->get(Foo::class)`, `has()`. Loads the modules `Api`, `Catalog`, `Feed`, `Sync`, `Admin`, `Frontend`, `Cli` (each `includes/<Name>/Module.php`, skipped while missing) |
| `includes/ModuleInterface.php` | `services(): array<class-string, callable(Plugin): object>` and `register(Plugin $plugin): void` |
| `includes/Config.php` | Every setting: option names (`Config::OPTION_*`), defaults and getters; constants (`SYNC_PUSH`, `FEED_JSON`, `LOCALES`, `PRODUCT_TYPES`, `VISIBILITIES`, `EXCLUDE_META` = `_askmerra_exclude`, `CATALOG_OPTIONS`...) |
| `includes/Crypto.php` | `encrypt(string): string`, `decrypt(string): string`, `isEncrypted(string): bool` - the secret key is stored encrypted |
| `includes/Storefront.php` | Value object: `id`, `name`, `homeUrl`, `currency`, `wpLocale`, `locale` (AskMerra locale or null). Version 1 has one storefront, id `default` |
| `includes/StorefrontRepository.php` | `all()`, `get(string $id)`, `getDefault()`, `syncing()`, `pushing()`, `feeding()`, `reset()` (arrays keyed by storefront id) |
| `includes/Install.php` | Tables (`Install::table('queue'|'state'|'run')` gives the full name), `activate()`, `deactivate()` (calls `Sync\Scheduler::unscheduleAll()`), `maybeUpgrade()`, `dropTables()` |
| `includes/Log/Logger.php` | `error()`, `warning()`, `info()`, `debug()` (debug only with the Debug setting); context `['exception' => $e]` is formatted |

Everything is keyed by **storefront id** (string, `default` in version 1) - never assume a single
storefront in the sync engine.

### Services and constructors

Each module's `Module::services()` returns the factories of **its own** classes; a factory gets what
the class needs from the container, including other modules' services:

```php
QueueProcessor::class => static fn (Plugin $p): QueueProcessor => new QueueProcessor(
    $p->get(Config::class), $p->get(\AskMerra\WooCommerce\Catalog\ProductBuilder::class), $p->get(\AskMerra\WooCommerce\Api\Client::class), ...
),
```

Constructors belong to the owning module (only the public methods below are shared). Code outside a
class's module receives it through its own constructor (or `Plugin::instance()->get()` in hook
callbacks). Hooks are added in `Module::register()` or by classes registered there, never at file
load. Constructors do no work (no queries, no options read).

### Tables (created by `Install`)

```
askmerra_queue: queue_id PK, storefront varchar(32), product_id bigint, attempts smallint default 0,
                available_at datetime, created_at datetime, last_error text NULL, revision bigint (bumped
                by every add(): a run removes or updates only the rows it fetched, unchanged);
                UNIQUE(storefront, product_id); KEY(storefront, attempts, available_at)
askmerra_state: PK(storefront, product_id), external_id varchar(255), locale varchar(8) NULL,
                payload_hash varchar(40), in_stock tinyint(1) NULL, payload mediumblob NULL (gzdeflate'd JSON,
                feed storefronts only), synced_at datetime; KEY(storefront, external_id(191))
askmerra_run:   run_id PK, storefront, type varchar(16), status varchar(16), started_at, finished_at NULL,
                stats text NULL (JSON), message text NULL; KEY(storefront, type, started_at)
```

### Options outside the settings page

`askmerra_feed_token` (32 hex; Install creates it), `askmerra_problems` (array storefront => {message, since}),
`askmerra_feed_dirty` (array storefront => {version, written}: dirty while version > written) and
`askmerra_feed_ready` (array storefront => bool), `askmerra_sync_methods` (array storefront => {method,
destination: HMAC of API URL + secret key, context: fingerprint of the shop settings that shape every
product, rebuild: a rebuild still pending}), `askmerra_stock_missing` (storefront => ids in stock but not
in AskMerra at the last stock check), `askmerra_last_modified_check` (UTC datetime),
`askmerra_last_queue_run` / `askmerra_last_check_run` (timestamps), `askmerra_outages` (storefront =>
outages in a row, for the backoff; cleared by any successful call), `askmerra_schedule` (signature of
the scheduled jobs, autoloaded), `askmerra_needs_scheduling` ('yes' after activation),
`askmerra_db_version`; transients `askmerra_custom_attributes` and `askmerra_admin_messages_<user>`;
user meta `askmerra_connect_notice_dismissed`. Options the sync engine changes during long runs
(problems, sync methods, feed flags) are read fresh from the database, not from the options cache.
Uninstall removes everything named `askmerra_*`.

## Cross-module events

- `do_action('askmerra_settings_saved', string[] $changedOptions)` - fired by the settings page after
  WooCommerce saved the AskMerra settings, with the option names whose value changed. The Sync module
  listens: when any of `Config::CATALOG_OPTIONS` changed, every syncing storefront is rebuilt
  (`Reconciler::rebuild()`, which also notices a push/feed switch); when the feed format changed, the
  feed is marked dirty; when `OPTION_DAILY_TIME`, `OPTION_REBUILD` or `OPTION_FEED_FREQUENCY` changed,
  the background jobs are rescheduled.
- `do_action('askmerra_cart_added', WC_Product $product, string $externalId)` - after the chat added a
  product to the cart (Frontend module).
- Filter `askmerra_product_payload` (array $payload, WC_Product $product, Storefront $storefront) - lets a
  shop adjust a product before it is hashed and sent (Catalog module applies it last).
- Filter `askmerra_product_is_eligible` (?string $ineligibleReason, WC_Product $product, Storefront $storefront)
  - return a reason string to keep a product out (Catalog module; called for products that passed the
  built-in rules).
- Filters `askmerra_can_add_directly` (bool, WC_Product) and `askmerra_is_checkout_page` (bool), Frontend.
- Filter `askmerra_queue_run_seconds` (int, default 25) - length of a background queue run (Sync).
- Actions `askmerra_queue_changed` (string[] $storefrontIds) after products were queued, and
  `askmerra_rescheduled` (string[] $started, string[] $stopped) after the jobs changed (Sync).

## Product payload (AskMerra ProductInput, same as the Magento module)

```
external_id      string 1-255 (product id, or SKU with the "SKU" identifier setting, id:<n> without SKU) - the PARENT product, never a variation
sku              ?string <= 255
parent_external_id null
name             string 1-500
description      ?string <= 20000, plain text
url              ?string http(s) <= 2048 (permalink, unsafe characters percent-encoded)
image_urls       string[] <= 20 http(s) (main image first, then the gallery; count from settings)
price            ?float (display price, the shop's tax display setting; lowest for variable/grouped)
sale_price       ?float only when lower than price
currency         ?string ISO 4217 (storefront currency)
in_stock         bool
stock_qty        ?int >= 0 (only with the setting, simple products managing stock)
categories       string[] <= 50, "Parent > Child" paths, most specific first, without the default "Uncategorized"
brand            ?string <= 255
attributes       object (never an empty JSON array: use `new \stdClass()`), keys <= 100, values string <= 2000 |
                 number | bool | string[] (<= 50 items, <= 500 each); labels as the shop shows them
locale           ?string (storefront locale)
source_updated_at ?string ISO 8601 with offset (date_modified, UTC)
```

Lengths are counted like JavaScript (UTF-16 code units). Hash = `sha1(json)` of the payload without
`source_updated_at`; JSON with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE`
(`Sync\QueueProcessor::encode()` / `hash()`).

External id with the "SKU" identifier: the parent's SKU, or `id:<product id>` when it has no SKU, so it
never collides with a numeric SKU (`ExternalId::toProductId()` tries the SKU first, then `id:<n>`, then
a bare numeric id). When a product's external id
changes (SKU edited, identifier setting switched) the old one is deleted from AskMerra - the sync
engine compares the stored `external_id`, as the Magento QueueProcessor does.

## Modules and their public API

### Api (`includes/Api/`)

```php
final class ApiException extends \RuntimeException
    __construct(string $message, int $httpStatus, string $errorCode, string $requestId, int $retryAfter = 0, array $details = [])
    static transport(string $reason, string $requestId): self
    static fromResponse(int $status, mixed $decodedBody, array $headers, string $requestId): self
    getHttpStatus(): int; getErrorCode(): string; getRequestId(): string; getDetails(): array
    isAuthError(): bool   // 401, 403, 404
    isRateLimited(): bool // 429
    isPayloadTooLarge(): bool // 413
    isRetryable(): bool   // 0, 408, 429, >= 500
    getRetryAfter(): int  // Retry-After or details.retryAfterSec, default 60

final class Client   // wp_remote_request; Bearer secret key; JSON; User-Agent "AskMerra-WooCommerce/{ver} WooCommerce/{ver} WordPress/{ver}"; x-request-id "wc-<hex>"
    const MAX_UPSERT_BYTES = 9_500_000; const MAX_DELETE_IDS = 1000;
    ping(?string $secretKey = null, ?string $apiUrl = null): array{ok: bool, shopId: string, shopName: string}  // throws ApiException
    batchUpsert(array $products, ?string $locale): array{received: int, created: int, updated: int, unchanged: int, failed: int, errors: array}
    batchDelete(array $externalIds, ?string $locale): int   // splits over MAX_DELETE_IDS, returns the total
```
A body over `MAX_UPSERT_BYTES` throws a local 413 `payload_too_large` without being sent (the sync
engine splits the batch); a redirect is a transport error naming its target.
Endpoints: `GET /v1/catalog/ping`, `POST /v1/catalog/products:batchUpsert` `{products, locale?}`,
`POST /v1/catalog/products:batchDelete` `{external_ids, locale?}`. Debug setting logs request/response (4 KB).

### Catalog (`includes/Catalog/`)

```php
final class ProductBuilder
    /** @param int[] $productIds parent product ids @return array{payloads: array<int, array>, ineligible: array<int, string>, errors: array<int, string>} */
    build(Storefront $storefront, array $productIds): array
    /** Ids a storefront may send (published, default language, allowed type & visibility, not hidden, stock rule); one SQL-backed query, fast for 50k. @return int[] */
    getCandidateIds(Storefront $storefront): array
    /** "Can be bought now" of every product that passes all rules but the stock rule (so products that sell out are
        noticed even when out-of-stock products are not sent), one query. @return array<int, bool> */
    getStockFlags(Storefront $storefront): array

final class ExternalId
    get(\WC_Product $product): string            // id, or SKU of the parent product ("id:<id>" when it has no SKU)
    toProductId(string $externalId): ?int        // reverse: SKU (wc_get_product_id_by_sku, then the _sku meta), "id:<id>", bare id

final class AttributeValues
    /** Attributes a shop can choose (settings multiselect): 'pa_color' => 'Color', 'custom:material' => 'Material (product attribute)'. @return array<string, string> */
    getAvailableAttributes(): array
    /** Brand sources present: 'taxonomy:product_brand' => 'Brands', 'attribute:pa_brand' => 'Brand (attribute)'... @return array<string, string> */
    getBrandSources(): array
```
Rules: products identified by parent id; variations are not sent on their own - with
"Include variant options", the attributes used for variations become `{label: [values]}`. Ineligible:
not published (or password protected), type not selected, catalog visibility not selected, meta
`_askmerra_exclude` = 'yes', in an excluded category (children included), out of stock while
out-of-stock products are excluded, no name, deleted, a translation into another language. A product
that cannot be built (exception) goes to `errors` (retried), never to `ineligible` (which removes it
from AskMerra). `build()` runs as a guest (user 0, the guest tax location) and, from the second batch
in a process on, drops the process's object cache first, so a long run never sends data another
request changed meanwhile.

Multilingual shops send their **default language** until per-language storefronts exist
(`Translations`): with WPML (`ICL_SITEPRESS_VERSION` and its `icl_translations` table, default
`apply_filters('wpml_default_language', null)`) or Polylang (`pll_default_language('slug')` and the
`language` taxonomy), products in another language are left out of the candidate SQL and are
ineligible in `build()` ("A translation (language fr)..."). Products without a language (post types
the plugin does not translate) are still sent; without either plugin nothing changes.

With the "SKU" identifier a product without SKU is `id:<product id>`: a bare id could be another
product's numeric SKU. `toProductId()` looks the SKU up first (the `_sku` meta, which has no index,
only after WooCommerce's lookup table missed, never for `id:` ids), then `id:<id>`, then a bare id.

### Feed (`includes/Feed/`)

```php
final class FeedGenerator
    const DIRECTORY = 'askmerra/feeds';  // in wp_upload_dir()['basedir'], with index.php / index.html against listing (no .htaccess)
    generate(Storefront $storefront, bool $force = false): array   // {status: written|unchanged|building|disabled|failed, message?, products?, bytes?, url?}
    generateAll(bool $force = false): array<string, array>         // per storefront id; also calls removeUnusedFiles()
    getInfo(Storefront $storefront): array{exists: bool, url: string, bytes: int, modified_at: ?string /* UTC */}
    getUrl(Storefront $storefront): string
    getFileName(Storefront $storefront): string
    deleteFiles(Storefront $storefront): void
    removeUnusedFiles(): int
final class FeedToken   get(): string; regenerate(): string
final class FeedFlags   markDirty(string $sf); clearDirty(string $sf); isDirty(string $sf): bool;
                        getVersion(string $sf): int; markWritten(string $sf, int $version): void  // a write records the version it started from
                        markReady(string $sf); resetReady(string $sf); isReady(string $sf): bool
```
Same behaviour as the Magento `FeedGenerator` (but `generate()` never throws: `status: failed` + message): calls `Sync\Reconciler::ensureSyncMethod()` first;
written only when dirty (or forced); never before the storefront's catalog is ready (no pending queue
and at least one stored entry); streamed from `Sync\State::getPayloadPage()` to a temporary file then
renamed; a damaged stored entry aborts the write, invalidates and re-queues that product. File name
`<storefront id>-<token>.json|xml`. JSON `{generator, generated_at, store, store_name, store_url,
locale, currency, products: [...], count}`; Google XML as in the Magento `GoogleXmlWriter`.

### Sync (`includes/Sync/`)

```php
final class Queue    const MAX_ATTEMPTS = 8;
    add(array $storefrontIds, array $productIds): int; fetchDue(string $sf, int $limit): array; getNextDueAt(array $sfIds): ?int; getProductIds(string $sf): array;
    remove(string $sf, array $productIds): void; fail(string $sf, array $productIds, string $error): void;
    failPermanently(string $sf, array $productIds, string $error): void;
    postpone(string $sf, array $productIds, int $seconds, ?string $reason = null): void;
    retryFailed(?string $sf = null): int; countPending(string $sf): int; getFailedProductIds(string $sf): array;
    getErrors(string $sf, int $limit = 20): array /* rows: product_id, attempts, available_at, last_error */;
    clear(?string $sf = null): void; removeStorefrontsExcept(array $storefrontIds): int;
    postponeStorefront(string $sf, int $seconds, ?string $reason = null): int  // every waiting row of a paused storefront
final class State
    get(string $sf, array $productIds): array /* product_id => {product_id, external_id, locale, payload_hash, in_stock} */;
    save(string $sf, array $rows): void /* rows: product_id, external_id, locale, payload_hash, in_stock, payload? (JSON) */;
    invalidate(string $sf, array $productIds): void; invalidateStorefront(string $sf, bool $keepPayloads): void;
    delete(string $sf, array $productIds): void; clear(string $sf): void; getProductIds(string $sf): array;
    getPage(string $sf, int $afterProductId, int $limit): array; getPayloadPage(string $sf, int $afterProductId, int $limit): array /* id => ?json */;
    getStockFlags(string $sf): array; count(string $sf): int; countPayloads(string $sf): int; getLastSyncedAt(string $sf): ?string;
    getByExternalIds(string $sf, array $externalIds): array /* product_id => {product_id, external_id, locale}, exact matches */
final class RunLog   TYPE_REBUILD, TYPE_RECONCILE, TYPE_FEED, TYPE_REMOVE_ALL; STATUS_RUNNING, STATUS_SUCCESS, STATUS_PARTIAL, STATUS_FAILED, STATUS_REPLACED
    start(string $sf, string $type, array $stats = []): int; finish(int $runId, string $status, array $stats = [], ?string $message = null): void;
    get(int $runId): ?array; getRunning(string $sf, string $type): ?array; getLast(string $sf, string $type): ?array; cleanup(int $days = 30): int
    // rows: run_id, storefront, type, status, started_at, finished_at (UTC), stats (decoded array), message
final class Problems  set(string $sf, string $message): void; clear(string $sf): void; all(): array
final class Enqueuer
    enqueue(array $productIds, ?array $storefrontIds = null): void  // variations => parent; grouped parents too; collected and written once on shutdown
    flush(): int                                                     // write now (CLI, admin actions)
    rememberParent(int $variationId, int $parentId): void; reset(): void
final class QueueProcessor   const DEFAULT_SECONDS = 50;
    run(int $seconds = self::DEFAULT_SECONDS, ?array $storefrontIds = null): ?array  // null when another run holds the lock; per syncing storefront {sent, unchanged, removed, failed} (zeros when nothing was due)
    deleteRemote(string $sf, array $rows): void
    static encode(array $payload): string; static hash(array $payload): string
final class Reconciler
    reconcile(Storefront $s): array{missing: int, gone: int, price_dates: int}; rebuild(Storefront $s): array{queued: int}; ensureSyncMethod(Storefront $s): bool
final class StockChecker   check(Storefront $s): int
final class MethodTracker  check(Storefront $s): bool   // true: the method, the destination (new key or API URL) or the
                           // shop settings changed, or a rebuild is still pending - Reconciler rebuilds, then calls
    rebuilt(Storefront $s): void                       // which resets the feed's "ready" after the catalog is queued
final class Remover        removeAll(Storefront $s): int   // throws Api\ApiException (also missing_api_key while products are known)
final class Status
    getStorefronts(): array  // rows like the Magento Model\Status::getStores(): storefront (id), name, enabled, mode (push|feed|null), missing_key, widget, locale, currency, products, pending, failed, errors (Queue::getErrors 10), last_synced_at, last_reconcile, last_rebuild (RunLog rows|null), problem ({message, since}|null), feed (FeedGenerator::getInfo() + ready, dirty, format, last_run) | null
    getWarnings(): string[]  // background jobs not running, language not served...
final class Scheduler
    const HOOK_PROCESS = 'askmerra_process_queue', HOOK_STOCK = 'askmerra_stock_check', HOOK_MODIFIED = 'askmerra_modified_check',
          HOOK_DAILY = 'askmerra_daily', HOOK_FEED = 'askmerra_feed_generate', GROUP = 'askmerra';
    ensureScheduled(): void; reschedule(): void; static unscheduleAll(): void; scheduleNextRun(int $notBefore = 0): void
    isRunning(): bool  // false when products waited 15+ minutes without a queue run, or the 15-minute check stopped
```
Action Scheduler (bundled with WooCommerce), group `askmerra`. The queue job is a single action
scheduled when products are queued and again after each run for the next due product (an idle shop
runs nothing; up to 25 s per run in the background, a MySQL `GET_LOCK` so runs never overlap). While
something syncs: stock check and modified-posts check every 15 minutes, the daily check at the daily
time (cron expression, site time zone), full rebuild on its days, the feed job on its frequency while
feeding. Jobs are (re)planned on admin, cron and WP-CLI requests when their signature changes. Change listening (ChangeListener):
`woocommerce_new_product`, `woocommerce_update_product`, `woocommerce_new_product_variation`,
`woocommerce_update_product_variation`, stock hooks (`woocommerce_product_set_stock`,
`woocommerce_variation_set_stock`, `woocommerce_product_set_stock_status`,
`woocommerce_variation_set_stock_status`), `set_object_terms`, trash/untrash/delete of products and
variations, price/image/visibility post meta updates (`_price`, `_regular_price`, `_sale_price`,
`_thumbnail_id`, `_product_image_gallery`, `_stock_status`, `_askmerra_exclude`),
`wc_after_products_starting_sales` / `wc_after_products_ending_sales`, `transition_post_status`
(scheduled products going live), `edited_term` / `delete_term` for product_cat, product_brand and
`pa_*` when the name or parent changed (the term's products). The modified check queues products/variations with `post_modified_gmt` after
the last check (imports or SQL that skip the hooks). Queue processing follows the Magento `Model/Sync/*`,
made safer:
- In a batch, removals run before upserts. A copy is deleted only when no other product holds that
  external id (state, or an upsert of the same batch). An id another product still holds fails the
  newcomer for good ("same SKU as product N"); a stale holder gives way.
- Stale copies (external id or language changed) are deleted before the new state is saved; if that
  fails, the products stay queued with their old state.
- A rate limit or a refused key pauses the whole storefront (all waiting rows, Retry-After or 10
  minutes) and skips it for the rest of the run. Transport errors, 408 and 5xx pause it with a backoff
  (60 s doubling to 6 h) without counting attempts. Attempts count only for a product's own errors.
- A single product too large for a request fails for good; the others are sent.

### Admin (`includes/Admin/`, `assets/admin/`)

- **Settings** - `WC_Settings_Page` subclass, tab "AskMerra" (id `askmerra`), sections: Connection
  (default), Catalog, Sync & feed (the sync method is here, so the feed rows follow it), Storefront
  widget, Advanced. Admin hooks are registered in wp-admin only. Field ids = `Config::OPTION_*`,
  checkboxes stored as 'yes'/'no'. The secret key field never shows the saved value (shows a "saved"
  placeholder); a new value (`sk_...` only, which also stops browser autofill) is stored with
  `Crypto::encrypt()`; an empty submit keeps the saved key; "Remove the saved key" deletes it.
  "Check connection" button (AJAX, nonce, `manage_woocommerce`) tests the typed or saved key with
  `Api\Client::ping()`, detects a site key pasted as secret key and the reverse, reminds to allow the
  domain in AskMerra (dashboard > Install > Allowed domains), shows the feed URL for feeds. Attribute
  multiselect from `AttributeValues::getAvailableAttributes()`, brand select from `getBrandSources()`,
  excluded categories multiselect (product_cat). Feed URL shown read-only with "Copy". After saving,
  fires `askmerra_settings_saved` with the changed option names. Widget script URL and API URL outside
  askmerra.com need `Config::canUseCustomUrls()` (the `unfiltered_html` capability, or the
  `ASKMERRA_ALLOW_CUSTOM_URLS` constant), on save and in the connection check (which sends the key).
- **Sync status page** - WooCommerce > AskMerra (submenu, slug `askmerra-status`): the Magento status
  page (per storefront table, feed file, latest errors with product edit links, warnings, actions:
  send changes now, write the feed now, retry failed, run the daily check, full rebuild (confirm), new
  feed URL (confirm), remove from AskMerra (only when no longer syncing; confirm), hide problem) and
  "Preview a product" (id or SKU) showing the payload JSON, why it is not sent, whether AskMerra has
  the latest version, "send this product now". Actions are POSTs to `admin-post.php` with nonces.
- **Product** - "Hide from AskMerra" checkbox in the product data General tab (meta `_askmerra_exclude`
  'yes'/'no'), also in quick/bulk edit if simple; products list bulk action "Send to AskMerra now".
- **Notices** - admin notice while `Sync\Problems::all()` is not empty (key refused), linking to the
  status page; a notice when WooCommerce is missing is in the main file already.

### Frontend (`includes/Frontend/`, `assets/frontend/`)

- **Widget** in `wp_head` (priority 1-5): `wp_print_inline_script_tag` with
  `window.AskMerraSettings = Object.assign({...}, window.AskMerraSettings || {})` (productId on single
  product pages with product context; `consent: {analytics: true}` with consent "granted") and
  `window.AskMerraWooCommerce = {addToCart, afterAdd, ajaxUrl, cartUrl, consentMode, errorMessage, ...}`,
  then the script tag `id="askmerra-widget-script" src=<widget URL> data-site-key data-locale
  data-position data-open data-api-url async` (`wp_print_script_tag`). Not on checkout unless
  "Show on the checkout page". Nothing when the widget is off.
- **assets/frontend/askmerra.js** (plain JS, deferred, no jQuery required) - waits for `window.AskMerra`; registers
  `add_to_cart`: POST `?wc-ajax=askmerra_add_to_cart` (`external_id`, `sku`); success => refresh the
  mini cart (classic: `jQuery(document.body).trigger('added_to_cart', [fragments, cart_hash])` when
  jQuery exists, plus `wc_fragment_refresh`; blocks: dispatch `wc-blocks_added_to_cart` on
  `document.body`), fire `askmerra:cart-added` on `document`, then stay (short notice with "View cart")
  or go to the cart; `{redirect}` => open the product page. WP Consent API mode:
  `wp_has_consent('statistics')` + `wp_listen_for_consent_change` => `AskMerra.setConsent`. Order:
  `window.AskMerraWooPurchase` => `AskMerra.trackPurchase()` once (sessionStorage guard).
- **AddToCart** - `wc_ajax_askmerra_add_to_cart`: resolves the product (`ExternalId::toProductId()`,
  then the SKU); adds it directly only when it is a purchasable, in-stock simple product (filter
  `askmerra_can_add_directly`, bool, WC_Product - false for products needing options, e.g. add-ons),
  running `woocommerce_add_to_cart_validation` like `WC_AJAX::add_to_cart()`, then
  `WC()->cart->add_to_cart()`; returns `{success: true, name, message, cartUrl, cartLabel, fragments,
  cart_hash, qty}` or `{success: false, redirect: <product URL>, message?}` (variable, grouped,
  external, not addable, validation failed); fires `askmerra_cart_added` and WooCommerce's
  `woocommerce_ajax_added_to_cart`. Works for guests (session cookie). Also a GET link
  `?askmerra_add_to_cart=<external id or SKU>` for the AskMerra widget designer's add-to-cart link
  template (simple products are added and the cart opens; others open their page).
- **Purchase** - on the order received page (`woocommerce_thankyou`), when tracking is on and the
  widget is on: `window.AskMerraWooPurchase = {transaction_id, value, currency, tax, shipping, items:
  [{item_id (external id of the PARENT product), item_name, price (unit, after discount, incl. tax),
  quantity}]}`; once per order (order meta `_askmerra_reported`, HPOS-safe).
- **RestConfig** - `GET /wp-json/askmerra/v1/widget-config` (public): the widget settings for headless
  storefronts, the fields of the Magento `askMerraWidgetConfig` GraphQL query plus
  `add_to_cart_endpoint`, `add_to_cart_link` and `cart_url`; `{enabled: false}` when the widget is off.

### Cli (`includes/Cli/`)

`wp askmerra test | status | sync [--rebuild] [--check] [--product=<ids or SKUs>] [--retry-failed] [--time=<s>] |
feed [--force] | payload <id or SKU> | remove [--yes]` - same behaviour and output as the Magento
`askmerra:*` commands (WP_CLI::success/warning/error, `WP_CLI\Utils\format_items` tables).

### Uninstall (`uninstall.php`)

Unschedules the `askmerra` Action Scheduler group, drops the tables (`Install::dropTables()`), deletes
the `askmerra_*` options, the feed files and the `_askmerra_exclude` meta. It runs without the plugin
booted: require the autoloader logic itself.

## Development environment

- A Warden demo site (env type `wordpress`, e.g. `~/Sites/Merra/woo-demo`, https://app.askmerra-woo.test:
  WordPress 7.1, WooCommerce 11.1 with HPOS, the Storefront theme and WooCommerce's sample products -
  simple, variable, grouped, external, brands, attributes, sale and out-of-stock items).
- The repository is bind-mounted as `wp-content/plugins/askmerra-for-woocommerce` in the php-fpm,
  php-debug and nginx containers (`.warden/warden-env.yml` of the demo), so edits are live.
- WP-CLI: `docker exec -w /var/www/html askmerra-woo-php-fpm-1 php wp-cli.phar <command>`, e.g.
  `wp askmerra status`, `wp eval '...'` with `\AskMerra\WooCommerce\Plugin::instance()->get(...)`.
- Lint: `php -l` on every file, also with PHP 8.1 (`docker run --rm -v "$PWD":/app:ro php:8.1-cli php -l /app/<file>`).
- Logs: `wp-content/debug.log` (WP_DEBUG_LOG) and WooCommerce logs (`wp-content/uploads/wc-logs/askmerra-*.log`).
- The mock Push API runs inside the php-fpm container (setting `askmerra_api_url` = `http://127.0.0.1:8765`):
  `docker exec -d askmerra-woo-php-fpm-1 sh -c 'ASKMERRA_MOCK_DIR=/tmp/askmerra-mock php -S 127.0.0.1:8765 /var/www/html/wp-content/plugins/askmerra-for-woocommerce/dev/mock-askmerra-api.php'`.
  In `/tmp/askmerra-mock`: `requests.jsonl` (every request), `catalog-<key>-<locale>.json` (what it
  holds), `mode.json` (`{"mode": "normal|rate_limit|server_error|reject_first|too_large_over:N"}`).
  Keys `sk_test_*` (`sk_test_revoked` answers 401).
- Demo settings: enabled, Push API, secret key `sk_test_woo_demo`, site key `pk_test_woo_demo`, API
  URL = the mock, widget script URL = `dev/mock-widget.js` (a stand-in for the AskMerra widget with the
  same API and a product panel to click through add to cart).
- Admin pages over HTTP: a logged-in cookie from `wp_generate_auth_cookie()` via WP-CLI; storefront
  click-through: headless Chrome with a throwaway profile over the DevTools protocol.
