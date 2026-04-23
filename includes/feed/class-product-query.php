<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds a WC_Product_Query from feed filters and returns WC_Product objects.
 */
class OtwFeed_Product_Query {

    /** @var array<int,int[]> Static cache for get_ancestors() — persists across batches in the same PHP process. */
    private static array $ancestors_cache = array();

    /**
     * Returns all published parent product IDs via a raw DB query.
     * Extremely lightweight — no WC_Product objects loaded.
     *
     * @return int[]
     */
    public static function get_parent_ids(): array {
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col( // phpcs:ignore
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status = 'publish'
             ORDER BY ID ASC"
        ) );
    }

    /**
     * Loads one batch of parent IDs, expands variations efficiently via a
     * single bulk DB query (no wc_get_product() loop), applies filters,
     * and returns the final product list for that batch.
     *
     * @param object   $feed
     * @param object[] $filters
     * @param int[]    $parent_ids
     * @return \WC_Product[]
     */
    public static function get_products_for_batch( object $feed, array $filters, array $parent_ids ): array {
        if ( empty( $parent_ids ) ) {
            return array();
        }

        $ev = (int) ( $feed->expand_variations ?? 1 );

        // Load parent products for this batch.
        $parents = wc_get_products( array(
            'include' => $parent_ids,
            'limit'   => count( $parent_ids ),
            'return'  => 'objects',
            'status'  => 'publish',
        ) );

        $products     = array();
        $variable_ids = array();

        foreach ( $parents as $product ) {
            if ( $product->is_type( 'variable' ) && $ev > 0 ) {
                $variable_ids[] = $product->get_id();
            } else {
                $products[] = $product;
            }
        }

        // Expand variable products using a single bulk variation ID query.
        if ( ! empty( $variable_ids ) ) {
            global $wpdb;
            $placeholders  = implode( ',', array_fill( 0, count( $variable_ids ), '%d' ) );
            $variation_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( // phpcs:ignore
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'product_variation' AND post_status = 'publish'
                 AND post_parent IN ($placeholders)",
                ...$variable_ids
            ) ) );

            if ( ! empty( $variation_ids ) ) {
                $variations = wc_get_products( array(
                    'include' => $variation_ids,
                    'limit'   => count( $variation_ids ),
                    'return'  => 'objects',
                ) );

                // Only keep purchasable variations that have a price.
                $variations = array_values( array_filter(
                    $variations,
                    static fn( $v ) => $v->is_purchasable() && '' !== $v->get_price()
                ) );

                if ( 2 === $ev ) {
                    // Keep only the lowest-price variation per parent.
                    $by_parent = array();
                    foreach ( $variations as $var ) {
                        $pid   = $var->get_parent_id();
                        $price = OtwFeed_Price_Calculator::get_price_float(
                            $var,
                            $feed->tax_mode,
                            $feed->country,
                            $feed->currency,
                            false
                        );
                        if ( ! isset( $by_parent[ $pid ] ) || $price < $by_parent[ $pid ]['price'] ) {
                            $by_parent[ $pid ] = array( 'var' => $var, 'price' => $price );
                        }
                    }
                    foreach ( $by_parent as $entry ) {
                        $products[] = $entry['var'];
                    }
                } else {
                    // All variations.
                    array_push( $products, ...$variations );
                }
            }
        }

        return empty( $filters ) ? $products : self::apply_filters( $products, $filters, $feed );
    }

    /**
     * Legacy full-load method — kept for small-scale use (e.g. filter previews).
     * For feed generation use get_parent_ids() + get_products_for_batch() instead.
     *
     * @param object   $feed
     * @param object[] $filters
     * @return \WC_Product[]
     */
    public static function get_products( object $feed, array $filters ): array {
        $args = array(
            'status' => 'publish',
            'limit'  => -1,
            'return' => 'objects',
        );

        $query    = new \WC_Product_Query( $args );
        $products = $query->get_products();

        $ev = (int) ( $feed->expand_variations ?? 1 );
        if ( 1 === $ev ) {
            $products = self::expand_variable_products( $products );
        } elseif ( 2 === $ev ) {
            $products = self::expand_lowest_price_variation( $products, $feed );
        }

        return empty( $filters ) ? array_values( $products ) : self::apply_filters( $products, $filters, $feed );
    }

    // ── Filter evaluation ──────────────────────────────────────────────────────

    /**
     * @param \WC_Product[] $products
     * @param object[]      $filters
     * @param object        $feed
     * @return \WC_Product[]
     */
    private static function apply_filters( array $products, array $filters, object $feed ): array {
        $groups = array();
        foreach ( $filters as $filter ) {
            $gid = (int) $filter->group_id;
            if ( ! isset( $groups[ $gid ] ) ) {
                $groups[ $gid ] = array(
                    'action'     => $filter->group_action,
                    'conditions' => array(),
                );
            }
            $groups[ $gid ]['conditions'][] = $filter;
        }

        $include_groups = array_filter( $groups, static fn( $g ) => 'include' === $g['action'] );
        $exclude_groups = array_filter( $groups, static fn( $g ) => 'exclude' === $g['action'] );

        if ( ! empty( $include_groups ) ) {
            $kept = array();
            foreach ( $products as $product ) {
                foreach ( $include_groups as $group ) {
                    if ( self::product_matches_group( $product, $group['conditions'], $feed ) ) {
                        $kept[ $product->get_id() ] = $product;
                        break;
                    }
                }
            }
            $products = array_values( $kept );
        }

        foreach ( $exclude_groups as $group ) {
            $products = array_values( array_filter(
                $products,
                static fn( $p ) => ! self::product_matches_group( $p, $group['conditions'], $feed )
            ) );
        }

        return $products;
    }

    private static function product_matches_group( \WC_Product $product, array $conditions, object $feed ): bool {
        foreach ( $conditions as $cond ) {
            if ( ! self::evaluate_condition( $product, $cond, $feed ) ) {
                return false;
            }
        }
        return true;
    }

    private static function evaluate_condition( \WC_Product $product, object $cond, object $feed ): bool {
        $attribute = $cond->attribute ?? 'price';
        $op        = $cond->condition_op ?? 'lt';
        $expected  = (string) ( $cond->value ?? '' );
        $cs        = (bool) ( $cond->case_sensitive ?? false );

        switch ( $attribute ) {
            case 'price':
                $actual = (string) OtwFeed_Price_Calculator::get_price_float(
                    $product,
                    $feed->tax_mode,
                    $feed->country,
                    $feed->currency,
                    false
                );
                break;

            case 'stock_status':
                $actual = $product->get_stock_status();
                break;

            case 'sku':
                $actual = $product->get_sku();
                break;

            case 'name':
                $actual = $product->get_name();
                break;

            case 'category':
                $terms  = get_the_terms( $product->get_id(), 'product_cat' );
                $actual = is_array( $terms ) ? implode( ',', wp_list_pluck( $terms, 'name' ) ) : '';
                break;

            case 'tag':
                $terms  = get_the_terms( $product->get_id(), 'product_tag' );
                $actual = is_array( $terms ) ? implode( ',', wp_list_pluck( $terms, 'name' ) ) : '';
                break;

            default:
                $actual = (string) get_post_meta( $product->get_id(), $attribute, true );
                break;
        }

        return self::compare( $actual, $op, $expected, $cs );
    }

    private static function compare( mixed $actual, string $op, string $expected, bool $cs ): bool {
        if ( ! $cs ) {
            $actual   = strtolower( (string) $actual );
            $expected = strtolower( $expected );
        }

        switch ( $op ) {
            case 'eq':  return $actual === $expected;
            case 'neq': return $actual !== $expected;
            case 'lt':  return (float) $actual <  (float) $expected;
            case 'lte': return (float) $actual <= (float) $expected;
            case 'gt':  return (float) $actual >  (float) $expected;
            case 'gte': return (float) $actual >= (float) $expected;
            case 'contains':     return str_contains( $actual, $expected );
            case 'not_contains': return ! str_contains( $actual, $expected );
            case 'starts_with':  return str_starts_with( $actual, $expected );
            case 'ends_with':    return str_ends_with( $actual, $expected );
            case 'regex':
                return (bool) @preg_match( '/' . $expected . '/i', $actual );
        }

        return false;
    }

    // ── Legacy variation helpers (used by get_products()) ─────────────────────

    private static function expand_variable_products( array $products ): array {
        $expanded = array();

        foreach ( $products as $product ) {
            if ( $product->is_type( 'variable' ) ) {
                /** @var \WC_Product_Variable $product */
                foreach ( $product->get_children() as $variation_id ) {
                    $variation = wc_get_product( $variation_id );
                    if ( $variation && $variation->is_purchasable() && '' !== $variation->get_price() ) {
                        $expanded[] = $variation;
                    }
                }
            } else {
                $expanded[] = $product;
            }
        }

        return $expanded;
    }

    private static function expand_lowest_price_variation( array $products, object $feed ): array {
        $expanded = array();

        foreach ( $products as $product ) {
            if ( $product->is_type( 'variable' ) ) {
                $best_price = PHP_FLOAT_MAX;
                $best_var   = null;

                foreach ( $product->get_children() as $variation_id ) {
                    $variation = wc_get_product( $variation_id );
                    if ( ! $variation || ! $variation->is_purchasable() || '' === $variation->get_price() ) {
                        continue;
                    }
                    $price = OtwFeed_Price_Calculator::get_price_float(
                        $variation,
                        $feed->tax_mode,
                        $feed->country,
                        $feed->currency,
                        false
                    );
                    if ( $price < $best_price ) {
                        $best_price = $price;
                        $best_var   = $variation;
                    }
                }

                if ( $best_var ) {
                    $expanded[] = $best_var;
                }
            } else {
                $expanded[] = $product;
            }
        }

        return $expanded;
    }

    // ── Product attribute builder ──────────────────────────────────────────────

    public static function get_product_attributes( \WC_Product $product ): array {
        $parent_id    = $product->get_parent_id() ?: $product->get_id();
        $parent       = $parent_id !== $product->get_id() ? wc_get_product( $parent_id ) : $product;
        $image_id     = $product->get_image_id() ?: ( $parent ? $parent->get_image_id() : 0 );
        $image_url    = $image_id ? wp_get_attachment_url( $image_id ) : wc_placeholder_img_src();

        // Additional images.
        $gallery_ids  = $parent ? $parent->get_gallery_image_ids() : array();
        $extra_images = array_map( 'wp_get_attachment_url', array_slice( $gallery_ids, 0, 9 ) );

        // Category breadcrumb path — deepest category, ancestors first.
        $category_ids            = wc_get_product_term_ids( $parent_id, 'product_cat' );
        $product_type            = self::get_deepest_category_path( $category_ids );
        $google_product_category = self::get_top_level_category( $category_ids );

        // Brand (check common attribute names and meta).
        $brand = '';
        foreach ( array( 'brand', 'pa_brand', 'manufacturer', 'pa_manufacturer' ) as $key ) {
            $val = $product->get_attribute( $key );
            if ( ! $val ) {
                $val = (string) get_post_meta( $parent_id, $key, true );
            }
            if ( $val ) {
                $brand = $val;
                break;
            }
        }

        // Availability — Google requires underscores.
        $stock_status = $product->get_stock_status();
        if ( 'onbackorder' === $stock_status ) {
            $availability = 'preorder';
        } elseif ( 'instock' === $stock_status || 'in_stock' === $stock_status ) {
            $availability = 'in_stock';
        } else {
            $availability = 'out_of_stock';
        }

        // item_group_id: parent ID for variations, product ID for simple.
        $is_variation  = $product->is_type( 'variation' );
        $item_group_id = $is_variation ? (string) $parent_id : (string) $product->get_id();

        // checkout_link_template: direct add-to-cart URL, skipping cart.
        $checkout_link = add_query_arg( 'add-to-cart', $product->get_id(), wc_get_checkout_url() );

        // identifier_exists: 'yes' if GTIN or MPN present, otherwise 'no'.
        $has_gtin = (bool) get_post_meta( $product->get_id(), '_gtin', true );
        $has_mpn  = (bool) get_post_meta( $product->get_id(), '_mpn', true );
        $identifier_exists = ( $has_gtin || $has_mpn ) ? 'yes' : 'no';

        // Description: keep raw text (HTML tags stripped, whitespace collapsed).
        $raw_desc    = $product->get_description() ?: ( $parent ? $parent->get_description() : '' );
        $description = wp_strip_all_tags( $raw_desc );
        $description = preg_replace( '/\s+/', ' ', $description );
        $description = trim( $description );

        return array(
            'id'                     => (string) $product->get_id(),
            'name'                   => $product->get_name(),
            'description'            => $description,
            'permalink'              => get_permalink( $parent_id ),
            'image'                  => $image_url ?: '',
            'extra_images'           => $extra_images,
            'sku'                    => $product->get_sku(),
            'availability'           => $availability,
            'brand'                  => $brand,
            'product_type'           => $product_type,
            'google_product_category' => $google_product_category,
            'item_group_id'          => $item_group_id,
            'checkout_link'          => $checkout_link,
            'identifier_exists'      => $identifier_exists,
        );
    }

    private static function get_ancestors_cached( int $term_id ): array {
        if ( ! isset( self::$ancestors_cache[ $term_id ] ) ) {
            self::$ancestors_cache[ $term_id ] = get_ancestors( $term_id, 'product_cat', 'taxonomy' );
        }
        return self::$ancestors_cache[ $term_id ];
    }

    private static function get_top_level_category( array $term_ids ): string {
        if ( empty( $term_ids ) ) {
            return '';
        }

        $best_term_id = 0;
        $best_depth   = -1;

        foreach ( $term_ids as $term_id ) {
            $depth = count( self::get_ancestors_cached( $term_id ) );
            if ( $depth > $best_depth ) {
                $best_depth   = $depth;
                $best_term_id = $term_id;
            }
        }

        if ( ! $best_term_id ) {
            return '';
        }

        $ancestors = self::get_ancestors_cached( $best_term_id );
        $root_id   = ! empty( $ancestors ) ? end( $ancestors ) : $best_term_id;
        $term      = get_term( $root_id, 'product_cat' );

        return ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
    }

    /**
     * Finds the deepest (most specific) category from a set of term IDs
     * and returns its full ancestor path, e.g. "Home > Judaica > Candlesticks".
     *
     * @param int[] $term_ids
     */
    private static function get_deepest_category_path( array $term_ids ): string {
        if ( empty( $term_ids ) ) {
            return '';
        }

        $best_term_id = 0;
        $best_depth   = -1;

        foreach ( $term_ids as $term_id ) {
            $depth = count( self::get_ancestors_cached( $term_id ) );
            if ( $depth > $best_depth ) {
                $best_depth   = $depth;
                $best_term_id = $term_id;
            }
        }

        if ( ! $best_term_id ) {
            return '';
        }

        $ancestors   = array_reverse( self::get_ancestors_cached( $best_term_id ) );
        $ancestors[] = $best_term_id;

        $parts = array();
        foreach ( $ancestors as $ancestor_id ) {
            $term = get_term( $ancestor_id, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $parts[] = $term->name;
            }
        }

        return implode( ' > ', $parts );
    }
}
