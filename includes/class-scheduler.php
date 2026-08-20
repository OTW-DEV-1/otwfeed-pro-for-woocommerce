<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Automatic feed regeneration.
 *
 * Every feed has its own `regen_interval` (seconds, 0 = manual only). A single
 * recurring "tick" runs every 15 minutes (Action Scheduler, WP-Cron fallback),
 * finds every active feed whose last generation is older than its interval and
 * queues a background regeneration for it via OtwFeed_Background_Generator.
 */
class OtwFeed_Scheduler {

    public  const TICK_HOOK     = 'otwfeed_pro_auto_regen_tick';
    private const TICK_INTERVAL = 15 * MINUTE_IN_SECONDS;
    private const AS_GROUP      = 'otwfeed-pro-scheduler';
    private const CRON_SCHEDULE = 'otwfeed_every_15min';
    private const ENSURE_KEY    = 'otwfeed_scheduler_ensured';
    public  const LAST_TICK_OPT = 'otwfeed_scheduler_last_tick';
    private const FIRST_SEEN_OPT = 'otwfeed_scheduler_first_seen';
    /** Tolerance so a "daily" feed generated at 09:00 is picked up by the 09:00 tick, not 09:15. */
    private const TOLERANCE     = 5 * MINUTE_IN_SECONDS;

    /** Available per-feed intervals (seconds => label). */
    public static function get_intervals(): array {
        return array(
            0      => __( 'Manual only (never auto-update)', 'otwfeed-pro' ),
            3600   => __( 'Every hour', 'otwfeed-pro' ),
            21600  => __( 'Every 6 hours', 'otwfeed-pro' ),
            43200  => __( 'Every 12 hours', 'otwfeed-pro' ),
            86400  => __( 'Daily', 'otwfeed-pro' ),
            604800 => __( 'Weekly', 'otwfeed-pro' ),
        );
    }

    public static function get_interval_label( int $seconds ): string {
        $intervals = self::get_intervals();
        if ( isset( $intervals[ $seconds ] ) ) {
            return $intervals[ $seconds ];
        }
        /* translators: %s: human readable interval, e.g. "2 hours" */
        return sprintf( __( 'Every %s', 'otwfeed-pro' ), human_time_diff( 0, $seconds ) );
    }

    /** Default interval for newly created feeds (global setting, falls back to daily). */
    public static function get_default_interval(): int {
        $value = get_option( 'otwfeed_auto_regen_interval', null );
        return null === $value ? DAY_IN_SECONDS : absint( $value );
    }

