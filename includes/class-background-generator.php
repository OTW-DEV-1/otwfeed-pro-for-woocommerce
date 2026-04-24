<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Runs feed generation in batches via Action Scheduler (bundled with WooCommerce).
 * Each batch is an independent WP job — no long-running PHP process needed.
 *
 * Flow:
 *   1. schedule()   — cancels stale pending actions, writes XML preamble to a
 *                     temp file, queues the first AS batch action.
 *   2. run_batch()  — AS callback: processes BATCH_SIZE parent products, appends
 *                     to the temp file, then either queues the next batch or calls
 *                     finalise() when all parents are processed.
 *   3. finalise()   — validates the temp file XML, atomically renames it to the
 *                     live path, updates DB product_count + file_path.
 *   4. get_progress() — read-only: returns the current transient state.
 *
 * Fallback: if Action Scheduler is unavailable (edge case — WC always ships it)
 * the old non-blocking self-HTTP POST path is used instead.
 */
class OtwFeed_Background_Generator {

    public  const AS_HOOK         = 'otwfeed_pro_run_batch';
    private const BATCH_SIZE      = 50;
    private const PROGRESS_TTL    = 2 * HOUR_IN_SECONDS;
    private const STALE_THRESHOLD = 7200; // 2 h without completion → mark timed out

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Schedule background generation for the given feed.
     * Returns immediately — the actual work happens via Action Scheduler.
     */
    public static function schedule( int $feed_id ): array {
        $existing = self::get_progress( $feed_id );
        if ( in_array( $existing['status'], array( 'queued', 'running' ), true ) ) {
            return array( 'success' => true, 'already_running' => true );
        }

        $feed = OtwFeed_DB_Feeds::get( $feed_id );
        if ( ! $feed ) {
            return array( 'success' => false, 'error' => __( 'Feed not found.', 'otwfeed-pro' ) );
        }

        // Cancel any stale pending AS actions for this feed.
        self::cancel_pending_actions( $feed_id );

        // Ensure upload directory exists.
        $dir = self::upload_dir();
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        // Remove leftover temp file from a previous failed run.
        $temp = self::temp_path( $feed );
        if ( file_exists( $temp ) ) {
            @unlink( $temp ); // phpcs:ignore
        }

        $total   = OtwFeed_Product_Query::get_parent_count();
        $builder = 'google' === $feed->channel ? 'OtwFeed_Feed_Builder_Google' : 'OtwFeed_Feed_Builder_Facebook';

        // Write preamble now — fail fast if the directory is not writable.
        if ( false === file_put_contents( $temp, $builder::build_preamble( $feed ) ) ) { // phpcs:ignore
            return array( 'success' => false, 'error' => __( 'Cannot write to upload directory.', 'otwfeed-pro' ) );
        }

        self::set_progress( $feed_id, array(
            'status'           => 'queued',
            'processed'        => 0,
            'total'            => $total,
            'products_written' => 0,
            'started'          => time(),
            'error'            => '',
        ) );

        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action(
                time(),
                self::AS_HOOK,
                array( 'feed_id' => $feed_id, 'offset' => 0 ),
                self::as_group( $feed_id )
            );
        } else {
            // Fallback: non-blocking self-HTTP POST.
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
        }

