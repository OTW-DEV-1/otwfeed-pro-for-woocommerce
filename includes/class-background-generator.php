<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Runs feed generation in a background PHP process so the browser
 * can be closed without interrupting generation.
 *
 * Flow:
 *   1. schedule()          — stores initial progress, fires a non-blocking
 *                            self-HTTP POST to the private REST endpoint.
 *   2. handle_rest()       — REST callback: sets ignore_user_abort(true) so
 *                            the process survives Cloudflare 524 drops, then
 *                            calls run().
 *   3. run()               — streams XML in 20-parent batches, writing
 *                            progress to a transient after each one.
 *   4. get_progress()      — read-only: returns the current transient state.
 */
class OtwFeed_Background_Generator {

    private const BATCH_SIZE      = 20;
    private const PROGRESS_TTL    = 2 * HOUR_IN_SECONDS;
    private const STALE_THRESHOLD = 7200; // 2 h without completion → mark timed out

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Schedule background generation for the given feed.
     * Returns immediately — the actual work happens in a separate PHP process.
     */
    public static function schedule( int $feed_id ): array {
        // Don't start a second job if one is already running.
        $existing = self::get_progress( $feed_id );
        if ( in_array( $existing['status'], array( 'queued', 'running' ), true ) ) {
            return array( 'success' => true, 'already_running' => true );
        }

        self::set_progress( $feed_id, array(
            'status'           => 'queued',
            'processed'        => 0,
            'total'            => 0,
            'products_written' => 0,
            'started'          => time(),
            'error'            => '',
        ) );

        // Non-blocking self-request — PHP on the other end runs independently.
        $url = rest_url( 'otwfeed-pro/v1/generate-bg/' . $feed_id );
        wp_remote_post( $url, array(
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
            'headers'   => array(
                'X-OtwFeed-Token' => self::token( $feed_id ),
                'Content-Type'    => 'application/json',
            ),
        ) );

        return array( 'success' => true, 'already_running' => false );
    }

    /**
     * Returns the current progress for a feed.
     * Marks stale 'running' jobs as timed out automatically.
     *
     * @return array{status:string,processed:int,total:int,products_written:int,started:int,error:string}
     */
    public static function get_progress( int $feed_id ): array {
        $p = get_transient( self::key( $feed_id ) );
        if ( ! is_array( $p ) ) {
            return array(
                'status'           => 'idle',
                'processed'        => 0,
                'total'            => 0,
                'products_written' => 0,
                'started'          => 0,
                'error'            => '',
            );
        }
        if ( 'running' === $p['status'] && time() - ( $p['started'] ?? 0 ) > self::STALE_THRESHOLD ) {
            $p['status'] = 'error';
            $p['error']  = __( 'Generation timed out.', 'otwfeed-pro' );
            set_transient( self::key( $feed_id ), $p, self::PROGRESS_TTL );
        }
        return $p;
    }

