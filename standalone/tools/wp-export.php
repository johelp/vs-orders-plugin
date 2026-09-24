<?php
/**
 * Exports VS Orders data from a WordPress site into SQL for the standalone (Cloudflare D1) version.
 *
 * On the WordPress server (WP-CLI):
 *   wp eval-file wp-export.php > vs-orders-export.sql
 * Then, from standalone/:
 *   npx wrangler d1 execute vs-orders --remote --file vs-orders-export.sql
 *
 * The SQL REPLACES categories, products, orders, messages and subscribers in D1, and updates settings.
 * Stripe / MailerLite keys are NOT exported: set them as Cloudflare secrets (see README).
 * Product images keep pointing at their WordPress URLs until re-uploaded from the new admin panel.
 */
if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Run inside WordPress: wp eval-file wp-export.php > vs-orders-export.sql\n" );
    exit( 1 );
}

global $wpdb;

$q = function ( $v ) {
    if ( $v === null ) return 'NULL';
    if ( is_int( $v ) || is_float( $v ) ) return (string) $v;
    return "'" . str_replace( "'", "''", (string) $v ) . "'";
};
$txt = function ( $v ) {
    return html_entity_decode( wp_specialchars_decode( (string) $v, ENT_QUOTES ), ENT_QUOTES, 'UTF-8' );
};
$utc = function ( $local ) {
    return $local ? get_gmt_from_date( $local, 'Y-m-d\TH:i:s\Z' ) : gmdate( 'Y-m-d\TH:i:s\Z' );
};
$lines = [];
$emit  = function ( $sql ) use ( &$lines ) { $lines[] = $sql; };

$emit( '-- VS Orders export from ' . home_url() . ' on ' . gmdate( 'c' ) );
$emit( 'DELETE FROM order_items;' );
$emit( 'DELETE FROM messages;' );
$emit( 'DELETE FROM orders;' );
$emit( 'DELETE FROM products;' );
$emit( 'DELETE FROM categories;' );
$emit( 'DELETE FROM subscribers;' );

// ─── Categories ───
$terms = get_terms( [ 'taxonomy' => 'vs_product_cat', 'hide_empty' => false ] );
$terms = is_wp_error( $terms ) ? [] : $terms;
usort( $terms, function ( $a, $b ) {
    $oa = get_term_meta( $a->term_id, '_vso_order', true );
    $ob = get_term_meta( $b->term_id, '_vso_order', true );
    $oa = $oa === '' ? PHP_INT_MAX : (int) $oa;
    $ob = $ob === '' ? PHP_INT_MAX : (int) $ob;
    return $oa === $ob ? $a->term_id - $b->term_id : $oa - $ob;
} );
foreach ( $terms as $i => $t ) {
    $emit( sprintf(
        'INSERT INTO categories (id, slug, name, icon, sort_order) VALUES (%d, %s, %s, %s, %d);',
        $t->term_id, $q( $t->slug ), $q( $txt( $t->name ) ), $q( (string) get_term_meta( $t->term_id, '_vso_icon', true ) ), $i
    ) );
}

// ─── Products ───
$products = get_posts( [ 'post_type' => 'vs_product', 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => [ 'menu_order' => 'ASC', 'ID' => 'ASC' ] ] );
$product_ids = [];
foreach ( $products as $i => $p ) {
    $product_ids[ $p->ID ] = true;
    $cats  = wp_get_object_terms( $p->ID, 'vs_product_cat' );
    $cat   = ( ! is_wp_error( $cats ) && ! empty( $cats ) ) ? (int) $cats[0]->term_id : null;
    $image = get_the_post_thumbnail_url( $p->ID, 'large' ) ?: '';
    if ( $image && strpos( $image, 'http://' ) === 0 ) $image = 'https://' . substr( $image, 7 );
    $emit( sprintf(
        'INSERT INTO products (id, category_id, name, description, price, image_url, active, sort_order) VALUES (%d, %s, %s, %s, %s, %s, %d, %d);',
        $p->ID, $q( $cat ), $q( $txt( $p->post_title ) ), $q( $txt( get_post_meta( $p->ID, '_vso_description', true ) ) ),
        $q( round( (float) get_post_meta( $p->ID, '_vso_price', true ), 2 ) ), $q( $image ),
        get_post_meta( $p->ID, '_vso_active', true ) === '1' && $p->post_status === 'publish' ? 1 : 0, $i
    ) );
}

// ─── Orders + items ───
$statuses  = [ 'new', 'confirmed', 'preparing', 'delivered', 'cancelled' ];
$payments  = [ 'pending', 'paid', 'refunded' ];
$seen_pi   = [];
$order_ids = [];
$orders    = get_posts( [ 'post_type' => 'vs_order', 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ] );
foreach ( $orders as $o ) {
    $m   = function ( $k ) use ( $o ) { return get_post_meta( $o->ID, $k, true ); };
    $ref = $m( '_vso_ref' ) ?: $o->post_title;
    $st  = in_array( $m( '_vso_status' ), $statuses, true ) ? $m( '_vso_status' ) : 'new';
    $pay = in_array( $m( '_vso_payment_status' ), $payments, true ) ? $m( '_vso_payment_status' ) : 'pending';
    $pi  = trim( (string) $m( '_vso_stripe_payment_intent' ) );
    if ( $pi === '' || isset( $seen_pi[ $pi ] ) ) $pi = null; else $seen_pi[ $pi ] = true;
    $created = $utc( $m( '_vso_created_at' ) ?: $o->post_date );
    $order_ids[ $o->ID ] = true;
    $emit( sprintf(
        'INSERT INTO orders (id, ref, status, subtotal, total, customer_name, customer_email, customer_phone, customer_address, customer_zone, notes, payment_status, stripe_payment_intent, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s);',
        $o->ID, $q( $ref ), $q( $st ), $q( (float) $m( '_vso_subtotal' ) ?: (float) $m( '_vso_total' ) ), $q( (float) $m( '_vso_total' ) ),
        $q( $m( '_vso_customer_name' ) ), $q( strtolower( (string) $m( '_vso_customer_email' ) ) ), $q( $m( '_vso_customer_phone' ) ),
        $q( $m( '_vso_customer_address' ) ), $q( $m( '_vso_customer_zone' ) ), $q( $m( '_vso_customer_notes' ) ),
        $q( $pay ), $q( $pi ), $q( $created ), $q( $created )
    ) );
    $items = $m( '_vso_items' );
    foreach ( is_array( $items ) ? $items : [] as $it ) {
        $pid = isset( $it['id'] ) && isset( $product_ids[ (int) $it['id'] ] ) ? (int) $it['id'] : null;
        $emit( sprintf(
            'INSERT INTO order_items (order_id, product_id, name, price, qty) VALUES (%d, %s, %s, %s, %d);',
            $o->ID, $q( $pid ), $q( $txt( $it['name'] ?? '' ) ), $q( (float) ( $it['price'] ?? 0 ) ), max( 1, (int) ( $it['qty'] ?? 1 ) )
        ) );
    }
}

