<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function vso_admin_orders_page() {
    $statuses = vso_order_statuses();
    $rest_nonce = wp_create_nonce('wp_rest');
    $filter_status = sanitize_text_field($_GET['status']??'');
    $filter_zone = sanitize_text_field($_GET['zone']??'');
    $filter_search = sanitize_text_field($_GET['s']??'');
    $filter_payment = sanitize_text_field($_GET['payment']??'');
    $view_order = (int)($_GET['view']??0);

    if ($view_order) { vso_render_order_detail($view_order); return; }

    $args = ['post_type'=>'vs_order','posts_per_page'=>50,'orderby'=>'date','order'=>'DESC','meta_query'=>[]];
    if ($filter_status) $args['meta_query'][] = ['key'=>'_vso_status','value'=>$filter_status];
    if ($filter_zone) $args['meta_query'][] = ['key'=>'_vso_customer_zone','value'=>$filter_zone,'compare'=>'LIKE'];
    if ($filter_payment) $args['meta_query'][] = ['key'=>'_vso_payment_status','value'=>$filter_payment];
    if ($filter_search) {
        $args['meta_query']['relation'] = 'OR';
        foreach (['_vso_ref','_vso_customer_name','_vso_customer_email','_vso_customer_phone'] as $k)
            $args['meta_query'][] = ['key'=>$k,'value'=>$filter_search,'compare'=>'LIKE'];
    }
    $orders = get_posts($args);
    global $wpdb;
    $zones = $wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_vso_customer_zone' AND meta_value!='' ORDER BY meta_value");
    ?>
    <div class="wrap" style="max-width:1200px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
        <h1 style="font-size:22px;font-weight:700;color:#2C1810;margin-bottom:16px;">📋 Orders <span style="font-size:14px;color:#8B7355;font-weight:400;">(<?php echo (int)count($orders);?>)</span></h1>

        <div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=vs-orders-list'));?>" style="padding:4px 12px;border-radius:14px;font-size:12px;text-decoration:none;<?php echo !$filter_status?'background:#2C1810;color:#D4AF37;':'background:#f5f5f5;color:#666;';?>">All</a>
            <?php foreach($statuses as $sk=>$si):?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=vs-orders-list&status='.$sk));?>" style="padding:4px 12px;border-radius:14px;font-size:12px;text-decoration:none;background:<?php echo esc_attr($si['bg']);?>;color:<?php echo esc_attr($si['color']);?>;<?php echo $filter_status===$sk?'border:2px solid '.$si['color'].';font-weight:700;':'border:2px solid transparent;';?>"><?php echo esc_html($si['label']);?></a>
            <?php endforeach;?>
        </div>

        <form method="get" style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap;">
            <input type="hidden" name="page" value="vs-orders-list">
            <?php if($filter_status):?><input type="hidden" name="status" value="<?php echo esc_attr($filter_status);?>"><?php endif;?>
            <input type="text" name="s" placeholder="Search…" value="<?php echo esc_attr($filter_search);?>" style="padding:7px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;flex:1;min-width:160px;">
            <select name="zone" style="padding:7px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                <option value="">All Zones</option>
                <?php foreach($zones as $z):?><option value="<?php echo esc_attr($z);?>" <?php selected($filter_zone,$z);?>><?php echo esc_html(wp_trim_words($z,4));?></option><?php endforeach;?>
            </select>
            <select name="payment" style="padding:7px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                <option value="">All Pay</option>
                <option value="paid" <?php selected($filter_payment,'paid');?>>Paid</option>
                <option value="pending" <?php selected($filter_payment,'pending');?>>Pending</option>
            </select>
            <button type="submit" style="padding:7px 14px;background:#2C1810;color:#D4AF37;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;">Filter</button>
            <a href="<?php echo esc_url(admin_url('admin.php?page=vs-orders-list'));?>" style="padding:7px 12px;border:1px solid #ddd;border-radius:6px;font-size:12px;text-decoration:none;color:#666;">Clear</a>
        </form>

        <div style="background:#fff;border:1px solid #e5e5e5;border-radius:8px;overflow:hidden;">
            <?php if(empty($orders)):?>
                <p style="padding:30px;text-align:center;color:#8B7355;">No orders match your filters.</p>
            <?php endif;?>
            <?php foreach($orders as $o):
                $ref=get_post_meta($o->ID,'_vso_ref',true);$name=get_post_meta($o->ID,'_vso_customer_name',true);
                $phone=get_post_meta($o->ID,'_vso_customer_phone',true);$email=get_post_meta($o->ID,'_vso_customer_email',true);
                $zone=get_post_meta($o->ID,'_vso_customer_zone',true);$total=(float)get_post_meta($o->ID,'_vso_total',true);
                $st=get_post_meta($o->ID,'_vso_status',true);$pay=get_post_meta($o->ID,'_vso_payment_status',true);
                $date=get_post_meta($o->ID,'_vso_created_at',true);$items=get_post_meta($o->ID,'_vso_items',true);
                $si=$statuses[$st]??$statuses['new'];$ic=is_array($items)?array_sum(array_column($items,'qty')):0;
                $url=admin_url('admin.php?page=vs-orders-list&view='.$o->ID);
            ?>
            <a href="<?php echo esc_url($url);?>" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #f5f3f0;text-decoration:none;color:#2C1810;">
                <div style="flex:1;min-width:0;">
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <span style="font-weight:700;font-size:13px;"><?php echo esc_html($ref);?></span>
                        <span style="padding:2px 8px;border-radius:8px;font-size:10px;font-weight:600;background:<?php echo esc_attr($si['bg']);?>;color:<?php echo esc_attr($si['color']);?>;"><?php echo esc_html($si['label']);?></span>
                        <span style="padding:2px 8px;border-radius:8px;font-size:10px;font-weight:600;background:<?php echo esc_attr($pay==='paid'?'#eafaf1':'#fef9e7');?>;color:<?php echo esc_attr($pay==='paid'?'#27ae60':'#f39c12');?>;"><?php echo esc_html(ucfirst($pay?:'pending'));?></span>
                    </div>
                    <div style="font-size:12px;color:#8B7355;margin-top:2px;"><?php echo esc_html($name);?> · <?php echo esc_html($phone);?> · <?php echo esc_html(wp_trim_words($zone,3,'…'));?></div>
                    <div style="font-size:11px;color:#aaa;margin-top:1px;"><?php echo (int)$ic;?> items · <?php echo $date?esc_html(date('M j, g:ia',strtotime($date))):'—';?></div>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <div style="font-weight:700;font-size:14px;">$<?php echo esc_html(number_format($total,2));?></div>
                </div>
            </a>
            <?php endforeach;?>
        </div>
    </div>
    <?php
}

