<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds a Google Merchant Center RSS 2.0 XML feed.
 */
class OtwFeed_Feed_Builder_Google {

    public static function build( object $feed, array $mappings, array $products ): string {
        $doc  = new \DOMDocument( '1.0', 'UTF-8' );
        $doc->formatOutput = false;

        $rss = $doc->createElement( 'rss' );
        $rss->setAttribute( 'version', '2.0' );
        $rss->setAttribute( 'xmlns:g', 'http://base.google.com/ns/1.0' );
        $doc->appendChild( $rss );

        $channel = $doc->createElement( 'channel' );
        $rss->appendChild( $channel );

        $site_name = get_bloginfo( 'name' );
        self::append_text( $doc, $channel, 'title', $site_name );
        self::append_text( $doc, $channel, 'link',  get_site_url() );
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

        $attrs['price']         = $prices['price'];
        $attrs['sale_price']    = $prices['regular'];

        // Resolve meta values.
        foreach ( array( '_gtin', '_mpn', '_google_category' ) as $meta_key ) {
            $attr_key = ltrim( $meta_key, '_' );
            $attrs[ $meta_key ] = get_post_meta( $product->get_id(), $meta_key, true );
        }

        foreach ( $mappings as $mapping ) {
            $value = self::resolve_value( $mapping, $attrs, $product );
            if ( '' === $value && '' === ( $mapping->static_val ?? '' ) ) {
                continue;
            }
            if ( '' === $value ) {
                continue;
            }
            self::append_text( $doc, $item, $mapping->channel_tag, $value, 'g:description' === $mapping->channel_tag );
        }

        // Always append sale_price if the product is on sale.
        if ( ! empty( $prices['regular'] ) ) {
            self::append_text( $doc, $item, 'g:sale_price', $prices['price'] );
            // Overwrite price with regular price.
            foreach ( $item->childNodes as $child ) {
                if ( $child instanceof \DOMElement && 'g:price' === $child->tagName ) {
                    $item->removeChild( $child );
                    break;
                }
            }
            self::append_text( $doc, $item, 'g:price', $prices['regular'] );
        }

        // Top-level category as google_product_category.
        if ( ! empty( $attrs['google_product_category'] ) ) {
            self::append_text( $doc, $item, 'g:google_product_category', $attrs['google_product_category'] );
        }

        // Additional images (only when the feed option is enabled).
        if ( (int) ( $feed->include_gallery_images ?? 1 ) ) {
            foreach ( array_slice( $attrs['extra_images'], 0, 9 ) as $extra_image ) {
                if ( ! empty( $extra_image ) ) {
                    self::append_text( $doc, $item, 'g:additional_image_link', (string) $extra_image );
                }
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
