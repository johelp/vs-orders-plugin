<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'init', 'vso_register_product_cpt' );

function vso_register_product_cpt() {
    register_post_type( 'vs_product', [
        'labels' => [
            'name'          => 'Menu Products',
            'singular_name' => 'Product',
            'add_new_item'  => 'Add New Product',
            'edit_item'     => 'Edit Product',
        ],
        'public'       => false,
        'show_ui'      => false, // We use custom admin pages
        'show_in_rest' => false,
        'supports'     => [ 'title', 'thumbnail' ],
        'menu_icon'    => 'dashicons-food',
        'has_archive'  => false,
    ]);

    register_taxonomy( 'vs_product_cat', 'vs_product', [
        'labels' => [
            'name'          => 'Menu Categories',
            'singular_name' => 'Category',
        ],
        'public'       => false,
        'show_ui'      => false,
        'hierarchical' => true,
        'show_in_rest' => false,
    ]);
}
