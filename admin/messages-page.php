<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Messages page is integrated into the Order Detail view.
// This file is kept for potential future standalone messages dashboard.

function vso_admin_messages_page() {
    // This functionality is embedded in the order detail view.
    // Redirect to orders list.
    wp_redirect( admin_url('admin.php?page=vs-orders-list') );
    exit;
}
