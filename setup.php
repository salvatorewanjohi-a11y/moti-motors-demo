<?php
/**
 * Builds the MOTi Moto-Rs showroom inside WordPress Playground.
 * Run by the blueprint after WooCommerce and the theme are installed.
 * Cars come from products.json (next to this file), photos from /wordpress/mm-images.
 */
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// The photos are already web-sized webp files, so skip making thumbnails of each one.
// The theme reads this option and serves the photos as they are. (Real hosting can regenerate thumbnails later.)
update_option( 'mm_fast_images', 'yes' );
add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
add_filter( 'big_image_size_threshold', '__return_false' );
add_filter( 'woocommerce_background_image_regeneration', '__return_false' );
add_filter( 'woocommerce_resize_images', '__return_false' );

// Store settings.
foreach ( [
	'blogname'                               => 'MOTi Moto-Rs',
	'blogdescription'                        => 'Affordable • Clean • Verified',
	'timezone_string'                        => 'Africa/Nairobi',
	'woocommerce_currency'                   => 'KES',
	'woocommerce_currency_pos'               => 'left_space',
	'woocommerce_price_num_decimals'         => '0',
	'woocommerce_price_thousand_sep'         => ',',
	'woocommerce_default_country'            => 'KE:KE30',
	'woocommerce_store_address'              => 'Lavington',
	'woocommerce_store_city'                 => 'Nairobi',
	'woocommerce_manage_stock'               => 'yes',
	'woocommerce_notify_low_stock_amount'    => '0',
	'woocommerce_notify_no_stock_amount'     => '0',
	'woocommerce_hide_out_of_stock_items'    => 'no',
	'woocommerce_allowed_countries'          => 'specific',
	'woocommerce_specific_allowed_countries' => [ 'KE' ],
	'woocommerce_ship_to_countries'          => 'disabled',
	'woocommerce_enable_reviews'             => 'no',
	'woocommerce_onboarding_profile'         => [ 'skipped' => true ],
	'woocommerce_task_list_hidden'           => 'yes',
	'woocommerce_coming_soon'                => 'no',
	'woocommerce_checkout_phone_field'       => 'required',
	'woocommerce_checkout_company_field'     => 'hidden',
	'woocommerce_checkout_address_2_field'   => 'hidden',
	'woocommerce_enable_coupons'             => 'no',
	'woocommerce_cart_redirect_after_add'    => 'yes',
	// Filters query the attribute terms directly, so they work the moment a car is saved.
	'woocommerce_attribute_lookup_enabled'   => 'no',
] as $k => $v ) {
	update_option( $k, $v );
}

// Remove sample content.
foreach ( get_posts( [ 'post_type' => [ 'post', 'page' ], 'name' => 'hello-world', 'numberposts' => 1 ] ) as $p ) wp_delete_post( $p->ID, true );
$sample = get_page_by_path( 'sample-page' );
if ( $sample ) wp_delete_post( $sample->ID, true );

// Body types (menu order = order in the filters and on the home page).
$cat = [];
$i   = 0;
foreach ( [
	'suv'     => [ 'SUVs & crossovers', 'Subaru Forester, Mazda CX-5, Toyota Harrier, Nissan X-Trail, VW Touareg and more: clean, verified SUVs with financing and trade-ins welcome.' ],
	'saloon'  => [ 'Saloons & estates', 'Mercedes-Benz C- and E-Class, Toyota Crown, Mark X and Allion, Subaru Legacy: comfortable daily drivers at honest prices.' ],
	'hatch'   => [ 'Hatchbacks', 'Mazda Demio, Honda Fit, Nissan Note, VW Golf and Polo, Mercedes A 180: easy to park, easy on fuel.' ],
	'utility' => [ 'Pickups & MPVs', 'The Toyota Hilux double cab and the 7-seat Toyota Vellfire: room for the business or the whole family.' ],
] as $slug => [ $name, $desc ] ) {
	$t = term_exists( $slug, 'product_cat' ) ?: wp_insert_term( $name, 'product_cat', [ 'slug' => $slug, 'description' => $desc ] );
	$cat[ $slug ] = (int) $t['term_id'];
	update_term_meta( $cat[ $slug ], 'order', $i++ );
}