        return array( 'success' => true, 'already_running' => false );
    }

    /**
     * Action Scheduler callback — processes one batch of parent products.
     * Registered on hook 'otwfeed_pro_run_batch'.
     */
    public static function run_batch( int $feed_id, int $offset ): void {
        @set_time_limit( 120 ); // phpcs:ignore — 2 min per batch max

        $progress = self::get_progress( $feed_id );
        if ( 'error' === $progress['status'] ) {
            return; // Aborted externally.
        }

        $feed = OtwFeed_DB_Feeds::get( $feed_id );
        if ( ! $feed ) {
            self::set_progress( $feed_id, array( 'status' => 'error', 'error' => __( 'Feed not found.', 'otwfeed-pro' ) ) );
            return;
        }

        $total            = (int) ( $progress['total']            ?? 0 );
        $started          = (int) ( $progress['started']          ?? time() );
        $products_written = (int) ( $progress['products_written'] ?? 0 );
        $temp             = self::temp_path( $feed );

        if ( ! file_exists( $temp ) ) {
            self::set_progress( $feed_id, array(
                'status' => 'error',
                'error'  => __( 'Temp file missing — please regenerate.', 'otwfeed-pro' ),
            ) );
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

        $builder   = 'google' === $feed->channel ? 'OtwFeed_Feed_Builder_Google' : 'OtwFeed_Feed_Builder_Facebook';
        $batch_ids = OtwFeed_Product_Query::get_parent_ids( $offset, self::BATCH_SIZE );

        if ( 0 === $offset ) {
            self::set_progress( $feed_id, array(
                'status'           => 'running',
                'processed'        => 0,
                'total'            => $total,
                'products_written' => 0,
                'started'          => $started,
                'error'            => '',
            ) );
            OtwFeed_DB_Logs::info( $feed_id, __( 'Background feed generation started.', 'otwfeed-pro' ) );
        }

        if ( ! empty( $batch_ids ) ) {
            try {
                $products = OtwFeed_Product_Query::get_products_for_batch( $feed, $filters, $batch_ids );
                if ( ! empty( $products ) ) {
                    file_put_contents( $temp, $builder::build_items_xml( $feed, $mappings, $products ), FILE_APPEND ); // phpcs:ignore
                    $products_written += count( $products );
                }
            } catch ( \Throwable $e ) {
                @unlink( $temp ); // phpcs:ignore
                self::set_progress( $feed_id, array(
                    'status'           => 'error',
                    'processed'        => $offset,
                    'total'            => $total,
                    'products_written' => $products_written,
                    'started'          => $started,
                    'error'            => $e->getMessage(),
                ) );
                OtwFeed_DB_Logs::error( $feed_id, $e->getMessage(), array( 'trace' => $e->getTraceAsString() ) );
                return;
            }
        }

        $processed = min( $offset + count( $batch_ids ), $total );
        $is_last   = count( $batch_ids ) < self::BATCH_SIZE;

        if ( $is_last ) {
            self::finalise( $feed, $feed_id, $temp, $total, $products_written, $started );
        } else {
            self::set_progress( $feed_id, array(
                'status'           => 'running',
                'processed'        => $processed,
                'total'            => $total,
                'products_written' => $products_written,
                'started'          => $started,
                'error'            => '',
            ) );

            unset( $products, $batch_ids );
            if ( function_exists( 'gc_collect_cycles' ) ) {
                gc_collect_cycles();
            }

            if ( function_exists( 'as_schedule_single_action' ) ) {
                as_schedule_single_action(
                    time(),
                    self::AS_HOOK,
                    array( 'feed_id' => $feed_id, 'offset' => $offset + self::BATCH_SIZE ),
                    self::as_group( $feed_id )
                );
            }
        }
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

    /** Register the legacy REST route (fallback when Action Scheduler is unavailable). */
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

    /** Legacy REST callback — only invoked when Action Scheduler is unavailable. */
    public static function handle_rest( \WP_REST_Request $request ): \WP_REST_Response {
        $feed_id = absint( $request->get_param( 'feed_id' ) );
        $token   = (string) ( $request->get_header( 'X-OtwFeed-Token' ) ?? '' );

        if ( ! hash_equals( self::token( $feed_id ), $token ) ) {
            return new \WP_REST_Response( null, 403 );
        }

        ignore_user_abort( true );
        @set_time_limit( 0 );           // phpcs:ignore
        @ini_set( 'memory_limit', '512M' ); // phpcs:ignore

        // Drive all batches sequentially in this single process.
        $offset = 0;
        do {
            self::run_batch( $feed_id, $offset );
            $progress = self::get_progress( $feed_id );
            $offset  += self::BATCH_SIZE;
            usleep( 200000 ); // 0.2 s breathing room
        } while ( 'running' === $progress['status'] );

        return new \WP_REST_Response( null, 200 );
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private static function finalise(
        object $feed,
        int    $feed_id,
        string $temp,
        int    $total,
        int    $products_written,
        int    $started
    ): void {
        $builder = 'google' === $feed->channel ? 'OtwFeed_Feed_Builder_Google' : 'OtwFeed_Feed_Builder_Facebook';
        file_put_contents( $temp, $builder::build_epilogue(), FILE_APPEND ); // phpcs:ignore

        // Validate XML before replacing the live file — discard if corrupt.
        if ( ! self::validate_xml( $temp ) ) {
            @unlink( $temp ); // phpcs:ignore
            self::set_progress( $feed_id, array(
                'status'           => 'error',
                'processed'        => $total,
                'total'            => $total,
                'products_written' => $products_written,
                'started'          => $started,
                'error'            => __( 'Generated XML failed validation — please regenerate.', 'otwfeed-pro' ),
            ) );
            OtwFeed_DB_Logs::error( $feed_id, __( 'Feed XML failed validation.', 'otwfeed-pro' ) );
            return;
        }

        // Atomic rename: temp replaces live in a single OS call.
        $live = self::live_path( $feed );
        rename( $temp, $live );

        OtwFeed_DB_Feeds::update( $feed_id, array(
            'last_gen'      => current_time( 'mysql' ),
            'file_path'     => $live,
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

    private static function validate_xml( string $path ): bool {
        libxml_use_internal_errors( true );
        $dom   = new \DOMDocument();
        $valid = (bool) $dom->load( $path );
        libxml_clear_errors();
        libxml_use_internal_errors( false );
        return $valid;
    }

    private static function cancel_pending_actions( int $feed_id ): void {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( self::AS_HOOK, array(), self::as_group( $feed_id ) );
        }
    }

    private static function as_group( int $feed_id ): string {
        return 'otwfeed-pro-' . $feed_id;
    }

    private static function upload_dir(): string {
        return trailingslashit( wp_upload_dir()['basedir'] ) . 'otwfeed-pro/';
    }

    private static function temp_path( object $feed ): string {
        return self::upload_dir() . 'feed-' . absint( $feed->id ) . '-' . sanitize_file_name( $feed->token ) . '.xml.tmp';
    }

    private static function live_path( object $feed ): string {
        return self::upload_dir() . 'feed-' . absint( $feed->id ) . '-' . sanitize_file_name( $feed->token ) . '.xml';
    }

    private static function key( int $feed_id ): string {
        return 'otwfeed_progress_' . $feed_id;
    }

    private static function token( int $feed_id ): string {
        return wp_hash( 'otwfeed_bg_gen_' . $feed_id );
    }

    private static function set_progress( int $feed_id, array $data ): void {
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
