<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$feeds          = OtwFeed_DB_Feeds::get_all( array( 'limit' => 100 ) );
$feed_id        = absint( $_GET['feed_id'] ?? ( $feeds[0]->id ?? 0 ) );
$feed           = $feed_id ? OtwFeed_DB_Feeds::get( $feed_id ) : null;
$grouped        = $feed_id ? OtwFeed_DB_Filters::get_grouped_for_feed( $feed_id ) : array();
$filter_attrs   = OtwFeed_DB_Filters::get_attributes();
$filter_conds   = OtwFeed_DB_Filters::get_conditions();
$next_group_id  = empty( $grouped ) ? 0 : max( array_column( $grouped, 'group_id' ) ) + 1;
?>
<div class="otwfeed-wrap wrap">

    <div class="otwfeed-header">
        <div class="otwfeed-header__logo">
            <span class="dashicons dashicons-filter" aria-hidden="true"></span>
            <h1><?php esc_html_e( 'Advanced Filtering & Exclusion Manager', 'otwfeed-pro' ); ?></h1>
        </div>
    </div>

    <?php if ( empty( $feeds ) ) : ?>
        <div class="card">
            <div class="card-body otwfeed-empty">
                <p><?php esc_html_e( 'Create a feed first before configuring filters.', 'otwfeed-pro' ); ?></p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=otwfeed-new' ) ); ?>" class="btn btn-primary">
                    <?php esc_html_e( 'Create Feed', 'otwfeed-pro' ); ?>
                </a>
            </div>
        </div>
    <?php else : ?>

        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <label for="otwfeed-filter-feed-select" class="form-label"><?php esc_html_e( 'Select Feed', 'otwfeed-pro' ); ?></label>
                <select id="otwfeed-filter-feed-select" class="form-select otwfeed-select2 otwfeed-feed-switcher"
                        data-base-url="<?php echo esc_attr( admin_url( 'admin.php?page=otwfeed-filters&feed_id=' ) ); ?>"
                        aria-label="<?php esc_attr_e( 'Select feed', 'otwfeed-pro' ); ?>">
                    <?php foreach ( $feeds as $f ) : ?>
                        <option value="<?php echo esc_attr( $f->id ); ?>" <?php selected( $feed_id, $f->id ); ?>>
                            <?php echo esc_html( $f->title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if ( $feed ) : ?>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="card-title mb-0">
                        <?php printf( esc_html__( 'Filters for: %s', 'otwfeed-pro' ), esc_html( $feed->title ) ); ?>
                    </h2>
                    <button type="button" class="btn btn-sm btn-outline-accent" id="otwfeed-add-filter-group">
                        + <?php esc_html_e( 'Add Filter Group', 'otwfeed-pro' ); ?>
                    </button>
                </div>
                <div class="card-body">

                    <div id="otwfeed-filter-groups" data-next-group-id="<?php echo esc_attr( $next_group_id ); ?>">

                        <?php if ( empty( $grouped ) ) : ?>
                            <div class="otwfeed-filter-empty text-center text-muted py-4">
                                <?php esc_html_e( 'No filters. All products will be included.', 'otwfeed-pro' ); ?>
                            </div>
                        <?php else : ?>
                            <div class="otwfeed-filter-empty d-none text-center text-muted py-4">
                                <?php esc_html_e( 'No filters. All products will be included.', 'otwfeed-pro' ); ?>
                            </div>
                            <?php foreach ( $grouped as $group ) : ?>
                                <div class="otwfeed-filter-group" data-group-id="<?php echo esc_attr( $group['group_id'] ); ?>">
                                    <div class="otwfeed-fg-header">
                                        <select class="form-select form-select-sm otwfeed-fg-action"
                                                aria-label="<?php esc_attr_e( 'Group action', 'otwfeed-pro' ); ?>">
                                            <option value="exclude" <?php selected( $group['group_action'], 'exclude' ); ?>><?php esc_html_e( 'Exclude', 'otwfeed-pro' ); ?></option>
                                            <option value="include" <?php selected( $group['group_action'], 'include' ); ?>><?php esc_html_e( 'Include', 'otwfeed-pro' ); ?></option>
                                        </select>
                                        <button type="button" class="btn btn-xs btn-ghost-danger otwfeed-fg-remove"
                                                aria-label="<?php esc_attr_e( 'Remove group', 'otwfeed-pro' ); ?>">
                                            <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                                        </button>
                                    </div>
                                    <div class="otwfeed-fg-body">
                                        <div class="otwfeed-fg-if-label"><?php esc_html_e( 'IF...', 'otwfeed-pro' ); ?></div>
                                        <div class="otwfeed-fg-conditions">
                                            <?php foreach ( $group['conditions'] as $cond ) :
                                                $no_val = in_array( $cond->condition_op, array( 'is_empty', 'is_not_empty' ), true );
                                            ?>
                                                <div class="otwfeed-fc-row">
                                                    <div class="otwfeed-fc-attr">
                                                        <select class="form-select form-select-sm otwfeed-fc-attr-sel"
                                                                aria-label="<?php esc_attr_e( 'Attribute', 'otwfeed-pro' ); ?>">
                                                            <?php foreach ( $filter_attrs as $k => $l ) : ?>
                                                                <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $cond->attribute, $k ); ?>><?php echo esc_html( $l ); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="otwfeed-fc-op">
                                                        <select class="form-select form-select-sm otwfeed-fc-op-sel"
                                                                aria-label="<?php esc_attr_e( 'Condition', 'otwfeed-pro' ); ?>">
                                                            <?php foreach ( $filter_conds as $k => $l ) : ?>
                                                                <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $cond->condition_op, $k ); ?>><?php echo esc_html( $l ); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="otwfeed-fc-val">
                                                        <input type="text"
                                                               class="form-control form-control-sm otwfeed-fc-val-input"
                                                               value="<?php echo esc_attr( $cond->value ); ?>"
                                                               placeholder="<?php esc_attr_e( 'Enter value', 'otwfeed-pro' ); ?>"
                                                               <?php echo $no_val ? 'disabled' : ''; ?>>
                                                    </div>
                                                    <label class="otwfeed-fc-case" title="<?php esc_attr_e( 'Case sensitive', 'otwfeed-pro' ); ?>">
                                                        <input type="checkbox" class="otwfeed-fc-case-input" <?php checked( $cond->case_sensitive, 1 ); ?>> Aa
                                                    </label>
                                                    <button type="button" class="btn btn-xs btn-ghost-danger otwfeed-fc-remove"
                                                            aria-label="<?php esc_attr_e( 'Remove condition', 'otwfeed-pro' ); ?>">&times;</button>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="button" class="btn btn-xs btn-outline-secondary otwfeed-fg-add-cond mt-2">
                                            + <?php esc_html_e( 'Add Condition', 'otwfeed-pro' ); ?>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                    </div>

                </div>
                <div class="card-footer d-flex justify-content-end gap-2 align-items-center">
                    <input type="hidden" id="otwfeed-filter-feed-id" value="<?php echo esc_attr( $feed_id ); ?>">
                    <div id="otwfeed-filter-status" class="otwfeed-save-status me-auto" aria-live="polite" aria-atomic="true"></div>
                    <button type="button" class="btn btn-primary" id="otwfeed-save-filters">
                        <?php esc_html_e( 'Save Filters', 'otwfeed-pro' ); ?>
                    </button>
                </div>
            </div>

        <?php endif; ?>
    <?php endif; ?>

</div>