// Filters on the shop pages: fuel, gearbox and drive.
function mm_filter_attribute( $label, $slug ) {
	if ( ! wc_attribute_taxonomy_id_by_name( $slug ) ) {
		wc_create_attribute( [ 'name' => $label, 'slug' => $slug, 'type' => 'select', 'order_by' => 'name', 'has_archives' => false ] );
	}
	$tax = wc_attribute_taxonomy_name( $slug );
	if ( ! taxonomy_exists( $tax ) ) {
		register_taxonomy( $tax, [ 'product' ], [ 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false ] );
	}
	return $tax;
}
$filters = [
	'fuel'         => mm_filter_attribute( 'Fuel', 'fuel' ),
	'transmission' => mm_filter_attribute( 'Gearbox', 'transmission' ),
	'drive'        => mm_filter_attribute( 'Drive', 'drive' ),
];
delete_transient( 'wc_attribute_taxonomies' );

// Payment: nothing is paid online. The reservation holds the car until the buyer has seen it.
update_option( 'woocommerce_cod_settings', [
	'enabled'            => 'yes',
	'title'              => 'Reserve now, pay after viewing',
	'description'        => 'Nothing to pay today. We call to arrange your viewing and test drive; once you are happy with the car, you pay in cash (bank transfer), through your financier, or with a trade-in plus top-up.',
	'instructions'       => 'We will call you shortly to confirm the car is held for you and to arrange your viewing.',
	'enable_for_methods' => [],
	'enable_for_virtual' => 'yes',
] );

// Cars: one per Instagram reel, built by catalogue/build.mjs.
$products = json_decode( file_get_contents( __DIR__ . '/products.json' ), true );

// The photos are web-sized webp files made by catalogue/build.mjs, so they are copied straight into
// uploads and registered with their size. (media_handle_sideload processes every image, which made
// the demo slow to build.)
function mm_attach_images( $slug, $name ) {
	$files = glob( "/wordpress/mm-images/$slug*.webp" ) ?: [];
	$files = array_values( array_filter( $files, fn( $f ) => preg_match( '#/' . preg_quote( $slug, '#' ) . '(-\d+)?\.webp$#', $f ) ) );
	// slug.webp is the lead photo, then slug-2.webp, slug-3.webp…
	$num = fn( $f ) => preg_match( '#-(\d+)\.webp$#', substr( $f, strlen( $slug ) ), $m ) ? (int) $m[1] : 1;
	usort( $files, fn( $a, $b ) => $num( basename( $a ) ) <=> $num( basename( $b ) ) );
	$up  = wp_upload_dir();
	$ids = [];
	foreach ( $files as $i => $file ) {
		$base = basename( $file );
		$dest = trailingslashit( $up['path'] ) . $base;
		if ( ! copy( $file, $dest ) ) {
			continue;
		}
		$size = @getimagesize( $dest ) ?: [ 1280, 960 ];
		$att  = wp_insert_attachment( [
			'post_mime_type' => 'image/webp',
			'post_title'     => $name . ( $i ? ' – photo ' . ( $i + 1 ) : '' ),
			'post_status'    => 'inherit',
			'guid'           => trailingslashit( $up['url'] ) . $base,
		], $dest, 0, false, false );
		if ( ! $att ) {
			continue;
		}
		wp_update_attachment_metadata( $att, [ 'width' => $size[0], 'height' => $size[1], 'file' => _wp_relative_upload_path( $dest ), 'sizes' => [], 'image_meta' => [] ] );
		update_post_meta( $att, '_wp_attachment_image_alt', $name );
		$ids[] = $att;
	}
	return $ids;
}

// Count terms once at the end, and run the import as one transaction: SQLite otherwise commits
// (and syncs to disk) after every query.
wp_defer_term_counting( true );
wp_suspend_cache_invalidation( true );
$wpdb->query( 'START TRANSACTION' );
$started = microtime( true );

