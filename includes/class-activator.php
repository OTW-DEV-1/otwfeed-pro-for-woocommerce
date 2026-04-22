<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OtwFeed_Activator {

    public static function activate(): void {
        self::create_tables();
        self::create_feeds_dir();
        update_option( 'otwfeed_db_version', OTWFEED_DB_VER );
    }

    public static function deactivate(): void {
        // Intentionally left empty — keep data on deactivate.
    }

    public static function drop_filters_table(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}otwfeed_filters" ); // phpcs:ignore
    }

    private static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$wpdb->prefix}otwfeed_feeds (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title             VARCHAR(255)    NOT NULL DEFAULT '',
            channel           VARCHAR(50)     NOT NULL DEFAULT 'google',
            country           VARCHAR(10)     NOT NULL DEFAULT 'IT',
            currency          VARCHAR(10)     NOT NULL DEFAULT 'EUR',
            tax_mode          VARCHAR(20)     NOT NULL DEFAULT 'include',
            status            VARCHAR(20)     NOT NULL DEFAULT 'active',
            token             VARCHAR(64)     NOT NULL DEFAULT '',
            expand_variations      TINYINT(1)      NOT NULL DEFAULT 1,
            include_gallery_images TINYINT(1)      NOT NULL DEFAULT 1,
            last_gen          DATETIME        DEFAULT NULL,
            file_path         VARCHAR(500)    NOT NULL DEFAULT '',
            created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY status (status),
            KEY channel (channel)
        ) $charset;
        CREATE TABLE {$wpdb->prefix}otwfeed_mappings (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            feed_id     BIGINT UNSIGNED NOT NULL,
            channel_tag VARCHAR(100)    NOT NULL DEFAULT '',
            source_type VARCHAR(50)     NOT NULL DEFAULT 'attribute',
            source_key  VARCHAR(255)    NOT NULL DEFAULT '',
            static_val  VARCHAR(500)    NOT NULL DEFAULT '',
            price_round TINYINT(1)      NOT NULL DEFAULT 0,
            sort_order  SMALLINT        NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY feed_id (feed_id)
        ) $charset;
        CREATE TABLE {$wpdb->prefix}otwfeed_filters (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            feed_id        BIGINT UNSIGNED NOT NULL,
            group_id       INT UNSIGNED    NOT NULL DEFAULT 0,
            group_action   VARCHAR(20)     NOT NULL DEFAULT 'exclude',
            attribute      VARCHAR(100)    NOT NULL DEFAULT 'price',
            condition_op   VARCHAR(50)     NOT NULL DEFAULT 'lt',
            value          TEXT            NOT NULL,
            case_sensitive TINYINT(1)      NOT NULL DEFAULT 0,
            sort_order     SMALLINT        NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY feed_id  (feed_id),
            KEY group_id (group_id)
        ) $charset;
        CREATE TABLE {$wpdb->prefix}otwfeed_logs (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            feed_id    BIGINT UNSIGNED NOT NULL,
            level      VARCHAR(20)     NOT NULL DEFAULT 'info',
            message    TEXT            NOT NULL,
            context    LONGTEXT        DEFAULT NULL,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY feed_id (feed_id),
            KEY level (level)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    private static function create_feeds_dir(): void {
        $upload = wp_upload_dir();
        $dir    = trailingslashit( $upload['basedir'] ) . 'otwfeed-pro/';

        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        $htaccess = $dir . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Options -Indexes\n" ); // phpcs:ignore
        }
    }
}
