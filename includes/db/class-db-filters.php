<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OtwFeed_DB_Filters {

    private static string $table = '';

    private static function table(): string {
        global $wpdb;
        if ( '' === self::$table ) {
            self::$table = $wpdb->prefix . 'otwfeed_filters';
        }
        return self::$table;
    }

    public static function get_for_feed( int $feed_id ): array {
        global $wpdb;
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE feed_id = %d ORDER BY group_id ASC, sort_order ASC',
                self::table(),
                $feed_id
            )
        );
    }

    /**
     * Returns filters grouped by group_id.
     * Each entry: [ 'group_id' => int, 'group_action' => string, 'conditions' => object[] ]
     */
    public static function get_grouped_for_feed( int $feed_id ): array {
        $rows   = self::get_for_feed( $feed_id );
        $groups = array();

        foreach ( $rows as $row ) {
            $gid = (int) $row->group_id;
            if ( ! isset( $groups[ $gid ] ) ) {
                $groups[ $gid ] = array(
                    'group_id'     => $gid,
                    'group_action' => $row->group_action,
                    'conditions'   => array(),
                );
            }
            $groups[ $gid ]['conditions'][] = $row;
        }

        return array_values( $groups );
    }

    public static function insert( array $data ): int|false {
        global $wpdb;
        $result = $wpdb->insert( self::table(), $data );
        return $result ? (int) $wpdb->insert_id : false;
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

    public static function get_attributes(): array {
        return array(
            'price'          => __( 'Price',                 'otwfeed-pro' ),
            'regular_price'  => __( 'Regular Price',         'otwfeed-pro' ),
            'sale_price'     => __( 'Sale Price',            'otwfeed-pro' ),
            'sku'            => __( 'SKU',                   'otwfeed-pro' ),
            'title'          => __( 'Title',                 'otwfeed-pro' ),
            'description'    => __( 'Description',           'otwfeed-pro' ),
            'product_id'     => __( 'Product ID',            'otwfeed-pro' ),
            'stock_status'   => __( 'Stock Status',          'otwfeed-pro' ),
            'stock_quantity' => __( 'Stock Quantity',        'otwfeed-pro' ),
            'product_type'   => __( 'Product Type',          'otwfeed-pro' ),
            'category'       => __( 'Category (name)',       'otwfeed-pro' ),
            'tag'            => __( 'Tag (name)',             'otwfeed-pro' ),
            'weight'         => __( 'Weight',                'otwfeed-pro' ),
        );
    }

    public static function get_conditions(): array {
        return array(
            'equals'       => __( 'Equals',                'otwfeed-pro' ),
            'not_equals'   => __( 'Not Equals',            'otwfeed-pro' ),
            'contains'     => __( 'Contains',              'otwfeed-pro' ),
            'not_contains' => __( 'Not Contains',          'otwfeed-pro' ),
            'gt'           => __( 'Greater Than',          'otwfeed-pro' ),
            'lt'           => __( 'Less Than',             'otwfeed-pro' ),
            'gte'          => __( 'Greater Than or Equal', 'otwfeed-pro' ),
            'lte'          => __( 'Less Than or Equal',    'otwfeed-pro' ),
            'is_empty'     => __( 'Is Empty',              'otwfeed-pro' ),
            'is_not_empty' => __( 'Is Not Empty',          'otwfeed-pro' ),
        );
    }

    /** @deprecated Use get_attributes() */
    public static function get_filter_types(): array {
        return self::get_attributes();
    }
}
