<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$feeds       = OtwFeed_DB_Feeds::get_all( array( 'limit' => 100 ) );
$integration = OtwFeed_Currency_Manager::detect();
$currencies  = OtwFeed_Currency_Manager::get_currencies();
$wc_currency = get_woocommerce_currency();
$countries   = WC()->countries->get_countries();
$tax_enabled = wc_tax_enabled();
?>
<div class="otwfeed-wrap wrap">

    <div class="otwfeed-header">
        <div class="otwfeed-header__logo">
            <span class="dashicons dashicons-money-alt" aria-hidden="true"></span>
            <h1><?php esc_html_e( 'Tax & Currency Rules Engine', 'otwfeed-pro' ); ?></h1>
        </div>
    </div>

    <!-- Integration status -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card otwfeed-integration-card">
                <div class="card-body">
                    <h3 class="otwfeed-section-label"><?php esc_html_e( 'WooCommerce Tax', 'otwfeed-pro' ); ?></h3>
                    <div class="d-flex align-items-center gap-2 mt-2">
                        <span class="otwfeed-status-dot otwfeed-status-dot--<?php echo $tax_enabled ? 'on' : 'off'; ?>" aria-hidden="true"></span>
                        <span><?php echo $tax_enabled ? esc_html__( 'Enabled', 'otwfeed-pro' ) : esc_html__( 'Disabled', 'otwfeed-pro' ); ?></span>
                    </div>
                    <?php if ( $tax_enabled ) :
                        $mode = get_option( 'woocommerce_prices_include_tax' );
                    ?>
                    <p class="text-muted small mt-1 mb-0">
                        <?php echo 'yes' === $mode
                            ? esc_html__( 'Base prices include tax', 'otwfeed-pro' )
                            : esc_html__( 'Base prices exclude tax', 'otwfeed-pro' );
                        ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card otwfeed-integration-card">
                <div class="card-body">
                    <h3 class="otwfeed-section-label"><?php esc_html_e( 'Currency Plugin', 'otwfeed-pro' ); ?></h3>
                    <div class="d-flex align-items-center gap-2 mt-2">
                        <span class="otwfeed-status-dot otwfeed-status-dot--<?php echo in_array( $integration, array( 'curcy', 'fox' ), true ) ? 'on' : 'warn'; ?>" aria-hidden="true"></span>
                        <span class="otwfeed-integration-badge otwfeed-integration-badge--<?php echo esc_attr( $integration ); ?>">
                            <?php echo esc_html( strtoupper( $integration ) ); ?>
                        </span>
                    </div>
                    <?php if ( 'woocommerce' === $integration ) : ?>
                        <p class="text-muted small mt-1 mb-0"><?php esc_html_e( 'No multi-currency switcher detected.', 'otwfeed-pro' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card otwfeed-integration-card">
                <div class="card-body">
                    <h3 class="otwfeed-section-label"><?php esc_html_e( 'Base Currency', 'otwfeed-pro' ); ?></h3>
                    <div class="d-flex align-items-center gap-2 mt-2">
                        <span class="otwfeed-currency-code"><?php echo esc_html( $wc_currency ); ?></span>
                        <span class="text-muted"><?php echo esc_html( get_woocommerce_currency_symbol( $wc_currency ) ); ?></span>
                    </div>
                    <p class="text-muted small mt-1 mb-0"><?php esc_html_e( 'WooCommerce base currency.', 'otwfeed-pro' ); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Available currencies -->
    <?php if ( count( $currencies ) > 1 ) : ?>
        <div class="card mb-4">
            <div class="card-header"><h2 class="card-title mb-0"><?php esc_html_e( 'Available Currencies', 'otwfeed-pro' ); ?></h2></div>
            <div class="card-body">
                <div class="otwfeed-currency-grid">
                    <?php foreach ( $currencies as $code => $symbol ) :
                        $rate = OtwFeed_Currency_Manager::get_rate( $code );
                    ?>
                        <div class="otwfeed-currency-item <?php echo $code === $wc_currency ? 'otwfeed-currency-item--base' : ''; ?>">
                            <span class="otwfeed-currency-item__code"><?php echo esc_html( $code ); ?></span>
                            <span class="otwfeed-currency-item__symbol"><?php echo esc_html( $symbol ); ?></span>
                            <span class="otwfeed-currency-item__rate">
                                <?php echo $code === $wc_currency
                                    ? esc_html__( 'Base', 'otwfeed-pro' )
                                    : '1 ' . esc_html( $wc_currency ) . ' = ' . esc_html( number_format( $rate, 4 ) ) . ' ' . esc_html( $code );
                                ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Per-feed tax preview -->
    <div class="card">
        <div class="card-header"><h2 class="card-title mb-0"><?php esc_html_e( 'Feed Tax Configuration', 'otwfeed-pro' ); ?></h2></div>
        <div class="card-body p-0">
            <?php if ( empty( $feeds ) ) : ?>
                <div class="otwfeed-empty">
                    <p><?php esc_html_e( 'No feeds configured yet.', 'otwfeed-pro' ); ?></p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=otwfeed-new' ) ); ?>" class="btn btn-primary"><?php esc_html_e( 'Create Feed', 'otwfeed-pro' ); ?></a>
                </div>
            <?php else : ?>
                <div class="table-responsive">
                    <table class="table otwfeed-table mb-0">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Feed', 'otwfeed-pro' ); ?></th>
                                <th><?php esc_html_e( 'Country', 'otwfeed-pro' ); ?></th>
                                <th><?php esc_html_e( 'Currency', 'otwfeed-pro' ); ?></th>
                                <th><?php esc_html_e( 'Tax Mode', 'otwfeed-pro' ); ?></th>
                                <th><?php esc_html_e( 'Est. VAT Rate', 'otwfeed-pro' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'otwfeed-pro' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $feeds as $f ) :
                                $vat_rate = $tax_enabled ? OtwFeed_Tax_Calculator::get_tax_rate_percentage( $f->country ) : 0;
                            ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo esc_html( $f->title ); ?></td>
                                    <td><?php echo esc_html( $countries[ $f->country ] ?? $f->country ); ?></td>
                                    <td><?php echo esc_html( $f->currency ); ?></td>
                                    <td>
                                        <span class="badge <?php echo 'include' === $f->tax_mode ? 'bg-info' : 'bg-secondary'; ?>">
                                            <?php echo esc_html( $f->tax_mode ); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ( $tax_enabled && $vat_rate > 0 ) : ?>
                                            <span class="text-accent fw-semibold"><?php echo esc_html( $vat_rate . '%' ); ?></span>
                                        <?php else : ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=otwfeed-new&feed_id=' . $f->id ) ); ?>"
                                           class="btn btn-xs btn-outline-primary">
                                            <?php esc_html_e( 'Edit', 'otwfeed-pro' ); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>
