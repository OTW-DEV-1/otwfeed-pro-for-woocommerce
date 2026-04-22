<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Orchestrates feed generation: queries products, builds XML, writes file.
 */
class OtwFeed_Feed_Generator {

    public static function generate( int $feed_id ): array {
        $feed = OtwFeed_DB_Feeds::get( $feed_id );
        if ( ! $feed ) {
            return array( 'success' => false, 'error' => __( 'Feed not found.', 'otwfeed-pro' ) );
        }

        $filters  = OtwFeed_DB_Filters::get_for_feed( $feed_id );
        $mappings = OtwFeed_DB_Mappings::get_for_feed( $feed_id );

        if ( empty( $mappings ) ) {
            $mappings = 'google' === $feed->channel
                ? OtwFeed_DB_Mappings::get_default_google_mappings()
                : OtwFeed_DB_Mappings::get_default_facebook_mappings();
            // Cast to objects for consistent access.
            $mappings = array_map( static fn( $m ) => (object) $m, $mappings );
        }

        OtwFeed_DB_Logs::info( $feed_id, __( 'Feed generation started.', 'otwfeed-pro' ) );

        try {
            $products = OtwFeed_Product_Query::get_products( $feed, $filters );

            if ( empty( $products ) ) {
                OtwFeed_DB_Logs::info( $feed_id, __( 'No products matched the feed criteria.', 'otwfeed-pro' ) );
            }

            $xml = 'google' === $feed->channel
                ? OtwFeed_Feed_Builder_Google::build( $feed, $mappings, $products )
                : OtwFeed_Feed_Builder_Facebook::build( $feed, $mappings, $products );

            $file_path = self::write_file( $feed, $xml );

            OtwFeed_DB_Feeds::update( $feed_id, array(
                'last_gen'  => current_time( 'mysql' ),
                'file_path' => $file_path,
            ) );

            OtwFeed_DB_Logs::info( $feed_id, sprintf(
                __( 'Feed generated successfully. %d products.', 'otwfeed-pro' ),
                count( $products )
            ) );

            return array(
                'success'       => true,
                'product_count' => count( $products ),
                'file_path'     => $file_path,
                'feed_url'      => self::get_feed_url( $feed->token ),
            );

        } catch ( \Throwable $e ) {
            OtwFeed_DB_Logs::error( $feed_id, $e->getMessage(), array( 'trace' => $e->getTraceAsString() ) );
            return array( 'success' => false, 'error' => $e->getMessage() );
        }
    }

    private static function write_file( object $feed, string $xml ): string {
        $upload    = wp_upload_dir();
        $dir       = trailingslashit( $upload['basedir'] ) . 'otwfeed-pro/';
        $file_name = 'feed-' . absint( $feed->id ) . '-' . sanitize_file_name( $feed->token ) . '.xml';
        $file_path = $dir . $file_name;

        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        file_put_contents( $file_path, $xml ); // phpcs:ignore

        return $file_path;
    }

    public static function get_feed_url( string $token ): string {
        return rest_url( 'otwfeed-pro/v1/feed/' . rawurlencode( $token ) );
    }
}
