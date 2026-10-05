/*
 * A stand-in for the AskMerra chat widget (https://cdn.askmerra.com/v1/widget.js), for development
 * and demos only - never on a live shop. It needs no AskMerra account: set it as the "Widget script
 * URL" (WooCommerce > Settings > AskMerra > Storefront widget), e.g.
 *
 *   https://<shop>/wp-content/plugins/askmerra-for-woocommerce/dev/mock-widget.js
 *
 * It reads the same script data attributes and window.AskMerraSettings as the real widget and
 * offers the same window.AskMerra API (on, setProduct, setConsent, trackPurchase, open...), logging
 * every call to the console. A small panel lists a few products of the shop (WooCommerce Store API)
 * with "Add to cart" buttons that emit add_to_cart exactly like the real widget, and shows the
 * product of the page, the analytics consent and the purchases reported - so add to cart, product
 * context and order reporting can be tried in a browser.
 */
(function () {
    'use strict';

    // The real widget (or this one) is already on the page.
    if (window.AskMerra && window.AskMerra.version) {
        return;
    }

    var script = document.currentScript || document.querySelector('script[data-site-key]');
    var data = (script && script.dataset) || {};
    var settings = window.AskMerraSettings || {};
    var boot = {
        siteKey: data.siteKey || settings.siteKey || '',
        locale: data.locale || settings.locale || '',
        position: data.position || settings.position || 'bottom-right',
        open: data.open === 'true' || settings.open === true,
        apiUrl: data.apiUrl || settings.apiUrl || ''
    };
    var state = {
        ready: false,
        open: boot.open,
        productId: settings.productId === undefined || settings.productId === null ? null : String(settings.productId),
        consent: settings.consent && typeof settings.consent.analytics === 'boolean' ? settings.consent.analytics : null,
        purchases: [],
        products: null,
        lines: []
    };
    var handlers = {};
    var ui = null;

    function log() {
        var args = Array.prototype.slice.call(arguments);

        args.unshift('[AskMerra mock]');
        console.info.apply(console, args);
    }

    /** Calls the page's handlers; true when there was one (the real widget then does nothing more). */
    function emit(event, payload) {
        var list = (handlers[event] || []).slice();

        list.forEach(function (handler) {
            try {
                handler(payload);
            } catch (e) {
                console.error('[AskMerra mock] handler error', e);
            }
        });

        return list.length > 0;
    }

    /** As the real widget: an explicit setConsent wins; without any signal nothing is sent. */
    function sendPurchases() {
        state.purchases.forEach(function (purchase) {
            if (!purchase.sent && state.consent === true) {
                purchase.sent = true;
                log('purchase sent to AskMerra', purchase.order);
            }
        });
    }

    var api = {
        version: 'mock',
        open: function () {
            log('open()');
            setOpen(true);
        },
        close: function () {
            log('close()');
            setOpen(false);
        },
        toggle: function () {
            log('toggle()');
            setOpen(!state.open);
        },
        sendMessage: function (text) {
            log('sendMessage()', text);
            state.lines.push(String(text));
            setOpen(true);
            emit('message', { role: 'user', text: String(text) });
        },
        setLocale: function (locale) {
            log('setLocale()', locale);
            boot.locale = String(locale || '');
            render();
        },
        identify: function (identity) {
            log('identify()', identity);
        },
        setProduct: function (productId) {
            log('setProduct()', productId);
            state.productId = productId === undefined || productId === null ? null : String(productId);
            render();
        },
        trackPurchase: function (order) {
            var id = order && order.transaction_id ? String(order.transaction_id) : '';

            log('trackPurchase()', order);

            if (!id || state.purchases.some(function (purchase) { return purchase.id === id; })) {
                return;
            }

            state.purchases.push({ id: id, order: order, sent: false });
            sendPurchases();
            render();
        },
        setConsent: function (consent) {
            log('setConsent()', consent);

            if (consent && typeof consent.analytics === 'boolean') {
                state.consent = consent.analytics;
                sendPurchases();
                render();
            }
        },
        forget: function () {
            log('forget()');

            return Promise.resolve();
        },
        on: function (event, handler) {
            if (typeof handler !== 'function') {
                return function () {};
            }

            log('on()', event);
            (handlers[event] = handlers[event] || []).push(handler);

            if (event === 'ready' && state.ready) {
                Promise.resolve().then(function () {
                    handler();
                });
            }

            return function () {
                var list = handlers[event] || [];
                var index = list.indexOf(handler);

                if (index >= 0) {
                    list.splice(index, 1);
                }
            };
        }
    };

    window.AskMerra = api;

    function externalIdOf(product) {
        var identifier = (window.AskMerraWooCommerce || {}).productIdentifier;

        if (identifier !== 'sku') {
            return String(product.id);
        }

        // As the plugin: a product without a SKU is "id:<product id>".
        return product.sku ? String(product.sku) : 'id:' + product.id;
    }

    function decode(text) {
        var area = document.createElement('textarea');

        area.innerHTML = String(text || '');

        return area.value;
    }

    function loadProducts() {
        var root = (window.wpApiSettings && window.wpApiSettings.root) || '/wp-json/';

        if (typeof window.fetch !== 'function') {
            state.products = [];

            return;
        }

        window.fetch(root.replace(/\/?$/, '/') + 'wc/store/v1/products?per_page=6', { credentials: 'same-origin' })
            .then(function (response) {
                return response.ok ? response.json() : [];
            })
            .then(function (products) {
                state.products = (Array.isArray(products) ? products : []).map(function (product) {
                    return {
                        externalId: externalIdOf(product),
                        sku: product.sku || null,
                        name: decode(product.name),
                        type: product.type,
                        url: product.permalink
                    };
                });
                render();
            })['catch'](function () {
                state.products = [];
                render();
            });
    }

    function element(tag, attributes, children) {
        var node = document.createElement(tag);

        Object.keys(attributes || {}).forEach(function (name) {
            if (name === 'onclick') {
                node.addEventListener('click', attributes[name]);
            } else {
                node.setAttribute(name, attributes[name]);
            }
        });

        (children || []).forEach(function (child) {
            node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
        });

        return node;
    }

    function setOpen(open) {
        if (state.open !== open) {
            state.open = open;
            emit(open ? 'open' : 'close');
        }

        render();
    }

    function consentText() {
        if (state.consent === true) {
            return 'granted';
        }

        return state.consent === false ? 'denied' : 'not given (orders wait)';
    }

    function render() {
        if (!ui) {
            return;
        }

        var body = ui.body;
        var side = boot.position === 'bottom-left' ? 'left' : 'right';

        ui.launcher.style.cssText = 'position:fixed;bottom:20px;' + side + ':20px;';
        ui.panel.style.cssText = 'position:fixed;bottom:84px;' + side + ':20px;display:' + (state.open ? 'block' : 'none') + ';';
        ui.launcher.setAttribute('aria-expanded', state.open ? 'true' : 'false');
        body.textContent = '';

        body.appendChild(element('dl', {}, [
            element('dt', {}, ['Site key']), element('dd', {}, [boot.siteKey ? boot.siteKey.slice(0, 12) + '...' : 'missing']),
            element('dt', {}, ['Language']), element('dd', {}, [boot.locale || 'shop default']),
            element('dt', {}, ['Product on this page']), element('dd', {}, [state.productId || 'none']),
            element('dt', {}, ['Analytics consent']), element('dd', {}, [consentText()]),
            element('dt', {}, ['Add to cart handler']), element('dd', {}, [(handlers.add_to_cart || []).length ? 'registered by the shop' : 'none (opens the product page)'])
        ]));

        state.lines.slice(-3).forEach(function (line) {
            body.appendChild(element('p', { 'class': 'order' }, ['Message: ' + line]));
        });

        state.purchases.forEach(function (purchase) {
            var order = purchase.order || {};

            body.appendChild(element('p', { 'class': 'order' }, [
                'Order ' + purchase.id + ': ' + order.value + ' ' + (order.currency || '') + ', '
                    + ((order.items || []).length) + ' products - ' + (purchase.sent ? 'sent' : 'waiting for consent')
            ]));
        });

        if (state.products === null) {
            body.appendChild(element('p', {}, ['Loading products...']));
        } else if (!state.products.length) {
            body.appendChild(element('p', {}, ['No products from the Store API.']));
        }

        (state.products || []).forEach(function (product) {
            var card = { externalId: product.externalId, sku: product.sku, url: product.url };

            body.appendChild(element('div', { 'class': 'product' }, [
                element('a', {
                    href: product.url,
                    onclick: function (event) {
                        log('product_click', card);

                        if (emit('product_click', card)) {
                            event.preventDefault();
                        }
                    }
                }, [product.name]),
                element('small', {}, [' #' + product.externalId + ' (' + product.type + ')']),
                element('button', {
                    type: 'button',
                    onclick: function () {
                        log('add_to_cart', card);

                        // As the real widget: the shop's handler, else the product page.
                        if (!emit('add_to_cart', card) && card.url) {
                            window.location.href = card.url;
                        }
                    }
                }, ['Add to cart'])
            ]));
        });
    }

    function mount() {
        var host = document.createElement('askmerra-mock-widget');
        var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
        var style = document.createElement('style');

        style.textContent = ''
            + ':host{all:initial}'
            + 'button,a,p,dl,dt,dd,small,strong{font:14px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
            + '.launcher{z-index:2147483646;border:0;border-radius:24px;padding:12px 18px;background:#4f46e5;color:#fff;cursor:pointer;box-shadow:0 8px 24px rgba(0,0,0,.25)}'
            + '.panel{z-index:2147483646;width:320px;max-width:calc(100vw - 40px);max-height:70vh;overflow:auto;box-sizing:border-box;padding:16px;border-radius:12px;background:#fff;color:#111827;box-shadow:0 12px 32px rgba(0,0,0,.3)}'
            + '.title{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}'
            + '.title strong{font-weight:700}'
            + '.title button{border:0;background:none;cursor:pointer;font-size:18px}'
            + 'dl{display:grid;grid-template-columns:auto 1fr;gap:2px 8px;margin:0 0 12px}'
            + 'dt{color:#6b7280}dd{margin:0;word-break:break-all}'
            + '.order{margin:0 0 8px;padding:8px;border-radius:8px;background:#ecfdf5}'
            + '.product{display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px;padding:8px 0;border-top:1px solid #e5e7eb}'
            + '.product a{color:#111827;font-weight:600;text-decoration:none;flex:1 1 60%}'
            + '.product small{color:#6b7280}'
            + '.product button{border:0;border-radius:6px;padding:6px 10px;background:#111827;color:#fff;cursor:pointer}';

        var launcher = element('button', { type: 'button', 'class': 'launcher', 'aria-label': 'AskMerra (mock)', onclick: function () { api.toggle(); } }, ['AskMerra (mock)']);
        var body = element('div', {});
        var panel = element('div', { 'class': 'panel', role: 'dialog', 'aria-label': 'AskMerra (mock)' }, [
            element('div', { 'class': 'title' }, [
                element('strong', {}, ['AskMerra (mock)']),
                element('button', { type: 'button', 'aria-label': 'Close', onclick: function () { api.close(); } }, ['×'])
            ]),
            body
        ]);

        root.appendChild(style);
        root.appendChild(launcher);
        root.appendChild(panel);
        document.body.appendChild(host);
        ui = { launcher: launcher, panel: panel, body: body };
    }

    function start() {
        log('started', { boot: boot, settings: settings });

        if (!boot.siteKey) {
            console.warn('[AskMerra mock] missing data-site-key on the widget script tag');
        }

        mount();
        loadProducts();
        render();
        state.ready = true;
        emit('ready');

        if (typeof settings.onReady === 'function') {
            settings.onReady();
        }
    }

    // Like the real widget: after the page has loaded.
    if (document.readyState === 'complete') {
        start();
    } else {
        window.addEventListener('load', start, { once: true });
    }
})();
