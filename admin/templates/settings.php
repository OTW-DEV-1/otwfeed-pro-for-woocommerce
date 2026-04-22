<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Handle settings form save.
if ( isset( $_POST['otwfeed_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['otwfeed_settings_nonce'] ) ), 'otwfeed_settings' ) ) {
    if ( current_user_can( 'manage_woocommerce' ) ) {
        update_option( 'otwfeed_auto_regen_interval', absint( $_POST['auto_regen_interval'] ?? 0 ) );
        update_option( 'otwfeed_log_retention_days', absint( $_POST['log_retention_days'] ?? 30 ) );
        wp_safe_redirect( add_query_arg( 'saved', '1', wp_get_referer() ?: admin_url( 'admin.php?page=otwfeed-settings' ) ) );
        exit;
    }
}

$auto_regen     = (int) get_option( 'otwfeed_auto_regen_interval', 0 );
$log_days       = (int) get_option( 'otwfeed_log_retention_days', 30 );
$integration    = OtwFeed_Currency_Manager::detect();
?>
<div class="otwfeed-wrap wrap">

    <div class="otwfeed-header">
        <div class="otwfeed-header__logo">
            <span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>
            <h1><?php esc_html_e( 'Global Settings', 'otwfeed-pro' ); ?></h1>
        </div>
    </div>

    <?php if ( ! empty( $_GET['saved'] ) ) : ?>
        <div class="alert alert-success" role="alert"><?php esc_html_e( 'Settings saved.', 'otwfeed-pro' ); ?></div>
    <?php endif; ?>

    <form method="post" action="">
        <?php wp_nonce_field( 'otwfeed_settings', 'otwfeed_settings_nonce' ); ?>

        <!-- General -->
        <div class="card mb-3">
            <div class="card-header"><h2 class="card-title mb-0"><?php esc_html_e( 'General', 'otwfeed-pro' ); ?></h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="otwfeed-auto-regen" class="form-label"><?php esc_html_e( 'Auto-regenerate interval', 'otwfeed-pro' ); ?></label>
                        <select id="otwfeed-auto-regen" name="auto_regen_interval" class="form-select otwfeed-select2">
                            <option value="0"     <?php selected( $auto_regen, 0 ); ?>><?php esc_html_e( 'Disabled', 'otwfeed-pro' ); ?></option>
                            <option value="3600"  <?php selected( $auto_regen, 3600 ); ?>><?php esc_html_e( 'Every hour', 'otwfeed-pro' ); ?></option>
                            <option value="21600" <?php selected( $auto_regen, 21600 ); ?>><?php esc_html_e( 'Every 6 hours', 'otwfeed-pro' ); ?></option>
                            <option value="43200" <?php selected( $auto_regen, 43200 ); ?>><?php esc_html_e( 'Every 12 hours', 'otwfeed-pro' ); ?></option>
                            <option value="86400" <?php selected( $auto_regen, 86400 ); ?>><?php esc_html_e( 'Daily', 'otwfeed-pro' ); ?></option>
                        </select>
                        <p class="form-text text-muted"><?php esc_html_e( 'Automatically regenerate active feeds on a schedule.', 'otwfeed-pro' ); ?></p>
                    </div>
                    <div class="col-md-4">
                        <label for="otwfeed-log-days" class="form-label"><?php esc_html_e( 'Log retention (days)', 'otwfeed-pro' ); ?></label>
                        <input type="number" id="otwfeed-log-days" name="log_retention_days" class="form-control"
                               min="1" max="365" value="<?php echo esc_attr( $log_days ); ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Integration status -->
        <div class="card mb-3">
            <div class="card-header"><h2 class="card-title mb-0"><?php esc_html_e( 'Integration Status', 'otwfeed-pro' ); ?></h2></div>
            <div class="card-body">
                <div class="otwfeed-integration-status">
                    <?php
                    $checks = array(
                        array(
                            'label'  => 'WooCommerce',
                            'active' => class_exists( 'WooCommerce' ),
                        ),
                        array(
                            'label'  => 'CURCY (Multi Currency)',
                            'active' => 'curcy' === $integration,
                        ),
                        array(
                            'label'  => 'FOX Currency Switcher',
                            'active' => 'fox' === $integration,
                        ),
                        array(
                            'label'  => 'WC Tax',
                            'active' => wc_tax_enabled(),
                        ),
                    );
                    foreach ( $checks as $check ) : ?>
                        <div class="otwfeed-integration-row">
                            <span class="otwfeed-status-dot otwfeed-status-dot--<?php echo $check['active'] ? 'on' : 'off'; ?>" aria-hidden="true"></span>
                            <span><?php echo esc_html( $check['label'] ); ?></span>
                            <span class="ms-auto text-muted small">
                                <?php echo $check['active'] ? esc_html__( 'Active', 'otwfeed-pro' ) : esc_html__( 'Not detected', 'otwfeed-pro' ); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Danger zone -->
        <div class="card otwfeed-danger-card mb-3">
            <div class="card-header"><h2 class="card-title mb-0"><?php esc_html_e( 'Maintenance', 'otwfeed-pro' ); ?></h2></div>
            <div class="card-body">
                <p class="text-muted"><?php esc_html_e( 'Purge all regenerated feed XML files from the uploads directory.', 'otwfeed-pro' ); ?></p>
                <button type="button" class="btn btn-outline-danger btn-sm" id="otwfeed-purge-files">
                    <?php esc_html_e( 'Purge Feed Files', 'otwfeed-pro' ); ?>
                </button>
                <div id="otwfeed-purge-status" class="otwfeed-save-status mt-2" aria-live="polite"></div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><?php esc_html_e( 'Save Settings', 'otwfeed-pro' ); ?></button>
    </form>

</div>
