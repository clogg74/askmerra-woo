/**
 * AskMerra for WooCommerce, admin screens: the settings (rows that depend on another setting,
 * "Check connection", copy buttons), the sync status page (confirmations) and the products list
 * (quick edit). Plain JavaScript, no build step.
 */
(function ($) {
    'use strict';

    var config = window.AskMerraAdmin || {i18n: {}};
    var WIDGET_ROWS = [
        'askmerra_widget_script_url', 'askmerra_widget_position', 'askmerra_widget_open_on_load',
        'askmerra_widget_show_on_checkout', 'askmerra_widget_product_context', 'askmerra_widget_add_to_cart',
        'askmerra_widget_track_purchases', 'askmerra_widget_consent'
    ];
    var FEED_ROWS = ['askmerra_feed_format', 'askmerra_feed_frequency', 'askmerra_feed_urls'];

    function rowOf(id) {
        var field = document.getElementById(id);

        return field ? field.closest('tr') : null;
    }

    function toggle(element, show) {
        if (element) {
            element.style.display = show ? '' : 'none';
        }
    }

    function isChecked(id) {
        var field = document.getElementById(id);

        return !!(field && field.checked);
    }

    /** Rows that only matter with another setting, like Magento's <depends>. */
    function updateDependentRows() {
        var method = document.getElementById('askmerra_sync_method');

        if (method) {
            var feed = method.value === 'feed';
            var description = document.getElementById('askmerra_feed-description');

            toggle(rowOf('askmerra_batch_size'), !feed);
            FEED_ROWS.forEach(function (id) {
                toggle(rowOf(id), feed);
            });

            if (description) {
                toggle(description, feed);
                toggle(description.previousElementSibling && description.previousElementSibling.tagName === 'H2' ? description.previousElementSibling : null, feed);
            }
        }

        var widget = document.getElementById('askmerra_widget_enabled');

        if (widget) {
            WIDGET_ROWS.forEach(function (id) {
                toggle(rowOf(id), widget.checked);
            });
            toggle(rowOf('askmerra_widget_after_add'), widget.checked && isChecked('askmerra_widget_add_to_cart'));
        }

        var consent = document.getElementById('askmerra_widget_consent');

        // Only rendered when the WP Consent API plugin is missing.
        toggle(document.getElementById('askmerra-consent-api-missing'), !!consent && consent.value === 'wp_consent_api');
    }

    /** "Check connection": the keys as typed, before saving. */
    function setUpConnectionTest() {
        var button = document.getElementById('askmerra-test-connection');
        var output = document.getElementById('askmerra-test-connection-result');

        if (!button || !output) {
            return;
        }

        function show(messages) {
            output.innerHTML = '';
            messages.forEach(function (message) {
                var box = document.createElement('div');
                var text = document.createElement('p');
                var type = ['success', 'warning', 'error'].indexOf(message.type) !== -1 ? message.type : 'info';

                box.className = 'notice inline notice-' + type;
                text.textContent = message.text;
                box.appendChild(text);
                output.appendChild(box);
            });
        }

        button.addEventListener('click', function () {
            var body = new URLSearchParams();

            body.append('action', config.testAction);
            body.append('nonce', config.testNonce);
            [['secret_key', 'askmerra_secret_key'], ['site_key', 'askmerra_site_key'], ['api_url', 'askmerra_api_url']].forEach(function (pair) {
                var field = document.getElementById(pair[1]);

                if (field && !field.disabled) {
                    body.append(pair[0], field.value);
                }
            });

            button.disabled = true;
            show([{type: 'info', text: config.i18n.checking}]);

            fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {Accept: 'application/json'},
                body: body
            }).then(function (response) {
                return response.json();
            }).then(function (result) {
                show(result && result.messages ? result.messages : [{type: 'error', text: config.i18n.failed}]);
            })['catch'](function () {
                show([{type: 'error', text: config.i18n.failed}]);
            }).then(function () {
                button.disabled = false;
            });
        });
    }

    /** Buttons with data-askmerra-copy="<input id>" copy that input's value. */
    function setUpCopyButtons() {
        document.addEventListener('click', function (event) {
            var button = event.target.closest ? event.target.closest('[data-askmerra-copy]') : null;
            var input = button ? document.getElementById(button.getAttribute('data-askmerra-copy')) : null;

            if (!input) {
                return;
            }

            event.preventDefault();

            if (!button.hasAttribute('data-label')) {
                button.setAttribute('data-label', button.textContent);
            }

            function done() {
                button.textContent = config.i18n.copied;
                window.setTimeout(function () {
                    button.textContent = button.getAttribute('data-label');
                }, 1500);
            }

            // Older browsers, or a refused clipboard: copy the selection; it stays selected either way.
            function copySelection() {
                input.focus();
                input.select();

                try {
                    if (document.execCommand('copy')) {
                        done();
                    }
                } catch (error) {
                    // Nothing more to try: the URL is selected for Ctrl/Cmd+C.
                }
            }

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(input.value).then(done, copySelection);
            } else {
                copySelection();
            }
        });
    }

    /** Status page actions that need a second thought. */
    function setUpConfirmations() {
        document.addEventListener('submit', function (event) {
            var form = event.target;

            if (form.matches && form.matches('form[data-askmerra-confirm]') && !window.confirm(form.getAttribute('data-askmerra-confirm'))) {
                event.preventDefault();
            }
        });
    }

    /** Quick edit starts from the product's "Hide from AskMerra" choice. */
    function setUpQuickEdit() {
        if (!window.inlineEditPost || typeof window.inlineEditPost.edit !== 'function') {
            return;
        }

        var edit = window.inlineEditPost.edit;

        window.inlineEditPost.edit = function (id) {
            edit.apply(this, arguments);

            var postId = parseInt(typeof id === 'object' ? this.getId(id) : id, 10);
            var data = postId ? document.getElementById('askmerra_inline_' + postId) : null;
            var row = postId ? document.getElementById('edit-' + postId) : null;
            var checkbox = row ? row.querySelector('input[name="_askmerra_exclude"]') : null;

            if (data && checkbox) {
                checkbox.checked = data.getAttribute('data-hidden') === 'yes';
            }
        };
    }

    $(function () {
        updateDependentRows();
        $(document).on('change', '#askmerra_sync_method, #askmerra_widget_enabled, #askmerra_widget_add_to_cart, #askmerra_widget_consent', updateDependentRows);
        setUpConnectionTest();
        setUpCopyButtons();
        setUpConfirmations();
        setUpQuickEdit();
    });
})(jQuery);