    /** Register the private REST route (called on rest_api_init). */
    public static function register_rest_route(): void {
        register_rest_route(
            'otwfeed-pro/v1',
            '/generate-bg/(?P<feed_id>\d+)',
            array(
                'methods'             => 'POST',
                'callback'            => array( self::class, 'handle_rest' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    /** REST callback — runs in a detached background process. */
    public static function handle_rest( \WP_REST_Request $request ): \WP_REST_Response {
        $feed_id = absint( $request->get_param( 'feed_id' ) );
        $token   = (string) ( $request->get_header( 'X-OtwFeed-Token' ) ?? '' );

        if ( ! hash_equals( self::token( $feed_id ), $token ) ) {
            return new \WP_REST_Response( null, 403 );
        }

        // Keep PHP alive even after the HTTP connection is dropped (Cloudflare 524).
        ignore_user_abort( true );
        @set_time_limit( 0 );           // phpcs:ignore
        @ini_set( 'memory_limit', '512M' ); // phpcs:ignore

        self::run( $feed_id );

        return new \WP_REST_Response( null, 200 );
    }

    // ── Core runner ────────────────────────────────────────────────────────────

    public static function run( int $feed_id ): void {
        $feed = OtwFeed_DB_Feeds::get( $feed_id );
        if ( ! $feed ) {
            self::set_progress( $feed_id, array( 'status' => 'error', 'error' => __( 'Feed not found.', 'otwfeed-pro' ) ) );
            return;
        }

        $filters  = OtwFeed_DB_Filters::get_for_feed( $feed_id );
        $mappings = OtwFeed_DB_Mappings::get_for_feed( $feed_id );
        if ( empty( $mappings ) ) {
            $mappings = 'google' === $feed->channel
                ? OtwFeed_DB_Mappings::get_default_google_mappings()
                : OtwFeed_DB_Mappings::get_default_facebook_mappings();
            $mappings = array_map( static fn( $m ) => (object) $m, $mappings );
        }

        $builder        = 'google' === $feed->channel ? 'OtwFeed_Feed_Builder_Google' : 'OtwFeed_Feed_Builder_Facebook';
        $all_parent_ids = OtwFeed_Product_Query::get_parent_ids();
        $total          = count( $all_parent_ids );
        $batches        = array_chunk( $all_parent_ids, self::BATCH_SIZE );

        // Prepare output file and write XML preamble.
        $upload    = wp_upload_dir();
        $dir       = trailingslashit( $upload['basedir'] ) . 'otwfeed-pro/';
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $file_name = 'feed-' . absint( $feed->id ) . '-' . sanitize_file_name( $feed->token ) . '.xml';
        $file_path = $dir . $file_name;
        file_put_contents( $file_path, $builder::build_preamble( $feed ) ); // phpcs:ignore

        $started = time();

        self::set_progress( $feed_id, array(
            'status'           => 'running',
            'processed'        => 0,
            'total'            => $total,
            'products_written' => 0,
            'started'          => $started,
            'error'            => '',
        ) );

        OtwFeed_DB_Logs::info( $feed_id, __( 'Background feed generation started.', 'otwfeed-pro' ) );

        $products_written = 0;
        $processed        = 0;

        foreach ( $batches as $batch_ids ) {
            @set_time_limit( 120 ); // phpcs:ignore — reset per batch so PHP does not kill a long run

            try {
                $products = OtwFeed_Product_Query::get_products_for_batch( $feed, $filters, $batch_ids );
                if ( ! empty( $products ) ) {
                    file_put_contents( $file_path, $builder::build_items_xml( $feed, $mappings, $products ), FILE_APPEND ); // phpcs:ignore
                    $products_written += count( $products );
                }
            } catch ( \Throwable $e ) {
                self::set_progress( $feed_id, array(
                    'status'           => 'error',
                    'processed'        => $processed,
                    'total'            => $total,
                    'products_written' => $products_written,
                    'started'          => $started,
                    'error'            => $e->getMessage(),
                ) );
                OtwFeed_DB_Logs::error( $feed_id, $e->getMessage(), array( 'trace' => $e->getTraceAsString() ) );
                return;
            }

            $processed = min( $processed + count( $batch_ids ), $total );

            self::set_progress( $feed_id, array(
                'status'           => 'running',
                'processed'        => $processed,
                'total'            => $total,
                'products_written' => $products_written,
                'started'          => $started,
                'error'            => '',
            ) );

            foreach ( $batch_ids as $id ) {
                clean_post_cache( (int) $id );
            }
            if ( function_exists( 'gc_collect_cycles' ) ) {
                gc_collect_cycles();
            }
            unset( $products );

            // Yield to the server between batches to keep CPU from spiking to 100%.
            usleep( 1500000 ); // 1.5 s
        }

        // Write closing tags.
        file_put_contents( $file_path, $builder::build_epilogue(), FILE_APPEND ); // phpcs:ignore

        OtwFeed_DB_Feeds::update( $feed_id, array(
            'last_gen'      => current_time( 'mysql' ),
            'file_path'     => $file_path,
            'product_count' => $products_written,
        ) );

        OtwFeed_DB_Logs::info( $feed_id, sprintf(
            /* translators: %d: number of products */
            __( 'Feed generated successfully. %d products.', 'otwfeed-pro' ),
            $products_written
        ) );

        self::set_progress( $feed_id, array(
            'status'           => 'done',
            'processed'        => $total,
            'total'            => $total,
            'products_written' => $products_written,
            'started'          => $started,
            'error'            => '',
        ) );
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private static function key( int $feed_id ): string {
        return 'otwfeed_progress_' . $feed_id;
    }

    private static function token( int $feed_id ): string {
        return wp_hash( 'otwfeed_bg_gen_' . $feed_id );
    }

    private static function set_progress( int $feed_id, array $data ): void {
        // Merge so callers only need to supply changed keys.
        $defaults = array(
            'status'           => 'idle',
            'processed'        => 0,
            'total'            => 0,
            'products_written' => 0,
            'started'          => 0,
            'error'            => '',
        );
        set_transient( self::key( $feed_id ), array_merge( $defaults, $data ), self::PROGRESS_TTL );
    }
}
