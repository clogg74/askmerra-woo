# AskMerra for WooCommerce

Connects a WooCommerce shop to [AskMerra](https://askmerra.com), the AI shopping assistant that
answers shoppers' questions and recommends products from your catalog. The WooCommerce counterpart of
[AskMerra for Magento 2](https://github.com/clogg74/askmerra-magento2), with the same features.

- **Catalog sync, two ways** - choose **Push API** (changes reach AskMerra within about a minute) or
  **Product feed** (a JSON or Google Shopping XML file AskMerra downloads).
- **Only what changed** - built for catalogs of 50,000+ products. Product, price, stock, category and
  term changes are picked up by WooCommerce's hooks, and a check every 15 minutes catches imports and
  integrations that write the database directly. Only products whose content changed are rebuilt and
  sent. Feed files are written from stored entries in seconds, never rebuilt from scratch.
- **You choose what AskMerra knows** - the product attributes to send (global and custom ones), the
  brand (WooCommerce Brands, a brand plugin or an attribute), the description, images, product types,
  catalog visibility, stock rules and excluded categories, or hide single products.
- **The chat on your storefront** - the widget script goes into the `<head>` of every page, from the
  settings (script URL + site key). It works with classic themes (Storefront, Astra, ...) and block
  themes alike.
- **Add to cart from the chat**, into the real WooCommerce cart. The mini cart refreshes in classic
  themes and in the Mini-Cart block.
- **Sales attribution** - orders are reported, with the shopper's analytics consent, so AskMerra can
  show the sales the assistant helped with. The WP Consent API is supported.
- **Admin tools**:
  - a connection check and a sync status page with errors, actions and a product payload preview;
  - a "Hide from AskMerra" product setting and a products list bulk action;
  - WP-CLI commands;
  - an admin notice when AskMerra refuses the key.
- **Compatible** with High-Performance Order Storage (HPOS) and the cart & checkout blocks.

## Requirements

- WordPress 6.3+, WooCommerce 8.0+ (tested up to WordPress 7.1 and WooCommerce 11.1), PHP 8.1+.
- WP-Cron, or better a real cron, running WooCommerce's Action Scheduler. See [Background jobs](#background-jobs).
- An AskMerra shop with its keys (AskMerra dashboard > API keys):
  - the **secret key** (`sk_live_...`) for the Push API;
  - the **site key** (`pk_live_...`) for the widget.

## Install

**From a zip:** download the release zip, then go to Plugins > Add New > Upload Plugin and activate it.

**From git**, in `wp-content/plugins`:

```bash
git clone https://github.com/clogg74/askmerra-woo.git askmerra-for-woocommerce
wp plugin activate askmerra-for-woocommerce
```

The folder must be named `askmerra-for-woocommerce` (the plugin slug and text domain).

**With Composer** (Bedrock and similar setups, where `composer/installers` puts `wordpress-plugin`
packages into the plugins folder):

```json
"repositories": [
    {"type": "vcs", "url": "https://github.com/clogg74/askmerra-woo.git", "no-api": true}
]
```

```bash
composer require askmerra/askmerra-for-woocommerce:^1.0
```

## Set up (5 minutes)

1. **WooCommerce > Settings > AskMerra > Connection**:
   - **Enable AskMerra**: on;
   - the **Secret key** and **Site key** from the AskMerra dashboard;
   - **Check connection** checks the keys as typed, before saving.
2. **Catalog**: choose the **Product attributes to include**, the **Brand**, and which products are
   sent. Save.
3. **Sync & feed**: choose **How AskMerra gets the catalog**.
   - With the Push API, products start flowing within a minute.
   - With the product feed, copy the **Feed URL** into AskMerra (Catalog > Add source > Feed URL)
     once the status page says it is published.
4. In AskMerra, add the shop's domain to the widget's allowed domains (dashboard > Install). The chat
   appears on the storefront.
5. Follow the sync under **WooCommerce > AskMerra** (the sync status page).

One AskMerra shop has one currency and serves one language per catalog. A multilingual site (WPML or
Polylang) sends the products of its default language, in the language set under **Language**;
translations are not sent as products of their own.

## How the sync works

```
 product saved / imported / stock change / category renamed / sale starts
        |  WooCommerce hooks (variations queue their product, grouped products their parents)
        |  + every 15 min: products modified without hooks, stock that changed through orders
        v
 {prefix}askmerra_queue (storefront, product)
        |                                     ^  daily check: missing products, products that left
        |                                     |  the catalog, sale prices starting or ending today;
        |                                     |  weekly full rebuild (configurable)
        v
 askmerra_process_queue (Action Scheduler, runs while products wait)
        |  build the product as a guest sees it, hash it, compare with {prefix}askmerra_state
        |  unchanged -> nothing is sent
        +--> Push API:  batchUpsert (up to 500 per call, 10 MB) / batchDelete
        +--> Feed:      store the finished entry, mark the feed changed
                              |
              askmerra_feed_generate (configurable) - writes the file from the stored entries,
              only when something changed, never half built
```

- **Change detection** - WooCommerce's product hooks, stock hooks, term assignments (categories,
  attributes, brands), trash/untrash/delete, meta updates (prices, images, the hide setting) and
  scheduled sales. Renaming or moving a category queues its products.
  - Products edited by imports or SQL that bypass the hooks are caught by the 15-minute modified
    check.
  - Stock sold through orders is caught by the 15-minute stock check.
- **Only changed content is sent** - every product is built, hashed and compared with what AskMerra
  has. A full rebuild of 50,000 products sends nothing when nothing changed. This matters: AskMerra
  re-reads a product with AI (billed usage) when its name, description, categories, brand or
  attributes change.
- **Removals** - products that are deleted, unpublished, hidden, out of stock (when excluded) or moved
  into an excluded category are removed from AskMerra. The daily check also catches products that
  left the catalog in ways no hook saw.
- **Failures** - every kind of failure is handled and recovers by itself:
  - a product AskMerra rejects is marked failed with AskMerra's message (status page), as is a
    product too large to send or one with the same SKU as another;
  - when AskMerra does not answer, the sync pauses with growing pauses (1 minute ... 6 hours) and
    resumes by itself, without giving up on any product;
  - a rate limit waits as long as AskMerra asks;
  - a request too large is split;
  - a refused key pauses the sync and shows an admin notice, then resumes once the key works;
  - a product edited while it is being sent is sent again with the edit.
- **Feed safety** - AskMerra deactivates every product missing from a feed. So the first feed is
  written only once the whole catalog is ready, and a file is never published with products missing.
- **Switching between Push API and feed**, in the admin or with `wp option update`, is noticed at
  the next run:
  - the whole catalog is sent again the new way;
  - the feed is published once complete;
  - a feed that is no longer used is deleted.

  Remove the source you no longer use in the AskMerra dashboard.
- **A new key or shop settings** - a new secret key or API URL (from a test shop to the live one, say)
  sends the whole catalog to the new AskMerra shop. A change to the site language, currency, tax
  display, prices with or without tax, permalinks or the out-of-stock visibility rebuilds the catalog
  within 15 minutes, and only the products it changed are sent.

Measured on the demo catalog: building and comparing 10,000 products takes about 4 seconds; selecting
the products to send from 50,000 takes 0.2 seconds; a feed file of 2,500 products is written in 12 ms.

### Background jobs

The sync runs in WooCommerce's Action Scheduler (WooCommerce > Status > Scheduled Actions, group
`askmerra`). By default Action Scheduler runs on visits through WP-Cron. On a quiet site, or with
`DISABLE_WP_CRON`, run it from the server's cron every minute:

