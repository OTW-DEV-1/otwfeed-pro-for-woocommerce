<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds a Facebook / Meta Product Catalog XML feed (RSS 2.0 compatible).
 *
 * Uses direct string concatenation instead of DOMDocument to minimise
 * per-item CPU and memory overhead on large catalogs.
 */
class OtwFeed_Feed_Builder_Facebook {

    // ── Public API ─────────────────────────────────────────────────────────────

    public static function build_preamble( object $feed ): string {
        $name = esc_xml( get_bloginfo( 'name' ) );
        $url  = esc_xml( get_site_url() );
        $desc = esc_xml( get_bloginfo( 'description' ) );

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<rss version="2.0" xmlns:c="http://base.google.com/ns/1.0">' . "\n"
             . '<channel>' . "\n"
             . "<title>{$name}</title>\n"
             . "<link>{$url}</link>\n"
             . "<description>{$desc}</description>\n";
    }

    public static function build_epilogue(): string {
        return '</channel>' . "\n" . '</rss>';
    }

    /**
     * @param object        $feed
     * @param object[]      $mappings
     * @param \WC_Product[] $products
     */
    public static function build_items_xml( object $feed, array $mappings, array $products ): string {
        $xml = '';
        foreach ( $products as $product ) {
            $xml .= "<item>\n" . self::build_item( $product, $feed, $mappings ) . "</item>\n";
        }
        return $xml;
    }

    public static function build( object $feed, array $mappings, array $products ): string {
        return self::build_preamble( $feed )
             . self::build_items_xml( $feed, $mappings, $products )
             . self::build_epilogue();
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private static function build_item( \WC_Product $product, object $feed, array $mappings ): string {
        $attrs = OtwFeed_Product_Query::get_product_attributes( $product );

        if ( empty( $feed->skip_currency_param ) ) {
            $attrs['permalink']     = OtwFeed_Currency_Manager::get_currency_url( $attrs['permalink'],     $feed->currency );
            $attrs['checkout_link'] = OtwFeed_Currency_Manager::get_currency_url( $attrs['checkout_link'], $feed->currency );
        }

        $utm = array(
            'utm_source'   => 'Google Shopping',
            'utm_medium'   => 'cpc',
            'utm_campaign' => $feed->title,
            'utm_term'     => 'otwfeed',
        );
        if ( empty( $feed->skip_country_param ) && ! empty( $feed->country ) ) {
            $utm = array_merge( array( 'wc-country' => strtoupper( $feed->country ) ), $utm );
        }
        $attrs['permalink'] = add_query_arg( $utm, $attrs['permalink'] );
        if ( empty( $feed->skip_country_param ) && ! empty( $feed->country ) ) {
            $attrs['checkout_link'] = add_query_arg( 'wc-country', strtoupper( $feed->country ), $attrs['checkout_link'] );
        }

        $price_round = false;
        foreach ( $mappings as $m ) {
            if ( 'attribute' === ( $m->source_type ?? '' ) && 'price' === ( $m->source_key ?? '' ) ) {
                $price_round = ! empty( $m->price_round );
                break;
            }
        }

        $prices = OtwFeed_Price_Calculator::get_price_pair( $product, $feed->tax_mode, $feed->country, $feed->currency, $price_round );

        $attrs['price']      = $prices['price'];
        $attrs['sale_price'] = $prices['regular'];

        $xml = '';
        foreach ( $mappings as $mapping ) {
            $value = self::resolve_value( $mapping, $attrs, $product );
            if ( '' === $value ) {
                continue;
            }
            $xml .= self::xml_tag( $mapping->channel_tag, $value, 'description' === $mapping->channel_tag );
        }

        if ( ! empty( $prices['regular'] ) ) {
            $xml .= self::xml_tag( 'sale_price', $prices['price'] );
        }

        foreach ( array_slice( $attrs['extra_images'], 0, 9 ) as $extra_image ) {
            if ( ! empty( $extra_image ) ) {
                $xml .= self::xml_tag( 'additional_image_link', (string) $extra_image );
            }
        }

        return $xml;
    }

    private static function resolve_value( object $mapping, array $attrs, \WC_Product $product ): string {
        if ( 'static' === $mapping->source_type ) {
            return $mapping->static_val ?? '';
        }
        if ( 'attribute' === $mapping->source_type ) {
            return (string) ( $attrs[ $mapping->source_key ] ?? '' );
        }
        if ( 'meta' === $mapping->source_type ) {
            return (string) get_post_meta( $product->get_id(), $mapping->source_key, true );
        }
        if ( 'taxonomy' === $mapping->source_type ) {
            $terms = wp_get_post_terms( $product->get_id(), $mapping->source_key, array( 'fields' => 'names' ) );
            return is_array( $terms ) ? implode( ', ', $terms ) : '';
        }
        return '';
    }

    private static function xml_tag( string $tag, string $value, bool $force_cdata = false ): string {
        if ( $force_cdata || str_contains( $value, '&' ) || str_contains( $value, '<' ) || str_contains( $value, '>' ) ) {
            return "<{$tag}><![CDATA[{$value}]]></{$tag}>\n";
        }
        return "<{$tag}>" . htmlspecialchars( $value, ENT_XML1, 'UTF-8' ) . "</{$tag}>\n";
    }
}
