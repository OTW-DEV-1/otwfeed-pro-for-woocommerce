<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles WooCommerce tax calculations per country feed configuration.
 */
class OtwFeed_Tax_Calculator {

    /**
     * @param \WC_Product $product
     * @param string      $tax_mode  'include' | 'exclude'
     * @param string      $country   2-letter country code, e.g. 'IT'
     */
    public static function get_price( \WC_Product $product, string $tax_mode, string $country ): float {
        $base_price = (float) ( $product->get_price() ?: 0 );

        if ( ! wc_tax_enabled() ) {
            return $base_price;
        }

        if ( 'include' === $tax_mode ) {
            return self::get_price_including_tax( $product, $base_price, $country );
        }

        return self::get_price_excluding_tax( $product, $base_price, $country );
    }

    /**
     * @param \WC_Product $product
     * @param string      $tax_mode
     * @param string      $country
     */
    public static function get_regular_price( \WC_Product $product, string $tax_mode, string $country ): float {
        $price = (float) ( $product->get_regular_price() ?: 0 );

        if ( ! wc_tax_enabled() || 0.0 === $price ) {
            return $price;
        }

        if ( 'include' === $tax_mode ) {
            return self::get_price_including_tax( $product, $price, $country );
        }

        return self::get_price_excluding_tax( $product, $price, $country );
    }

    private static function get_price_including_tax( \WC_Product $product, float $base_price, string $country ): float {
        $tax_rates = self::get_tax_rates_for_country( $product, $country );

        if ( empty( $tax_rates ) ) {
            // No tax configured for this country — return a tax-free price.
            // If the store stores prices inclusive of base-country tax, strip it first.
            if ( wc_prices_include_tax() ) {
                return (float) wc_get_price_excluding_tax( $product, array( 'price' => $base_price, 'qty' => 1 ) );
            }
            // Store prices are already ex-tax; nothing to add.
            return $base_price;
        }

        $tax_amount = \WC_Tax::calc_tax( $base_price, $tax_rates, wc_prices_include_tax() );
        $total      = $base_price + array_sum( $tax_amount );

        return (float) $total;
    }

    private static function get_price_excluding_tax( \WC_Product $product, float $base_price, string $country ): float {
        $tax_rates = self::get_tax_rates_for_country( $product, $country );

        if ( empty( $tax_rates ) ) {
            return wc_get_price_excluding_tax( $product, array( 'price' => $base_price, 'qty' => 1 ) );
        }

        if ( wc_prices_include_tax() ) {
            $tax_amount = \WC_Tax::calc_tax( $base_price, $tax_rates, true );
            return (float) ( $base_price - array_sum( $tax_amount ) );
        }

        return $base_price;
    }

    private static function get_tax_rates_for_country( \WC_Product $product, string $country ): array {
        $tax_class = $product->get_tax_class();

        $args = array(
            'country'   => $country,
            'state'     => '',
            'postcode'  => '',
            'city'      => '',
            'tax_class' => $tax_class,
        );

        return \WC_Tax::find_rates( $args );
    }

    public static function get_tax_rate_percentage( string $country, string $tax_class = '' ): float {
        $args = array(
            'country'   => $country,
            'state'     => '',
            'postcode'  => '',
            'city'      => '',
            'tax_class' => $tax_class,
        );

        $rates = \WC_Tax::find_rates( $args );
        if ( empty( $rates ) ) {
            return 0.0;
        }

        return (float) array_sum( array_column( $rates, 'rate' ) );
    }
}