// ─── Messages ───
$table = $wpdb->prefix . 'vso_messages';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
    foreach ( $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id", ARRAY_A ) as $msg ) {
        if ( ! isset( $order_ids[ (int) $msg['order_id'] ] ) ) continue;
        $emit( sprintf(
            'INSERT INTO messages (order_id, direction, channel, subject, body, sender, message_id, status, created_at, read_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s);',
            $msg['order_id'], $q( $msg['direction'] === 'incoming' ? 'incoming' : 'outgoing' ), $q( $msg['channel'] ?: 'email' ),
            $q( $msg['subject'] ), $q( $msg['body'] ), $q( $msg['sender'] ), $q( $msg['message_id'] ), $q( $msg['status'] ?: 'sent' ),
            $q( $utc( $msg['created_at'] ) ), $q( $msg['read_at'] ? $utc( $msg['read_at'] ) : null )
        ) );
    }
}

// ─── Subscribers ───
$seen_email = [];
foreach ( (array) get_option( 'vso_subscribers', [] ) as $s ) {
    $email = strtolower( trim( $s['email'] ?? '' ) );
    if ( ! is_email( $email ) || isset( $seen_email[ $email ] ) ) continue;
    $seen_email[ $email ] = true;
    $emit( sprintf(
        'INSERT INTO subscribers (email, name, zone, created_at) VALUES (%s, %s, %s, %s);',
        $q( $email ), $q( $s['name'] ?? '' ), $q( $s['zone'] ?? '' ), $q( $utc( isset( $s['date'] ) ? $s['date'] . ':00' : '' ) )
    ) );
}

// ─── Settings ───
$map = [
    'vso_delivery_active'     => 'delivery_active',
    'vso_weekly_zone'         => 'weekly_zone',
    'vso_weekly_delivery_day' => 'weekly_delivery_day',
    'vso_weekly_deadline'     => 'weekly_deadline',
    'vso_minimum_order'       => 'minimum_order',
    'vso_max_orders'          => 'max_orders',
    'vso_current_order_count' => 'current_order_count',
    'vso_last_order_seq'      => 'last_order_seq',
    'vso_ubereats_url'        => 'ubereats_url',
    'vso_closed_message'      => 'closed_message',
    'vso_how_title'           => 'how_title',
    'vso_how_it_works'        => 'how_steps',
    'vso_how_note'            => 'how_note',
    'vso_checkout_note'       => 'checkout_note',
    'vso_confirm_title'       => 'confirm_title',
    'vso_confirm_message'     => 'confirm_message',
    'vso_admin_email'         => 'admin_email',
    'vso_stripe_mode'         => 'stripe_mode',
    'vso_mailerlite_group_id' => 'mailerlite_group_id',
];
foreach ( $map as $wp_key => $key ) {
    $v = get_option( $wp_key, null );
    if ( $v === null || $v === false ) continue;
    if ( $key === 'how_note' ) $v = str_replace( '${{minimum}}', '{{minimum}}', $v );
    $emit( sprintf( 'INSERT INTO settings (key, value) VALUES (%s, %s) ON CONFLICT(key) DO UPDATE SET value = excluded.value;', $q( $key ), $q( (string) $v ) ) );
}
$last = get_option( 'vso_last_subscriber_notify', null );
if ( is_array( $last ) && isset( $last['date'] ) ) {
    $json = wp_json_encode( [ 'date' => $utc( $last['date'] . ':00' ), 'count' => (int) $last['count'], 'total' => (int) $last['total'] ] );
    $emit( sprintf( "INSERT INTO settings (key, value) VALUES ('last_subscriber_notify', %s) ON CONFLICT(key) DO UPDATE SET value = excluded.value;", $q( $json ) ) );
}

// ─── Panel users (WP administrators and Delivery Managers) ───
foreach ( get_users( [ 'role__in' => [ 'administrator', 'vso_delivery_manager' ] ] ) as $u ) {
    $role = in_array( 'administrator', (array) $u->roles, true ) ? 'admin' : 'manager';
    $emit( sprintf( 'INSERT OR IGNORE INTO admin_users (email, role) VALUES (%s, %s);', $q( strtolower( $u->user_email ) ), $q( $role ) ) );
}

$emit( sprintf(
    '-- Exported %d categories, %d products, %d orders, %d subscribers.',
    count( $terms ), count( $products ), count( $orders ), count( $seen_email )
) );
echo implode( "\n", $lines ) . "\n";
