<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OtwFeed_Ajax_Handler {

    private static ?OtwFeed_Ajax_Handler $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $actions = array(
            'otwfeed_save_feed',
            'otwfeed_delete_feed',
            'otwfeed_generate_feed',
            'otwfeed_generate_start',
            'otwfeed_generate_batch',
            'otwfeed_generate_finish',
            'otwfeed_save_mappings',
            'otwfeed_save_filters',
            'otwfeed_get_feed',
            'otwfeed_preview_price',
            'otwfeed_get_wc_fields',
        );

        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, array( $this, str_replace( 'otwfeed_', 'handle_', $action ) ) );
        }
    }

    private function check_nonce(): void {
        check_ajax_referer( 'otwfeed_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'otwfeed-pro' ) ), 403 );
        }
    }

    public function handle_save_feed(): void {
        $this->check_nonce();

        $id      = absint( $_POST['id'] ?? 0 );
        $data    = array(
            'title'             => sanitize_text_field( $_POST['title'] ?? '' ),
            'channel'           => sanitize_text_field( $_POST['channel'] ?? 'google' ),
            'country'           => strtoupper( sanitize_text_field( $_POST['country'] ?? '' ) ),
            'currency'          => strtoupper( sanitize_text_field( $_POST['currency'] ?? 'EUR' ) ),
            'tax_mode'          => sanitize_text_field( $_POST['tax_mode'] ?? 'include' ),
            'status'            => sanitize_text_field( $_POST['status'] ?? 'active' ),
            'expand_variations'      => absint( $_POST['expand_variations'] ?? 1 ),
            'include_gallery_images' => absint( $_POST['include_gallery_images'] ?? 1 ),
            'skip_country_param'     => absint( $_POST['skip_country_param']     ?? 0 ),
            'skip_currency_param'    => absint( $_POST['skip_currency_param']    ?? 0 ),
        );

        if ( empty( $data['title'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Title is required.', 'otwfeed-pro' ) ) );
        }

        if ( $id > 0 ) {
            OtwFeed_DB_Feeds::update( $id, $data );
            wp_send_json_success( array( 'id' => $id, 'message' => __( 'Feed updated.', 'otwfeed-pro' ) ) );
        } else {
            $new_id = OtwFeed_DB_Feeds::insert( $data );
            if ( false === $new_id ) {
                wp_send_json_error( array( 'message' => __( 'Could not create feed.', 'otwfeed-pro' ) ) );
            }

            // Insert default mappings for new feed.
            $defaults = 'google' === $data['channel']
                ? OtwFeed_DB_Mappings::get_default_google_mappings()
                : OtwFeed_DB_Mappings::get_default_facebook_mappings();

            foreach ( $defaults as $row ) {
                $row['feed_id'] = $new_id;
                OtwFeed_DB_Mappings::insert( $row );
            }

            wp_send_json_success( array( 'id' => $new_id, 'message' => __( 'Feed created.', 'otwfeed-pro' ) ) );
        }
    }

    public function handle_delete_feed(): void {
        $this->check_nonce();

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid ID.', 'otwfeed-pro' ) ) );
        }

        OtwFeed_DB_Mappings::delete_for_feed( $id );
        OtwFeed_DB_Filters::delete_for_feed( $id );
        OtwFeed_DB_Logs::clear_for_feed( $id );
        OtwFeed_DB_Feeds::delete( $id );

        wp_send_json_success( array( 'message' => __( 'Feed deleted.', 'otwfeed-pro' ) ) );
    }

    public function handle_generate_feed(): void {
        $this->check_nonce();

        $id     = absint( $_POST['id'] ?? 0 );
        $result = OtwFeed_Feed_Generator::generate( $id );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( array( 'message' => $result['error'] ) );
        }
    }

    public function handle_generate_start(): void {
        $this->check_nonce();
        $id     = absint( $_POST['id'] ?? 0 );
        $result = OtwFeed_Feed_Generator::generate_start( $id );
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( array( 'message' => $result['error'] ) );
        }
    }

    public function handle_generate_batch(): void {
        $this->check_nonce();
        $id          = absint( $_POST['id'] ?? 0 );
        $batch_index = absint( $_POST['batch_index'] ?? 0 );
        $result      = OtwFeed_Feed_Generator::generate_batch( $id, $batch_index );
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( array( 'message' => $result['error'] ) );
        }
    }

    public function handle_generate_finish(): void {
        $this->check_nonce();
        $id     = absint( $_POST['id'] ?? 0 );
        $result = OtwFeed_Feed_Generator::generate_finish( $id );
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( array( 'message' => $result['error'] ) );
        }
    }

    public function handle_save_mappings(): void {
        $this->check_nonce();

        $feed_id = absint( $_POST['feed_id'] ?? 0 );
        $rows    = isset( $_POST['mappings'] ) ? (array) $_POST['mappings'] : array();

        if ( ! $feed_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid feed.', 'otwfeed-pro' ) ) );
        }

        $clean = array();
        foreach ( $rows as $i => $row ) {
            $source_type = sanitize_text_field( $row['source_type'] ?? 'attribute' );
            $source_key  = sanitize_text_field( $row['source_key'] ?? '' );
            $static_val  = sanitize_text_field( $row['static_val'] ?? '' );

            // When type is static the value comes through as source_key from the text input.
            if ( 'static' === $source_type ) {
                $static_val = $source_key;
                $source_key = '';
            }

            $clean[] = array(
                'channel_tag' => sanitize_text_field( $row['channel_tag'] ?? '' ),
                'source_type' => $source_type,
                'source_key'  => $source_key,
                'static_val'  => $static_val,
                'price_round' => absint( $row['price_round'] ?? 0 ),
                'sort_order'  => (int) $i,
            );
        }

        OtwFeed_DB_Mappings::replace_for_feed( $feed_id, $clean );
        wp_send_json_success( array( 'message' => __( 'Mappings saved.', 'otwfeed-pro' ) ) );
    }

    public function handle_save_filters(): void {
        $this->check_nonce();

        $feed_id = absint( $_POST['feed_id'] ?? 0 );
        $rows    = isset( $_POST['filters'] ) ? (array) $_POST['filters'] : array();

        if ( ! $feed_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid feed.', 'otwfeed-pro' ) ) );
        }

        $valid_attrs = array_keys( OtwFeed_DB_Filters::get_attributes() );
        $valid_conds = array_keys( OtwFeed_DB_Filters::get_conditions() );

        $clean = array();
        foreach ( $rows as $i => $row ) {
            $attr = sanitize_text_field( $row['attribute'] ?? 'price' );
            $cond = sanitize_text_field( $row['condition_op'] ?? 'lt' );

            if ( ! in_array( $attr, $valid_attrs, true ) ) {
                $attr = 'price';
            }
            if ( ! in_array( $cond, $valid_conds, true ) ) {
                $cond = 'lt';
            }

            $clean[] = array(
                'group_id'       => absint( $row['group_id'] ?? 0 ),
                'group_action'   => in_array( $row['group_action'] ?? '', array( 'include', 'exclude' ), true )
                                        ? $row['group_action']
                                        : 'exclude',
                'attribute'      => $attr,
                'condition_op'   => $cond,
                'value'          => sanitize_text_field( $row['value'] ?? '' ),
                'case_sensitive' => absint( $row['case_sensitive'] ?? 0 ),
                'sort_order'     => (int) $i,
            );
        }

        OtwFeed_DB_Filters::replace_for_feed( $feed_id, $clean );
        wp_send_json_success( array( 'message' => __( 'Filters saved.', 'otwfeed-pro' ) ) );
    }

    public function handle_get_feed(): void {
        $this->check_nonce();

        $id   = absint( $_POST['id'] ?? 0 );
        $feed = OtwFeed_DB_Feeds::get( $id );

        if ( ! $feed ) {
            wp_send_json_error( array( 'message' => __( 'Feed not found.', 'otwfeed-pro' ) ) );
        }

        $feed->mappings = OtwFeed_DB_Mappings::get_for_feed( $id );
        $feed->filters  = OtwFeed_DB_Filters::get_for_feed( $id );
        $feed->logs     = OtwFeed_DB_Logs::get_for_feed( $id, 20 );
        $feed->feed_url = OtwFeed_Feed_Generator::get_feed_url( $feed->token );

        wp_send_json_success( $feed );
    }

    public function handle_preview_price(): void {
        $this->check_nonce();

        $product_id = absint( $_POST['product_id'] ?? 0 );
        $tax_mode   = sanitize_text_field( $_POST['tax_mode'] ?? 'include' );
        $country    = strtoupper( sanitize_text_field( $_POST['country'] ?? 'IT' ) );
        $currency   = strtoupper( sanitize_text_field( $_POST['currency'] ?? 'EUR' ) );

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( array( 'message' => __( 'Product not found.', 'otwfeed-pro' ) ) );
        }

        $price   = OtwFeed_Price_Calculator::get_price( $product, $tax_mode, $country, $currency );
        $regular = OtwFeed_Price_Calculator::get_regular_price( $product, $tax_mode, $country, $currency );

        wp_send_json_success( array(
            'price'   => $price,
            'regular' => $regular,
            'name'    => $product->get_name(),
        ) );
    }

    public function handle_get_wc_fields(): void {
        $this->check_nonce();

        // ── Standard product attributes ──────────────────────────────────────
        $standard = array(
            array( 'value' => 'id',                'label' => __( 'ID',                              'otwfeed-pro' ), 'group' => 'Standard' ),
            array( 'value' => 'name',              'label' => __( 'Title / Name',                    'otwfeed-pro' ), 'group' => 'Standard' ),
            array( 'value' => 'description',       'label' => __( 'Description',                     'otwfeed-pro' ), 'group' => 'Standard' ),
            array( 'value' => 'permalink',         'label' => __( 'Product URL',                     'otwfeed-pro' ), 'group' => 'Standard' ),
            array( 'value' => 'image',             'label' => __( 'Main Image URL',                  'otwfeed-pro' ), 'group' => 'Standard' ),
            array( 'value' => 'sku',               'label' => __( 'SKU',                             'otwfeed-pro' ), 'group' => 'Standard' ),
            array( 'value' => 'price',             'label' => __( 'Price (tax + currency)',          'otwfeed-pro' ), 'group' => 'Standard' ),
            array( 'value' => 'availability',      'label' => __( 'Availability (in_stock / …)',     'otwfeed-pro' ), 'group' => 'Standard' ),
            array( 'value' => 'item_group_id',     'label' => __( 'Item Group ID (parent ID)',       'otwfeed-pro' ), 'group' => 'Standard' ),
            array( 'value' => 'identifier_exists', 'label' => __( 'Identifier Exists (yes / no)',   'otwfeed-pro' ), 'group' => 'Standard' ),
            array( 'value' => 'brand',             'label' => __( 'Brand (auto-detected)',           'otwfeed-pro' ), 'group' => 'Standard' ),
            array( 'value' => 'product_type',      'label' => __( 'Product Type (category path)',   'otwfeed-pro' ), 'group' => 'Standard' ),
            array( 'value' => 'checkout_link',     'label' => __( 'Checkout Link (add-to-cart URL)', 'otwfeed-pro' ), 'group' => 'Standard' ),
        );

        // ── WooCommerce custom PA attributes ─────────────────────────────────
        $wc_attr_taxonomies = wc_get_attribute_taxonomies();
        $pa_attrs = array();
        foreach ( $wc_attr_taxonomies as $attr ) {
            $pa_attrs[] = array(
                'value' => 'pa_' . $attr->attribute_name,
                'label' => $attr->attribute_label,
                'group' => __( 'WC Attributes', 'otwfeed-pro' ),
            );
        }

        // ── Meta keys: curated + top keys from product postmeta ──────────────
        $curated_meta = array(
            '_sku', '_price', '_regular_price', '_sale_price',
            '_stock_status', '_stock', '_manage_stock',
            '_weight', '_length', '_width', '_height',
            '_gtin', '_mpn', '_upc', '_ean', '_isbn',
            '_google_category', '_product_image_gallery',
            '_purchase_note', '_tax_class', '_tax_status',
        );

        global $wpdb;
        $db_meta = (array) $wpdb->get_col( // phpcs:ignore
            "SELECT DISTINCT meta_key
             FROM {$wpdb->postmeta}
             INNER JOIN {$wpdb->posts} ON ID = post_id
             WHERE post_type = 'product'
               AND meta_key NOT LIKE '\_%\_%\_%'
               AND meta_key NOT LIKE 'attribute\_%'
               AND meta_key NOT LIKE '_edit%'
               AND meta_key NOT LIKE '_wp%'
               AND meta_key NOT IN ('_thumbnail_id','_product_version')
             ORDER BY meta_key
             LIMIT 200"
        );

        $meta_keys = array_values( array_unique( array_merge( $curated_meta, $db_meta ) ) );
        sort( $meta_keys );

        // ── Product taxonomies ────────────────────────────────────────────────
        $raw_taxonomies = get_object_taxonomies( 'product', 'objects' );
        $taxonomies = array();
        foreach ( $raw_taxonomies as $tax ) {
            $taxonomies[] = array(
                'value' => $tax->name,
                'label' => $tax->label ?: $tax->name,
            );
        }

        wp_send_json_success( array(
            'attributes' => array_merge( $standard, $pa_attrs ),
            'meta_keys'  => $meta_keys,
            'taxonomies' => $taxonomies,
        ) );
    }
}
