<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OtwFeed_DB_Feeds {

    private static string $table = '';

    private static function table(): string {
        global $wpdb;
        if ( '' === self::$table ) {
            self::$table = $wpdb->prefix . 'otwfeed_feeds';
        }
        return self::$table;
    }

    public static function get_all( array $args = [] ): array {
        global $wpdb;
        $defaults = array(
            'status'   => '',
            'channel'  => '',
            'orderby'  => 'created_at',
            'order'    => 'DESC',
            'limit'    => 50,
            'offset'   => 0,
        );
        $args     = wp_parse_args( $args, $defaults );

        $where  = array( '1=1' );
        $params = array();

        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }
        if ( ! empty( $args['channel'] ) ) {
            $where[]  = 'channel = %s';
            $params[] = $args['channel'];
        }

        $allowed_orderby = array( 'id', 'title', 'created_at', 'last_gen', 'status' );
        $orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s ORDER BY %s %s LIMIT %d OFFSET %d',
            self::table(),
            implode( ' AND ', $where ),
            $orderby,
            $order,
            absint( $args['limit'] ),
            absint( $args['offset'] )
        );

        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore
        }

        return (array) $wpdb->get_results( $sql ); // phpcs:ignore
    }

    public static function get( int $id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id )
        );
        return $row ?: null;
    }

    public static function get_by_token( string $token ): ?object {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE token = %s', self::table(), $token )
        );
        return $row ?: null;
    }

    public static function insert( array $data ): int|false {
        global $wpdb;
        if ( empty( $data['token'] ) ) {
            $data['token'] = wp_generate_password( 32, false );
        }
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

    public static function count( array $args = [] ): int {
        global $wpdb;
        $where  = array( '1=1' );
        $params = array();

        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }

        $sql = sprintf( 'SELECT COUNT(*) FROM %s WHERE %s', self::table(), implode( ' AND ', $where ) );
        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore
        }
        return (int) $wpdb->get_var( $sql ); // phpcs:ignore
    }
}
