<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_dashboard_setup', 'vso_add_dashboard_widget' );

function vso_add_dashboard_widget() {
    if ( ! current_user_can('manage_vso_orders') ) return;
    wp_add_dashboard_widget(
        'vso_dashboard_widget',
        '🇮🇹 Visit Sicily — Orders',
        'vso_dashboard_widget_render'
    );
    // Move to top
    global $wp_meta_boxes;
    $widget = $wp_meta_boxes['dashboard']['normal']['core']['vso_dashboard_widget'] ?? null;
    if ($widget) {
        unset($wp_meta_boxes['dashboard']['normal']['core']['vso_dashboard_widget']);
        $wp_meta_boxes['dashboard']['side']['high']['vso_dashboard_widget'] = $widget;
    }
}

function vso_dashboard_widget_render() {
    $del_active = get_option('vso_delivery_active','0');
    $weekly_zone = get_option('vso_weekly_zone','');
    $all_orders = get_posts(['post_type'=>'vs_order','posts_per_page'=>-1,'fields'=>'ids']);
    $today = date('Y-m-d');
    $today_count = 0; $today_rev = 0; $new_count = 0;
    foreach ($all_orders as $oid) {
        $st = get_post_meta($oid,'_vso_status',true);
        $total = (float)get_post_meta($oid,'_vso_total',true);
        $created = get_post_meta($oid,'_vso_created_at',true);
        if ($st === 'new') $new_count++;
        if (substr($created,0,10)===$today && $st!=='cancelled') { $today_count++; $today_rev+=$total; }
    }
    $subs = get_option('vso_subscribers',[]);
    $nonce_url = wp_nonce_url(admin_url('admin.php?page=vs-settings&vso_toggle_delivery=1'),'vso_toggle_delivery');
    ?>
    <style>
        .vso-dw{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;margin:-12px;padding:0}
        .vso-dw .status-bar{padding:12px;display:flex;align-items:center;gap:8px;border-bottom:1px solid #eee}
        .vso-dw .status-bar .dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
        .vso-dw .status-bar .dot.on{background:#27ae60;box-shadow:0 0 6px rgba(39,174,96,.4)}
        .vso-dw .status-bar .dot.off{background:#e74c3c}
        .vso-dw .status-bar .txt{flex:1;font-size:13px;font-weight:600}
        .vso-dw .status-bar .txt.on{color:#27ae60}
        .vso-dw .status-bar .txt.off{color:#e74c3c}
        .vso-dw .status-bar a{font-size:11px;padding:4px 10px;border-radius:4px;text-decoration:none;color:#fff;font-weight:600}
        .vso-dw .stats{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:#eee;border-bottom:1px solid #eee}
        .vso-dw .stats .s{background:#fff;padding:12px 8px;text-align:center}
        .vso-dw .stats .s .n{font-size:20px;font-weight:700;color:#2C1810}
        .vso-dw .stats .s .n.gold{color:#D4AF37}
        .vso-dw .stats .s .l{font-size:9px;color:#8B7355;text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
        .vso-dw .zone{padding:10px 12px;font-size:12px;color:#4A2C20;border-bottom:1px solid #eee}
        .vso-dw .links{padding:10px 12px;display:flex;gap:6px;flex-wrap:wrap}
        .vso-dw .links a{font-size:12px;text-decoration:none;color:#D4AF37;font-weight:600}
        .vso-dw .links a:hover{text-decoration:underline}
    </style>
    <div class="vso-dw">
        <div class="status-bar">
            <span class="dot <?php echo $del_active==='1'?'on':'off';?>"></span>
            <span class="txt <?php echo $del_active==='1'?'on':'off';?>"><?php echo $del_active==='1'?'Delivery OPEN':'Delivery CLOSED';?></span>
            <a href="<?php echo $nonce_url;?>" style="background:<?php echo $del_active==='1'?'#e74c3c':'#27ae60';?>"><?php echo $del_active==='1'?'Pause':'Open';?></a>
        </div>
        <?php if ($del_active==='1' && $weekly_zone): ?>
        <div class="zone">📍 <?php echo esc_html($weekly_zone);?></div>
        <?php endif; ?>
        <div class="stats">
            <div class="s"><div class="n gold"><?php echo (int)$today_count;?></div><div class="l">Today</div></div>
            <div class="s"><div class="n gold">$<?php echo esc_html(number_format($today_rev,0));?></div><div class="l">Revenue</div></div>
            <div class="s"><div class="n" style="color:<?php echo (int)$new_count?'#e74c3c':'#27ae60';?>"><?php echo (int)$new_count;?></div><div class="l">Pending</div></div>
        </div>
        <div class="stats" style="grid-template-columns:1fr 1fr;">
            <div class="s"><div class="n"><?php echo (int)count($all_orders);?></div><div class="l">Total Orders</div></div>
            <div class="s"><div class="n"><?php echo (int)count($subs);?></div><div class="l">Subscribers</div></div>
        </div>
        <div class="links">
            <a href="<?php echo esc_url(admin_url('admin.php?page=vs-orders-list'));?>">📋 Orders</a> ·
            <a href="<?php echo esc_url(admin_url('admin.php?page=vs-products'));?>">🍽️ Products</a> ·
            <a href="<?php echo esc_url(admin_url('admin.php?page=vs-settings'));?>">⚙️ Settings</a>
        </div>
    </div>
    <?php
}
