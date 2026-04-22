<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds a WC_Product_Query from feed filters and returns WC_Product objects.
 */
class OtwFeed_Product_Query {

    /**
     * @param object   $feed    Feed row from DB.
     * @param object[] $filters Filter rows from DB (new group-based schema).
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

        // Expand variable products based on feed setting.
        $ev = (int) ( $feed->expand_variations ?? 1 );
        if ( 1 === $ev ) {
            $products = self::expand_variable_products( $products );
        } elseif ( 2 === $ev ) {
            $products = self::expand_lowest_price_variation( $products, $feed );
        }
        // 0 = keep parent products only, no expansion.

        if ( empty( $filters ) ) {
            return array_values( $products );
        }

        // Group filters by group_id.
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

        // Include groups: only products matching at least one include group are kept.
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
            $products = $kept;
        }

        // Exclude groups: remove products matching any exclude group.
        foreach ( $exclude_groups as $group ) {
            $products = array_filter(
                $products,
                static fn( $p ) => ! self::product_matches_group( $p, $group['conditions'], $feed )
            );
        }

        return array_values( $products );
    }

    private static function product_matches_group( \WC_Product $product, array $conditions, object $feed ): bool {
        foreach ( $conditions as $cond ) {
            if ( ! self::evaluate_condition( $product, $cond, $feed ) ) {
                return false; // AND logic: one false breaks the group.
            }
        }
        return true;
    }

    private static function evaluate_condition( \WC_Product $product, object $cond, object $feed ): bool {
        $attr = $cond->attribute;
        $op   = $cond->condition_op;
        $cs   = (bool) ( $cond->case_sensitive ?? false );

        // For variations, use parent for taxonomy lookups.
        $lookup_id = $product->get_parent_id() ?: $product->get_id();

        switch ( $attr ) {
            case 'price':
                $actual = OtwFeed_Price_Calculator::get_price_float(
                    $product,
                    $feed->tax_mode,
                    $feed->country,
                    $feed->currency
                );
                break;
            case 'regular_price':
                $actual = (float) ( $product->get_regular_price() ?: 0 );
                break;
            case 'sale_price':
                $actual = (float) ( $product->get_sale_price() ?: 0 );
                break;
            case 'sku':
                $actual = $product->get_sku();
                break;
            case 'title':
                $actual = $product->get_name();
                break;
            case 'description':
                $actual = wp_strip_all_tags( $product->get_description() );
                break;
            case 'product_id':
                $actual = (float) $product->get_id();
                break;
            case 'stock_status':
                $actual = $product->get_stock_status();
                break;
            case 'stock_quantity':
                $actual = (float) ( $product->get_stock_quantity() ?? 0 );
                break;
            case 'product_type':
                $actual = $product->get_type();
                break;
            case 'category':
                $terms  = wp_get_post_terms( $lookup_id, 'product_cat', array( 'fields' => 'names' ) );
                $actual = is_wp_error( $terms ) ? '' : implode( ', ', $terms );
                break;
            case 'tag':
                $terms  = wp_get_post_terms( $lookup_id, 'product_tag', array( 'fields' => 'names' ) );
                $actual = is_wp_error( $terms ) ? '' : implode( ', ', $terms );
                break;
            case 'weight':
                $actual = (float) ( $product->get_weight() ?: 0 );
                break;
            default:
                if ( str_starts_with( $attr, 'meta:' ) ) {
                    $actual = (string) get_post_meta( $product->get_id(), substr( $attr, 5 ), true );
                } else {
                    return true; // Unknown attribute → skip (don't filter).
                }
        }

        return self::compare( $actual, $op, $cond->value, $cs );
    }

    private static function compare( mixed $actual, string $op, string $expected, bool $cs ): bool {
        // Numeric comparisons.
        if ( in_array( $op, array( 'gt', 'lt', 'gte', 'lte' ), true ) ) {
            $a = (float) $actual;
            $e = (float) $expected;
            return match ( $op ) {
                'gt'  => $a > $e,
                'lt'  => $a < $e,
                'gte' => $a >= $e,
                'lte' => $a <= $e,
            };
        }

        // Empty checks (value-independent).
        if ( 'is_empty' === $op )     return '' === (string) $actual;
        if ( 'is_not_empty' === $op ) return '' !== (string) $actual;

        // String comparisons.
        $a = (string) $actual;
        $e = (string) $expected;
        if ( ! $cs ) {
            $a = mb_strtolower( $a );
            $e = mb_strtolower( $e );
        }

        return match ( $op ) {
            'equals'       => $a === $e,
            'not_equals'   => $a !== $e,
            'contains'     => str_contains( $a, $e ),
            'not_contains' => ! str_contains( $a, $e ),
            default        => true,
        };
    }

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
                        $feed->currency
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

    public static function get_product_attributes( \WC_Product $product ): array {
        $parent_id    = $product->get_parent_id() ?: $product->get_id();
        $parent       = $parent_id !== $product->get_id() ? wc_get_product( $parent_id ) : $product;
        $image_id     = $product->get_image_id() ?: ( $parent ? $parent->get_image_id() : 0 );
        $image_url    = $image_id ? wp_get_attachment_url( $image_id ) : wc_placeholder_img_src();

        // Additional images.
        $gallery_ids  = $parent ? $parent->get_gallery_image_ids() : array();
        $extra_images = array_map( 'wp_get_attachment_url', array_slice( $gallery_ids, 0, 9 ) );

        // Category breadcrumb path — deepest category, ancestors first.
        $category_ids          = wc_get_product_term_ids( $parent_id, 'product_cat' );
        $product_type          = self::get_deepest_category_path( $category_ids );
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
            'id'                => (string) $product->get_id(),
            'name'              => $product->get_name(),
            'description'       => $description,
            'permalink'         => get_permalink( $parent_id ),
            'image'             => $image_url ?: '',
            'extra_images'      => $extra_images,
            'sku'               => $product->get_sku(),
            'availability'      => $availability,
            'brand'             => $brand,
            'product_type'           => $product_type,
            'google_product_category' => $google_product_category,
            'item_group_id'     => $item_group_id,
            'checkout_link'     => $checkout_link,
            'identifier_exists' => $identifier_exists,
        );
    }

    /**
     * Finds the deepest (most specific) category from a set of term IDs
     * and returns its full ancestor path, e.g. "Home > Judaica > Candlesticks".
     *
     * @param int[] $term_ids
     */
    private static function get_top_level_category( array $term_ids ): string {
        if ( empty( $term_ids ) ) {
            return '';
        }

        // Pick the deepest assigned category, then walk up to its root ancestor.
        $best_term_id = 0;
        $best_depth   = -1;

        foreach ( $term_ids as $term_id ) {
            $depth = count( get_ancestors( $term_id, 'product_cat', 'taxonomy' ) );
            if ( $depth > $best_depth ) {
                $best_depth   = $depth;
                $best_term_id = $term_id;
            }
        }

        if ( ! $best_term_id ) {
            return '';
        }

        $ancestors = get_ancestors( $best_term_id, 'product_cat', 'taxonomy' );
        $root_id   = ! empty( $ancestors ) ? end( $ancestors ) : $best_term_id;
        $term      = get_term( $root_id, 'product_cat' );

        return ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
    }

    private static function get_deepest_category_path( array $term_ids ): string {
        if ( empty( $term_ids ) ) {
            return '';
        }

        $best_term_id = 0;
        $best_depth   = -1;

        foreach ( $term_ids as $term_id ) {
            $ancestors = get_ancestors( $term_id, 'product_cat', 'taxonomy' );
            $depth     = count( $ancestors );
            if ( $depth > $best_depth ) {
                $best_depth   = $depth;
                $best_term_id = $term_id;
            }
        }

        if ( ! $best_term_id ) {
            return '';
        }

        $ancestors   = array_reverse( get_ancestors( $best_term_id, 'product_cat', 'taxonomy' ) );
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
