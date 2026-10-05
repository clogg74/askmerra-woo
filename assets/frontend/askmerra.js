/*
 * AskMerra for WooCommerce: connects the chat widget to the shop. Plain JavaScript, no build step
 * and no jQuery needed, so it runs with classic themes (Storefront...) and block themes.
 *
 * - "Add to cart" in the chat puts the product into the WooCommerce cart and refreshes the mini
 *   cart (classic cart fragments and the Mini-Cart block); other products open their page.
 * - With the "WP Consent API" consent mode, orders are reported once the shopper allows statistics.
 * - On the order received page, the order is reported for sales attribution.
 *
 * Settings come from window.AskMerraWooCommerce (Frontend\Widget). Themes can react to
 * document "askmerra:cart-added" events, e.g. to open their cart drawer.
 */
(function () {
    'use strict';

    var config = window.AskMerraWooCommerce || {};

    /** Calls back with window.AskMerra once the widget script has run. */
    function whenApi(callback) {
        var done = false;
        var tries = 0;
        var timer;

        function attempt() {
            var api = window.AskMerra;

            if (!done && api && typeof api.on === 'function') {
                done = true;
                callback(api);
            }

            return done;
        }

        if (attempt()) {
            return;
        }

        var script = document.getElementById('askmerra-widget-script');

        if (script) {
            script.addEventListener('load', attempt);
        }

        // The script can also be loaded late, e.g. by a tag manager: keep looking for a minute.
        timer = setInterval(function () {
            if (attempt() || ++tries > 240) {
                clearInterval(timer);
            }
        }, 250);
    }

    function open(url) {
        if (url && url !== '#') {
            window.location.href = url;
        }
    }

    /** A short message over the page and the chat. */
    function notify(text, link, isError) {
        var previous = document.getElementById('askmerra-woocommerce-notice');
        var box = document.createElement('div');

        if (previous && previous.parentNode) {
            previous.parentNode.removeChild(previous);
        }

        if (!text) {
            return;
        }

        box.id = 'askmerra-woocommerce-notice';
        box.setAttribute('role', isError ? 'alert' : 'status');
        box.style.cssText = 'position:fixed;left:50%;top:16px;transform:translateX(-50%);z-index:2147483647;'
            + 'max-width:calc(100% - 32px);box-sizing:border-box;padding:12px 16px;border-radius:8px;'
            + 'font:14px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#fff;'
            + 'background:' + (isError ? '#b42318' : '#1f2937') + ';box-shadow:0 8px 24px rgba(0,0,0,.25)';
        box.appendChild(document.createTextNode(text));

        if (link) {
            var anchor = document.createElement('a');

            anchor.href = link.href;
            anchor.textContent = link.text;
            anchor.style.cssText = 'color:inherit;text-decoration:underline;font-weight:600;margin-left:12px';
            box.appendChild(anchor);
        }

        document.body.appendChild(box);
        setTimeout(function () {
            if (box.parentNode) {
                box.parentNode.removeChild(box);
            }
        }, 6000);
    }

    /** Puts the fresh cart fragments (mini cart, header cart) into the page, as WooCommerce does. */
    function applyFragments(fragments) {
        Object.keys(fragments || {}).forEach(function (selector) {
            var nodes;

            try {
                nodes = document.querySelectorAll(selector);
            } catch (e) {
                return; // not a CSS selector
            }

            Array.prototype.forEach.call(nodes, function (node) {
                node.outerHTML = fragments[selector];
            });
        });
    }

    /** Refreshes the classic cart fragments, the Mini-Cart block and the checkout. */
    function refreshCart(result, detail) {
        var $ = window.jQuery;

        if ($) {
            // WooCommerce's add-to-cart script puts the fragments in when it is on the page.
            if (!window.wc_add_to_cart_params) {
                applyFragments(result.fragments);
                $(document.body).trigger('wc_fragments_loaded');
            }

            // Cart fragments storage, the Mini-Cart block (it listens to this jQuery event too) and other plugins.
            $(document.body).trigger('added_to_cart', [result.fragments, result.cart_hash]);
            $(document.body).trigger('update_checkout');
        } else {
            applyFragments(result.fragments);

            try {
                document.body.dispatchEvent(new CustomEvent('wc-blocks_added_to_cart', {
                    bubbles: true,
                    cancelable: true,
                    detail: { preserveCartData: false }
                }));
            } catch (e) {
                // very old browser
            }
        }

        try {
            document.dispatchEvent(new CustomEvent('askmerra:cart-added', { detail: detail }));
        } catch (e) {
            // very old browser
        }
    }

    function isCartPage() {
        return document.body.classList.contains('woocommerce-cart');
    }

    var busy = {};

    function addToCart(item) {
        item = item || {};

        var externalId = item.externalId === undefined || item.externalId === null ? '' : String(item.externalId);
        var sku = item.sku === undefined || item.sku === null ? '' : String(item.sku);
        var key = externalId || sku;
        var body;

        if (!config.ajaxUrl || !key || typeof window.fetch !== 'function') {
            return open(item.url);
        }

        if (busy[key]) {
            return;
        }

        busy[key] = true;
        body = new URLSearchParams();
        body.append('external_id', externalId);
        body.append('sku', sku);

        window.fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (response) {
            return response.json();
        }).then(function (result) {
            busy[key] = false;
            result = result || {};

            if (result.success) {
                refreshCart(result, { externalId: externalId, sku: sku, name: result.name, qty: result.qty });

                if (config.afterAdd === 'cart' && result.cartUrl) {
                    return open(result.cartUrl);
                }

                // The cart page lists its products only on load.
                if (isCartPage()) {
                    return window.location.reload();
                }

                return notify(result.message, result.cartUrl ? { href: result.cartUrl, text: result.cartLabel } : null, false);
            }

            if (result.redirect) {
                return open(result.redirect);
            }

            notify(result.message || config.errorMessage, null, true);
        })['catch'](function () {
            busy[key] = false;
            open(item.url);
        });
    }

    /** "WP Consent API": the shopper's "statistics" consent from the site's cookie banner. */
    function watchConsentApi(api) {
        function check() {
            if (typeof window.wp_has_consent === 'function') {
                api.setConsent({ analytics: !!window.wp_has_consent('statistics') });
            }
        }

        check();
        // The banner may set the consent type after the page loaded.
        document.addEventListener('wp_consent_type_defined', check);
        document.addEventListener('wp_listen_for_consent_change', function (event) {
            var changed = (event && event.detail) || {};

            if (Object.prototype.hasOwnProperty.call(changed, 'statistics')) {
                api.setConsent({ analytics: changed.statistics === 'allow' });
            }
        });
    }

    /** The order on the order received page (Frontend\Purchase), reported once. */
    function reportPurchase(api) {
        var order = window.AskMerraWooPurchase;
        var storageKey;

        if (!order || !order.transaction_id || typeof api.trackPurchase !== 'function') {
            return;
        }

        storageKey = 'askmerra:order:' + order.transaction_id;

        try {
            if (window.sessionStorage.getItem(storageKey)) {
                return;
            }

            window.sessionStorage.setItem(storageKey, '1');
        } catch (e) {
            // storage blocked: AskMerra ignores an order reported twice anyway
        }

        api.trackPurchase(order);
    }

    whenApi(function (api) {
        if (config.addToCart && config.ajaxUrl) {
            api.on('add_to_cart', addToCart);
        }

        if (config.consentMode === 'wp_consent_api' && typeof api.setConsent === 'function') {
            watchConsentApi(api);
        }

        reportPurchase(api);
    });
})();