foreach ( $products as $d ) {
	$imgs = mm_attach_images( $d['slug'], $d['name'] );
	$p    = new WC_Product_Simple();
	$p->set_name( $d['name'] );
	$p->set_slug( $d['slug'] );
	$p->set_status( 'publish' );
	$p->set_menu_order( $d['order'] );
	$p->set_description( wp_kses_post( $d['desc'] ) );
	$p->set_short_description( wp_kses_post( $d['short'] ) );
	$p->set_category_ids( array_map( fn( $s ) => $cat[ $s ], $d['cat'] ) );
	$p->set_featured( ! empty( $d['featured'] ) );
	$p->set_virtual( true );
	$p->set_sold_individually( true );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 1 );
	$p->set_regular_price( $d['price'] );
	$p->set_sku( 'MM' . ( 101 + $d['order'] ) );
	$p->update_meta_data( '_mm_spec', $d['badge'] );
	$p->update_meta_data( '_mm_specs', $d['specs'] );
	$p->update_meta_data( '_mm_year', $d['year'] );
	$p->update_meta_data( '_mm_ready', $d['ready'] ? 'yes' : 'no' );
	$p->update_meta_data( '_mm_arrived', $d['arrived'] ? 'yes' : 'no' );
	$p->update_meta_data( '_mm_ig', $d['ig'] );
	$p->update_meta_data( '_mm_views', $d['views'] );

	// Filter values.
	$attributes = [];
	foreach ( $d['f'] as $key => $value ) {
		if ( $value === '' ) {
			continue;
		}
		$tax = $filters[ $key ];
		$t   = term_exists( $value, $tax ) ?: wp_insert_term( $value, $tax );
		if ( is_wp_error( $t ) ) {
			continue;
		}
		$attr = new WC_Product_Attribute();
		$attr->set_id( wc_attribute_taxonomy_id_by_name( $tax ) );
		$attr->set_name( $tax );
		$attr->set_options( [ (int) $t['term_id'] ] );
		$attr->set_visible( false );
		$attr->set_variation( false );
		$attributes[] = $attr;
	}
	$p->set_attributes( $attributes );

	if ( $imgs ) {
		$p->set_image_id( $imgs[0] );
		$p->set_gallery_image_ids( array_slice( $imgs, 1 ) );
	}
	$pid = $p->save();

	if ( $d['brand'] && taxonomy_exists( 'product_brand' ) ) {
		wp_set_object_terms( $pid, $d['brand'], 'product_brand' );
	}
}

$wpdb->query( 'COMMIT' );
wp_suspend_cache_invalidation( false );
wp_defer_term_counting( false );
update_option( 'mm_setup_seconds', round( microtime( true ) - $started, 1 ) );

// Pages. Their layout lives in the theme's page templates.
function mm_page( $slug, $title, $content = '' ) {
	$existing = get_page_by_path( $slug );
	if ( $existing ) return $existing->ID;
	return wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content ] );
}
mm_page( 'saved-cars', 'Your saved cars', "<!-- wp:shortcode -->\n[mm_wishlist]\n<!-- /wp:shortcode -->" );
mm_page( 'compare', 'Compare cars', "<!-- wp:shortcode -->\n[mm_compare]\n<!-- /wp:shortcode -->" );
mm_page( 'sell-trade-in', 'Sell or trade in your car' );
mm_page( 'find-a-car', 'Find me a car' );

// Cart and checkout read as a reservation.
foreach ( [ 'shop' => 'Verified cars for sale', 'cart' => 'Your reservation', 'checkout' => 'Reserve your car' ] as $page => $title ) {
	$id = wc_get_page_id( $page );
	if ( $id > 0 ) wp_update_post( [ 'ID' => $id, 'post_title' => $title ] );
}

update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules();

// Skip WooCommerce's first-run redirect and setup checklist so the admin opens on the store itself.
delete_transient( '_wc_activation_redirect' );
update_option( 'woocommerce_task_list_hidden_lists', [ 'setup', 'extended' ] );
update_option( 'woocommerce_task_list_complete', 'yes' );
update_option( 'woocommerce_show_marketplace_suggestions', 'no' );
update_option( 'woocommerce_admin_install_timestamp', time() - WEEK_IN_SECONDS );
