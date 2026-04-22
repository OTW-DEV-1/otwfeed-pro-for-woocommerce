<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$feeds = OtwFeed_DB_Feeds::get_all( array( 'limit' => 100 ) );
$feed_id = absint( $_GET['feed_id'] ?? ( $feeds[0]->id ?? 0 ) );
$feed    = $feed_id ? OtwFeed_DB_Feeds::get( $feed_id ) : null;
$mappings = $feed_id ? OtwFeed_DB_Mappings::get_for_feed( $feed_id ) : array();

if ( empty( $mappings ) && $feed ) {
    $defaults = 'google' === $feed->channel
        ? OtwFeed_DB_Mappings::get_default_google_mappings()
        : OtwFeed_DB_Mappings::get_default_facebook_mappings();
    $mappings = array_map( static fn( $m ) => (object) $m, $defaults );
}

$available_attributes = array(
    // Core product data
    'id'                => __( 'Product ID', 'otwfeed-pro' ),
    'name'              => __( 'Product Name / Title', 'otwfeed-pro' ),
    'description'       => __( 'Description (HTML stripped)', 'otwfeed-pro' ),
    'permalink'         => __( 'Product URL', 'otwfeed-pro' ),
    'image'             => __( 'Main Image URL', 'otwfeed-pro' ),
    'sku'               => __( 'SKU', 'otwfeed-pro' ),
    // Pricing & availability
    'price'             => __( 'Price (tax+currency calculated)', 'otwfeed-pro' ),
    'availability'      => __( 'Availability (in_stock / out_of_stock / preorder)', 'otwfeed-pro' ),
    // Product identity
    'item_group_id'     => __( 'Item Group ID (parent ID for variations)', 'otwfeed-pro' ),
    'identifier_exists' => __( 'Identifier Exists (yes / no — based on GTIN/MPN)', 'otwfeed-pro' ),
    'brand'             => __( 'Brand (auto-detected from attributes)', 'otwfeed-pro' ),
    // Categorisation
    'product_type'      => __( 'Product Type (full category breadcrumb path)', 'otwfeed-pro' ),
    // Checkout
    'checkout_link'     => __( 'Checkout Link (add-to-cart URL)', 'otwfeed-pro' ),
);
?>
<div class="otwfeed-wrap wrap">

    <div class="otwfeed-header">
        <div class="otwfeed-header__logo">
            <span class="dashicons dashicons-columns" aria-hidden="true"></span>
            <h1><?php esc_html_e( 'Channel Mapping Profiles', 'otwfeed-pro' ); ?></h1>
        </div>
    </div>

    <?php if ( empty( $feeds ) ) : ?>
        <div class="card">
            <div class="card-body otwfeed-empty">
                <p><?php esc_html_e( 'Create a feed first before configuring mappings.', 'otwfeed-pro' ); ?></p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=otwfeed-new' ) ); ?>" class="btn btn-primary"><?php esc_html_e( 'Create Feed', 'otwfeed-pro' ); ?></a>
            </div>
        </div>
    <?php else : ?>

        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <label for="otwfeed-mapping-feed-select" class="form-label"><?php esc_html_e( 'Select Feed', 'otwfeed-pro' ); ?></label>
                <select id="otwfeed-mapping-feed-select" class="form-select otwfeed-select2 otwfeed-feed-switcher"
                        data-base-url="<?php echo esc_attr( admin_url( 'admin.php?page=otwfeed-mapping&feed_id=' ) ); ?>"
                        aria-label="<?php esc_attr_e( 'Select feed', 'otwfeed-pro' ); ?>">
                    <?php foreach ( $feeds as $f ) : ?>
                        <option value="<?php echo esc_attr( $f->id ); ?>" <?php selected( $feed_id, $f->id ); ?>>
                            <?php echo esc_html( $f->title . ' (' . strtoupper( $f->channel ) . ')' ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if ( $feed ) : ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="card-title mb-0">
                        <?php printf( esc_html__( 'Mapping: %s', 'otwfeed-pro' ), esc_html( $feed->title ) ); ?>
                        <span class="badge otwfeed-channel-badge otwfeed-channel-badge--<?php echo esc_attr( $feed->channel ); ?> ms-2"><?php echo esc_html( strtoupper( $feed->channel ) ); ?></span>
                    </h2>
                    <button type="button" class="btn btn-sm btn-outline-accent" id="otwfeed-add-mapping">
                        + <?php esc_html_e( 'Add Row', 'otwfeed-pro' ); ?>
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table otwfeed-table otwfeed-mapping-table mb-0" aria-label="<?php esc_attr_e( 'Field Mapping', 'otwfeed-pro' ); ?>">
                            <colgroup>
                                <col style="width:36px"> <!-- drag handle -->
                                <col style="width:200px"><!-- channel tag -->
                                <col style="width:160px"><!-- source type -->
                                <col>                    <!-- source / value (fills remaining) -->
                                <col style="width:40px"> <!-- remove -->
                            </colgroup>
                            <thead>
                                <tr>
                                    <th scope="col"></th>
                                    <th scope="col"><?php esc_html_e( 'Channel Tag', 'otwfeed-pro' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Source Type', 'otwfeed-pro' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Source / Value', 'otwfeed-pro' ); ?></th>
                                    <th scope="col"></th>
                                </tr>
                            </thead>
                            <tbody id="otwfeed-mapping-tbody">
                                <?php foreach ( $mappings as $row ) :
                                    $src_type = $row->source_type ?? 'attribute';
                                    $src_val  = 'static' === $src_type ? ( $row->static_val ?? '' ) : ( $row->source_key ?? '' );
                                ?>
                                    <tr class="otwfeed-mapping-row" draggable="true" data-price-round="<?php echo esc_attr( $row->price_round ?? 0 ); ?>">
                                        <td class="otwfeed-drag-handle" aria-label="<?php esc_attr_e( 'Drag to reorder', 'otwfeed-pro' ); ?>">
                                            <span class="dashicons dashicons-menu" aria-hidden="true"></span>
                                        </td>
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
                <div class="card-footer d-flex justify-content-end gap-2">
                    <input type="hidden" id="otwfeed-mapping-feed-id" value="<?php echo esc_attr( $feed_id ); ?>">
                    <div id="otwfeed-mapping-status" class="otwfeed-save-status me-auto" aria-live="polite" aria-atomic="true"></div>
                    <button type="button" class="btn btn-primary" id="otwfeed-save-mappings">
                        <?php esc_html_e( 'Save Mappings', 'otwfeed-pro' ); ?>
                    </button>
                </div>
            </div>

            <!-- Available attributes reference -->
            <datalist id="otwfeed-attr-datalist">
                <?php foreach ( $available_attributes as $key => $label ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </datalist>

            <div class="card mt-3">
                <div class="card-header"><h3 class="card-title mb-0 fs-6"><?php esc_html_e( 'Available Attributes Reference', 'otwfeed-pro' ); ?></h3></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table otwfeed-table table-sm mb-0">
                            <thead><tr><th><?php esc_html_e( 'Key', 'otwfeed-pro' ); ?></th><th><?php esc_html_e( 'Description', 'otwfeed-pro' ); ?></th></tr></thead>
                            <tbody>
                                <?php foreach ( $available_attributes as $key => $label ) : ?>
                                    <tr><td><code><?php echo esc_html( $key ); ?></code></td><td><?php echo esc_html( $label ); ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    <?php endif; ?>

</div>
