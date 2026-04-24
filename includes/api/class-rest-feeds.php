<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST endpoint: GET /wp-json/otwfeed-pro/v1/feed/{token}
 * Serves the generated XML feed file by token.
 */
class OtwFeed_REST_Feeds extends \WP_REST_Controller {

    protected $namespace = 'otwfeed-pro/v1';
    protected $rest_base = 'feed';

    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<token>[a-zA-Z0-9_-]+)',
            array(
                array(
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'serve_feed' ),
                    'permission_callback' => '__return_true',
                    'args'                => array(
                        'token' => array(
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ),
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/regenerate/(?P<id>[\d]+)',
            array(
                array(
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'regenerate_feed' ),
                    'permission_callback' => array( $this, 'edit_permission_check' ),
                    'args'                => array(
                        'id' => array(
                            'required'          => true,
                            'sanitize_callback' => 'absint',
                        ),
                    ),
                ),
            )
        );
    }

    public function serve_feed( \WP_REST_Request $request ): void {
        $token = $request->get_param( 'token' );
        $feed  = OtwFeed_DB_Feeds::get_by_token( $token );

        if ( ! $feed || 'active' !== $feed->status ) {
            wp_die( esc_html__( 'Feed not found or inactive.', 'otwfeed-pro' ), '', array( 'response' => 404 ) );
        }

        // Generate on demand if file missing.
        if ( empty( $feed->file_path ) || ! file_exists( $feed->file_path ) ) {
            $result = OtwFeed_Feed_Generator::generate( (int) $feed->id );
            if ( ! $result['success'] ) {
                wp_die( esc_html__( 'Feed generation failed.', 'otwfeed-pro' ), '', array( 'response' => 500 ) );
            }
            $feed = OtwFeed_DB_Feeds::get( (int) $feed->id );
        }

        // Discard any buffered output (WP debug notices, plugin hooks) so nothing
        // gets appended before or after the XML content.
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        header( 'Content-Type: application/xml; charset=utf-8' );
        header( 'Cache-Control: public, max-age=3600' );
        header( 'Content-Length: ' . filesize( $feed->file_path ) );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
        readfile( $feed->file_path );
        exit;
    }

    public function regenerate_feed( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $id     = $request->get_param( 'id' );
        $result = OtwFeed_Feed_Generator::generate( $id );

        if ( ! $result['success'] ) {
            return new \WP_Error( 'generation_failed', $result['error'], array( 'status' => 500 ) );
        }

        return rest_ensure_response( $result );
    }

    public function edit_permission_check(): bool {
        return current_user_can( 'manage_woocommerce' );
    }
}
