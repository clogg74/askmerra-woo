<?php
/**
 * Plugin Name:          AskMerra for WooCommerce
 * Plugin URI:           https://askmerra.com
 * Description:          The AskMerra AI shopping assistant for WooCommerce: catalog sync through the Push API or a product feed, the chat widget, add to cart from the chat and sales attribution.
 * Version:              1.0.1
 * Requires at least:    6.3
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * WC tested up to:      11.1
 * Author:               AskMerra
 * Author URI:           https://askmerra.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          askmerra-for-woocommerce
 * Domain Path:          /languages
 */

defined('ABSPATH') || exit;

define('ASKMERRA_WC_VERSION', '1.0.1');
define('ASKMERRA_WC_FILE', __FILE__);
define('ASKMERRA_WC_DIR', plugin_dir_path(__FILE__));
define('ASKMERRA_WC_URL', plugin_dir_url(__FILE__));

// PSR-4: AskMerra\WooCommerce\Sync\Queue => includes/Sync/Queue.php
spl_autoload_register(static function (string $class): void {
    $prefix = 'AskMerra\\WooCommerce\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = ASKMERRA_WC_DIR . 'includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

// High-performance order storage and the cart & checkout blocks are supported.
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', ASKMERRA_WC_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', ASKMERRA_WC_FILE, true);
    }
});

register_activation_hook(__FILE__, [\AskMerra\WooCommerce\Install::class, 'activate']);
register_deactivation_hook(__FILE__, [\AskMerra\WooCommerce\Install::class, 'deactivate']);

// Translations load on init (WordPress 6.7+ warns about earlier loading); nothing translatable runs before.
add_action('init', static function (): void {
    load_plugin_textdomain('askmerra-for-woocommerce', false, dirname(plugin_basename(ASKMERRA_WC_FILE)) . '/languages');
}, 0);

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('AskMerra for WooCommerce needs WooCommerce to be installed and active.', 'askmerra-for-woocommerce')
                . '</p></div>';
        });

        return;
    }

    \AskMerra\WooCommerce\Plugin::instance()->boot();
}, 20);
