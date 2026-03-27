<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'vso_admin_menu' );

function vso_admin_menu() {
    $cap = 'manage_vso_orders';
    add_menu_page('Visit Sicily Orders','Sicily Orders',$cap,'vs-orders','vso_admin_dashboard_page','dashicons-food',26);
    add_submenu_page('vs-orders','Dashboard','Dashboard',$cap,'vs-orders','vso_admin_dashboard_page');
    add_submenu_page('vs-orders','Orders','Orders',$cap,'vs-orders-list','vso_admin_orders_page');
    add_submenu_page('vs-orders','Products','Products',$cap,'vs-products','vso_admin_products_page');
    add_submenu_page('vs-orders','Categories','Categories',$cap,'vs-categories','vso_admin_categories_page');
    add_submenu_page('vs-orders','Settings','Settings',$cap,'vs-settings','vso_admin_settings_page');
}

function vso_admin_dashboard_page() {
    $all_orders = get_posts(['post_type'=>'vs_order','posts_per_page'=>-1,'fields'=>'ids']);
    $today = date('Y-m-d');
    $today_orders=0;$today_revenue=0;$total_revenue=0;
    $status_counts=['new'=>0,'confirmed'=>0,'preparing'=>0,'delivered'=>0,'cancelled'=>0];
    foreach ($all_orders as $oid) {
        $st=get_post_meta($oid,'_vso_status',true);$total=(float)get_post_meta($oid,'_vso_total',true);
        $created=get_post_meta($oid,'_vso_created_at',true);
        if(isset($status_counts[$st]))$status_counts[$st]++;
        if($st!=='cancelled')$total_revenue+=$total;
        if(substr($created,0,10)===$today&&$st!=='cancelled'){$today_orders++;$today_revenue+=$total;}
    }
    $active_products=count(get_posts(['post_type'=>'vs_product','posts_per_page'=>-1,'meta_key'=>'_vso_active','meta_value'=>'1','fields'=>'ids']));
    $statuses=vso_order_statuses();
    $delivery_active=get_option('vso_delivery_active','0');
    $weekly_zone=get_option('vso_weekly_zone','');
    $recent=get_posts(['post_type'=>'vs_order','posts_per_page'=>5,'orderby'=>'date','order'=>'DESC']);
    ?>
    <div class="wrap" style="max-width:1100px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
        <h1 style="font-size:24px;font-weight:700;color:#2C1810;margin-bottom:16px;">🇮🇹 Visit Sicily NYC — Dashboard</h1>

        <!-- Delivery toggle -->
        <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:8px;margin-bottom:16px;background:<?php echo esc_attr($delivery_active==='1'?'#eafaf1':'#fdedec');?>;border:1.5px solid <?php echo esc_attr($delivery_active==='1'?'#27ae60':'#e74c3c');?>;">
            <span style="font-size:14px;font-weight:700;color:<?php echo esc_attr($delivery_active==='1'?'#27ae60':'#e74c3c');?>;flex:1;">
                <?php echo $delivery_active==='1'?'🟢 Delivery OPEN':'🔴 Delivery CLOSED';?>
                <?php if($delivery_active==='1'&&$weekly_zone):?><span style="font-weight:400;color:#4A2C20;margin-left:8px;">— <?php echo esc_html($weekly_zone);?></span><?php endif;?>
            </span>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=vs-settings&vso_toggle_delivery=1'),'vso_toggle_delivery'));?>"
               style="padding:6px 14px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;color:#fff;background:<?php echo esc_attr($delivery_active==='1'?'#e74c3c':'#27ae60');?>;">
                <?php echo $delivery_active==='1'?'⏸ Pause':'▶ Open';?>
            </a>
        </div>

        <!-- Stats -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:20px;">
            <?php foreach([[''.$today_orders,'Orders Today',true],['$'.number_format($today_revenue,2),'Revenue Today',true],[count($all_orders),'Total Orders',false],['$'.number_format($total_revenue,2),'Total Revenue',false],[$active_products,'Active Products',false]] as $s): ?>
            <div style="background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:14px;text-align:center;">
                <div style="font-size:24px;font-weight:700;color:<?php echo $s[2]?'#D4AF37':'#2C1810';?>"><?php echo esc_html($s[0]);?></div>
                <div style="font-size:10px;color:#8B7355;text-transform:uppercase;letter-spacing:1px;margin-top:2px;"><?php echo esc_html($s[1]);?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Status chips -->
        <div style="display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap;">
            <?php foreach($status_counts as $sk=>$sc):$si=$statuses[$sk];?>
            <span style="padding:4px 12px;border-radius:12px;font-size:12px;font-weight:600;background:<?php echo esc_attr($si['bg']);?>;color:<?php echo esc_attr($si['color']);?>;"><?php echo esc_html($si['label']);?>: <?php echo (int)$sc;?></span>
            <?php endforeach; ?>
        </div>

        <!-- Recent orders -->
        <div style="background:#fff;border:1px solid #e5e5e5;border-radius:8px;overflow:hidden;">
            <div style="padding:12px 14px;border-bottom:1px solid #e5e5e5;font-size:12px;font-weight:600;color:#8B7355;text-transform:uppercase;letter-spacing:1px;">Recent Orders</div>
            <?php if(empty($recent)):?>
            <p style="padding:20px;text-align:center;color:#8B7355;">No orders yet.</p>
            <?php else: foreach($recent as $o):
                $ref=get_post_meta($o->ID,'_vso_ref',true);$name=get_post_meta($o->ID,'_vso_customer_name',true);
                $zone=get_post_meta($o->ID,'_vso_customer_zone',true);$total=get_post_meta($o->ID,'_vso_total',true);
                $st=get_post_meta($o->ID,'_vso_status',true);$pay=get_post_meta($o->ID,'_vso_payment_status',true);
                $date=get_post_meta($o->ID,'_vso_created_at',true);$si=$statuses[$st]??$statuses['new'];
            ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=vs-orders-list&view='.$o->ID));?>" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #f5f3f0;text-decoration:none;color:#2C1810;">
                <div style="flex:1;">
                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                        <span style="font-weight:700;font-size:13px;"><?php echo esc_html($ref);?></span>
                        <span style="padding:2px 8px;border-radius:8px;font-size:10px;font-weight:600;background:<?php echo esc_attr($si['bg']);?>;color:<?php echo esc_attr($si['color']);?>;"><?php echo esc_html($si['label']);?></span>
                        <span style="padding:2px 8px;border-radius:8px;font-size:10px;font-weight:600;background:<?php echo esc_attr($pay==='paid'?'#eafaf1':'#fef9e7');?>;color:<?php echo esc_attr($pay==='paid'?'#27ae60':'#f39c12');?>;"><?php echo esc_html(ucfirst($pay?:'pending'));?></span>
                    </div>
                    <div style="font-size:12px;color:#8B7355;margin-top:2px;"><?php echo esc_html($name);?> · <?php echo esc_html(wp_trim_words($zone,3,'…'));?> · <?php echo $date?esc_html(date('M j, g:ia',strtotime($date))):'';?></div>
                </div>
                <div style="font-weight:700;font-size:14px;">$<?php echo esc_html(number_format((float)$total,2));?></div>
            </a>
            <?php endforeach; endif; ?>
        </div>
        <p style="margin-top:12px;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=vs-orders-list'));?>" class="button button-primary">View All Orders →</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=vs-products'));?>" class="button" style="margin-left:6px;">Manage Products</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=vs-categories'));?>" class="button" style="margin-left:6px;">Categories</a>
        </p>
    </div>
    <?php
}
