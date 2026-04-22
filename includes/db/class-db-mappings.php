<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OtwFeed_DB_Mappings {

    private static string $table = '';

    private static function table(): string {
        global $wpdb;
        if ( '' === self::$table ) {
            self::$table = $wpdb->prefix . 'otwfeed_mappings';
        }
        return self::$table;
    }

    public static function get_for_feed( int $feed_id ): array {
        global $wpdb;
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE feed_id = %d ORDER BY sort_order ASC',
                self::table(),
                $feed_id
            )
        );
    }

    public static function insert( array $data ): int|false {
        global $wpdb;
        $result = $wpdb->insert( self::table(), $data );
        return $result ? (int) $wpdb->insert_id : false;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        return (bool) $wpdb->update( self::table(), $data, array( 'id' => $id ) );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
    }

    public static function delete_for_feed( int $feed_id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), array( 'feed_id' => $feed_id ), array( '%d' ) );
    }

    public static function replace_for_feed( int $feed_id, array $rows ): void {
        self::delete_for_feed( $feed_id );
        foreach ( $rows as $row ) {
            $row['feed_id'] = $feed_id;
            self::insert( $row );
        }
    }

    public static function get_default_google_mappings(): array {
        return array(
            // ── Required fields ──────────────────────────────────────────────
            array( 'channel_tag' => 'g:id',                      'source_type' => 'attribute', 'source_key' => 'id',                'static_val' => '', 'price_round' => 0, 'sort_order' => 1 ),
            array( 'channel_tag' => 'g:title',                   'source_type' => 'attribute', 'source_key' => 'name',              'static_val' => '', 'price_round' => 0, 'sort_order' => 2 ),
            array( 'channel_tag' => 'g:description',             'source_type' => 'attribute', 'source_key' => 'description',       'static_val' => '', 'price_round' => 0, 'sort_order' => 3 ),
            array( 'channel_tag' => 'g:link',                    'source_type' => 'attribute', 'source_key' => 'permalink',         'static_val' => '', 'price_round' => 0, 'sort_order' => 4 ),
            array( 'channel_tag' => 'g:image_link',              'source_type' => 'attribute', 'source_key' => 'image',             'static_val' => '', 'price_round' => 0, 'sort_order' => 5 ),
            array( 'channel_tag' => 'g:availability',            'source_type' => 'attribute', 'source_key' => 'availability',      'static_val' => '', 'price_round' => 0, 'sort_order' => 6 ),
            array( 'channel_tag' => 'g:price',                   'source_type' => 'attribute', 'source_key' => 'price',             'static_val' => '', 'price_round' => 0, 'sort_order' => 7 ),
            array( 'channel_tag' => 'g:condition',               'source_type' => 'static',    'source_key' => '',                  'static_val' => 'new', 'price_round' => 0, 'sort_order' => 8 ),
            // ── Product identity ─────────────────────────────────────────────
            array( 'channel_tag' => 'g:item_group_id',           'source_type' => 'attribute', 'source_key' => 'item_group_id',     'static_val' => '', 'price_round' => 0, 'sort_order' => 9 ),
            array( 'channel_tag' => 'g:identifier_exists',       'source_type' => 'attribute', 'source_key' => 'identifier_exists', 'static_val' => '', 'price_round' => 0, 'sort_order' => 10 ),
            array( 'channel_tag' => 'g:gtin',                    'source_type' => 'meta',      'source_key' => '_gtin',             'static_val' => '', 'price_round' => 0, 'sort_order' => 11 ),
            array( 'channel_tag' => 'g:mpn',                     'source_type' => 'meta',      'source_key' => '_mpn',              'static_val' => '', 'price_round' => 0, 'sort_order' => 12 ),
            array( 'channel_tag' => 'g:brand',                   'source_type' => 'attribute', 'source_key' => 'brand',             'static_val' => '', 'price_round' => 0, 'sort_order' => 13 ),
            // ── Categorisation ───────────────────────────────────────────────
            array( 'channel_tag' => 'g:product_type',            'source_type' => 'attribute', 'source_key' => 'product_type',      'static_val' => '', 'price_round' => 0, 'sort_order' => 14 ),
            array( 'channel_tag' => 'g:google_product_category', 'source_type' => 'meta',      'source_key' => '_google_category',  'static_val' => '', 'price_round' => 0, 'sort_order' => 15 ),
            // ── Checkout & additional ────────────────────────────────────────
            array( 'channel_tag' => 'g:checkout_link_template',  'source_type' => 'attribute', 'source_key' => 'checkout_link',     'static_val' => '', 'price_round' => 0, 'sort_order' => 16 ),
        );
    }

    public static function get_default_facebook_mappings(): array {
        return array(
            // ── Required fields ──────────────────────────────────────────────
            array( 'channel_tag' => 'id',                    'source_type' => 'attribute', 'source_key' => 'id',             'static_val' => '', 'price_round' => 0, 'sort_order' => 1 ),
            array( 'channel_tag' => 'title',                 'source_type' => 'attribute', 'source_key' => 'name',           'static_val' => '', 'price_round' => 0, 'sort_order' => 2 ),
            array( 'channel_tag' => 'description',           'source_type' => 'attribute', 'source_key' => 'description',    'static_val' => '', 'price_round' => 0, 'sort_order' => 3 ),
            array( 'channel_tag' => 'link',                  'source_type' => 'attribute', 'source_key' => 'permalink',      'static_val' => '', 'price_round' => 0, 'sort_order' => 4 ),
            array( 'channel_tag' => 'image_link',            'source_type' => 'attribute', 'source_key' => 'image',          'static_val' => '', 'price_round' => 0, 'sort_order' => 5 ),
            array( 'channel_tag' => 'availability',          'source_type' => 'attribute', 'source_key' => 'availability',   'static_val' => '', 'price_round' => 0, 'sort_order' => 6 ),
            array( 'channel_tag' => 'price',                 'source_type' => 'attribute', 'source_key' => 'price',          'static_val' => '', 'price_round' => 0, 'sort_order' => 7 ),
            array( 'channel_tag' => 'condition',             'source_type' => 'static',    'source_key' => '',               'static_val' => 'new', 'price_round' => 0, 'sort_order' => 8 ),
            // ── Product identity ─────────────────────────────────────────────
            array( 'channel_tag' => 'item_group_id',         'source_type' => 'attribute', 'source_key' => 'item_group_id',  'static_val' => '', 'price_round' => 0, 'sort_order' => 9 ),
            array( 'channel_tag' => 'brand',                 'source_type' => 'attribute', 'source_key' => 'brand',          'static_val' => '', 'price_round' => 0, 'sort_order' => 10 ),
            array( 'channel_tag' => 'retailer_id',           'source_type' => 'attribute', 'source_key' => 'sku',            'static_val' => '', 'price_round' => 0, 'sort_order' => 11 ),
            array( 'channel_tag' => 'gtin',                  'source_type' => 'meta',      'source_key' => '_gtin',          'static_val' => '', 'price_round' => 0, 'sort_order' => 12 ),
            array( 'channel_tag' => 'mpn',                   'source_type' => 'meta',      'source_key' => '_mpn',           'static_val' => '', 'price_round' => 0, 'sort_order' => 13 ),
            // ── Categorisation ───────────────────────────────────────────────
            array( 'channel_tag' => 'product_type',          'source_type' => 'attribute', 'source_key' => 'product_type',   'static_val' => '', 'price_round' => 0, 'sort_order' => 14 ),
            array( 'channel_tag' => 'google_product_category','source_type' => 'meta',     'source_key' => '_google_category','static_val' => '', 'price_round' => 0, 'sort_order' => 15 ),
            // ── Checkout ─────────────────────────────────────────────────────
            array( 'channel_tag' => 'checkout_url',          'source_type' => 'attribute', 'source_key' => 'checkout_link',  'static_val' => '', 'price_round' => 0, 'sort_order' => 16 ),
        );
    }
}
