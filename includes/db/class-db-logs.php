<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OtwFeed_DB_Logs {

    private static string $table = '';

    private static function table(): string {
        global $wpdb;
        if ( '' === self::$table ) {
            self::$table = $wpdb->prefix . 'otwfeed_logs';
        }
        return self::$table;
    }

    public static function log( int $feed_id, string $level, string $message, array $context = [] ): void {
        global $wpdb;
        $wpdb->insert(
            self::table(),
            array(
                'feed_id' => $feed_id,
                'level'   => $level,
                'message' => $message,
                'context' => ! empty( $context ) ? wp_json_encode( $context ) : null,
            )
        );
    }

    public static function info( int $feed_id, string $message, array $context = [] ): void {
        self::log( $feed_id, 'info', $message, $context );
    }

    public static function error( int $feed_id, string $message, array $context = [] ): void {
        self::log( $feed_id, 'error', $message, $context );
    }

    public static function get_for_feed( int $feed_id, int $limit = 50 ): array {
        global $wpdb;
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE feed_id = %d ORDER BY created_at DESC LIMIT %d',
                self::table(),
                $feed_id,
                $limit
            )
        );
    }

    public static function clear_for_feed( int $feed_id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), array( 'feed_id' => $feed_id ), array( '%d' ) );
    }

    public static function clear_old( int $days = 30 ): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)',
                self::table(),
                $days
            )
        );
    }
}
