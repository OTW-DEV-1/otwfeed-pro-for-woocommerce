<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Orchestrates feed generation: queries products in batches, streams XML to file.
 */
class OtwFeed_Feed_Generator {

    private const BATCH_SIZE        = 200; // monolithic generate()
    private const CLIENT_BATCH_SIZE = 20;  // client-driven generate_batch() — keeps each request under ~10 s

    public static function generate( int $feed_id ): array {
        $feed = OtwFeed_DB_Feeds::get( $feed_id );
        if ( ! $feed ) {
            return array( 'success' => false, 'error' => __( 'Feed not found.', 'otwfeed-pro' ) );
        }

        // Raise limits — large feeds can take several minutes and hundreds of MB.
        @set_time_limit( 600 );
        @ini_set( 'memory_limit', '256M' );

        $filters  = OtwFeed_DB_Filters::get_for_feed( $feed_id );
        $mappings = OtwFeed_DB_Mappings::get_for_feed( $feed_id );

        if ( empty( $mappings ) ) {
            $mappings = 'google' === $feed->channel
                ? OtwFeed_DB_Mappings::get_default_google_mappings()
                : OtwFeed_DB_Mappings::get_default_facebook_mappings();
            $mappings = array_map( static fn( $m ) => (object) $m, $mappings );
        }

        $builder = 'google' === $feed->channel
            ? 'OtwFeed_Feed_Builder_Google'
            : 'OtwFeed_Feed_Builder_Facebook';

        OtwFeed_DB_Logs::info( $feed_id, __( 'Feed generation started.', 'otwfeed-pro' ) );

        $fh = null;

        try {
            $file_path = self::prepare_file( $feed );
            $fh        = fopen( $file_path, 'w' ); // phpcs:ignore
            if ( false === $fh ) {
                throw new \RuntimeException( 'Could not open feed file for writing: ' . $file_path );
            }

            // Write XML preamble (channel header).
            fwrite( $fh, $builder::build_preamble( $feed ) ); // phpcs:ignore

            // Fetch all parent product IDs via a lightweight DB query (no WC objects yet).
            $all_parent_ids = OtwFeed_Product_Query::get_parent_ids();
            $total_count    = 0;

            foreach ( array_chunk( $all_parent_ids, self::BATCH_SIZE ) as $batch_ids ) {
                $products = OtwFeed_Product_Query::get_products_for_batch( $feed, $filters, $batch_ids );

                if ( ! empty( $products ) ) {
                    fwrite( $fh, $builder::build_items_xml( $feed, $mappings, $products ) ); // phpcs:ignore
                    $total_count += count( $products );
                }

                // Release WP object cache entries for this batch to keep memory flat.
                self::free_memory( $batch_ids );
                unset( $products );
            }

            // Write closing tags.
            fwrite( $fh, $builder::build_epilogue() ); // phpcs:ignore
            fclose( $fh ); // phpcs:ignore

            OtwFeed_DB_Feeds::update( $feed_id, array(
                'last_gen'  => current_time( 'mysql' ),
                'file_path' => $file_path,
            ) );

            OtwFeed_DB_Logs::info( $feed_id, sprintf(
                /* translators: %d: number of products */
                __( 'Feed generated successfully. %d products.', 'otwfeed-pro' ),
                $total_count
            ) );

            return array(
                'success'       => true,
                'product_count' => $total_count,
                'file_path'     => $file_path,
                'feed_url'      => self::get_feed_url( $feed->token ),
            );

        } catch ( \Throwable $e ) {
            if ( is_resource( $fh ) ) {
                fclose( $fh ); // phpcs:ignore
            }
            OtwFeed_DB_Logs::error( $feed_id, $e->getMessage(), array( 'trace' => $e->getTraceAsString() ) );
            return array( 'success' => false, 'error' => $e->getMessage() );
        }
    }

    private static function prepare_file( object $feed ): string {
        $upload    = wp_upload_dir();
        $dir       = trailingslashit( $upload['basedir'] ) . 'otwfeed-pro/';
        $file_name = 'feed-' . absint( $feed->id ) . '-' . sanitize_file_name( $feed->token ) . '.xml';

        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        return $dir . $file_name;
    }

    private static function free_memory( array $processed_ids ): void {
        foreach ( $processed_ids as $id ) {
            clean_post_cache( (int) $id );
        }
        if ( function_exists( 'gc_collect_cycles' ) ) {
            gc_collect_cycles();
        }
    }

    public static function get_feed_url( string $token ): string {
        return rest_url( 'otwfeed-pro/v1/feed/' . rawurlencode( $token ) );
    }

    // ── Incremental (client-driven) generation ─────────────────────────────────

