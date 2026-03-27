<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'init', 'vso_register_order_cpt' );

function vso_register_order_cpt() {
    register_post_type( 'vs_order', [
        'labels' => [
            'name'          => 'Orders',
            'singular_name' => 'Order',
        ],
        'public'       => false,
        'show_ui'      => false,
        'show_in_rest' => false,
        'supports'     => [ 'title' ],
        'has_archive'  => false,
    ]);
}

/**
 * Generate a unique order reference: VS-YYMMDD-XXXX
 */
function vso_generate_order_ref() {
    $prefix = 'VS-' . date('ymd') . '-';
    $last = get_option( 'vso_last_order_seq', 0 );
    $seq = $last + 1;
    update_option( 'vso_last_order_seq', $seq );
    return $prefix . str_pad( $seq, 4, '0', STR_PAD_LEFT );
}

/**
 * Create an order post and return order data
 */
function vso_create_order( $data ) {
    $ref = vso_generate_order_ref();

    $post_id = wp_insert_post([
        'post_type'   => 'vs_order',
        'post_title'  => $ref,
        'post_status' => 'publish',
    ]);

    if ( is_wp_error( $post_id ) ) {
        return $post_id;
    }

    // Store order meta
    update_post_meta( $post_id, '_vso_ref', $ref );
    update_post_meta( $post_id, '_vso_status', 'new' ); // new, confirmed, preparing, delivered, cancelled
    update_post_meta( $post_id, '_vso_items', $data['items'] ); // array of {id, name, price, qty}
    update_post_meta( $post_id, '_vso_subtotal', $data['subtotal'] );
    update_post_meta( $post_id, '_vso_total', $data['total'] );

    // Customer info
    update_post_meta( $post_id, '_vso_customer_name', sanitize_text_field( $data['name'] ) );
    update_post_meta( $post_id, '_vso_customer_email', sanitize_email( $data['email'] ) );
    update_post_meta( $post_id, '_vso_customer_phone', sanitize_text_field( $data['phone'] ) );
    update_post_meta( $post_id, '_vso_customer_address', sanitize_textarea_field( $data['address'] ?? '' ) );
    update_post_meta( $post_id, '_vso_customer_zone', sanitize_text_field( $data['zone'] ) );
    update_post_meta( $post_id, '_vso_customer_notes', sanitize_textarea_field( $data['notes'] ?? '' ) );

    // Payment
    update_post_meta( $post_id, '_vso_payment_status', $data['payment_status'] ?? 'pending' ); // pending, paid, refunded
    update_post_meta( $post_id, '_vso_stripe_payment_intent', $data['stripe_pi'] ?? '' );

    // Timestamps
    update_post_meta( $post_id, '_vso_created_at', current_time( 'mysql' ) );

    return [
        'order_id' => $post_id,
        'ref'      => $ref,
    ];
}

/**
 * Get order statuses with labels and colors
 */
function vso_order_statuses() {
    return [
        'new'       => [ 'label' => 'New',       'color' => '#3498db', 'bg' => '#ebf5fb' ],
        'confirmed' => [ 'label' => 'Confirmed', 'color' => '#f39c12', 'bg' => '#fef9e7' ],
        'preparing' => [ 'label' => 'Preparing', 'color' => '#e67e22', 'bg' => '#fdf2e9' ],
        'delivered' => [ 'label' => 'Delivered', 'color' => '#27ae60', 'bg' => '#eafaf1' ],
        'cancelled' => [ 'label' => 'Cancelled', 'color' => '#e74c3c', 'bg' => '#fdedec' ],
    ];
}
