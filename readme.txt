=== AskMerra for WooCommerce ===
Contributors: askmerra
Tags: woocommerce, ai, shopping assistant, chat, product feed
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
WC requires at least: 8.0
WC tested up to: 11.1
Stable tag: 1.0.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The AskMerra AI shopping assistant for WooCommerce: catalog sync, the chat widget, add to cart from the chat and sales attribution.

== Description ==

AskMerra answers your shoppers' questions and recommends products from your catalog. This plugin connects your WooCommerce shop to it:

* **Catalog sync, two ways** - the Push API (changes reach AskMerra within about a minute) or a product feed (JSON or Google Shopping XML) that AskMerra downloads.
* **Only what changed** - built for catalogs of 50,000+ products: changes are picked up by WooCommerce's hooks and by checks every 15 minutes, and only products whose content changed are sent.
* **You choose what AskMerra knows** - product attributes, brand, description, images, product types, catalog visibility, stock rules, excluded categories, and a "Hide from AskMerra" setting per product.
* **The chat on your storefront** - the widget script in the head of every page, for classic and block themes.
* **Add to cart from the chat** into the WooCommerce cart, with the mini cart refreshing (classic themes and the Mini-Cart block).
* **Sales attribution** - orders are reported with the shopper's analytics consent (Google Consent Mode, WP Consent API or your cookie banner).
* **Admin tools** - connection check, sync status page with actions and a product preview, products bulk action, WP-CLI commands (`wp askmerra`).

Compatible with High-Performance Order Storage (HPOS) and the cart & checkout blocks.

= What is sent to AskMerra =

* Catalog: product names, descriptions, prices, stock, images, URLs, categories, brand and the attributes you choose. The secret key never leaves the server.
* Orders, when reporting is on and the shopper's analytics consent allows it: the order number, totals and the products bought - never names, e-mail or postal addresses.

AskMerra is a third-party service: see https://askmerra.com for its terms of service and privacy policy.

== Installation ==

1. Upload the plugin through Plugins > Add New > Upload Plugin, or into `wp-content/plugins/askmerra-for-woocommerce`, and activate it.
2. Go to WooCommerce > Settings > AskMerra. Turn on **Enable AskMerra**, enter the **Secret key** and **Site key** from the AskMerra dashboard (API keys), and click **Check connection**.
3. Choose the product attributes and the brand to send (Catalog), and how AskMerra gets the catalog (Sync & feed). Save.
4. In the AskMerra dashboard, allow your shop's domain for the widget. The chat appears on your storefront.
5. Follow the sync under WooCommerce > AskMerra.

The sync runs in WooCommerce's Action Scheduler. On quiet sites, or with `DISABLE_WP_CRON`, run `wp action-scheduler run --group=askmerra` from the server's cron every minute.

== Frequently Asked Questions ==

= A product is missing in AskMerra =

WooCommerce > AskMerra > Preview a product (or `wp askmerra payload <id or SKU>`) shows the product as AskMerra receives it, or why it is not sent.

= The chat does not show =

Check that a site key is set, that your domain is allowed in the AskMerra dashboard and that page caches were purged. The chat stays off the checkout page unless "Show on the checkout page" is on.

= How do I remove my products from AskMerra? =

Turn AskMerra off, then use "Remove its products from AskMerra" on the status page or `wp askmerra remove`. Delete feed sources in the AskMerra dashboard too.

== Changelog ==

= 1.0.2 =
* The chat's "Add to cart" button shows that the product was added.

= 1.0.1 =
* The askmerra:cart-added event's detail carries cartQty (the items in the cart after the add) instead of the misleading qty.

= 1.0.0 =
* First release.
