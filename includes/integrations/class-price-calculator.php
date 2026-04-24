<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Combines currency conversion and tax calculation to produce final feed prices.
 */
class OtwFeed_Price_Calculator {

    /**
     * Returns the final price string for a product as it should appear in the feed.
     *
     * @param \WC_Product $product
     * @param string      $tax_mode  'include' | 'exclude'
     * @param string      $country   e.g. 'IT'
     * @param string      $currency  e.g. 'EUR'
     * @param bool        $round     Round to nearest integer before formatting.
     */
    public static function get_price( \WC_Product $product, string $tax_mode, string $country, string $currency, bool $round = false ): string {
        $price = OtwFeed_Tax_Calculator::get_price( $product, $tax_mode, $country );
        $price = OtwFeed_Currency_Manager::convert( $price, $currency );
        if ( $round ) {
            $price = (float) round( $price );
        }
        return OtwFeed_Currency_Manager::format_price( $price, $currency );
    }

    public static function get_regular_price( \WC_Product $product, string $tax_mode, string $country, string $currency, bool $round = false ): string {
        $price = OtwFeed_Tax_Calculator::get_regular_price( $product, $tax_mode, $country );
        $price = OtwFeed_Currency_Manager::convert( $price, $currency );
        if ( $round ) {
            $price = (float) round( $price );
        }
        return OtwFeed_Currency_Manager::format_price( $price, $currency );
    }

    /**
     * Returns both price and regular price (when genuinely on sale), for feed output.
     * 'regular' is only populated when regular > price — guards against variable
     * products whose _min_variation_regular_price meta is 0 or missing.
     */
    public static function get_price_pair( \WC_Product $product, string $tax_mode, string $country, string $currency, bool $round = false ): array {
        $price_float = OtwFeed_Tax_Calculator::get_price( $product, $tax_mode, $country );
        $price_float = OtwFeed_Currency_Manager::convert( $price_float, $currency );

        $regular = '';

        if ( $product->is_on_sale() ) {
            $reg_float = OtwFeed_Tax_Calculator::get_regular_price( $product, $tax_mode, $country );
            $reg_float = OtwFeed_Currency_Manager::convert( $reg_float, $currency );

            // Only treat as on-sale when the regular price is genuinely above the
            // current price (avoids 0.00 regular from empty _min_variation_regular_price).
            if ( $reg_float > $price_float ) {
                if ( $round ) {
                    $reg_float = (float) round( $reg_float );
                }
                $regular = OtwFeed_Currency_Manager::format_price( $reg_float, $currency );
            }
        }

        if ( $round ) {
            $price_float = (float) round( $price_float );
        }

        return array(
            'price'   => OtwFeed_Currency_Manager::format_price( $price_float, $currency ),
            'regular' => $regular,
        );
    }

    /**
     * Returns the price as a float (without formatting) for filtering purposes.
     */
    public static function get_price_float( \WC_Product $product, string $tax_mode, string $country, string $currency ): float {
        $price = OtwFeed_Tax_Calculator::get_price( $product, $tax_mode, $country );
        return OtwFeed_Currency_Manager::convert( $price, $currency );
    }
}
