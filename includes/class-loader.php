<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OtwFeed_Loader {

    private static ?OtwFeed_Loader $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function init(): void {
        $this->require_files();
        $this->run_db_upgrade();
        $this->init_admin();
        $this->init_api();
        $this->init_frontend();
        $this->load_textdomain();
    }

    private function require_files(): void {
        // DB repositories.
        require_once OTWFEED_DIR . 'includes/db/class-db-feeds.php';
        require_once OTWFEED_DIR . 'includes/db/class-db-mappings.php';
        require_once OTWFEED_DIR . 'includes/db/class-db-filters.php';
        require_once OTWFEED_DIR . 'includes/db/class-db-logs.php';

        // Integrations.
        require_once OTWFEED_DIR . 'includes/integrations/class-currency-manager.php';
        require_once OTWFEED_DIR . 'includes/integrations/class-tax-calculator.php';
        require_once OTWFEED_DIR . 'includes/integrations/class-price-calculator.php';

        // Feed engine.
        require_once OTWFEED_DIR . 'includes/feed/class-product-query.php';
        require_once OTWFEED_DIR . 'includes/feed/class-feed-builder-google.php';
        require_once OTWFEED_DIR . 'includes/feed/class-feed-builder-facebook.php';
        require_once OTWFEED_DIR . 'includes/feed/class-feed-generator.php';
        require_once OTWFEED_DIR . 'includes/class-background-generator.php';

        // REST.
        require_once OTWFEED_DIR . 'includes/api/class-rest-feeds.php';

        // Admin.
        require_once OTWFEED_DIR . 'admin/class-ajax-handler.php';
        require_once OTWFEED_DIR . 'admin/class-admin.php';
    }

    private function run_db_upgrade(): void {
        $installed = (int) get_option( 'otwfeed_db_version', 0 );
        if ( $installed < OTWFEED_DB_VER ) {
            if ( $installed < 4 ) {
                // Filters table schema changed completely in v4 — drop and recreate.
                OtwFeed_Activator::drop_filters_table();
            }
            OtwFeed_Activator::activate();
        }
    }

    private function init_frontend(): void {
        // When a feed checkout link includes ?wc-country=IL, pre-fill the
        // checkout billing/shipping country so tax is applied immediately.
        add_action( 'template_redirect', static function () {
            if ( ! is_checkout() || empty( $_GET['wc-country'] ) ) {
                return;
            }
            $country = strtoupper( sanitize_text_field( wp_unslash( $_GET['wc-country'] ) ) );
            if ( ! array_key_exists( $country, WC()->countries->get_countries() ) ) {
                return;
            }
            WC()->customer->set_billing_country( $country );
            WC()->customer->set_shipping_country( $country );
        } );
    }

    private function init_admin(): void {
        if ( is_admin() ) {
            OtwFeed_Admin::get_instance();
            OtwFeed_Ajax_Handler::get_instance();
        }
    }

    private function init_api(): void {
        // Action Scheduler hook — each batch fires this when WP-Cron runs.
        add_action( OtwFeed_Background_Generator::AS_HOOK, array( 'OtwFeed_Background_Generator', 'run_batch' ), 10, 2 );

        add_action( 'rest_api_init', static function () {
            $controller = new OtwFeed_REST_Feeds();
            $controller->register_routes();
            OtwFeed_Background_Generator::register_rest_route(); // legacy fallback
        } );
    }

    private function load_textdomain(): void {
        load_plugin_textdomain( 'otwfeed-pro', false, dirname( plugin_basename( OTWFEED_FILE ) ) . '/languages' );
    }
}
