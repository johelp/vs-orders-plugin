<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function vso_admin_settings_page() {
    $is_admin = current_user_can('manage_options');

    // Save form — both roles can save delivery/content, only admin saves credentials
    if (isset($_POST['vso_save_settings'])&&wp_verify_nonce($_POST['_wpnonce'],'vso_settings')) {
        // Delivery Manager + Admin can save these
        $dm_fields = ['vso_weekly_zone','vso_weekly_deadline','vso_weekly_delivery_day',
            'vso_how_title','vso_checkout_note','vso_confirm_title','vso_confirm_message','vso_how_note'];
        foreach ($dm_fields as $f) {
            if (isset($_POST[$f])) update_option($f, sanitize_text_field($_POST[$f]));
        }
        update_option('vso_ubereats_url', esc_url_raw($_POST['ubereats_url']??''));
        update_option('vso_closed_message', sanitize_textarea_field($_POST['closed_message']??''));
        update_option('vso_minimum_order', max(0,(float)($_POST['minimum_order']??100)));
        update_option('vso_max_orders', max(0,(int)($_POST['max_orders']??0)));
        $steps=[];for($i=0;$i<6;$i++){$t=sanitize_text_field($_POST['how_step_title_'.$i]??'');$d=sanitize_text_field($_POST['how_step_desc_'.$i]??'');if($t)$steps[]=['title'=>$t,'desc'=>$d];}
        if(!empty($steps))update_option('vso_how_it_works',json_encode($steps));

        // Admin-only saves
        if ($is_admin) {
            update_option('vso_admin_email', sanitize_email($_POST['admin_email']??''));
            update_option('vso_stripe_webhook_secret', sanitize_text_field($_POST['vso_stripe_webhook_secret']??''));
            update_option('vso_mailerlite_api_key', sanitize_text_field($_POST['vso_mailerlite_api_key']??''));
            update_option('vso_mailerlite_group_id', sanitize_text_field($_POST['vso_mailerlite_group_id']??''));
            $mode = sanitize_text_field($_POST['vso_stripe_mode']??'test');
            update_option('vso_stripe_mode', $mode);
            update_option('vso_stripe_test_pk', sanitize_text_field($_POST['vso_stripe_test_pk']??''));
            update_option('vso_stripe_test_sk', sanitize_text_field($_POST['vso_stripe_test_sk']??''));
            update_option('vso_stripe_live_pk', sanitize_text_field($_POST['vso_stripe_live_pk']??''));
            update_option('vso_stripe_live_sk', sanitize_text_field($_POST['vso_stripe_live_sk']??''));
            if ($mode === 'live') {
                update_option('vso_stripe_publishable_key', sanitize_text_field($_POST['vso_stripe_live_pk']??''));
                update_option('vso_stripe_secret_key', sanitize_text_field($_POST['vso_stripe_live_sk']??''));
            } else {
                update_option('vso_stripe_publishable_key', sanitize_text_field($_POST['vso_stripe_test_pk']??''));
                update_option('vso_stripe_secret_key', sanitize_text_field($_POST['vso_stripe_test_sk']??''));
            }
        }
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }

    // Feedback
    if(isset($_GET['toggled'])){$da=get_option('vso_delivery_active','0');echo '<div class="notice notice-info"><p>Delivery is now <strong>'.($da==='1'?'OPEN ✓':'CLOSED ⏸').'</strong></p></div>';}
    if(isset($_GET['notified'])){$sent=get_transient('vso_notify_result');if($sent!==false){delete_transient('vso_notify_result');echo '<div class="notice notice-success"><p>Sent to <strong>'.$sent.'</strong> subscribers!</p></div>';}}

    // Load options
    $o=[];foreach(['vso_delivery_active'=>'0','vso_stripe_publishable_key'=>'','vso_stripe_secret_key'=>'','vso_stripe_webhook_secret'=>'',
        'vso_admin_email'=>get_option('admin_email'),'vso_ubereats_url'=>'','vso_closed_message'=>'','vso_minimum_order'=>100,
        'vso_weekly_zone'=>'','vso_weekly_deadline'=>'','vso_weekly_delivery_day'=>'','vso_max_orders'=>0,
        'vso_mailerlite_api_key'=>'','vso_mailerlite_group_id'=>'','vso_current_order_count'=>0,
        'vso_how_title'=>'How Our Delivery Works','vso_checkout_note'=>'','vso_confirm_title'=>'Grazie mille!',
        'vso_confirm_message'=>'','vso_how_note'=>''] as $k=>$d){$o[$k]=get_option($k,$d);}
    $steps=json_decode(get_option('vso_how_it_works','[]'),true)?:[];
    $subs=get_option('vso_subscribers',[]);$stats=vso_subscriber_stats();
    $da=$o['vso_delivery_active'];
    $stl='style="width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;font-family:inherit;"';
    $lbl='style="display:block;font-size:11px;font-weight:600;color:#8B7355;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px;"';
    $sec='style="background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:16px;"';
    ?>
    <div class="wrap" style="max-width:720px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
        <h1 style="font-size:22px;font-weight:700;color:#2C1810;margin-bottom:16px;">⚙️ Settings</h1>

        <!-- ═══ DELIVERY TOGGLE — Everyone with manage_vso_orders ═══ -->
        <div style="background:#fff;border:2px solid <?php echo $da==='1'?'#27ae60':'#e74c3c';?>;border-radius:10px;padding:16px;margin-bottom:16px;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-size:16px;font-weight:700;color:<?php echo $da==='1'?'#27ae60':'#e74c3c';?>;flex:1;">
                    🚗 <?php echo $da==='1'?'Delivery OPEN':'Delivery CLOSED';?>
                    <?php if($da==='1'&&$o['vso_weekly_zone']):?><span style="font-weight:400;color:#4A2C20;font-size:13px;"> — <?php echo esc_html($o['vso_weekly_zone']);?></span><?php endif;?>
                    <?php if($o['vso_max_orders']>0&&$da==='1'):?><span style="font-weight:400;color:#8B7355;font-size:12px;"> · <?php echo $o['vso_current_order_count'];?>/<?php echo $o['vso_max_orders'];?> orders</span><?php endif;?>
                </span>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=vs-settings&vso_toggle_delivery=1'),'vso_toggle_delivery'));?>" style="padding:8px 16px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;color:#fff;background:<?php echo $da==='1'?'#e74c3c':'#27ae60';?>;"><?php echo $da==='1'?'⏸ Close':'▶ Open';?></a>
            </div>
        </div>

        <form method="post"><?php wp_nonce_field('vso_settings');?>

        <!-- ═══ WEEKLY CONFIG — Delivery Manager + Admin ═══ -->
        <div <?php echo $sec;?>><h3 style="font-size:14px;font-weight:700;margin:0 0 12px;">📅 This Week's Delivery</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                <div><label <?php echo $lbl;?>>Area</label><input name="vso_weekly_zone" value="<?php echo esc_attr($o['vso_weekly_zone']);?>" <?php echo $stl;?> placeholder="Westchester County, NY"></div>
                <div><label <?php echo $lbl;?>>Delivery Day</label><input name="vso_weekly_delivery_day" value="<?php echo esc_attr($o['vso_weekly_delivery_day']);?>" <?php echo $stl;?> placeholder="Saturday, March 29"></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
                <div><label <?php echo $lbl;?>>Deadline</label><input name="vso_weekly_deadline" value="<?php echo esc_attr($o['vso_weekly_deadline']);?>" <?php echo $stl;?> placeholder="Thursday 8pm"></div>
                <div><label <?php echo $lbl;?>>Min. Order ($)</label><input type="number" name="minimum_order" value="<?php echo esc_attr($o['vso_minimum_order']);?>" step="1" min="0" <?php echo $stl;?>></div>
                <div><label <?php echo $lbl;?>>Max Orders</label><input type="number" name="max_orders" value="<?php echo esc_attr($o['vso_max_orders']);?>" min="0" <?php echo $stl;?>></div>
            </div>
        </div>

        <!-- ═══ EDITABLE CONTENT — Delivery Manager + Admin ═══ -->
        <div <?php echo $sec;?>><h3 style="font-size:14px;font-weight:700;margin:0 0 4px;">✏️ Content</h3><p style="font-size:12px;color:#8B7355;margin-bottom:14px;">Customer-facing texts. <code>{{minimum}}</code> = min order amount.</p>
            <div style="margin-bottom:12px;"><label <?php echo $lbl;?>>How It Works — Title</label><input name="vso_how_title" value="<?php echo esc_attr($o['vso_how_title']);?>" <?php echo $stl;?>></div>
            <div style="margin-bottom:12px;"><label <?php echo $lbl;?>>Steps</label>
            <?php for($i=0;$i<max(count($steps),4);$i++):$s=$steps[$i]??['title'=>'','desc'=>''];?>
            <div style="display:grid;grid-template-columns:24px 1fr 1fr;gap:6px;align-items:center;margin-bottom:4px;">
                <span style="width:22px;height:22px;border-radius:50%;background:#2C1810;color:#D4AF37;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;"><?php echo (int)($i+1);?></span>
                <input name="how_step_title_<?php echo (int)$i;?>" value="<?php echo esc_attr($s['title']);?>" <?php echo $stl;?> placeholder="Title">
                <input name="how_step_desc_<?php echo (int)$i;?>" value="<?php echo esc_attr($s['desc']);?>" <?php echo $stl;?> placeholder="Description">
            </div><?php endfor;?></div>
            <div style="margin-bottom:12px;"><label <?php echo $lbl;?>>Footer Note</label><input name="vso_how_note" value="<?php echo esc_attr($o['vso_how_note']);?>" <?php echo $stl;?>></div>
            <div style="margin-bottom:12px;"><label <?php echo $lbl;?>>Checkout Note</label><input name="vso_checkout_note" value="<?php echo esc_attr($o['vso_checkout_note']);?>" <?php echo $stl;?>></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div><label <?php echo $lbl;?>>Confirm Title</label><input name="vso_confirm_title" value="<?php echo esc_attr($o['vso_confirm_title']);?>" <?php echo $stl;?>></div>
                <div><label <?php echo $lbl;?>>Confirm Message</label><input name="vso_confirm_message" value="<?php echo esc_attr($o['vso_confirm_message']);?>" <?php echo $stl;?>></div>
            </div>
        </div>

        <!-- ═══ WHEN CLOSED — Delivery Manager + Admin ═══ -->
        <div <?php echo $sec;?>><h3 style="font-size:14px;font-weight:700;margin:0 0 12px;">🔒 When Closed</h3>
            <div style="margin-bottom:10px;"><label <?php echo $lbl;?>>Message</label><textarea name="closed_message" <?php echo $stl;?> style="min-height:50px;"><?php echo esc_textarea($o['vso_closed_message']);?></textarea></div>
            <div><label <?php echo $lbl;?>>Uber Eats URL</label><input type="url" name="ubereats_url" value="<?php echo esc_attr($o['vso_ubereats_url']);?>" <?php echo $stl;?>></div>
        </div>

        <!-- ═══ SUBSCRIBERS — Delivery Manager + Admin ═══ -->
        <div <?php echo $sec;?>>
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px;margin-bottom:10px;">
                <h3 style="font-size:14px;font-weight:700;margin:0;">📩 Subscribers (<?php echo (int)$stats['total'];?>)</h3>
                <?php if($stats['total']>0):?><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=vs-settings&vso_notify_subs=1'),'vso_notify_subs'));?>" class="button" onclick="return confirm('Notify all?');" style="background:#27ae60;color:#fff;border-color:#27ae60;">📧 Notify All</a><?php endif;?>
            </div>
            <?php if(!empty($stats['zones'])):?><div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:8px;"><?php foreach($stats['zones'] as $z=>$c):?><span style="padding:2px 8px;border-radius:8px;font-size:10px;background:#f0ebe4;color:#4A2C20;"><?php echo esc_html($z);?>: <?php echo $c;?></span><?php endforeach;?></div><?php endif;?>
            <?php if(!empty($subs)):?><div style="max-height:180px;overflow-y:auto;background:#f9f9f9;border-radius:6px;font-size:12px;"><?php foreach($subs as $sub):?><div style="padding:4px 8px;border-bottom:1px solid #eee;display:flex;gap:8px;"><span style="flex:1;font-weight:600;"><?php echo esc_html($sub['name']??'—');?></span><span style="flex:1;"><?php echo esc_html($sub['email']);?></span><span style="color:#8B7355;"><?php echo esc_html($sub['zone']??'');?></span></div><?php endforeach;?></div>
            <?php else:?><p style="color:#8B7355;font-size:13px;">No subscribers yet.</p><?php endif;?>
        </div>

        <button type="submit" name="vso_save_settings" class="button button-primary button-hero">Save Settings</button>

        <?php if ($is_admin): ?>
        <!-- ═══════════════════════════════════════════════ -->
        <!-- ═══ ADMIN ONLY SECTIONS BELOW ═══════════════ -->
        <!-- ═══════════════════════════════════════════════ -->
        <div style="margin-top:24px;padding-top:20px;border-top:3px solid #e5e5e5;">
            <h2 style="font-size:16px;font-weight:700;color:#8B7355;margin-bottom:16px;">🔐 Administrator Only</h2>
        </div>

        <!-- STRIPE -->
        <?php
        $stripe_mode = get_option('vso_stripe_mode','test');
        $stripe_test_pk = get_option('vso_stripe_test_pk','');
        $stripe_test_sk = get_option('vso_stripe_test_sk','');
        $stripe_live_pk = get_option('vso_stripe_live_pk','');
        $stripe_live_sk = get_option('vso_stripe_live_sk','');
        $stripe_wh_val = $o['vso_stripe_webhook_secret'];
        $active_pk = $stripe_mode==='live' ? $stripe_live_pk : $stripe_test_pk;
        $active_sk = $stripe_mode==='live' ? $stripe_live_sk : $stripe_test_sk;
        $stripe_ready = !empty($active_pk) && !empty($active_sk);
        ?>
        <div id="vso-stripe-card" <?php echo $sec;?> style="background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:16px;border-left:4px solid <?php echo $stripe_mode==='test'?'#f39c12':'#27ae60';?>;">
            <h3 style="font-size:14px;font-weight:700;margin:0 0 4px;display:flex;align-items:center;gap:6px;">
                💳 Stripe
                <span id="vso-stripe-badge" style="padding:3px 10px;border-radius:6px;font-size:11px;font-weight:700;background:<?php echo $stripe_mode==='test'?'#fef9e7':'#eafaf1';?>;color:<?php echo $stripe_mode==='test'?'#f39c12':'#27ae60';?>;"><?php echo $stripe_mode==='test'?'⚠ TEST':'✓ LIVE';?></span>
                <?php if(!$stripe_ready):?><span style="padding:3px 8px;border-radius:6px;font-size:10px;font-weight:600;background:#fdedec;color:#e74c3c;">Keys Missing</span><?php endif;?>
            </h3>
            <p style="font-size:12px;color:#8B7355;margin-bottom:14px;">Toggle mode, then Save Settings.</p>
            <div style="display:flex;gap:0;margin-bottom:16px;border:1.5px solid #ddd;border-radius:8px;overflow:hidden;">
                <label id="vso-stripe-tab-test" onclick="vsoStripeMode('test')" style="flex:1;text-align:center;padding:10px;cursor:pointer;font-size:13px;font-weight:600;background:<?php echo $stripe_mode==='test'?'#f39c12':'#fff';?>;color:<?php echo $stripe_mode==='test'?'#fff':'#666';?>;">
                    <input type="radio" name="vso_stripe_mode" value="test" <?php checked($stripe_mode,'test');?> style="display:none;"> 🧪 Test
                </label>
                <label id="vso-stripe-tab-live" onclick="vsoStripeMode('live')" style="flex:1;text-align:center;padding:10px;cursor:pointer;font-size:13px;font-weight:600;background:<?php echo $stripe_mode==='live'?'#27ae60':'#fff';?>;color:<?php echo $stripe_mode==='live'?'#fff':'#666';?>;">
                    <input type="radio" name="vso_stripe_mode" value="live" <?php checked($stripe_mode,'live');?> style="display:none;"> 🟢 Live
                </label>
            </div>
            <div id="vso-stripe-test-box" style="padding:12px;background:<?php echo $stripe_mode==='test'?'#fffbf0':'#f9f9f9';?>;border-radius:8px;margin-bottom:10px;opacity:<?php echo $stripe_mode==='test'?'1':'.4';?>;">
                <div style="font-size:11px;font-weight:700;color:#f39c12;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Test Keys</div>
                <div style="margin-bottom:6px;"><label <?php echo $lbl;?>>Publishable</label><input type="text" name="vso_stripe_test_pk" value="<?php echo esc_attr($stripe_test_pk);?>" style="font-family:monospace;width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;" placeholder="pk_test_..."></div>
                <div><label <?php echo $lbl;?>>Secret</label><input type="password" name="vso_stripe_test_sk" value="<?php echo esc_attr($stripe_test_sk);?>" style="font-family:monospace;width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;" placeholder="sk_test_..."></div>
            </div>
            <div id="vso-stripe-live-box" style="padding:12px;background:<?php echo $stripe_mode==='live'?'#f0faf3':'#f9f9f9';?>;border-radius:8px;margin-bottom:10px;opacity:<?php echo $stripe_mode==='live'?'1':'.4';?>;">
                <div style="font-size:11px;font-weight:700;color:#27ae60;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Live Keys</div>
                <div style="margin-bottom:6px;"><label <?php echo $lbl;?>>Publishable</label><input type="text" name="vso_stripe_live_pk" value="<?php echo esc_attr($stripe_live_pk);?>" style="font-family:monospace;width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;" placeholder="pk_live_..."></div>
                <div><label <?php echo $lbl;?>>Secret</label><input type="password" name="vso_stripe_live_sk" value="<?php echo esc_attr($stripe_live_sk);?>" style="font-family:monospace;width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;" placeholder="sk_live_..."></div>
            </div>
            <div><label <?php echo $lbl;?>>Webhook Secret</label><input type="password" name="vso_stripe_webhook_secret" value="<?php echo esc_attr($stripe_wh_val);?>" style="font-family:monospace;width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;" placeholder="whsec_..."></div>
            <div style="font-size:11px;color:#aaa;margin-top:6px;">Test card: <code>4242 4242 4242 4242</code> · <a href="https://docs.stripe.com/testing#cards" target="_blank">More</a></div>
        </div>
        <script>
        function vsoStripeMode(mode){
            const tT=document.getElementById('vso-stripe-tab-test'),tL=document.getElementById('vso-stripe-tab-live');
            const bT=document.getElementById('vso-stripe-test-box'),bL=document.getElementById('vso-stripe-live-box');
            const badge=document.getElementById('vso-stripe-badge'),card=document.getElementById('vso-stripe-card');
            if(mode==='test'){tT.style.background='#f39c12';tT.style.color='#fff';tL.style.background='#fff';tL.style.color='#666';bT.style.opacity='1';bT.style.background='#fffbf0';bL.style.opacity='.4';bL.style.background='#f9f9f9';badge.style.background='#fef9e7';badge.style.color='#f39c12';badge.textContent='⚠ TEST';card.style.borderLeftColor='#f39c12';tT.querySelector('input').checked=true;}
            else{tL.style.background='#27ae60';tL.style.color='#fff';tT.style.background='#fff';tT.style.color='#666';bL.style.opacity='1';bL.style.background='#f0faf3';bT.style.opacity='.4';bT.style.background='#f9f9f9';badge.style.background='#eafaf1';badge.style.color='#27ae60';badge.textContent='✓ LIVE';card.style.borderLeftColor='#27ae60';tL.querySelector('input').checked=true;}
        }
        </script>

        <!-- NOTIFICATIONS + MAILERLITE -->
        <div <?php echo $sec;?>><h3 style="font-size:14px;font-weight:700;margin:0 0 12px;">📧 Notifications & MailerLite</h3>
            <div style="margin-bottom:10px;"><label <?php echo $lbl;?>>Admin Email</label><input type="email" name="admin_email" value="<?php echo esc_attr($o['vso_admin_email']);?>" <?php echo $stl;?>></div>
            <div style="margin-bottom:10px;"><label <?php echo $lbl;?>>MailerLite API Token</label><input type="password" name="vso_mailerlite_api_key" value="<?php echo esc_attr($o['vso_mailerlite_api_key']);?>" style="font-family:monospace;width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;"></div>
            <div><label <?php echo $lbl;?>>MailerLite Group ID</label><input name="vso_mailerlite_group_id" value="<?php echo esc_attr($o['vso_mailerlite_group_id']);?>" style="font-family:monospace;width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;"></div>
        </div>

        <button type="submit" name="vso_save_settings" class="button button-primary">Save All Settings</button>

        <?php endif; /* end admin only */ ?>
        </form>

        <!-- SHORTCODES — visible to all -->
        <div <?php echo $sec;?> style="margin-top:16px;background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;">
            <h3 style="font-size:14px;font-weight:700;margin:0 0 8px;">📋 Shortcodes</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div style="background:#f5f5f5;border:1px dashed #ccc;border-radius:6px;padding:10px;text-align:center;cursor:pointer;" onclick="navigator.clipboard.writeText('[visit_sicily_order]');this.querySelector('span').textContent='Copied!';setTimeout(()=>this.querySelector('span').textContent='[visit_sicily_order]',1500)"><div style="font-size:10px;color:#8B7355;margin-bottom:4px;">Ordering system</div><span style="font-family:monospace;font-size:13px;">[visit_sicily_order]</span></div>
                <div style="background:#f5f5f5;border:1px dashed #ccc;border-radius:6px;padding:10px;text-align:center;cursor:pointer;" onclick="navigator.clipboard.writeText('[visit_sicily_status]');this.querySelector('span').textContent='Copied!';setTimeout(()=>this.querySelector('span').textContent='[visit_sicily_status]',1500)"><div style="font-size:10px;color:#8B7355;margin-bottom:4px;">Status badge</div><span style="font-family:monospace;font-size:13px;">[visit_sicily_status]</span></div>
            </div>
        </div>

        <?php if ($is_admin): ?>
        <!-- DATA MANAGEMENT — Admin only -->
        <?php
        if(isset($_GET['reset_orders'])){$n=get_transient('vso_reset_result');delete_transient('vso_reset_result');echo '<div class="notice notice-warning"><p>Deleted <strong>'.($n?:0).'</strong> orders.</p></div>';}
        if(isset($_GET['reset_subs'])){$n=get_transient('vso_reset_subs_result');delete_transient('vso_reset_subs_result');echo '<div class="notice notice-warning"><p>Deleted <strong>'.($n?:0).'</strong> subscribers.</p></div>';}
        if(isset($_GET['factory_reset'])){echo '<div class="notice notice-warning"><p>Factory reset complete.</p></div>';}
        $oc=wp_count_posts('vs_order');$to=($oc->publish??0)+($oc->draft??0)+($oc->private??0);$sc=count($subs);
        ?>
        <div style="background:#fff;border:2px solid #e74c3c;border-radius:8px;padding:20px;margin-top:20px;">
            <h3 style="font-size:14px;font-weight:700;margin:0 0 4px;color:#e74c3c;">⚠️ Data Management</h3>
            <p style="font-size:12px;color:#8B7355;margin-bottom:16px;">Permanent and irreversible actions.</p>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#fdf2f2;border-radius:8px;flex-wrap:wrap;gap:8px;">
                    <div><div style="font-size:13px;font-weight:600;">Delete All Orders</div><div style="font-size:11px;color:#8B7355;"><?php echo (int)$to;?> orders + messages</div></div>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=vs-settings&vso_reset_orders=1'),'vso_reset_orders'));?>" onclick="return confirm('Delete ALL <?php echo (int)$to;?> orders?');" style="padding:7px 16px;background:#e74c3c;color:#fff;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;">🗑 Delete</a>
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#fdf2f2;border-radius:8px;flex-wrap:wrap;gap:8px;">
                    <div><div style="font-size:13px;font-weight:600;">Delete All Subscribers</div><div style="font-size:11px;color:#8B7355;"><?php echo (int)$sc;?> subscribers</div></div>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=vs-settings&vso_reset_subs=1'),'vso_reset_subs'));?>" onclick="return confirm('Delete all <?php echo (int)$sc;?> subscribers?');" style="padding:7px 16px;background:#e74c3c;color:#fff;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;">🗑 Delete</a>
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#fdf2f2;border:1px dashed #e74c3c;border-radius:8px;flex-wrap:wrap;gap:8px;">
                    <div><div style="font-size:13px;font-weight:600;color:#e74c3c;">Factory Reset</div><div style="font-size:11px;color:#8B7355;">All orders + messages + subscribers</div></div>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=vs-settings&vso_factory_reset=1'),'vso_factory_reset'));?>" onclick="return confirm('FACTORY RESET?')&&prompt('Type RESET to confirm')?.toUpperCase()==='RESET';" style="padding:7px 16px;background:#c0392b;color:#fff;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;">⚠️ Reset</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
