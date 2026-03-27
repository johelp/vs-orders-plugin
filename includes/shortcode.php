<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'visit_sicily_order', 'vso_render_order_shortcode' );
add_shortcode( 'visit_sicily_status', 'vso_render_status_shortcode' );

function vso_render_order_shortcode( $atts ) {
    $stripe_pk = get_option('vso_stripe_publishable_key','');
    $stripe_enabled = vso_stripe_is_configured();
    $stripe_test = vso_stripe_is_test_mode();
    if ($stripe_enabled) wp_enqueue_script('stripe-js','https://js.stripe.com/v3/',[],null,true);
    $content = [
        'howTitle'       => get_option('vso_how_title','How Our Delivery Works'),
        'howSteps'       => json_decode(get_option('vso_how_it_works','[]'),true)?:[],
        'howNote'        => str_replace('{{minimum}}','$'.get_option('vso_minimum_order',100),get_option('vso_how_note','')),
        'checkoutNote'   => get_option('vso_checkout_note',''),
        'confirmTitle'   => get_option('vso_confirm_title','Grazie mille!'),
        'confirmMessage' => get_option('vso_confirm_message',''),
    ];
    ob_start(); ?>
    <div id="vso-order-app"></div>
    <script>
    window.VSO_CONFIG={apiBase:'<?php echo esc_url(rest_url('vs-order/v1'));?>',nonce:'<?php echo esc_attr(wp_create_nonce('wp_rest'));?>',stripeEnabled:<?php echo $stripe_enabled?'true':'false';?>,stripePk:'<?php echo esc_js($stripe_pk);?>',stripeTestMode:<?php echo $stripe_test?'true':'false';?>,content:<?php echo wp_json_encode($content);?>};
    </script>
    <?php include VSO_PLUGIN_DIR.'public/order-app.php';
    return ob_get_clean();
}

/**
 * Status-only shortcode: shows special delivery status + always-available Uber Eats
 */