/**
 * Order Detail with Print Ticket
 */
function vso_render_order_detail($order_id) {
    $ref=get_post_meta($order_id,'_vso_ref',true);$status=get_post_meta($order_id,'_vso_status',true);
    $items=get_post_meta($order_id,'_vso_items',true);$total=(float)get_post_meta($order_id,'_vso_total',true);
    $name=get_post_meta($order_id,'_vso_customer_name',true);$email=get_post_meta($order_id,'_vso_customer_email',true);
    $phone=get_post_meta($order_id,'_vso_customer_phone',true);$address=get_post_meta($order_id,'_vso_customer_address',true);
    $zone=get_post_meta($order_id,'_vso_customer_zone',true);$notes=get_post_meta($order_id,'_vso_customer_notes',true);
    $pay=get_post_meta($order_id,'_vso_payment_status',true);$date=get_post_meta($order_id,'_vso_created_at',true);
    $statuses=vso_order_statuses();$si=$statuses[$status]??$statuses['new'];
    $messages=vso_get_order_messages($order_id);$rest_nonce=wp_create_nonce('wp_rest');
    ?>
    <div class="wrap" style="max-width:900px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
        <style>
            .vso-od .panel{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:16px;margin-bottom:14px}
            .vso-od .panel h3{font-size:12px;font-weight:600;color:#8B7355;text-transform:uppercase;letter-spacing:1.5px;margin:0 0 10px;padding-bottom:6px;border-bottom:1px solid #f0f0f0}
            .vso-od .row{display:flex;justify-content:space-between;padding:4px 0;font-size:13px}
            .vso-od .row .lbl{color:#8B7355}.vso-od .row .val{font-weight:600;text-align:right;max-width:60%;word-break:break-word}
            .vso-od .row .val a{color:#D4AF37;text-decoration:none}
            .vso-od .itbl{width:100%;border-collapse:collapse}
            .vso-od .itbl th{text-align:left;font-size:10px;color:#8B7355;text-transform:uppercase;padding:5px 0;border-bottom:1px solid #f0f0f0}
            .vso-od .itbl td{padding:5px 0;font-size:13px;border-bottom:1px solid #f8f8f8}
            .vso-od .itbl th:last-child,.vso-od .itbl td:last-child{text-align:right}
            .vso-od .itbl .trow td{border-top:2px solid #D4AF37;font-weight:700;font-size:15px;padding-top:8px}
            .vso-od .notes{background:#fff8e1;border-radius:6px;padding:8px 12px;font-size:13px;margin-top:6px;line-height:1.5}
            .vso-od .dgrid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
            @media(max-width:768px){.vso-od .dgrid{grid-template-columns:1fr}}
            .vso-msg{padding:8px 12px;border-radius:10px;margin-bottom:6px;max-width:82%;font-size:13px;line-height:1.5;word-break:break-word}
            .vso-msg.out{background:#2C1810;color:#faf6f1;margin-left:auto;border-bottom-right-radius:2px}
            .vso-msg.in{background:#f0ebe4;border-bottom-left-radius:2px}
            .vso-msg .m{font-size:10px;opacity:.55;margin-top:2px}

            /* ─── Print ticket styles ─── */
            @media print {
                body *{visibility:hidden!important}
                #vso-ticket,#vso-ticket *{visibility:visible!important}
                #vso-ticket{position:fixed;left:0;top:0;width:80mm;font-family:'Courier New',monospace;font-size:12px;color:#000;background:#fff;padding:4mm}
                .no-print{display:none!important}
            }
            #vso-ticket{display:none;font-family:'Courier New',monospace;font-size:12px;line-height:1.4;color:#000;width:80mm;margin:20px auto;background:#fff;padding:8mm;border:1px dashed #ccc}
            #vso-ticket.show{display:block}
            #vso-ticket .t-header{text-align:center;border-bottom:2px dashed #000;padding-bottom:6px;margin-bottom:6px}
            #vso-ticket .t-header h2{font-size:16px;margin:0;letter-spacing:1px}
            #vso-ticket .t-header p{font-size:10px;margin:2px 0}
            #vso-ticket .t-ref{text-align:center;font-size:18px;font-weight:700;margin:8px 0;letter-spacing:2px}
            #vso-ticket .t-divider{border-top:1px dashed #000;margin:6px 0}
            #vso-ticket .t-item{display:flex;justify-content:space-between;padding:2px 0}
            #vso-ticket .t-item .qty{font-weight:700;min-width:24px}
            #vso-ticket .t-item .name{flex:1;margin:0 6px}
            #vso-ticket .t-total{display:flex;justify-content:space-between;font-weight:700;font-size:16px;border-top:2px dashed #000;padding-top:6px;margin-top:6px}
            #vso-ticket .t-customer{margin-top:8px;font-size:11px}
            #vso-ticket .t-footer{text-align:center;margin-top:8px;font-size:10px;border-top:1px dashed #000;padding-top:6px}
        </style>

        <div class="vso-od">
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=vs-orders-list'));?>" class="no-print">← Back to Orders</a></p>

            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                <span style="font-size:22px;font-weight:700;"><?php echo esc_html($ref);?></span>
                <span style="padding:4px 12px;border-radius:10px;font-size:12px;font-weight:600;background:<?php echo esc_attr($si['bg']);?>;color:<?php echo esc_attr($si['color']);?>;"><?php echo esc_html($si['label']);?></span>
                <span style="padding:4px 12px;border-radius:10px;font-size:12px;font-weight:600;background:<?php echo esc_attr($pay==='paid'?'#eafaf1':'#fef9e7');?>;color:<?php echo esc_attr($pay==='paid'?'#27ae60':'#f39c12');?>;"><?php echo esc_html(ucfirst($pay?:'pending'));?></span>
                <button class="button no-print" onclick="document.getElementById('vso-ticket').classList.toggle('show')" style="margin-left:auto;">🖨️ Print Ticket</button>
                <button class="button no-print" onclick="document.getElementById('vso-ticket').classList.add('show');window.print();">Print Now</button>
            </div>
            <p style="font-size:12px;color:#8B7355;margin-bottom:16px;"><?php echo $date?esc_html(date('l, F j, Y — g:i A',strtotime($date))):'—';?></p>

            <div class="dgrid" style="margin-bottom:14px;">
                <div class="panel">
                    <h3>👤 Customer</h3>
                    <?php foreach([['Name',$name],['Email',$email],['Phone',$phone],['Zone',$zone],['Address',$address?:'—']] as $r):?>
                    <div class="row"><span class="lbl"><?php echo $r[0];?></span><span class="val"><?php if($r[0]==='Email'):?><a href="mailto:<?php echo esc_attr($r[1]);?>"><?php echo esc_html($r[1]);?></a><?php elseif($r[0]==='Phone'):?><a href="tel:<?php echo esc_attr($r[1]);?>"><?php echo esc_html($r[1]);?></a><?php else:echo esc_html($r[1]);endif;?></span></div>
                    <?php endforeach;?>
                    <?php if($notes):?><div class="notes">📝 <?php echo esc_html($notes);?></div><?php endif;?>
                </div>
                <div class="panel">
                    <h3>⚙️ Management</h3>
                    <div class="row"><span class="lbl">Status</span><span class="val">
                        <select onchange="vsoChangeStatus(<?php echo (int)$order_id;?>,this.value,this)" style="padding:4px 8px;border:1px solid #ddd;border-radius:6px;font-size:12px;font-weight:600;color:<?php echo esc_attr($si['color']);?>;background:<?php echo esc_attr($si['bg']);?>;cursor:pointer">
                            <?php foreach($statuses as $sk=>$si2):?><option value="<?php echo esc_attr($sk);?>" <?php selected($status,$sk);?>><?php echo esc_html($si2['label']);?></option><?php endforeach;?>
                        </select>
                    </span></div>
                    <div class="row"><span class="lbl">Payment</span><span class="val"><?php echo $pay==='paid'?'✅ Paid':'⏳ Pending';?></span></div>
                    <div class="row"><span class="lbl">Date</span><span class="val"><?php echo $date?esc_html(date('M j, g:ia',strtotime($date))):'—';?></span></div>
                </div>
            </div>

            <div class="panel">
                <h3>🛒 Items</h3>
                <table class="itbl">
                    <thead><tr><th>Item</th><th style="text-align:center">Qty</th><th>Subtotal</th></tr></thead>
                    <tbody>
                    <?php if(is_array($items)):foreach($items as $it):?>
                    <tr><td><?php echo esc_html($it['name']);?></td><td style="text-align:center"><?php echo (int)$it['qty'];?></td><td>$<?php echo esc_html(number_format($it['price']*$it['qty'],2));?></td></tr>
                    <?php endforeach;endif;?>
                    <tr class="trow"><td colspan="2">Total</td><td style="color:#D4AF37;">$<?php echo esc_html(number_format($total,2));?></td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Messages -->
            <div class="panel no-print">
                <h3>💬 Messages (<?php echo (int)count($messages);?>)</h3>
                <div style="max-height:300px;overflow-y:auto;padding:4px;margin-bottom:8px;" id="vso-msgs">
                    <?php if(empty($messages)):?><p style="text-align:center;color:#8B7355;padding:16px;font-size:13px;">No messages yet.</p><?php endif;?>
                    <?php foreach($messages as $msg):?>
                    <div class="vso-msg <?php echo esc_attr($msg['direction']==='outgoing'?'out':'in');?>">
                        <div><?php echo nl2br(esc_html($msg['body']));?></div>
                        <div class="m"><?php echo esc_html($msg['sender']);?> · <?php echo date('M j, g:ia',strtotime($msg['created_at']));?></div>
                    </div>
                    <?php endforeach;?>
                </div>
                <div style="display:flex;gap:6px;">
                    <textarea id="vso-msg-body" placeholder="Message <?php echo esc_attr($name);?>…" style="flex:1;padding:8px 10px;border:1px solid #ddd;border-radius:8px;font-size:13px;resize:none;height:44px;font-family:inherit;" onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();vsoSendMsg(<?php echo (int)$order_id;?>);}"></textarea>
                    <button id="vso-send-btn" onclick="vsoSendMsg(<?php echo (int)$order_id;?>)" style="padding:8px 14px;background:#2C1810;color:#D4AF37;border:none;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;">Send</button>
                </div>
                <div id="vso-msg-feedback" style="min-height:18px;margin-top:4px;"></div>
            </div>

            <!-- PRINT TICKET (comanda) -->
            <div id="vso-ticket">
                <div class="t-header">
                    <h2>VISIT SICILY NYC</h2>
                    <p>86 E 7th St, East Village</p>
                    <p>(917) 261-2033</p>
                </div>
                <div class="t-ref"><?php echo esc_html($ref);?></div>
                <div style="text-align:center;font-size:11px;"><?php echo $date?esc_html(date('M j, Y — g:i A',strtotime($date))):'';?></div>
                <div class="t-divider"></div>
                <?php if(is_array($items)):foreach($items as $it):?>
                <div class="t-item">
                    <span class="qty"><?php echo (int)$it['qty'];?>x</span>
                    <span class="name"><?php echo esc_html($it['name']);?></span>
                    <span>$<?php echo esc_html(number_format($it['price']*$it['qty'],2));?></span>
                </div>
                <?php endforeach;endif;?>
                <div class="t-total">
                    <span>TOTAL</span>
                    <span>$<?php echo esc_html(number_format($total,2));?></span>
                </div>
                <div class="t-divider"></div>
                <div class="t-customer">
                    <strong><?php echo esc_html($name);?></strong><br>
                    <?php echo esc_html($phone);?><br>
                    <?php echo esc_html($zone);?><br>
                    <?php if($address):echo esc_html($address).'<br>';endif;?>
                    <?php if($notes):?><br>📝 <?php echo esc_html($notes);?><?php endif;?>
                </div>
                <div class="t-divider"></div>
                <div style="text-align:center;font-size:11px;">
                    <strong>Payment: <?php echo esc_html(ucfirst($pay?:'pending'));?></strong><br>
                    Status: <?php echo esc_html($si['label']);?>
                </div>
                <div class="t-footer">
                    Grazie! 🇮🇹<br>
                    visitsicilynyc.com
                </div>
            </div>
        </div>
    </div>

    <script>
    const STATUSES=<?php echo json_encode($statuses);?>;
    async function vsoChangeStatus(id,status,el){
        try{const r=await fetch('<?php echo esc_url(rest_url('vs-order/v1/orders/'));?>'+id+'/status',{method:'POST',headers:{'X-WP-Nonce':'<?php echo esc_attr($rest_nonce);?>','Content-Type':'application/json'},body:JSON.stringify({status})});const d=await r.json();if(d.success){const s=STATUSES[status];if(s){el.style.color=s.color;el.style.background=s.bg;}}}catch(e){alert('Error');}
    }
    async function vsoSendMsg(id){
        const bodyEl=document.getElementById('vso-msg-body');
        const body=bodyEl.value.trim();
        if(!body)return;
        const btn=document.querySelector('#vso-send-btn');
        const feedback=document.getElementById('vso-msg-feedback');
        if(feedback)feedback.innerHTML='';
        btn.disabled=true;btn.textContent='Sending…';
        try{
            const r=await fetch('<?php echo esc_url(rest_url('vs-order/v1/orders/'));?>'+id+'/messages',{
                method:'POST',
                headers:{'X-WP-Nonce':'<?php echo esc_attr($rest_nonce);?>','Content-Type':'application/json'},
                body:JSON.stringify({body:body})
            });
            const d=await r.json();
            if(d.success){
                const t=document.getElementById('vso-msgs');
                const p=t.querySelector('p');if(p)p.remove();
                const div=document.createElement('div');div.className='vso-msg out';
                div.innerHTML='<div>'+body.replace(/\n/g,'<br>')+'</div><div class="m"><?php echo esc_js(get_bloginfo("name")?:"Visit Sicily NYC");?> · Just now · ✓ Sent</div>';
                t.appendChild(div);t.scrollTop=t.scrollHeight;bodyEl.value='';
                if(feedback)feedback.innerHTML='<span style="color:#27ae60;font-size:12px;">✓ Sent to <?php echo esc_js($email);?></span>';
            } else {
                // wp_mail failed but API responded
                const msg=d.message||'Email could not be sent.';
                if(feedback)feedback.innerHTML='<span style="color:#e74c3c;font-size:12px;">⚠ '+msg+'</span>';
                // Still show in thread as failed
                const t=document.getElementById('vso-msgs');
                const div=document.createElement('div');div.className='vso-msg out';
                div.style.opacity='0.5';div.style.borderLeft='3px solid #e74c3c';
                div.innerHTML='<div>'+body.replace(/\n/g,'<br>')+'</div><div class="m">⚠ Failed to send · Check WP Mail config</div>';
                t.appendChild(div);t.scrollTop=t.scrollHeight;
            }
        }catch(e){
            if(feedback)feedback.innerHTML='<span style="color:#e74c3c;font-size:12px;">⚠ Network error: '+e.message+'</span>';
        }
        btn.disabled=false;btn.textContent='Send';
    }
    document.getElementById('vso-msgs').scrollTop=document.getElementById('vso-msgs').scrollHeight;
    </script>
    <?php
}
