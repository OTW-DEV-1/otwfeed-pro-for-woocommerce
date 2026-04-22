<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detects and integrates with CURCY (WooCommerce Multi Currency) and FOX Currency Switcher.
 * Falls back to WooCommerce's default currency when no switcher is active.
 */
class OtwFeed_Currency_Manager {

    // Detected plugin: 'curcy' | 'fox' | 'woocommerce' | null
    private static ?string $active_plugin = null;

    public static function detect(): string {
        if ( null !== self::$active_plugin ) {
            return self::$active_plugin;
        }

        // CURCY – WooCommerce Multi Currency (VillaTheme): class/constant introduced in v2.x.
        if ( class_exists( 'WOOMULTI_CURRENCY' ) || defined( 'WOOMULTI_CURRENCY_FILE' ) ) {
            self::$active_plugin = 'curcy';
        } elseif ( class_exists( 'WOOMC\App' ) || defined( 'WOOMC_PLUGIN_FILE' ) ) {
            // Older CURCY namespace kept for backwards compatibility.
            self::$active_plugin = 'curcy';
        } elseif ( class_exists( 'FOX_plugin' ) || defined( 'FOX_CURCY_VERSION' ) ) {
            self::$active_plugin = 'fox';
        } elseif ( class_exists( 'WooCommerce' ) ) {
            self::$active_plugin = 'woocommerce';
        } else {
            self::$active_plugin = 'none';
        }

        return self::$active_plugin;
    }

    /**
     * Returns the available currencies as array of code => name.
     */
    public static function get_currencies(): array {
        $plugin = self::detect();

        if ( 'curcy' === $plugin ) {
            return self::get_curcy_currencies();
        }
        if ( 'fox' === $plugin ) {
            return self::get_fox_currencies();
        }

        $code = get_woocommerce_currency();
        return array( $code => get_woocommerce_currency_symbol( $code ) );
    }

    /**
     * Convert an amount from the WooCommerce base currency to $target_currency.
     */
    public static function convert( float $amount, string $target_currency ): float {
        $base = get_woocommerce_currency();

        if ( $base === $target_currency ) {
            return $amount;
        }

        $plugin = self::detect();

        if ( 'curcy' === $plugin ) {
            return self::curcy_convert( $amount, $target_currency );
        }
        if ( 'fox' === $plugin ) {
            return self::fox_convert( $amount, $target_currency );
        }

        return $amount;
    }

    public static function get_rate( string $target_currency ): float {
        $base = get_woocommerce_currency();
        if ( $base === $target_currency ) {
            return 1.0;
        }

        $plugin = self::detect();

        if ( 'curcy' === $plugin ) {
            return self::curcy_get_rate( $target_currency );
        }
        if ( 'fox' === $plugin ) {
            return self::fox_get_rate( $target_currency );
        }

        return 1.0;
    }

    // ── CURCY helpers ─────────────────────────────────────────────────────────

    private static function get_curcy_currencies(): array {
        $params = get_option( 'woo_multi_currency_params', array() );
        $codes  = (array) ( $params['currency'] ?? array() );

        $currencies = array();
        foreach ( $codes as $code ) {
            $currencies[ $code ] = get_woocommerce_currency_symbol( $code );
        }
        return $currencies ?: array( get_woocommerce_currency() => get_woocommerce_currency_symbol() );
    }

    private static function curcy_convert( float $amount, string $target_currency ): float {
        $rate = self::curcy_get_rate( $target_currency );
        return $amount * $rate;
    }

    private static function curcy_get_rate( string $target_currency ): float {
        $params = get_option( 'woo_multi_currency_params', array() );
        $codes  = (array) ( $params['currency']              ?? array() );
        $rates  = (array) ( $params['currency_rate']         ?? array() );
        $fees   = (array) ( $params['currency_rate_fee']     ?? array() );
        $ftypes = (array) ( $params['currency_rate_fee_type'] ?? array() );

        $idx = array_search( $target_currency, $codes, true );
        if ( false === $idx ) {
            return 1.0;
        }

        $rate    = (float) ( $rates[ $idx ] ?? 1 );
        $fee_raw = (float) ( $fees[ $idx ]  ?? 0 );

        if ( $fee_raw ) {
            $rate += 'percentage' === ( $ftypes[ $idx ] ?? '' )
                ? $fee_raw * $rate / 100
                : $fee_raw;
        }

        return $rate ?: 1.0;
    }

    // ── FOX helpers ───────────────────────────────────────────────────────────

    private static function get_fox_currencies(): array {
        $currencies = array();
        $fox_data   = get_option( 'fox_currencies', array() );
        foreach ( (array) $fox_data as $code => $data ) {
            $currencies[ $code ] = get_woocommerce_currency_symbol( $code );
        }
        return $currencies ?: array( get_woocommerce_currency() => get_woocommerce_currency_symbol() );
    }

    private static function fox_convert( float $amount, string $target_currency ): float {
        $rate = self::fox_get_rate( $target_currency );
        return $amount * $rate;
    }

    private static function fox_get_rate( string $target_currency ): float {
        $fox_data = get_option( 'fox_currencies', array() );
        return isset( $fox_data[ $target_currency ]['rate'] )
            ? (float) $fox_data[ $target_currency ]['rate']
            : 1.0;
    }

    public static function format_price( float $amount, string $currency ): string {
        return number_format( $amount, 2, '.', '' ) . ' ' . $currency;
    }

    /**
     * Appends the currency switcher query parameter to a URL when the feed
     * currency differs from the WooCommerce base currency.
     *
     * CURCY: ?wmc-currency=ILS
     * FOX:   ?currency=ILS
     * WooCommerce only / same currency: URL returned unchanged.
     */
    public static function get_currency_url( string $url, string $currency ): string {
        $base = get_woocommerce_currency();
        if ( $base === $currency ) {
            return $url;
        }

        $plugin = self::detect();

        if ( 'curcy' === $plugin ) {
            return add_query_arg( 'wmc-currency', $currency, $url );
        }
        if ( 'fox' === $plugin ) {
            return add_query_arg( 'currency', $currency, $url );
        }

        return $url;
    }
}
