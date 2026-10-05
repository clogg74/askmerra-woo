# Changelog

## 1.0.2 - 2026-10-06

- The chat's "Add to cart" button shows that the product was added: the add_to_cart handler
  resolves `true` once the product is in the cart (`false` when it opens the product page or the
  cart refuses it).
- The development stand-in widget (`dev/mock-widget.js`) shows "Added" like the real widget.

## 1.0.1 - 2026-10-05

- The `askmerra:cart-added` event's detail carries `cartQty` (the items in the cart after the add)
  instead of the misleading `qty`; documented in the README.

## 1.0.0 - 2026-10-05

First release, with the features of AskMerra for Magento 2 1.0.0.

- Catalog sync: Push API or product feed (JSON, Google Shopping XML).
- Incremental sync for large catalogs:
  - WooCommerce hooks, plus 15-minute checks for products modified without hooks and for stock sold
    through orders;
  - a queue with retries and content hashes (only changed products are sent);
  - a daily check (missing products, products that left the catalog, sale dates) and a weekly full
    rebuild;
  - Action Scheduler jobs that run only while there is work.
- Feed files written from stored entries, only when changed, never partial; secret feed URLs.
- Settings: product attributes (global and custom), brand (WooCommerce Brands, brand plugins,
  attributes), description, images, variant options, product types, catalog visibility, stock,
  excluded categories, product identifier, language; "Hide from AskMerra" per product (also in
  quick and bulk edit).
- Storefront widget in the `<head>` of every page (classic and block themes), product context, add to
  cart into the WooCommerce cart (cart fragments and the Mini-Cart block), an add-to-cart link for
  the AskMerra widget designer, order reporting with analytics consent modes (WP Consent API
  included); `GET /wp-json/askmerra/v1/widget-config` for headless storefronts.
- Admin: connection check, sync status page with actions and product preview, products bulk action,
  notices when AskMerra refuses the key; WP-CLI commands (`wp askmerra`); debug log in WooCommerce
  logs.
- HPOS and cart & checkout blocks compatible; secret key encrypted at rest (AES-256-GCM).
- Translations: en_US, ro_RO, it_IT, fr_FR, de_DE, es_ES.
- Development: a mock of the AskMerra Push API (`dev/mock-askmerra-api.php`) and of the widget
  (`dev/mock-widget.js`).
