<?php
declare(strict_types=1);
/**
 * Plugin Name:       OtwFeed Pro for WooCommerce
 * Plugin URI:        https://otwdesign.it/otwfeed-pro
 * Description:       Localized product feeds for Google Merchant Center and Facebook Business Manager with advanced currency and tax handling.
 * Version:           1.0.0
 * Author:            OTW Design
 * Author URI:        https://otwdesign.it
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       otwfeed-pro
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * WC requires at least: 7.0
 * WC tested up to:   9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'OTWFEED_VERSION',  '1.0.0' );
define( 'OTWFEED_FILE',     __FILE__ );
define( 'OTWFEED_DIR',      plugin_dir_path( __FILE__ ) );
define( 'OTWFEED_URL',      plugin_dir_url( __FILE__ ) );
define( 'OTWFEED_SLUG',     'otwfeed-pro' );
define( 'OTWFEED_DB_VER',   6 );

// WooCommerce HPOS compatibility declaration.
add_action( 'before_woocommerce_init', static function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

require_once OTWFEED_DIR . 'includes/class-activator.php';
register_activation_hook( __FILE__, array( 'OtwFeed_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OtwFeed_Activator', 'deactivate' ) );

add_action( 'plugins_loaded', static function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', static function () {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'OtwFeed Pro requires WooCommerce to be installed and active.', 'otwfeed-pro' );
            echo '</p></div>';
        } );
        return;
    }

    require_once OTWFEED_DIR . 'includes/class-loader.php';
    OtwFeed_Loader::get_instance()->init();
} );
