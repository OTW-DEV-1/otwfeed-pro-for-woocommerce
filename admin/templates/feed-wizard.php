<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$feed_id    = absint( $_GET['feed_id'] ?? 0 );
$feed       = $feed_id ? OtwFeed_DB_Feeds::get( $feed_id ) : null;
$is_edit    = ! empty( $feed );
$currencies = OtwFeed_Currency_Manager::get_currencies();
$countries  = WC()->countries->get_countries();
$page_title = $is_edit ? __( 'Edit Feed', 'otwfeed-pro' ) : __( 'New Feed', 'otwfeed-pro' );
?>
<div class="otwfeed-wrap wrap">

    <div class="otwfeed-header">
        <div class="otwfeed-header__logo">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=otwfeed-pro' ) ); ?>" class="otwfeed-back">
                <span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span>
            </a>
            <h1><?php echo esc_html( $page_title ); ?></h1>
        </div>
    </div>

    <!-- Wizard steps indicator -->
    <div class="otwfeed-wizard-steps mb-4" role="tablist" aria-label="<?php esc_attr_e( 'Feed setup steps', 'otwfeed-pro' ); ?>">
        <div class="otwfeed-wizard-step active" data-step="1" role="tab" aria-selected="true" aria-controls="otwfeed-step-1" id="otwfeed-tab-1">
            <span class="otwfeed-wizard-step__num" aria-hidden="true">1</span>
            <span><?php esc_html_e( 'Basic Info', 'otwfeed-pro' ); ?></span>
        </div>
        <div class="otwfeed-wizard-step" data-step="2" role="tab" aria-selected="false" aria-controls="otwfeed-step-2" id="otwfeed-tab-2">
            <span class="otwfeed-wizard-step__num" aria-hidden="true">2</span>
            <span><?php esc_html_e( 'Tax & Currency', 'otwfeed-pro' ); ?></span>
        </div>
        <div class="otwfeed-wizard-step" data-step="3" role="tab" aria-selected="false" aria-controls="otwfeed-step-3" id="otwfeed-tab-3">
            <span class="otwfeed-wizard-step__num" aria-hidden="true">3</span>
            <span><?php esc_html_e( 'Field Mapping', 'otwfeed-pro' ); ?></span>
        </div>
        <div class="otwfeed-wizard-step" data-step="4" role="tab" aria-selected="false" aria-controls="otwfeed-step-4" id="otwfeed-tab-4">
            <span class="otwfeed-wizard-step__num" aria-hidden="true">4</span>
            <span><?php esc_html_e( 'Filters', 'otwfeed-pro' ); ?></span>
        </div>
    </div>

    <form id="otwfeed-wizard-form" novalidate>
        <?php wp_nonce_field( 'otwfeed_ajax', 'nonce' ); ?>
        <input type="hidden" name="feed_id" id="otwfeed-feed-id" value="<?php echo esc_attr( $feed_id ); ?>">

        <!-- Step 1: Basic Info -->
        <div class="otwfeed-step-panel" id="otwfeed-step-1" role="tabpanel" aria-labelledby="otwfeed-tab-1">
            <div class="card">
                <div class="card-header"><h2 class="card-title mb-0"><?php esc_html_e( 'Basic Info', 'otwfeed-pro' ); ?></h2></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="otwfeed-title" class="form-label"><?php esc_html_e( 'Feed Title', 'otwfeed-pro' ); ?> <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="text" id="otwfeed-title" name="title" class="form-control"
                                   value="<?php echo esc_attr( $feed->title ?? '' ); ?>"
                                   required aria-required="true"
                                   placeholder="<?php esc_attr_e( 'e.g. Italy Google Feed', 'otwfeed-pro' ); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="otwfeed-channel" class="form-label"><?php esc_html_e( 'Channel', 'otwfeed-pro' ); ?></label>
                            <select id="otwfeed-channel" name="channel" class="form-select otwfeed-select2">
                                <option value="google" <?php selected( $feed->channel ?? 'google', 'google' ); ?>><?php esc_html_e( 'Google Merchant Center', 'otwfeed-pro' ); ?></option>
                                <option value="facebook" <?php selected( $feed->channel ?? '', 'facebook' ); ?>><?php esc_html_e( 'Facebook / Meta Catalog', 'otwfeed-pro' ); ?></option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="otwfeed-status" class="form-label"><?php esc_html_e( 'Status', 'otwfeed-pro' ); ?></label>
                            <select id="otwfeed-status" name="status" class="form-select otwfeed-select2">
                                <option value="active" <?php selected( $feed->status ?? 'active', 'active' ); ?>><?php esc_html_e( 'Active', 'otwfeed-pro' ); ?></option>
                                <option value="inactive" <?php selected( $feed->status ?? '', 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'otwfeed-pro' ); ?></option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="otwfeed-expand-variations" class="form-label"><?php esc_html_e( 'Variable Products', 'otwfeed-pro' ); ?></label>
                            <select id="otwfeed-expand-variations" name="expand_variations" class="form-select otwfeed-select2">
                                <option value="0" <?php selected( (int) ( $feed->expand_variations ?? 1 ), 0 ); ?>><?php esc_html_e( 'Parent product only (no expansion)', 'otwfeed-pro' ); ?></option>
                                <option value="1" <?php selected( (int) ( $feed->expand_variations ?? 1 ), 1 ); ?>><?php esc_html_e( 'All variations', 'otwfeed-pro' ); ?></option>
                                <option value="2" <?php selected( (int) ( $feed->expand_variations ?? 1 ), 2 ); ?>><?php esc_html_e( 'Lowest price variation only', 'otwfeed-pro' ); ?></option>
                            </select>
                            <p class="form-text text-muted"><?php esc_html_e( 'How variable products appear in the feed.', 'otwfeed-pro' ); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label for="otwfeed-include-gallery-images" class="form-label"><?php esc_html_e( 'Gallery Images', 'otwfeed-pro' ); ?></label>
                            <select id="otwfeed-include-gallery-images" name="include_gallery_images" class="form-select otwfeed-select2">
                                <option value="1" <?php selected( (int) ( $feed->include_gallery_images ?? 1 ), 1 ); ?>><?php esc_html_e( 'Include (additional_image_link)', 'otwfeed-pro' ); ?></option>
                                <option value="0" <?php selected( (int) ( $feed->include_gallery_images ?? 1 ), 0 ); ?>><?php esc_html_e( 'Exclude gallery images', 'otwfeed-pro' ); ?></option>
                            </select>
                            <p class="form-text text-muted"><?php esc_html_e( 'Whether to output product gallery images as additional_image_link.', 'otwfeed-pro' ); ?></p>
                        </div>
                    </div>

                    <?php if ( $is_edit && ! empty( $feed->token ) ) : ?>
                        <div class="mt-3">
                            <label class="form-label"><?php esc_html_e( 'Feed URL', 'otwfeed-pro' ); ?></label>
                            <div class="input-group">
                                <input type="text" class="form-control otwfeed-feed-url-input" readonly
                                       value="<?php echo esc_attr( OtwFeed_Feed_Generator::get_feed_url( $feed->token ) ); ?>"
                                       aria-label="<?php esc_attr_e( 'Feed URL', 'otwfeed-pro' ); ?>">
                                <button type="button" class="btn btn-outline-secondary otwfeed-copy-url">
                                    <?php esc_html_e( 'Copy', 'otwfeed-pro' ); ?>
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Step 2: Tax & Currency -->
        <div class="otwfeed-step-panel d-none" id="otwfeed-step-2" role="tabpanel" aria-labelledby="otwfeed-tab-2">
            <div class="card">
                <div class="card-header"><h2 class="card-title mb-0"><?php esc_html_e( 'Tax & Currency', 'otwfeed-pro' ); ?></h2></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="otwfeed-country" class="form-label"><?php esc_html_e( 'Target Country', 'otwfeed-pro' ); ?></label>
                            <select id="otwfeed-country" name="country" class="form-select otwfeed-select2">
                                <?php foreach ( $countries as $code => $name ) : ?>
                                    <option value="<?php echo esc_attr( $code ); ?>"
                                        <?php selected( $feed->country ?? 'IT', $code ); ?>>
                                        <?php echo esc_html( $name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="otwfeed-currency" class="form-label"><?php esc_html_e( 'Currency', 'otwfeed-pro' ); ?></label>
                            <select id="otwfeed-currency" name="currency" class="form-select otwfeed-select2">
                                <?php foreach ( $currencies as $code => $symbol ) : ?>
                                    <option value="<?php echo esc_attr( $code ); ?>"
                                        <?php selected( $feed->currency ?? 'EUR', $code ); ?>>
                                        <?php echo esc_html( $code . ' (' . $symbol . ')' ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="otwfeed-tax-mode" class="form-label"><?php esc_html_e( 'Tax Mode', 'otwfeed-pro' ); ?></label>
                            <select id="otwfeed-tax-mode" name="tax_mode" class="form-select otwfeed-select2">
                                <option value="include" <?php selected( $feed->tax_mode ?? 'include', 'include' ); ?>><?php esc_html_e( 'Price including tax', 'otwfeed-pro' ); ?></option>
                                <option value="exclude" <?php selected( $feed->tax_mode ?? '', 'exclude' ); ?>><?php esc_html_e( 'Price excluding tax', 'otwfeed-pro' ); ?></option>
                            </select>
                        </div>
                    </div>

                    <!-- Real-time price preview widget -->
                    <div class="otwfeed-price-preview mt-4">
                        <h3 class="otwfeed-section-label"><?php esc_html_e( 'Price Preview', 'otwfeed-pro' ); ?></h3>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label for="otwfeed-preview-product" class="form-label"><?php esc_html_e( 'Select a product to preview', 'otwfeed-pro' ); ?></label>
                                <input type="number" id="otwfeed-preview-product" class="form-control"
                                       placeholder="<?php esc_attr_e( 'Product ID', 'otwfeed-pro' ); ?>"
                                       aria-label="<?php esc_attr_e( 'Product ID for price preview', 'otwfeed-pro' ); ?>">
                            </div>
                            <div class="col-md-3">
                                <button type="button" class="btn btn-secondary otwfeed-preview-btn" id="otwfeed-preview-btn">
                                    <?php esc_html_e( 'Preview Price', 'otwfeed-pro' ); ?>
                                </button>
                            </div>
                            <div class="col-md-4">
                                <div class="otwfeed-price-result" id="otwfeed-price-result" aria-live="polite" aria-atomic="true"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 3: Field Mapping -->
        <div class="otwfeed-step-panel d-none" id="otwfeed-step-3" role="tabpanel" aria-labelledby="otwfeed-tab-3">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="card-title mb-0"><?php esc_html_e( 'Field Mapping', 'otwfeed-pro' ); ?></h2>
                    <button type="button" class="btn btn-sm btn-outline-accent" id="otwfeed-add-mapping">
                        + <?php esc_html_e( 'Add Row', 'otwfeed-pro' ); ?>
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table otwfeed-table otwfeed-mapping-table mb-0" aria-label="<?php esc_attr_e( 'Field Mapping', 'otwfeed-pro' ); ?>">
                            <colgroup>
                                <col style="width:200px"><!-- channel tag -->
                                <col style="width:160px"><!-- source type -->
                                <col>                   <!-- source / value (fills remaining) -->
                                <col style="width:40px"><!-- remove -->
                            </colgroup>
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e( 'Channel Tag', 'otwfeed-pro' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Source Type', 'otwfeed-pro' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Source / Value', 'otwfeed-pro' ); ?></th>
                                    <th scope="col"></th>
                                </tr>
                            </thead>
                            <tbody id="otwfeed-mapping-tbody">
                                <?php
                                $mappings = $feed_id ? OtwFeed_DB_Mappings::get_for_feed( $feed_id ) : array();
                                if ( empty( $mappings ) ) {
                                    $channel  = $feed->channel ?? 'google';
                                    $defaults = 'google' === $channel
                                        ? OtwFeed_DB_Mappings::get_default_google_mappings()
                                        : OtwFeed_DB_Mappings::get_default_facebook_mappings();
                                    $mappings = array_map( static fn( $m ) => (object) $m, $defaults );
                                }
                                foreach ( $mappings as $row ) :
                                    $src_type = $row->source_type ?? 'attribute';
                                    $src_val  = 'static' === $src_type ? ( $row->static_val ?? '' ) : ( $row->source_key ?? '' );
                                ?>
                                    <tr class="otwfeed-mapping-row" data-price-round="<?php echo esc_attr( $row->price_round ?? 0 ); ?>">
                                        <td>
                                            <input type="text" name="mappings[channel_tag][]" class="form-control form-control-sm"
                                                   value="<?php echo esc_attr( $row->channel_tag ?? '' ); ?>"
                                                   aria-label="<?php esc_attr_e( 'Channel tag', 'otwfeed-pro' ); ?>">
                                        </td>
                                        <td>
                                            <select name="mappings[source_type][]" class="form-select form-select-sm otwfeed-source-type"
                                                    aria-label="<?php esc_attr_e( 'Source type', 'otwfeed-pro' ); ?>">
                                                <option value="attribute" <?php selected( $src_type, 'attribute' ); ?>><?php esc_html_e( 'Attribute', 'otwfeed-pro' ); ?></option>
                                                <option value="meta"      <?php selected( $src_type, 'meta' ); ?>><?php esc_html_e( 'Meta Field', 'otwfeed-pro' ); ?></option>
                                                <option value="taxonomy"  <?php selected( $src_type, 'taxonomy' ); ?>><?php esc_html_e( 'Taxonomy', 'otwfeed-pro' ); ?></option>
                                                <option value="static"    <?php selected( $src_type, 'static' ); ?>><?php esc_html_e( 'Static Value', 'otwfeed-pro' ); ?></option>
                                            </select>
                                        </td>
                                        <td class="otwfeed-source-val-cell">
                                            <?php if ( 'static' === $src_type ) : ?>
                                                <input type="text"
                                                       name="mappings[source_key][]"
                                                       class="form-control form-control-sm otwfeed-source-static"
                                                       value="<?php echo esc_attr( $src_val ); ?>"
                                                       placeholder="<?php esc_attr_e( 'Enter static value…', 'otwfeed-pro' ); ?>"
                                                       aria-label="<?php esc_attr_e( 'Static value', 'otwfeed-pro' ); ?>">
                                            <?php else : ?>
                                                <select name="mappings[source_key][]"
                                                        class="form-select form-select-sm otwfeed-source-key-select"
                                                        data-current="<?php echo esc_attr( $src_val ); ?>"
                                                        data-type="<?php echo esc_attr( $src_type ); ?>"
                                                        aria-label="<?php esc_attr_e( 'Source field', 'otwfeed-pro' ); ?>">
                                                    <?php if ( $src_val ) : ?>
                                                        <option value="<?php echo esc_attr( $src_val ); ?>" selected><?php echo esc_html( $src_val ); ?></option>
                                                    <?php endif; ?>
                                                </select>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-xs btn-ghost-danger otwfeed-remove-row"
                                                    aria-label="<?php esc_attr_e( 'Remove row', 'otwfeed-pro' ); ?>">
                                                &times;
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 4: Filters -->
        <div class="otwfeed-step-panel d-none" id="otwfeed-step-4" role="tabpanel" aria-labelledby="otwfeed-tab-4">
            <?php
            $wiz_grouped       = $feed_id ? OtwFeed_DB_Filters::get_grouped_for_feed( $feed_id ) : array();
            $wiz_filter_attrs  = OtwFeed_DB_Filters::get_attributes();
            $wiz_filter_conds  = OtwFeed_DB_Filters::get_conditions();
            $wiz_next_group_id = empty( $wiz_grouped ) ? 0 : max( array_column( $wiz_grouped, 'group_id' ) ) + 1;
            ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="card-title mb-0"><?php esc_html_e( 'Filters & Exclusions', 'otwfeed-pro' ); ?></h2>
                    <button type="button" class="btn btn-sm btn-outline-accent" id="otwfeed-add-filter-group">
                        + <?php esc_html_e( 'Add Filter Group', 'otwfeed-pro' ); ?>
                    </button>
                </div>
                <div class="card-body">
                    <div id="otwfeed-filter-groups" data-next-group-id="<?php echo esc_attr( $wiz_next_group_id ); ?>">

                        <?php if ( empty( $wiz_grouped ) ) : ?>
                            <div class="otwfeed-filter-empty text-center text-muted py-4">
                                <?php esc_html_e( 'No filters. All products will be included.', 'otwfeed-pro' ); ?>
                            </div>
                        <?php else : ?>
                            <div class="otwfeed-filter-empty d-none text-center text-muted py-4">
                                <?php esc_html_e( 'No filters. All products will be included.', 'otwfeed-pro' ); ?>
                            </div>
                            <?php foreach ( $wiz_grouped as $wiz_group ) : ?>
                                <div class="otwfeed-filter-group" data-group-id="<?php echo esc_attr( $wiz_group['group_id'] ); ?>">
                                    <div class="otwfeed-fg-header">
                                        <select class="form-select form-select-sm otwfeed-fg-action"
                                                aria-label="<?php esc_attr_e( 'Group action', 'otwfeed-pro' ); ?>">
                                            <option value="exclude" <?php selected( $wiz_group['group_action'], 'exclude' ); ?>><?php esc_html_e( 'Exclude', 'otwfeed-pro' ); ?></option>
                                            <option value="include" <?php selected( $wiz_group['group_action'], 'include' ); ?>><?php esc_html_e( 'Include', 'otwfeed-pro' ); ?></option>
                                        </select>
                                        <button type="button" class="btn btn-xs btn-ghost-danger otwfeed-fg-remove"
                                                aria-label="<?php esc_attr_e( 'Remove group', 'otwfeed-pro' ); ?>">
                                            <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                                        </button>
                                    </div>
                                    <div class="otwfeed-fg-body">
                                        <div class="otwfeed-fg-if-label"><?php esc_html_e( 'IF...', 'otwfeed-pro' ); ?></div>
                                        <div class="otwfeed-fg-conditions">
                                            <?php foreach ( $wiz_group['conditions'] as $wiz_cond ) :
                                                $wiz_no_val = in_array( $wiz_cond->condition_op, array( 'is_empty', 'is_not_empty' ), true );
                                            ?>
                                                <div class="otwfeed-fc-row">
                                                    <div class="otwfeed-fc-attr">
                                                        <select class="form-select form-select-sm otwfeed-fc-attr-sel"
                                                                aria-label="<?php esc_attr_e( 'Attribute', 'otwfeed-pro' ); ?>">
                                                            <?php foreach ( $wiz_filter_attrs as $k => $l ) : ?>
                                                                <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $wiz_cond->attribute, $k ); ?>><?php echo esc_html( $l ); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="otwfeed-fc-op">
                                                        <select class="form-select form-select-sm otwfeed-fc-op-sel"
                                                                aria-label="<?php esc_attr_e( 'Condition', 'otwfeed-pro' ); ?>">
                                                            <?php foreach ( $wiz_filter_conds as $k => $l ) : ?>
                                                                <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $wiz_cond->condition_op, $k ); ?>><?php echo esc_html( $l ); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="otwfeed-fc-val">
                                                        <input type="text"
                                                               class="form-control form-control-sm otwfeed-fc-val-input"
                                                               value="<?php echo esc_attr( $wiz_cond->value ); ?>"
                                                               placeholder="<?php esc_attr_e( 'Enter value', 'otwfeed-pro' ); ?>"
                                                               <?php echo $wiz_no_val ? 'disabled' : ''; ?>>
                                                    </div>
                                                    <label class="otwfeed-fc-case" title="<?php esc_attr_e( 'Case sensitive', 'otwfeed-pro' ); ?>">
                                                        <input type="checkbox" class="otwfeed-fc-case-input" <?php checked( $wiz_cond->case_sensitive, 1 ); ?>> Aa
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
            </div>
        </div>

        <!-- Wizard navigation -->
        <div class="otwfeed-wizard-nav mt-4 d-flex justify-content-between align-items-center">
            <button type="button" class="btn btn-secondary otwfeed-wizard-prev" id="otwfeed-prev" style="visibility:hidden">
                &larr; <?php esc_html_e( 'Previous', 'otwfeed-pro' ); ?>
            </button>

            <div id="otwfeed-save-status" class="otwfeed-save-status" aria-live="polite" aria-atomic="true"></div>

            <div class="d-flex gap-2">
                <button type="button" class="btn btn-secondary otwfeed-wizard-next" id="otwfeed-next">
                    <?php esc_html_e( 'Next', 'otwfeed-pro' ); ?> &rarr;
                </button>
                <button type="button" class="btn btn-primary d-none" id="otwfeed-save-btn">
                    <?php echo $is_edit ? esc_html__( 'Save Changes', 'otwfeed-pro' ) : esc_html__( 'Create Feed', 'otwfeed-pro' ); ?>
                </button>
            </div>
        </div>
    </form>

</div>