function vso_render_status_shortcode( $atts ) {
    $active = get_option('vso_delivery_active','0') === '1';
    $zone = get_option('vso_weekly_zone','');
    $day = get_option('vso_weekly_delivery_day','');
    $deadline = get_option('vso_weekly_deadline','');
    $min = get_option('vso_minimum_order',100);
    $ue_url = get_option('vso_ubereats_url','');
    $closed_msg = get_option('vso_closed_message','');
    $max = (int)get_option('vso_max_orders',0);
    $cur = (int)get_option('vso_current_order_count',0);
    $at_cap = $max > 0 && $cur >= $max;

    $order_page = '';
    $pages = get_posts(['post_type'=>'page','posts_per_page'=>-1,'s'=>'visit_sicily_order']);
    if (!empty($pages)) $order_page = get_permalink($pages[0]->ID);

    ob_start();
    ?>
    <div class="vso-status-widget" style="font-family:'DM Sans',-apple-system,sans-serif;max-width:520px;margin:0 auto;">
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,700;1,400&family=DM+Sans:wght@400;600;700&display=swap');
            .vso-sw{border-radius:12px;overflow:hidden}

            /* Special delivery section */
            .vso-sw-special{padding:18px;position:relative}
            .vso-sw-special.open{background:linear-gradient(135deg,#f0faf3,#eafaf1);border:1.5px solid #27ae60}
            .vso-sw-special.closed{background:#fafafa;border:1.5px solid #ddd}
            .vso-sw-special.full{background:#fef9e7;border:1.5px solid #f39c12}

            .vso-sw .section-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:2px;margin-bottom:8px}
            .vso-sw .section-label.on{color:#27ae60}
            .vso-sw .section-label.off{color:#999}

            .vso-sw .sw-title{font-family:'Cormorant Garamond',serif;font-size:20px;font-weight:700;color:#2C1810;margin:0 0 2px;line-height:1.2}
            .vso-sw .sw-subtitle{font-size:13px;color:#8B7355;margin-bottom:10px;line-height:1.4;font-style:italic;font-family:'Cormorant Garamond',serif}

            .vso-sw .status-line{display:flex;align-items:center;gap:8px;margin-bottom:8px}
            .vso-sw .dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
            .vso-sw .dot.on{background:#27ae60;box-shadow:0 0 8px rgba(39,174,96,.4);animation:vso-pulse 2s infinite}
            .vso-sw .dot.off{background:#ccc}
            .vso-sw .dot.full{background:#f39c12}
            @keyframes vso-pulse{0%,100%{opacity:1}50%{opacity:.5}}

            .vso-sw .status-text{font-size:14px;font-weight:700}
            .vso-sw .status-text.on{color:#27ae60}
            .vso-sw .status-text.off{color:#999}
            .vso-sw .status-text.full{color:#f39c12}

            .vso-sw .zone-name{font-family:'Cormorant Garamond',serif;font-size:18px;font-weight:700;color:#2C1810;margin-bottom:4px}
            .vso-sw .meta{font-size:12px;color:#4A2C20;display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
            .vso-sw .meta span{display:flex;align-items:center;gap:3px}

            .vso-sw .btn-special{display:inline-block;background:#2C1810;color:#D4AF37;border:none;padding:11px 24px;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;text-decoration:none;text-align:center;font-family:'DM Sans',sans-serif;letter-spacing:.5px;transition:background .2s}
            .vso-sw .btn-special:hover{background:#4A2C20}

            .vso-sw .closed-note{font-size:13px;color:#8B7355;line-height:1.5;margin-bottom:4px}

            /* Uber Eats — always visible, separate section */
            .vso-sw-always{margin-top:10px;padding:14px 18px;background:#fff;border:1px solid #e8e4df;border-radius:10px;display:flex;align-items:center;gap:12px}
            .vso-sw-always .ue-info{flex:1}
            .vso-sw-always .ue-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:#8B7355;margin-bottom:2px}
            .vso-sw-always .ue-text{font-size:13px;color:#4A2C20;line-height:1.3}
            .vso-sw .btn-ue{display:inline-block;background:rgba(44,24,16,.06);color:#2C1810;border:1.5px solid rgba(44,24,16,.12);padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;text-align:center;font-family:'DM Sans',sans-serif;white-space:nowrap;transition:all .2s}
            .vso-sw .btn-ue:hover{background:rgba(44,24,16,.1);border-color:rgba(44,24,16,.2)}
        </style>
        <div class="vso-sw">
            <!-- SPECIAL DELIVERY -->
            <?php if ($active && !$at_cap): ?>
            <div class="vso-sw-special open">
                <div class="section-label on">🚗 Special Weekly Delivery</div>
                <div class="sw-title">Nicolas & Vincenzo deliver to your door</div>
                <div class="sw-subtitle">Fresh Sicilian food, personally delivered from our East Village bakery.</div>
                <div class="status-line"><span class="dot on"></span><span class="status-text on">Orders Open!</span></div>
                <?php if ($zone): ?><div class="zone-name">📍 <?php echo esc_html($zone); ?></div><?php endif; ?>
                <div class="meta">
                    <?php if ($day): ?><span>🗓 <?php echo esc_html($day); ?></span><?php endif; ?>
                    <?php if ($deadline): ?><span>⏰ Order by <?php echo esc_html($deadline); ?></span><?php endif; ?>
                    <span>💰 Min. $<?php echo esc_html($min); ?></span>
                </div>
                <?php if ($order_page): ?><a href="<?php echo esc_url($order_page); ?>" class="btn-special">Place Your Order →</a><?php endif; ?>
            </div>

            <?php elseif ($at_cap): ?>
            <div class="vso-sw-special full">
                <div class="section-label off">🚗 Special Weekly Delivery</div>
                <div class="status-line"><span class="dot full"></span><span class="status-text full">Capacity Reached</span></div>
                <div class="closed-note">This week's delivery round is full. Subscribe on our menu page to get notified for the next one!</div>
            </div>

            <?php else: ?>
            <div class="vso-sw-special closed">
                <div class="section-label off">🚗 Special Weekly Delivery</div>
                <div class="sw-title" style="color:#999;">Coming Soon to Your Area</div>
                <div class="status-line"><span class="dot off"></span><span class="status-text off">Not currently accepting orders</span></div>
                <div class="closed-note"><?php echo esc_html($closed_msg); ?></div>
                <?php if ($order_page): ?><a href="<?php echo esc_url($order_page); ?>" class="btn-ue" style="margin-top:4px;">📩 Get Notified →</a><?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- UBER EATS — ALWAYS SHOWN -->
            <?php if ($ue_url): ?>
            <div class="vso-sw-always">
                <div class="ue-info">
                    <div class="ue-label">Always Available</div>
                    <div class="ue-text">Order anytime via Uber Eats for standard delivery.</div>
                </div>
                <a href="<?php echo esc_url($ue_url); ?>" class="btn-ue" target="_blank">🛵 Uber Eats</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
