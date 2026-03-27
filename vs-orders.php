<?php
/**
 * Plugin Name: Visit Sicily NYC — Orders
 * Description: Weekly delivery ordering system with admin dashboard, Stripe payments, order tracking, print tickets, messaging, and subscriber management.
 * Version: 1.4.0
 * Author: Johel (Technical Consultant)
 * Text Domain: vs-orders
 * Requires PHP: 7.4
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'VSO_VERSION', '1.4.0' );
define( 'VSO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VSO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once VSO_PLUGIN_DIR . 'includes/cpt-products.php';
require_once VSO_PLUGIN_DIR . 'includes/cpt-orders.php';
require_once VSO_PLUGIN_DIR . 'includes/rest-api.php';
require_once VSO_PLUGIN_DIR . 'includes/shortcode.php';
require_once VSO_PLUGIN_DIR . 'includes/emails.php';
require_once VSO_PLUGIN_DIR . 'includes/stripe.php';
require_once VSO_PLUGIN_DIR . 'includes/messaging.php';
require_once VSO_PLUGIN_DIR . 'includes/subscribers.php';

if ( is_admin() ) {
    require_once VSO_PLUGIN_DIR . 'admin/dashboard.php';
    require_once VSO_PLUGIN_DIR . 'admin/settings.php';
    require_once VSO_PLUGIN_DIR . 'admin/products-page.php';
    require_once VSO_PLUGIN_DIR . 'admin/categories-page.php';
    require_once VSO_PLUGIN_DIR . 'admin/orders-page.php';
    require_once VSO_PLUGIN_DIR . 'admin/messages-page.php';
    require_once VSO_PLUGIN_DIR . 'admin/wp-dashboard-widget.php';
}

/**
 * Ensure role and capabilities exist on every load (not just activation).
 * This prevents the plugin from breaking when files are replaced without reactivation.
 */
add_action( 'init', 'vso_ensure_capabilities' );
function vso_ensure_capabilities() {
    // Ensure admin has the capability
    $admin = get_role('administrator');
    if ( $admin && ! $admin->has_cap('manage_vso_orders') ) {
        $admin->add_cap('manage_vso_orders');
    }
    // Ensure delivery manager role exists
    if ( ! get_role('vso_delivery_manager') ) {
        add_role('vso_delivery_manager', 'Delivery Manager', [
            'read'              => true,
            'upload_files'      => true,
            'manage_vso_orders' => true,
        ]);
    }
}

/**
 * EARLY HOOK: Handle admin actions BEFORE headers are sent.
 */
add_action( 'admin_init', 'vso_handle_admin_actions' );
function vso_handle_admin_actions() {
    // Delivery & notification actions — delivery managers can do these
    if ( current_user_can('manage_vso_orders') ) {
        if ( isset($_GET['vso_toggle_delivery']) && wp_verify_nonce($_GET['_wpnonce']??'','vso_toggle_delivery') ) {
            $cur = get_option('vso_delivery_active','0');
            $new = $cur === '1' ? '0' : '1';
            update_option('vso_delivery_active', $new);
            if ($new === '1') update_option('vso_current_order_count', 0);
            wp_safe_redirect( admin_url('admin.php?page=vs-settings&toggled=1') );
            exit;
        }
        if ( isset($_GET['vso_notify_subs']) && wp_verify_nonce($_GET['_wpnonce']??'','vso_notify_subs') ) {
            $sent = vso_notify_subscribers_delivery_open();
            set_transient('vso_notify_result', $sent, 30);
            wp_safe_redirect( admin_url('admin.php?page=vs-settings&notified=1') );
            exit;
        }
    }

    // Destructive actions — admin only
    if ( ! current_user_can('manage_options') ) return;
    // Delete all orders
    if ( isset($_GET['vso_reset_orders']) && wp_verify_nonce($_GET['_wpnonce']??'','vso_reset_orders') ) {
        $orders = get_posts(['post_type'=>'vs_order','posts_per_page'=>-1,'fields'=>'ids','post_status'=>'any']);
        foreach ($orders as $oid) wp_delete_post($oid, true);
        update_option('vso_current_order_count', 0);
        update_option('vso_last_order_seq', 0);
        // Clear messages table
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}vso_messages");
        set_transient('vso_reset_result', count($orders), 30);
        wp_safe_redirect( admin_url('admin.php?page=vs-settings&reset_orders=1') );
        exit;
    }
    // Delete all subscribers
    if ( isset($_GET['vso_reset_subs']) && wp_verify_nonce($_GET['_wpnonce']??'','vso_reset_subs') ) {
        $count = count(get_option('vso_subscribers',[]));
        update_option('vso_subscribers', []);
        delete_option('vso_last_subscriber_notify');
        set_transient('vso_reset_subs_result', $count, 30);
        wp_safe_redirect( admin_url('admin.php?page=vs-settings&reset_subs=1') );
        exit;
    }
    // Full factory reset
    if ( isset($_GET['vso_factory_reset']) && wp_verify_nonce($_GET['_wpnonce']??'','vso_factory_reset') ) {
        // Delete orders
        $orders = get_posts(['post_type'=>'vs_order','posts_per_page'=>-1,'fields'=>'ids','post_status'=>'any']);
        foreach ($orders as $oid) wp_delete_post($oid, true);
        // Clear messages
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}vso_messages");
        // Clear subscribers
        update_option('vso_subscribers', []);
        delete_option('vso_last_subscriber_notify');
        // Reset counters
        update_option('vso_current_order_count', 0);
        update_option('vso_last_order_seq', 0);
        wp_safe_redirect( admin_url('admin.php?page=vs-settings&factory_reset=1') );
        exit;
    }
}