```
* * * * * cd /path/to/wordpress && wp action-scheduler run --group=askmerra --quiet
```

The status page warns when the jobs have not run for 15 minutes.

## Settings

WooCommerce > Settings > AskMerra.

| Section | Setting | What it does |
| --- | --- | --- |
| Connection | Enable AskMerra | Turns everything on |
| | Secret key | Push API key, stored encrypted (AES-256-GCM with the site's salts), never sent to the browser. Leave empty to keep the saved key |
| | Site key | Public widget key |
| | Language | `Automatic` uses the site language (ro_RO -> ro); AskMerra serves en, ro, it, fr, de, es |
| Catalog | Product identifier | Product ID (recommended, never changes) or SKU (`id:<product ID>` when a product has no SKU). Changing it replaces the catalog in AskMerra |
| | Product attributes to include | Global attributes (`pa_*`) and custom product attributes, sent under their labels; one value as text, several as a list |
| | Brand | WooCommerce Brands (`product_brand`), a brand plugin's taxonomy (Perfect Brands, YITH...) or an attribute |
| | Description | Description, short description, or both; HTML, shortcodes and page-builder markup are removed |
| | Include variant options | Variable products: the sizes/colours a shopper can pick become attributes |
| | Images per product | 1-20, main image first |
| | Product types / Catalog visibility | Which products are sent (simple, variable, grouped, external; shop and search visibility) |
| | Include out-of-stock products | Yes (recommended): AskMerra knows them but does not recommend them |
| | Send stock quantity | Products that manage their stock |
| | Exclude categories | Subcategories too. Single products: **Hide from AskMerra** on the product |
| Sync & feed | How AskMerra gets the catalog | **Push API** (recommended) or **Product feed** |
| | Products per request | 1-500 |
| | Daily maintenance at | Site time zone |
| | Full rebuild | Weekly (Sunday), daily, monthly or never |
| | Format | JSON (all attributes) or Google Shopping XML (standard attributes; also usable in Google Merchant Center) |
| | Regenerate the feed | Every 1, 3, 6, 8, 12 or 24 hours (written only when something changed) |
| | Feed URL | Ready to copy |
| Storefront widget | Show the chat widget | Adds the script to the `<head>` of every page |
| | Widget script URL | The `src` of AskMerra's snippet (dashboard > Install); with the site key it makes the snippet |
| | Position, Open on page load, Show on the checkout page (off) | |
| | Know the product being viewed | Product pages tell the assistant which product the shopper is looking at |
| | Add to cart from the chat / After adding to cart | Into the WooCommerce cart; products with options open their page |
| | Report orders to AskMerra | Order number, totals and products - no name, e-mail or address |
| | Analytics consent | Automatic (Google Consent Mode / Tag Manager), WP Consent API, Not needed, Manual |
| Advanced | API URL | Only for an AskMerra test environment |
| | Request timeout, Debug log | The debug log writes every request (without the key) to WooCommerce > Status > Logs, source `askmerra` |

Saving settings that change what is sent (keys, language, catalog settings, the sync method) rebuilds
the catalog, still sending only what changed.

**Widget script URL** and **API URL** outside `askmerra.com` can be set only by users who may add
unfiltered HTML (administrators; super admins on a network), not by shop managers. That script runs on
every page and that API receives the secret key. For development or staging with a mock, allow it
for everyone in `wp-config.php`:

```php
define('ASKMERRA_ALLOW_CUSTOM_URLS', true);
```

## Admin

- **WooCommerce > AskMerra** - the sync status page:
  - **What it shows:** products in AskMerra, waiting and failed; when products were last sent; the
    daily check and full rebuild results; the feed file; the latest errors with links to the
    products; and warnings (background jobs not running, language not served, consent plugin
    missing).
  - **Actions:** *Send changes now*, *Write the feed now*, *Retry failed products*, *Run the daily
    check now*, *Full rebuild*, *Give the feeds new URLs*, and *Remove its products from AskMerra*
    (once AskMerra is turned off).
  - **Preview a product** (by ID or SKU) shows the product exactly as AskMerra receives it, or why it
    is not sent, and whether AskMerra has the latest version.
- **Products > All Products > Bulk actions > Send to AskMerra now** queues the selected products.
- **Hide from AskMerra** - product data > General, also in quick edit and bulk edit. The products list
  can be filtered with `?askmerra_hidden=yes`.
- An admin notice appears when AskMerra refuses the key.

## Command line

```bash
wp askmerra test                          # checks the keys
wp askmerra status [--format=json]        # what the sync status page shows
wp askmerra sync                          # sends the queue now instead of waiting for the background job
wp askmerra sync --rebuild                # first sync of a large catalog, with progress
wp askmerra sync --check                  # runs the daily check first
wp askmerra sync --product=woo-beanie,57  # only these products (IDs or SKUs)
wp askmerra sync --retry-failed --time=300
wp askmerra feed [--force]                # writes the feed file now
wp askmerra payload woo-beanie            # a product as AskMerra receives it, or why it is not sent
wp askmerra remove [--yes]                # takes the products out of AskMerra (turn AskMerra off first)
```

## Storefront

Nothing to do in the theme: with **Show the chat widget** on and a site key set, every page has the
AskMerra script in its `<head>` (`async`, so it never delays the page), with the site key and
language. On product pages the assistant knows the product. The chat stays off the checkout page
unless asked for; other checkout pages can be marked with the `askmerra_is_checkout_page` filter.

`assets/frontend/askmerra.js` (plain JavaScript, no jQuery needed) connects the chat to WooCommerce:

- **Add to cart** - `POST ?wc-ajax=askmerra_add_to_cart`. Simple products go into the cart. Variable,
  grouped and external products, out-of-stock products, and products with required add-ons open
  their page.
  - The mini cart refreshes: classic cart fragments (`added_to_cart`) and the Mini-Cart block
    (`wc-blocks_added_to_cart`).
  - `document` receives an `askmerra:cart-added` event for themes that open a cart drawer, with
    `detail: {externalId, sku, name, cartQty}` (`cartQty`: the items in the cart after the add).
- **Orders** - on the order received page the order is passed to `AskMerra.trackPurchase()` once.
  AskMerra sends it once the shopper's analytics consent allows it.
- **Consent** - *WP Consent API* mode reads the `statistics` category from consent plugins that
  support the API (Complianz, CookieYes, Cookiebot...) and follows its changes.

**Add-to-cart link** - for the AskMerra widget designer's "add-to-cart link" template, used when a page
registers no `add_to_cart` handler, use:

```
https://your-shop.example/?askmerra_add_to_cart={external_id}
```

It adds simple products and opens the cart, and opens the product page for the others. It works with
both product identifiers.

### Headless storefronts

Storefronts that render their own pages (Next.js, Faust.js...) load the widget themselves, with the
settings from `GET /wp-json/askmerra/v1/widget-config`. This is public and holds no secret, like
the Magento module's `askMerraWidgetConfig` GraphQL query:

```json
{"enabled": true, "script_url": "https://cdn.askmerra.com/v1/widget.js", "site_key": "pk_live_...",
 "locale": "en", "position": null, "open_on_load": false, "api_url": null, "product_identifier": "id",
 "product_context": true, "add_to_cart": true, "after_add_to_cart": "stay", "track_purchases": true,
 "consent_mode": "auto", "add_to_cart_endpoint": "https://shop.example/?wc-ajax=askmerra_add_to_cart",
 "add_to_cart_link": "https://shop.example/?askmerra_add_to_cart={external_id}", "cart_url": "https://shop.example/cart/"}
```

Load `script_url` with the attributes `data-site-key`, `data-locale`, `data-position`, `data-open`
and `data-api-url`, as in the AskMerra snippet. Then:
- on product pages, set `window.AskMerraSettings.productId` (the product ID, or its SKU when
  `product_identifier` is `sku`), or call `AskMerra.setProduct(id)` after client-side navigation;
- handle the widget's `add_to_cart` event with your cart;
- on the order confirmation, call `AskMerra.trackPurchase({transaction_id, value, currency, items})`.

## Developers

| Hook | Type | Use |
| --- | --- | --- |
| `askmerra_product_payload` | filter `(array $payload, WC_Product $product, Storefront $storefront)` | Change a product before it is hashed and sent (limits are applied afterwards) |
| `askmerra_product_is_eligible` | filter `(?string $reason, WC_Product $product, Storefront $storefront)` | Return a reason to keep a product out of AskMerra |
| `askmerra_can_add_directly` | filter `(bool $can, WC_Product $product)` | False sends the shopper to the product page instead of adding it |
| `askmerra_is_checkout_page` | filter `(bool $isCheckout)` | Mark other checkout pages (the chat stays off them) |
| `askmerra_queue_run_seconds` | filter `(int $seconds)` | Length of a background queue run (default 25) |
| `askmerra_cart_added` | action `(WC_Product $product, string $externalId)` | After the chat added a product to the cart |
| `askmerra_settings_saved` | action `(string[] $changedOptions)` | After the AskMerra settings were saved |

After changing a filter's logic, run `wp askmerra sync --rebuild` so every product is compared again.

The design and the contract between the plugin's modules are in
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Nothing is sent | `wp askmerra status` and the warnings on the status page. The background jobs need WP-Cron or a real cron (see [Background jobs](#background-jobs)) |
| A product is missing in AskMerra | *Preview a product* or `wp askmerra payload <id or SKU>` says why |
| "AskMerra refuses the key" | The secret key was revoked or the AskMerra shop is suspended. Fix the key and the queue resumes by itself |
| Feed URL returns 404 | The first feed is written once the whole catalog is ready (the status page shows how many products are left). `wp askmerra sync` speeds it up |
| The chat does not show | Site key set, the shop's domain allowed in AskMerra, the browser console. On the checkout page it is off by default. Page caches must be purged after changing widget settings |
| Orders are not attributed | Analytics consent mode: with WP Consent API a consent plugin must be active |
| Details | WooCommerce > Status > Logs, source `askmerra`; turn on **Debug log** to see every request and response |

## Uninstall

1. Turn AskMerra off (WooCommerce > Settings > AskMerra).
2. Run `wp askmerra remove`, or *Remove its products from AskMerra* on the status page.
3. Delete the plugin in Plugins.

Deleting the plugin removes everything it stored:
- the tables, settings and background jobs;
- the feed files;
- the "Hide from AskMerra" product setting and the marks on reported orders.

Also delete feed sources in the AskMerra dashboard: AskMerra keeps a feed's products when the file
disappears.

## Development

`dev/mock-askmerra-api.php` is a local stand-in for the AskMerra Push API. It validates every product
like AskMerra does and can simulate rate limits, rejected products, large requests and outages. It
runs only under PHP's built-in server:

```bash
php -S 127.0.0.1:8765 dev/mock-askmerra-api.php
wp option update askmerra_api_url http://127.0.0.1:8765
# secret keys: any sk_test_..., sk_test_revoked answers 401
# what it received and a request log: /tmp/askmerra-mock (or $ASKMERRA_MOCK_DIR)
echo '{"mode":"rate_limit"}' > /tmp/askmerra-mock/mode.json   # normal | rate_limit | server_error | reject_first | too_large_over:N
```

`dev/mock-widget.js` stands in for the AskMerra widget. Set it as **Widget script URL**
(`https://your-site/wp-content/plugins/askmerra-for-woocommerce/dev/mock-widget.js`) to click through
add to cart, product context and order reporting without an AskMerra account. The `dev/` and `docs/`
folders are left out of release archives (`.gitattributes`).

Build the translation template with `wp i18n make-pot . languages/askmerra-for-woocommerce.pot
--exclude=dev,docs --skip-js`. Translations: en_US (source), ro_RO, it_IT, fr_FR, de_DE, es_ES.

See [CHANGELOG.md](CHANGELOG.md).
