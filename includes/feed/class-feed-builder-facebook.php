<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds a Facebook / Meta Product Catalog XML feed (RSS 2.0 compatible).
 */
class OtwFeed_Feed_Builder_Facebook {

    public static function build( object $feed, array $mappings, array $products ): string {
        $doc = new \DOMDocument( '1.0', 'UTF-8' );
        $doc->formatOutput = false;

        $rss = $doc->createElement( 'rss' );
        $rss->setAttribute( 'version', '2.0' );
        $rss->setAttribute( 'xmlns:c', 'http://base.google.com/ns/1.0' );
        $doc->appendChild( $rss );

        $channel = $doc->createElement( 'channel' );
        $rss->appendChild( $channel );

        self::append_text( $doc, $channel, 'title', get_bloginfo( 'name' ) );
        self::append_text( $doc, $channel, 'link', get_site_url() );
        self::append_text( $doc, $channel, 'description', get_bloginfo( 'description' ) );

        foreach ( $products as $product ) {
            $item = $doc->createElement( 'item' );
            $channel->appendChild( $item );
            self::build_item( $doc, $item, $product, $feed, $mappings );
        }

        return $doc->saveXML() ?: '';
    }

    private static function build_item( \DOMDocument $doc, \DOMElement $item, \WC_Product $product, object $feed, array $mappings ): void {
        $attrs  = OtwFeed_Product_Query::get_product_attributes( $product );
        $attrs['permalink']    = OtwFeed_Currency_Manager::get_currency_url( $attrs['permalink'],    $feed->currency );
        $attrs['permalink']    = add_query_arg( array(
            'utm_source'   => 'Google Shopping',
            'utm_medium'   => 'cpc',
            'utm_campaign' => $feed->title,
            'utm_term'     => 'otwfeed',
        ), $attrs['permalink'] );
        $attrs['checkout_link'] = OtwFeed_Currency_Manager::get_currency_url( $attrs['checkout_link'], $feed->currency );
        $attrs['checkout_link'] = add_query_arg( 'wc-country', strtoupper( $feed->country ), $attrs['checkout_link'] );

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

        foreach ( $mappings as $mapping ) {
            $value = self::resolve_value( $mapping, $attrs, $product );
            if ( '' === $value ) {
                continue;
            }
            self::append_text( $doc, $item, $mapping->channel_tag, $value, 'description' === $mapping->channel_tag );
        }

        // sale_price as separate element when on sale.
        if ( ! empty( $prices['regular'] ) ) {
            self::append_text( $doc, $item, 'sale_price', $prices['price'] );
        }

        // Additional images.
        foreach ( array_slice( $attrs['extra_images'], 0, 9 ) as $idx => $extra_image ) {
            if ( ! empty( $extra_image ) ) {
                self::append_text( $doc, $item, 'additional_image_link', (string) $extra_image );
            }
        }
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

    private static function append_text( \DOMDocument $doc, \DOMElement $parent, string $tag, string $value, bool $force_cdata = false ): void {
        $el = $doc->createElement( $tag );
        if ( $force_cdata || str_contains( $value, '&' ) || str_contains( $value, '<' ) || str_contains( $value, '>' ) ) {
            $el->appendChild( $doc->createCDATASection( $value ) );
        } else {
            $el->appendChild( $doc->createTextNode( $value ) );
        }
        $parent->appendChild( $el );
    }
}