register_activation_hook( __FILE__, function() {
    vso_register_product_cpt();
    vso_register_order_cpt();
    flush_rewrite_rules();
    vso_create_messages_table();

    // Create custom role: Delivery Manager
    remove_role('vso_delivery_manager'); // Clean slate
    add_role('vso_delivery_manager', 'Delivery Manager', [
        'read'              => true,
        'upload_files'      => true,
        'manage_vso_orders' => true,
    ]);

    // Grant capability to administrators too
    $admin = get_role('administrator');
    if ($admin) $admin->add_cap('manage_vso_orders');

    $defaults = [
        'vso_delivery_active'=>'0','vso_minimum_order'=>100,
        'vso_ubereats_url'=>'https://www.ubereats.com/store/visit-sicily-86-east-7th-street/IyYwTzfDX9eSRteM088nig',
        'vso_closed_message'=>"We're preparing the next delivery round! Subscribe to get notified when we come to your area.",
        'vso_how_it_works'=>json_encode([['title'=>'Check the weekly area','desc'=>'We open deliveries for one area each week.'],['title'=>'Place your order','desc'=>'Order from the menu before the deadline.'],['title'=>'Personal delivery','desc'=>'Nicolas & Vincenzo drive to you, 11AM–7PM.'],['title'=>'We message you','desc'=>'We\'ll text before arrival.']]),
        'vso_checkout_note'=>'Nicolas & Vincenzo will deliver personally between 11AM–7PM. We\'ll message you before arrival.',
        'vso_confirm_title'=>'Grazie mille!','vso_confirm_message'=>'Your order has been received! We\'ll message you before arrival.',
        'vso_how_note'=>'Minimum order: ${{minimum}} · Areas change weekly · Orders close at capacity',
        'vso_how_title'=>'How Our Delivery Works',
    ];
    foreach ($defaults as $k=>$v) { if (get_option($k)===false) update_option($k,$v); }
    if (wp_count_posts('vs_product')->publish==0) vso_seed_products();
});

register_deactivation_hook( __FILE__, function() {
    flush_rewrite_rules();
    // Note: Role 'vso_delivery_manager' is kept on deactivation so users don't lose access.
    // To fully remove, use uninstall.php or delete the role manually.
});

