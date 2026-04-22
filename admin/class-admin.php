<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OtwFeed_Admin {

    private static ?OtwFeed_Admin $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',             array( $this, 'register_menus' ) );
        add_action( 'admin_enqueue_scripts',  array( $this, 'enqueue_assets' ) );
        add_filter( 'admin_body_class',       array( $this, 'add_body_class' ) );
    }

    public function add_body_class( string $classes ): string {
        $screen = get_current_screen();
        if ( $screen && false !== strpos( $screen->id, 'otwfeed' ) ) {
            $classes .= ' otwfeed-admin-page';
        }
        return $classes;
    }

    public function register_menus(): void {
        add_menu_page(
            __( 'OtwFeed Pro', 'otwfeed-pro' ),
            __( 'OtwFeed Pro', 'otwfeed-pro' ),
            'manage_woocommerce',
            'otwfeed-pro',
            array( $this, 'page_dashboard' ),
            'dashicons-rss',
            56
        );

        add_submenu_page(
            'otwfeed-pro',
            __( 'Dashboard', 'otwfeed-pro' ),
            __( 'Dashboard', 'otwfeed-pro' ),
            'manage_woocommerce',
            'otwfeed-pro',
            array( $this, 'page_dashboard' )
        );

        add_submenu_page(
            'otwfeed-pro',
            __( 'New Feed', 'otwfeed-pro' ),
            __( 'New Feed', 'otwfeed-pro' ),
            'manage_woocommerce',
            'otwfeed-new',
            array( $this, 'page_feed_wizard' )
        );

        add_submenu_page(
            'otwfeed-pro',
            __( 'Channel Mapping', 'otwfeed-pro' ),
            __( 'Channel Mapping', 'otwfeed-pro' ),
            'manage_woocommerce',
            'otwfeed-mapping',
            array( $this, 'page_channel_mapping' )
        );

        add_submenu_page(
            'otwfeed-pro',
            __( 'Tax & Currency', 'otwfeed-pro' ),
            __( 'Tax & Currency', 'otwfeed-pro' ),
            'manage_woocommerce',
            'otwfeed-tax-currency',
            array( $this, 'page_tax_currency' )
        );

        add_submenu_page(
            'otwfeed-pro',
            __( 'Filters', 'otwfeed-pro' ),
            __( 'Filters', 'otwfeed-pro' ),
            'manage_woocommerce',
            'otwfeed-filters',
            array( $this, 'page_filters' )
        );

        add_submenu_page(
            'otwfeed-pro',
            __( 'Settings', 'otwfeed-pro' ),
            __( 'Settings', 'otwfeed-pro' ),
            'manage_woocommerce',
            'otwfeed-settings',
            array( $this, 'page_settings' )
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( false === strpos( $hook, 'otwfeed' ) ) {
            return;
        }

        // Vendor: Bootstrap 5.
        wp_enqueue_style(
            'otwfeed-bootstrap',
            OTWFEED_URL . 'assets/css/vendor/bootstrap.min.css',
            array(),
            '5.3.3'
        );
        wp_enqueue_script(
            'otwfeed-bootstrap',
            OTWFEED_URL . 'assets/js/vendor/bootstrap.bundle.min.js',
            array(),
            '5.3.3',
            true
        );

        // Vendor: Select2.
        wp_enqueue_style(
            'otwfeed-select2',
            OTWFEED_URL . 'assets/css/vendor/select2.min.css',
            array(),
            '4.1.0'
        );
        wp_enqueue_script(
            'otwfeed-select2',
            OTWFEED_URL . 'assets/js/vendor/select2.min.js',
            array( 'jquery' ),
            '4.1.0',
            true
        );

        // Plugin admin CSS/JS.
        wp_enqueue_style(
            'otwfeed-admin',
            OTWFEED_URL . 'assets/css/admin.css',
            array( 'otwfeed-bootstrap', 'otwfeed-select2' ),
            (string) filemtime( OTWFEED_DIR . 'assets/css/admin.css' )
        );
        wp_enqueue_script(
            'otwfeed-admin',
            OTWFEED_URL . 'assets/js/admin.js',
            array( 'jquery', 'otwfeed-bootstrap', 'otwfeed-select2' ),
            (string) filemtime( OTWFEED_DIR . 'assets/js/admin.js' ),
            true
        );

        // Localize.
        $feed_id   = absint( $_GET['feed_id'] ?? 0 );
        $nonce     = wp_create_nonce( 'otwfeed_ajax' );
        $currencies = OtwFeed_Currency_Manager::get_currencies();

        wp_localize_script( 'otwfeed-admin', 'otwfeedAdmin', array(
            'ajaxurl'     => admin_url( 'admin-ajax.php' ),
            'restUrl'     => esc_url_raw( rest_url( 'otwfeed-pro/v1/' ) ),
            'nonce'       => $nonce,
            'feedId'      => $feed_id,
            'currencies'  => $currencies,
            'integration' => OtwFeed_Currency_Manager::detect(),
            'filterAttrs' => OtwFeed_DB_Filters::get_attributes(),
            'filterConds' => OtwFeed_DB_Filters::get_conditions(),
            'i18n'        => array(
                'confirmDelete'    => __( 'Delete this feed?', 'otwfeed-pro' ),
                'confirmRegen'     => __( 'Regenerate this feed now?', 'otwfeed-pro' ),
                'generating'       => __( 'Generating…', 'otwfeed-pro' ),
                'done'             => __( 'Done', 'otwfeed-pro' ),
                'error'            => __( 'Error', 'otwfeed-pro' ),
                'addRow'           => __( 'Add Row', 'otwfeed-pro' ),
                'remove'           => __( 'Remove', 'otwfeed-pro' ),
                'staticPlaceholder' => __( 'Enter static value…', 'otwfeed-pro' ),
                'staticLabel'       => __( 'Static value', 'otwfeed-pro' ),
                'sourceLabel'       => __( 'Source field', 'otwfeed-pro' ),
                'metaKeysLabel'     => __( 'Known Meta Keys', 'otwfeed-pro' ),
                'metaPlaceholder'   => __( 'Select or type a meta key…', 'otwfeed-pro' ),
                'roundPrice'        => __( 'Round price', 'otwfeed-pro' ),
                'exclude'           => __( 'Exclude', 'otwfeed-pro' ),
                'include'           => __( 'Include', 'otwfeed-pro' ),
                'addCondition'      => __( 'Add Condition', 'otwfeed-pro' ),
                'enterValue'        => __( 'Enter value', 'otwfeed-pro' ),
                'caseSensitive'     => __( 'Case sensitive', 'otwfeed-pro' ),
            ),
        ) );
    }

    // ── Page renderers ────────────────────────────────────────────────────────

    public function page_dashboard(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'otwfeed-pro' ) );
        }
        require OTWFEED_DIR . 'admin/templates/dashboard.php';
    }

    public function page_feed_wizard(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'otwfeed-pro' ) );
        }
        require OTWFEED_DIR . 'admin/templates/feed-wizard.php';
    }

    public function page_channel_mapping(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'otwfeed-pro' ) );
        }
        require OTWFEED_DIR . 'admin/templates/channel-mapping.php';
    }

    public function page_tax_currency(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'otwfeed-pro' ) );
        }
        require OTWFEED_DIR . 'admin/templates/tax-currency.php';
    }

    public function page_filters(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'otwfeed-pro' ) );
        }
        require OTWFEED_DIR . 'admin/templates/filter-manager.php';
    }

    public function page_settings(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'otwfeed-pro' ) );
        }
        require OTWFEED_DIR . 'admin/templates/settings.php';
    }
}