    public static function generate_start( int $feed_id ): array {
        $feed = OtwFeed_DB_Feeds::get( $feed_id );
        if ( ! $feed ) {
            return array( 'success' => false, 'error' => __( 'Feed not found.', 'otwfeed-pro' ) );
        }

        @set_time_limit( 600 );
        @ini_set( 'memory_limit', '256M' );

        $builder        = 'google' === $feed->channel ? 'OtwFeed_Feed_Builder_Google' : 'OtwFeed_Feed_Builder_Facebook';
        $all_parent_ids = OtwFeed_Product_Query::get_parent_ids();
        $total          = count( $all_parent_ids );

        try {
            $file_path = self::prepare_file( $feed );
            $fh        = fopen( $file_path, 'w' ); // phpcs:ignore
            if ( false === $fh ) {
                throw new \RuntimeException( 'Could not open feed file for writing: ' . $file_path );
            }
            fwrite( $fh, $builder::build_preamble( $feed ) ); // phpcs:ignore
            fclose( $fh ); // phpcs:ignore
        } catch ( \Throwable $e ) {
            return array( 'success' => false, 'error' => $e->getMessage() );
        }

        set_transient( 'otwfeed_gen_' . $feed_id, array(
            'file_path'       => $file_path,
            'parent_ids'      => $all_parent_ids,
            'total'           => $total,
            'builder'         => $builder,
            'products_written' => 0,
        ), HOUR_IN_SECONDS );

        OtwFeed_DB_Logs::info( $feed_id, __( 'Feed generation started.', 'otwfeed-pro' ) );

        return array(
            'success'     => true,
            'total'       => $total,
            'batch_size'  => self::CLIENT_BATCH_SIZE,
            'batch_count' => (int) ceil( $total / self::CLIENT_BATCH_SIZE ),
        );
    }

    public static function generate_batch( int $feed_id, int $batch_index ): array {
        $state = get_transient( 'otwfeed_gen_' . $feed_id );
        if ( ! $state ) {
            return array( 'success' => false, 'error' => __( 'No active generation session. Please start again.', 'otwfeed-pro' ) );
        }

        @set_time_limit( 120 );

        $feed     = OtwFeed_DB_Feeds::get( $feed_id );
        $filters  = OtwFeed_DB_Filters::get_for_feed( $feed_id );
        $mappings = OtwFeed_DB_Mappings::get_for_feed( $feed_id );

        if ( empty( $mappings ) ) {
            $mappings = 'google' === $feed->channel
                ? OtwFeed_DB_Mappings::get_default_google_mappings()
                : OtwFeed_DB_Mappings::get_default_facebook_mappings();
            $mappings = array_map( static fn( $m ) => (object) $m, $mappings );
        }

        $offset    = $batch_index * self::CLIENT_BATCH_SIZE;
        $batch_ids = array_slice( $state['parent_ids'], $offset, self::CLIENT_BATCH_SIZE );

        if ( ! empty( $batch_ids ) ) {
            $builder  = $state['builder'];
            $products = OtwFeed_Product_Query::get_products_for_batch( $feed, $filters, $batch_ids );

            if ( ! empty( $products ) ) {
                file_put_contents( $state['file_path'], $builder::build_items_xml( $feed, $mappings, $products ), FILE_APPEND ); // phpcs:ignore
                $state['products_written'] += count( $products );
            }

            self::free_memory( $batch_ids );
            unset( $products );
        }

        $processed = min( $offset + self::CLIENT_BATCH_SIZE, $state['total'] );
        $state['processed'] = $processed;
        set_transient( 'otwfeed_gen_' . $feed_id, $state, HOUR_IN_SECONDS );

        return array(
            'success'   => true,
            'processed' => $processed,
            'total'     => $state['total'],
        );
    }

    public static function generate_finish( int $feed_id ): array {
        $state = get_transient( 'otwfeed_gen_' . $feed_id );
        if ( ! $state ) {
            return array( 'success' => false, 'error' => __( 'No active generation session. Please start again.', 'otwfeed-pro' ) );
        }

        $builder = $state['builder'];
        file_put_contents( $state['file_path'], $builder::build_epilogue(), FILE_APPEND ); // phpcs:ignore

        delete_transient( 'otwfeed_gen_' . $feed_id );

        $feed = OtwFeed_DB_Feeds::get( $feed_id );
        OtwFeed_DB_Feeds::update( $feed_id, array(
            'last_gen'  => current_time( 'mysql' ),
            'file_path' => $state['file_path'],
        ) );

        $count = $state['products_written'];
        OtwFeed_DB_Logs::info( $feed_id, sprintf(
            /* translators: %d: number of products */
            __( 'Feed generated successfully. %d products.', 'otwfeed-pro' ),
            $count
        ) );

        return array(
            'success'       => true,
            'product_count' => $count,
            'file_path'     => $state['file_path'],
            'feed_url'      => self::get_feed_url( $feed->token ),
        );
    }
}