    public static function init(): void {
        add_action( self::TICK_HOOK, array( self::class, 'run_tick' ) );
        add_filter( 'cron_schedules', array( self::class, 'add_cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
        add_action( 'init', array( self::class, 'ensure_scheduled' ), 20 );
    }

    public static function add_cron_schedule( array $schedules ): array {
        $schedules[ self::CRON_SCHEDULE ] = array(
            'interval' => self::TICK_INTERVAL,
            'display'  => __( 'Every 15 minutes (OtwFeed Pro)', 'otwfeed-pro' ),
        );
        return $schedules;
    }

    /**
     * Make sure the recurring tick exists. Cheap: verified at most once per hour.
     */
    public static function ensure_scheduled(): void {
        if ( get_transient( self::ENSURE_KEY ) ) {
            return;
        }
        set_transient( self::ENSURE_KEY, 1, HOUR_IN_SECONDS );
        if ( ! get_option( self::FIRST_SEEN_OPT ) ) {
            update_option( self::FIRST_SEEN_OPT, time(), false );
        }

        if ( function_exists( 'as_has_scheduled_action' ) ) {
            if ( ! as_has_scheduled_action( self::TICK_HOOK, array(), self::AS_GROUP ) ) {
                as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, self::TICK_INTERVAL, self::TICK_HOOK, array(), self::AS_GROUP );
            }
            // Drop any WP-Cron fallback left over from a time AS was unavailable.
            if ( wp_next_scheduled( self::TICK_HOOK ) ) {
                wp_clear_scheduled_hook( self::TICK_HOOK );
            }
            return;
        }

        if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::TICK_HOOK );
        }
    }

    /** Remove the recurring tick (deactivation). */
    public static function unschedule(): void {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( self::TICK_HOOK, array(), self::AS_GROUP );
        }
        wp_clear_scheduled_hook( self::TICK_HOOK );
        delete_transient( self::ENSURE_KEY );
    }

    /**
     * The recurring tick: queue regeneration for every active feed that is due.
     */
    public static function run_tick(): void {
        update_option( self::LAST_TICK_OPT, time(), false );

        $feeds = OtwFeed_DB_Feeds::get_all( array( 'status' => 'active', 'limit' => 500 ) );
        $now   = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp — matches last_gen (current_time('mysql'))

        foreach ( $feeds as $feed ) {
            if ( ! self::is_due( $feed, $now ) ) {
                continue;
            }

            $progress = OtwFeed_Background_Generator::get_progress( (int) $feed->id );
            if ( in_array( $progress['status'], array( 'queued', 'running' ), true ) ) {
                continue; // A generation is already in flight.
            }

            $result = OtwFeed_Background_Generator::schedule( (int) $feed->id );
            if ( ! empty( $result['success'] ) ) {
                OtwFeed_DB_Logs::info( (int) $feed->id, sprintf(
                    /* translators: %s: interval label, e.g. "Daily" */
                    __( 'Scheduled automatic regeneration (%s).', 'otwfeed-pro' ),
                    self::get_interval_label( (int) $feed->regen_interval )
                ) );
            } else {
                OtwFeed_DB_Logs::error( (int) $feed->id, sprintf(
                    /* translators: %s: error message */
                    __( 'Automatic regeneration could not start: %s', 'otwfeed-pro' ),
                    $result['error'] ?? ''
                ) );
            }
        }
    }

    public static function is_due( object $feed, ?int $now = null ): bool {
        $interval = (int) ( $feed->regen_interval ?? 0 );
        if ( $interval <= 0 || 'active' !== ( $feed->status ?? '' ) ) {
            return false;
        }
        $next = self::get_next_run( $feed );
        if ( null === $next ) {
            return false;
        }
        $now = $now ?? current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
        return $next - self::TOLERANCE <= $now;
    }

    /**
     * Local (site-timezone) timestamp of the next automatic run, or null when
     * auto-update is off. A never-generated feed is due immediately.
     */
    public static function get_next_run( object $feed ): ?int {
        $interval = (int) ( $feed->regen_interval ?? 0 );
        if ( $interval <= 0 ) {
            return null;
        }
        if ( empty( $feed->last_gen ) ) {
            return current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
        }
        return (int) strtotime( $feed->last_gen ) + $interval;
    }

    /**
     * Health check for admin screens: returns a warning string when the
     * recurring tick has not run recently (e.g. WP-Cron disabled on the host),
     * or '' when everything looks fine.
     */
    public static function get_health_warning(): string {
        $last = (int) get_option( self::LAST_TICK_OPT, 0 );
        $age  = time() - $last;
        if ( $last > 0 && $age < 2 * HOUR_IN_SECONDS ) {
            return '';
        }
        // Freshly installed/upgraded: give the first tick two hours before warning.
        $first_seen = (int) get_option( self::FIRST_SEEN_OPT, 0 );
        if ( 0 === $last && ( 0 === $first_seen || time() - $first_seen < 2 * HOUR_IN_SECONDS ) ) {
            return '';
        }
        $when = $last > 0
            /* translators: %s: relative time e.g. "3 hours" */
            ? sprintf( __( 'last ran %s ago', 'otwfeed-pro' ), human_time_diff( $last, time() ) )
            : __( 'has never run', 'otwfeed-pro' );
        return sprintf(
            /* translators: %s: "last ran 3 hours ago" / "has never run" */
            __( 'Automatic feed updates may not be running: the scheduler %s. Feeds are regenerated by WP-Cron / WooCommerce Action Scheduler, which needs site traffic or a real server cron (DISABLE_WP_CRON + a cron job calling wp-cron.php). Check WooCommerce → Status → Scheduled Actions for pending "otwfeed_pro_auto_regen_tick" actions.', 'otwfeed-pro' ),
            $when
        );
    }

    /** Human readable next-run text for admin screens. */
    public static function describe_next_run( object $feed ): string {
        if ( 'active' !== ( $feed->status ?? '' ) ) {
            return __( 'Feed inactive', 'otwfeed-pro' );
        }
        $next = self::get_next_run( $feed );
        if ( null === $next ) {
            return __( 'Manual only', 'otwfeed-pro' );
        }
        $now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
        if ( $next - self::TOLERANCE <= $now ) {
            return __( 'Due now (next check within 15 min)', 'otwfeed-pro' );
        }
        return sprintf(
            /* translators: 1: date/time, 2: relative time e.g. "3 hours" */
            __( '%1$s (in %2$s)', 'otwfeed-pro' ),
            date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ),
            human_time_diff( $now, $next )
        );
    }
}