/* ─── Seed products (same as before, abbreviated) ─── */
function vso_seed_products() {
    $products = [
        ['Sicilian Scramble','Scrambled eggs, caciocavallo cheese, oregano',7,'breakfast'],['Egg alla Norma','Eggs, fried eggplant, tomato sauce, dry sicilian ricotta',9,'breakfast'],['Palermo Sausage e Egg','Fried egg, sicilian sausage, caramelized onion',10,'breakfast'],['Mini Frittata Slice','Eggs, onion, potato, with mixed green',8,'breakfast'],['Crudo and Cheese','Prosciutto di Parma, caciocavallo cheese',8,'breakfast'],
        ['Polpette','Beef and pork polpette with tomato sauce and Parmigiano',15,'antipasti'],['Parmigiana','Eggplant parmigiana, tomato sauce, mozzarella',15,'antipasti'],['Caponata','Eggplant, tomato sauce, celery, onion, olives, capers',12,'antipasti'],['Burrata e Prosciutto','Burrata cheese, prosciutto di Parma, arugula',15,'antipasti'],
        ['Rucola','Organic arugula, cherry tomatoes, shaved cheese',12,'salad'],['Mix Green','Organic mixed green, onion, cucumber, cherry tomatoes',12,'salad'],['Caprese','Fresh mozzarella, organic slice tomato, basil',12,'salad'],
        ['Spaghetti Pomodoro','Tomato sauce',16,'pasta'],['Tagliatelle Bolognese','Sicilian old style beef ragù sauce',19,'pasta'],['Penne alla Norma','Tomato sauce, eggplant, sicilian dry ricotta',19,'pasta'],['Rigatoni Boscaiola','Beef ragù with ham, peas in cream sauce',19,'pasta'],['Fusilli Salmone','Smoked salmon in light pink cream sauce',20,'pasta'],['Busiate Trapanese','Cherry tomatoes, almond, basil, sicilian pecorino',18,'pasta'],['Truffle Ravioli','Mushroom mini ravioli in truffle cream sauce',24,'pasta'],
        ['Lasagna Bolognese','Beef ragù, parmigiano cheese and béchamel sauce',18,'pasta-al-forno'],['Anelletti Siciliani','Baked ring pasta with ragù, eggplant, mozzarella, peas',18,'pasta-al-forno'],['Lasagna Vegetarian','Season vegetables, mozzarella, parmigiano, béchamel',18,'pasta-al-forno'],
        ['Focaccia Calia Farm','Grilled organic vegetables, arugula, EVOO Casa Calia',15,'focacce'],['Focaccia Cotto Rovagnati','Imported ham, fresh mozzarella, tomato, mixed green',16,'focacce'],['Focaccia Prosciutto','Prosciutto di Parma, fresh mozzarella, tomato, arugula',16,'focacce'],['Focaccia Roast Beef','Roast beef, arugula, parmigiano cheese, balsamic glaze',18,'focacce'],['Focaccia Pistacchiosa','Mortadella, stracciatella cheese, pesto pistachio',16,'focacce'],
        ['Panelle e Crocchè','Crispy chickpea fritters, potato croquettes, lemon sauce',12,'panini'],['Panino Favignana','Sicilian tuna, onion, tomatoes, mayo',15,'panini'],['Pollo Milanese','Breaded chicken cutlet, slice tomatoes, mixed green, mayo',15,'panini'],
        ['Pizza Margherita','Tomato sauce, mozzarella',5,'pizza-rosticceria'],['Pizza Patate','Mozzarella, potato, rosemary',7,'pizza-rosticceria'],['Pizza Cotto e Burrata','Imported italian ham, burrata cheese, tomato sauce',12,'pizza-rosticceria'],['Rosticceria Palermitana','Daily selection',7,'pizza-rosticceria'],['Arancina a Carne','Rice ball with beef ragù sauce, green peas',9.5,'pizza-rosticceria'],['Arancina a Burro','Rice ball with italian ham, mozzarella, béchamel',9.5,'pizza-rosticceria'],['Arancina Vegetariana','Rice ball with mushroom, spinach, mozzarella',9.5,'pizza-rosticceria'],
        ['Croissant','Plain or filled',5,'dolci'],['Bomboloni','Italian filled doughnut',6.5,'dolci'],['Cannoli','Sicilian cannoli, ricotta filling',5.5,'dolci'],['Cassata al Forno','Baked Sicilian cassata',9,'dolci'],['Cassata Siciliana','Traditional Sicilian cassata',9,'dolci'],['Tiramisù','Classic recipe, mascarpone & espresso',9,'dolci'],['Cheesecake','House style cheesecake',9,'dolci'],
        ['Espresso','Single or double shot',3.75,'caffe'],['Macchiato','Espresso macchiato',4,'caffe'],['Cortado','Espresso cortado',4.5,'caffe'],['Cappuccino','Classic cappuccino',5,'caffe'],['Latte','Caffè latte',5,'caffe'],['Americano','Caffè americano',4,'caffe'],['Special Cappuccino','Pistacchioso, Nutelloso, or Mandorloso',8.5,'caffe'],
        ['Brewed Coffee','Drip coffee',4,'beverages'],['Hot Chocolate','Italian hot chocolate',6,'beverages'],['Ice Coffee','Iced coffee or iced latte',4.5,'beverages'],['Soda / Water','Soft drink or mineral water',2.5,'beverages'],['Estathé','Italian iced tea',5,'beverages'],['Chinotto','Italian bitter citrus soda',5,'beverages'],
    ];
    $labels = ['breakfast'=>'Breakfast','antipasti'=>'Antipasti','salad'=>'Salad','pasta'=>'Fresh Pasta','pasta-al-forno'=>'Pasta al Forno','focacce'=>'Focacce','panini'=>'Panini','pizza-rosticceria'=>'Pizza & Rosticceria','dolci'=>'Dolci','caffe'=>'Caffè','beverages'=>'Beverages'];
    foreach ($products as $i=>$p) {
        $pid=wp_insert_post(['post_type'=>'vs_product','post_title'=>$p[0],'post_status'=>'publish','menu_order'=>$i]);
        if(!is_wp_error($pid)){update_post_meta($pid,'_vso_description',$p[1]);update_post_meta($pid,'_vso_price',$p[2]);update_post_meta($pid,'_vso_active','1');
            $term=term_exists($p[3],'vs_product_cat');if(!$term)$term=wp_insert_term($labels[$p[3]]??$p[3],'vs_product_cat',['slug'=>$p[3]]);if(!is_wp_error($term))wp_set_object_terms($pid,(int)($term['term_id']??$term),'vs_product_cat');}
    }
}
