<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$feeds       = OtwFeed_DB_Feeds::get_all( array( 'limit' => 100 ) );
$total       = OtwFeed_DB_Feeds::count();
$active      = OtwFeed_DB_Feeds::count( array( 'status' => 'active' ) );
$integration = OtwFeed_Currency_Manager::detect();

// Collect any feeds whose background generation is currently in progress.
$active_generations = array();
foreach ( $feeds as $f ) {
    $p = OtwFeed_Background_Generator::get_progress( $f->id );
    if ( in_array( $p['status'], array( 'queued', 'running' ), true ) ) {
        $active_generations[ $f->id ] = $p;
    }
}
?>
<?php if ( ! empty( $active_generations ) ) : ?>
<script>window.otwfeedActiveGenerations = <?php echo wp_json_encode( $active_generations ); ?>;</script>
<?php endif; ?>
<div class="otwfeed-wrap wrap">

    <div class="otwfeed-header">
        <div class="otwfeed-header__logo">
            <span class="dashicons dashicons-rss" aria-hidden="true"></span>
            <h1><?php esc_html_e( 'OtwFeed Pro', 'otwfeed-pro' ); ?></h1>
        </div>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=otwfeed-new' ) ); ?>"
           class="btn btn-primary btn-sm">
            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
            <?php esc_html_e( 'New Feed', 'otwfeed-pro' ); ?>
        </a>
    </div>

    <!-- Stats row -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-sm-6">
            <div class="card otwfeed-stat-card">
                <div class="card-body">
                    <p class="otwfeed-stat__label"><?php esc_html_e( 'Total Feeds', 'otwfeed-pro' ); ?></p>
                    <p class="otwfeed-stat__value"><?php echo esc_html( $total ); ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card otwfeed-stat-card">
                <div class="card-body">
                    <p class="otwfeed-stat__label"><?php esc_html_e( 'Active Feeds', 'otwfeed-pro' ); ?></p>
                    <p class="otwfeed-stat__value otwfeed-stat__value--accent"><?php echo esc_html( $active ); ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card otwfeed-stat-card">
                <div class="card-body">
                    <p class="otwfeed-stat__label"><?php esc_html_e( 'Currency Plugin', 'otwfeed-pro' ); ?></p>
                    <p class="otwfeed-stat__value otwfeed-integration-badge otwfeed-integration-badge--<?php echo esc_attr( $integration ); ?>">
                        <?php echo esc_html( strtoupper( $integration ) ); ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card otwfeed-stat-card">
                <div class="card-body">
                    <p class="otwfeed-stat__label"><?php esc_html_e( 'WC Tax', 'otwfeed-pro' ); ?></p>
                    <p class="otwfeed-stat__value">
                        <?php echo wc_tax_enabled()
                            ? '<span class="badge bg-success">' . esc_html__( 'Enabled', 'otwfeed-pro' ) . '</span>'
                            : '<span class="badge bg-secondary">' . esc_html__( 'Disabled', 'otwfeed-pro' ) . '</span>';
                        ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Feeds table -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title mb-0"><?php esc_html_e( 'Product Feeds', 'otwfeed-pro' ); ?></h2>
        </div>
        <div class="card-body p-0">
            <?php if ( empty( $feeds ) ) : ?>
                <div class="otwfeed-empty">
                    <span class="dashicons dashicons-rss" aria-hidden="true"></span>
                    <p><?php esc_html_e( 'No feeds yet. Create your first feed to get started.', 'otwfeed-pro' ); ?></p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=otwfeed-new' ) ); ?>" class="btn btn-primary">
                        <?php esc_html_e( 'Create Feed', 'otwfeed-pro' ); ?>
                    </a>
                </div>
            <?php else : ?>
                <div class="table-responsive">
                    <table class="table otwfeed-table mb-0" aria-label="<?php esc_attr_e( 'Product Feeds', 'otwfeed-pro' ); ?>">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e( 'Title', 'otwfeed-pro' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Channel', 'otwfeed-pro' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Country', 'otwfeed-pro' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Currency', 'otwfeed-pro' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Tax Mode', 'otwfeed-pro' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Status', 'otwfeed-pro' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Last Generated', 'otwfeed-pro' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Products', 'otwfeed-pro' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Actions', 'otwfeed-pro' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $feeds as $feed ) : ?>
                                <tr data-feed-id="<?php echo esc_attr( $feed->id ); ?>">
                                    <td class="fw-semibold"><?php echo esc_html( $feed->title ); ?></td>
                                    <td>
                                        <span class="badge otwfeed-channel-badge otwfeed-channel-badge--<?php echo esc_attr( $feed->channel ); ?>">
                                            <?php echo esc_html( ucfirst( $feed->channel ) ); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html( $feed->country ); ?></td>
                                    <td><?php echo esc_html( $feed->currency ); ?></td>
                                    <td><?php echo esc_html( $feed->tax_mode ); ?></td>
                                    <td>
                                        <?php if ( 'active' === $feed->status ) : ?>
                                            <span class="badge bg-success"><?php esc_html_e( 'Active', 'otwfeed-pro' ); ?></span>
                                        <?php else : ?>
                                            <span class="badge bg-secondary"><?php esc_html_e( 'Inactive', 'otwfeed-pro' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted small">
                                        <?php echo $feed->last_gen
                                            ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $feed->last_gen ) ) )
                                            : esc_html__( 'Never', 'otwfeed-pro' );
                                        ?>
                                    </td>
                                    <td class="text-muted small otwfeed-product-count">
                                        <?php echo ! empty( $feed->product_count )
                                            ? esc_html( number_format_i18n( (int) $feed->product_count ) )
                                            : '—';
                                        ?>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1 flex-wrap">
                                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=otwfeed-new&feed_id=' . $feed->id ) ); ?>"
                                               class="btn btn-xs btn-outline-primary"
                                               aria-label="<?php esc_attr_e( 'Edit feed', 'otwfeed-pro' ); ?>">
                                                <?php esc_html_e( 'Edit', 'otwfeed-pro' ); ?>
                                            </a>
                                            <button type="button"
                                                    class="btn btn-xs btn-outline-accent otwfeed-btn-generate"
                                                    data-id="<?php echo esc_attr( $feed->id ); ?>"
                                                    aria-label="<?php esc_attr_e( 'Generate feed', 'otwfeed-pro' ); ?>">
                                                <?php esc_html_e( 'Generate', 'otwfeed-pro' ); ?>
                                            </button>
                                            <?php if ( ! empty( $feed->token ) ) : ?>
                                                <a href="<?php echo esc_url( OtwFeed_Feed_Generator::get_feed_url( $feed->token ) ); ?>"
                                                   target="_blank"
                                                   rel="noopener noreferrer"
                                                   class="btn btn-xs btn-outline-secondary"
                                                   aria-label="<?php esc_attr_e( 'View feed URL', 'otwfeed-pro' ); ?>">
                                                    <?php esc_html_e( 'URL', 'otwfeed-pro' ); ?>
                                                </a>
                                            <?php endif; ?>
                                            <button type="button"
                                                    class="btn btn-xs btn-outline-danger otwfeed-btn-delete"
                                                    data-id="<?php echo esc_attr( $feed->id ); ?>"
                                                    aria-label="<?php esc_attr_e( 'Delete feed', 'otwfeed-pro' ); ?>">
                                                <?php esc_html_e( 'Delete', 'otwfeed-pro' ); ?>
                                            </button>
                                        </div>
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
