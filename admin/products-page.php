<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function vso_admin_products_page() {
    // Enqueue media uploader
    wp_enqueue_media();

    if ( isset($_POST['vso_add_product']) && wp_verify_nonce($_POST['_wpnonce'], 'vso_add_product') ) {
        $pid = wp_insert_post(['post_type'=>'vs_product','post_title'=>sanitize_text_field($_POST['product_name']),'post_status'=>'publish']);
        if (!is_wp_error($pid)) {
            update_post_meta($pid,'_vso_description',sanitize_text_field($_POST['product_desc']));
            update_post_meta($pid,'_vso_price',(float)$_POST['product_price']);
            update_post_meta($pid,'_vso_active',isset($_POST['product_active'])?'1':'0');
            $img_id = (int)($_POST['product_image_id']??0);
            if ($img_id) set_post_thumbnail($pid, $img_id);
            $cat_slug=sanitize_text_field($_POST['product_cat']??'');
            if($cat_slug){
                $term=get_term_by('slug',$cat_slug,'vs_product_cat');
                if(!$term) $term=get_term_by('name',$cat_slug,'vs_product_cat');
                if($term) wp_set_object_terms($pid,(int)$term->term_id,'vs_product_cat');
                else { $new_term=wp_insert_term($cat_slug,'vs_product_cat'); if(!is_wp_error($new_term)) wp_set_object_terms($pid,(int)$new_term['term_id'],'vs_product_cat'); }
            }
            clean_post_cache($pid);
            echo '<div class="notice notice-success"><p>Product added!</p></div>';
        }
    }
    if ( isset($_POST['vso_update_product']) && wp_verify_nonce($_POST['_wpnonce'], 'vso_update_product') ) {
        $pid=(int)$_POST['product_id'];
        wp_update_post(['ID'=>$pid,'post_title'=>sanitize_text_field($_POST['product_name'])]);
        update_post_meta($pid,'_vso_description',sanitize_text_field($_POST['product_desc']));
        update_post_meta($pid,'_vso_price',(float)$_POST['product_price']);
        $img_id = (int)($_POST['product_image_id']??0);
        if ($img_id) set_post_thumbnail($pid, $img_id);
        elseif (isset($_POST['product_remove_image'])) delete_post_thumbnail($pid);
        $cat_slug=sanitize_text_field($_POST['product_cat']??'');
        if($cat_slug){
            $term=get_term_by('slug',$cat_slug,'vs_product_cat');
            if(!$term) $term=get_term_by('name',$cat_slug,'vs_product_cat');
            if($term) wp_set_object_terms($pid,(int)$term->term_id,'vs_product_cat');
        }
        clean_post_cache($pid);
        echo '<div class="notice notice-success"><p>Product updated!</p></div>';
    }
    if ( isset($_GET['delete_product']) && wp_verify_nonce($_GET['_wpnonce'], 'vso_delete_product') ) {
        wp_delete_post((int)$_GET['delete_product'],true);echo '<div class="notice notice-warning"><p>Product deleted.</p></div>';
    }

    $cat_filter=sanitize_text_field($_GET['cat']??'');
    $args=['post_type'=>'vs_product','posts_per_page'=>200,'orderby'=>'menu_order','order'=>'ASC'];
    if($cat_filter)$args['tax_query']=[['taxonomy'=>'vs_product_cat','field'=>'slug','terms'=>$cat_filter]];
    $products=get_posts($args);
    $categories=get_terms(['taxonomy'=>'vs_product_cat','hide_empty'=>false]);
    ?>
    <div class="wrap" style="max-width:1100px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
        <h1 style="font-size:22px;font-weight:700;color:#2C1810;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;">
            <span>🍽️ Products <span style="font-size:14px;color:#8B7355;font-weight:400;">(<?php echo (int)count($products);?>)</span></span>
            <div><a href="<?php echo esc_url(admin_url('admin.php?page=vs-categories'));?>" class="button" style="margin-right:6px;">📂 Categories</a>
            <button class="button button-primary" onclick="document.getElementById('vso-addp').style.display=document.getElementById('vso-addp').style.display==='none'?'block':'none'">+ Add Product</button></div>
        </h1>

        <!-- Add form -->
        <div id="vso-addp" style="display:none;background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:16px;margin-bottom:16px;">
            <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
                <?php wp_nonce_field('vso_add_product');?>
                <div style="flex:2;min-width:140px;"><label style="display:block;font-size:11px;font-weight:600;color:#8B7355;text-transform:uppercase;letter-spacing:1px;margin-bottom:2px;">Name</label><input type="text" name="product_name" required style="width:100%;padding:7px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;"></div>
                <div style="flex:2;min-width:140px;"><label style="display:block;font-size:11px;font-weight:600;color:#8B7355;text-transform:uppercase;letter-spacing:1px;margin-bottom:2px;">Description</label><input type="text" name="product_desc" style="width:100%;padding:7px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;"></div>
                <div style="width:80px;"><label style="display:block;font-size:11px;font-weight:600;color:#8B7355;text-transform:uppercase;letter-spacing:1px;margin-bottom:2px;">Price</label><input type="number" name="product_price" step="0.01" min="0" required style="width:100%;padding:7px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;"></div>
                <div style="min-width:120px;"><label style="display:block;font-size:11px;font-weight:600;color:#8B7355;text-transform:uppercase;letter-spacing:1px;margin-bottom:2px;">Category</label><select name="product_cat" style="width:100%;padding:7px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;"><?php foreach($categories as $c):?><option value="<?php echo esc_attr($c->slug);?>"><?php echo esc_html($c->name);?></option><?php endforeach;?></select></div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:600;color:#8B7355;text-transform:uppercase;letter-spacing:1px;margin-bottom:2px;">Image</label>
                    <input type="hidden" name="product_image_id" id="vso-add-img-id" value="">
                    <div style="display:flex;align-items:center;gap:6px;">
                        <img id="vso-add-img-preview" src="" style="width:36px;height:36px;border-radius:6px;object-fit:cover;display:none;border:1px solid #ddd;">
                        <button type="button" class="button" onclick="vsoPickImage('vso-add-img-id','vso-add-img-preview')" style="font-size:11px;">📷</button>
                    </div>
                </div>
                <label style="display:flex;align-items:center;gap:4px;font-size:12px;cursor:pointer;"><input type="checkbox" name="product_active" checked> Active</label>
                <button type="submit" name="vso_add_product" class="button button-primary">Add</button>
            </form>
        </div>

        <!-- Category filters with toggle buttons -->
        <div style="display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap;align-items:center;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=vs-products'));?>" style="padding:4px 12px;border-radius:14px;font-size:12px;text-decoration:none;<?php echo !$cat_filter?'background:#2C1810;color:#D4AF37;':'background:#f5f5f5;color:#666;';?>">All</a>
            <?php foreach($categories as $c):?><a href="<?php echo esc_url(admin_url('admin.php?page=vs-products&cat='.$c->slug));?>" style="padding:4px 12px;border-radius:14px;font-size:12px;text-decoration:none;<?php echo $cat_filter===$c->slug?'background:#2C1810;color:#D4AF37;':'background:#f5f5f5;color:#666;';?>"><?php echo esc_html($c->name);?> (<?php echo $c->count;?>)</a><?php endforeach;?>
        </div>

        <!-- Bulk actions bar -->
        <div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap;align-items:center;">
            <span style="font-size:11px;color:#8B7355;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Quick:</span>
            <button class="button" onclick="vsoBulk(true)" style="font-size:11px;padding:2px 10px;">✅ Activate All<?php echo $cat_filter?' in '.esc_attr(ucfirst($cat_filter)):'';?></button>
            <button class="button" onclick="vsoBulk(false)" style="font-size:11px;padding:2px 10px;">⛔ Deactivate All<?php echo $cat_filter?' in '.esc_attr(ucfirst($cat_filter)):'';?></button>
            <?php if(!$cat_filter): ?>
            <span style="color:#ddd;margin:0 2px;">|</span>
            <span style="font-size:11px;color:#8B7355;">By category:</span>
            <?php foreach($categories as $c):
                $cat_ids = get_posts(['post_type'=>'vs_product','posts_per_page'=>200,'fields'=>'ids','tax_query'=>[['taxonomy'=>'vs_product_cat','field'=>'slug','terms'=>$c->slug]]]);
            ?>
            <button class="button vso-cat-toggle" data-ids="<?php echo implode(',',$cat_ids);?>" data-name="<?php echo esc_attr($c->name);?>" onclick="vsoCatToggle(this)" style="font-size:11px;padding:2px 8px;position:relative;" title="Toggle <?php echo esc_attr($c->name);?>">
                <?php echo esc_html($c->name);?> <span style="font-size:9px;">⇄</span>
            </button>
            <?php endforeach; endif; ?>
        </div>

        <!-- Products list -->
        <div style="background:#fff;border:1px solid #e5e5e5;border-radius:8px;overflow:hidden;">
            <?php foreach($products as $p):
                $active=get_post_meta($p->ID,'_vso_active',true);$desc=get_post_meta($p->ID,'_vso_description',true);
                $price=(float)get_post_meta($p->ID,'_vso_price',true);$terms=wp_get_object_terms($p->ID,'vs_product_cat');
                $cat=!empty($terms)?$terms[0]->name:'—';$cat_slug=!empty($terms)?$terms[0]->slug:'';
                $thumb=get_the_post_thumbnail_url($p->ID,'thumbnail');
                $thumb_id=get_post_thumbnail_id($p->ID);
            ?>
            <div id="prow-<?php echo (int)$p->ID;?>" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #f5f3f0;<?php echo $active!=='1'?'opacity:.4;':'';?>">
                <label style="position:relative;width:40px;height:22px;cursor:pointer;flex-shrink:0;">
                    <input type="checkbox" <?php echo $active==='1'?'checked':'';?> onchange="vsoToggle(<?php echo (int)$p->ID;?>,this)" style="opacity:0;width:0;height:0;position:absolute;">
                    <span style="position:absolute;inset:0;background:<?php echo $active==='1'?'#27ae60':'#ccc';?>;border-radius:22px;transition:.25s;"></span>
                    <span style="position:absolute;height:16px;width:16px;left:<?php echo $active==='1'?'21':'3';?>px;bottom:3px;background:#fff;border-radius:50%;transition:.25s;"></span>
                </label>
                <?php if($thumb):?>
                <img src="<?php echo esc_url($thumb);?>" style="width:40px;height:40px;border-radius:6px;object-fit:cover;flex-shrink:0;border:1px solid #eee;">
                <?php endif;?>
                <div style="flex:1;min-width:0;"><div style="font-weight:600;font-size:14px;"><?php echo esc_html($p->post_title);?></div><div style="font-size:11px;color:#8B7355;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($desc);?></div></div>
                <span style="padding:2px 8px;border-radius:8px;font-size:10px;background:#f0ebe4;color:#4A2C20;"><?php echo esc_html($cat);?></span>
                <span style="font-weight:700;color:#4A2C20;font-size:14px;min-width:50px;text-align:right;">$<?php echo esc_html(number_format($price,2));?></span>
                <div style="display:flex;gap:4px;">
                    <a href="#" onclick="vsoEditP(<?php echo (int)$p->ID;?>,'<?php echo esc_js($p->post_title);?>','<?php echo esc_js($desc);?>',<?php echo $price;?>,'<?php echo esc_js($cat_slug);?>','<?php echo esc_url($thumb?:'');?>',<?php echo (int)$thumb_id;?>);return false;" style="font-size:12px;padding:4px 8px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#666;">Edit</a>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=vs-products&delete_product='.$p->ID),'vso_delete_product'));?>" onclick="return confirm('Delete?')" style="font-size:12px;padding:4px 8px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#e74c3c;">×</a>
                </div>
            </div>
            <?php endforeach;?>
            <?php if(empty($products)):?><p style="padding:30px;text-align:center;color:#8B7355;">No products.</p><?php endif;?>
        </div>

        <div id="vso-editp" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:99999;align-items:center;justify-content:center;"></div>
    </div>

    <script>
    // Bulk toggle all visible products
    async function vsoBulk(activate){
        const rows=document.querySelectorAll('[id^="prow-"]');
        const ids=[...rows].map(r=>parseInt(r.id.replace('prow-','')));
        if(!ids.length)return;
        if(!confirm((activate?'Activate':'Deactivate')+' all '+ids.length+' products shown?'))return;
        try{
            const r=await fetch('<?php echo esc_url(rest_url('vs-order/v1/products/bulk-toggle'));?>',{
                method:'POST',headers:{'X-WP-Nonce':'<?php echo esc_attr(wp_create_nonce('wp_rest'));?>','Content-Type':'application/json'},
                body:JSON.stringify({ids:ids,active:activate})
            });
            const d=await r.json();
            if(d.success) location.reload();
        }catch(e){alert('Error: '+e.message);}
    }

    // Toggle entire category
    async function vsoCatToggle(btn){
        const ids=btn.dataset.ids.split(',').filter(Boolean).map(Number);
        const name=btn.dataset.name;
        if(!ids.length)return;
        // Check if majority are active to determine action
        let activeCount=0;
        ids.forEach(function(id){const row=document.getElementById('prow-'+id);if(row&&row.style.opacity!=='0.4')activeCount++;});
        const activate=activeCount<ids.length/2; // if most are off, activate; otherwise deactivate
        const action=activate?'Activate':'Deactivate';
        if(!confirm(action+' all '+ids.length+' products in "'+name+'"?'))return;
        btn.disabled=true;btn.textContent=name+' ⏳';
        try{
            const r=await fetch('<?php echo esc_url(rest_url('vs-order/v1/products/bulk-toggle'));?>',{
                method:'POST',headers:{'X-WP-Nonce':'<?php echo esc_attr(wp_create_nonce('wp_rest'));?>','Content-Type':'application/json'},
                body:JSON.stringify({ids:ids,active:activate})
            });
            const d=await r.json();
            if(d.success){
                ids.forEach(function(id){
                    const row=document.getElementById('prow-'+id);
                    if(row){
                        row.style.opacity=activate?'1':'0.4';
                        const sl=row.querySelector('label');
                        if(sl){
                            const cb=sl.querySelector('input');if(cb)cb.checked=activate;
                            const spans=sl.querySelectorAll('span');
                            if(spans[0])spans[0].style.background=activate?'#27ae60':'#ccc';
                            if(spans[1])spans[1].style.left=activate?'21px':'3px';
                        }
                    }
                });
                btn.textContent=name+' '+(activate?'✅':'⛔');
                btn.disabled=false;
                setTimeout(function(){btn.textContent=name+' ⇄';},1500);
            }
        }catch(e){btn.textContent=name+' ⇄';btn.disabled=false;alert('Error');}
    }
    function vsoPickImage(inputId, previewId) {
        const frame = wp.media({ title:'Select Product Image', multiple:false, library:{type:'image'} });
        frame.on('select', function(){
            const att = frame.state().get('selection').first().toJSON();
            document.getElementById(inputId).value = att.id;
            const prev = document.getElementById(previewId);
            prev.src = att.sizes?.thumbnail?.url || att.url;
            prev.style.display = 'block';
        });
        frame.open();
    }

    async function vsoToggle(id,el){
        const row=document.getElementById('prow-'+id);
        try{const r=await fetch('<?php echo esc_url(rest_url('vs-order/v1/products/'));?>'+id+'/toggle',{method:'POST',headers:{'X-WP-Nonce':'<?php echo esc_attr(wp_create_nonce('wp_rest'));?>','Content-Type':'application/json'}});const d=await r.json();row.style.opacity=d.active?'1':'.4';
          const sl=el.parentElement;sl.querySelector('span:first-of-type').style.background=d.active?'#27ae60':'#ccc';sl.querySelector('span:last-of-type').style.left=d.active?'21px':'3px';
        }catch(e){el.checked=!el.checked;alert('Error');}
    }

    function vsoEditP(id,name,desc,price,cat,imgUrl,imgId){
        const cats=<?php echo wp_json_encode(array_map(function($c){return['slug'=>$c->slug,'name'=>$c->name];}, $categories));?>;
        const hasImg = imgUrl && imgUrl.length > 0;
        const m=document.getElementById('vso-editp');

        // Build options
        let catOpts='';
        cats.forEach(function(c){ catOpts+='<option value="'+c.slug+'"'+(c.slug===cat?' selected':'')+'>'+c.name+'</option>'; });

        // Build image section
        let imgSection='<div style="margin-bottom:14px;">';
        imgSection+='<label style="display:block;font-size:11px;font-weight:600;color:#8B7355;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Image <span style="font-weight:400;color:#aaa;">(optional)</span></label>';
        imgSection+='<div style="display:flex;align-items:center;gap:10px;">';
        imgSection+='<img id="vso-edit-img-preview" src="'+(imgUrl||'')+'" style="width:60px;height:60px;border-radius:8px;object-fit:cover;border:1px solid #eee;'+(hasImg?'':'display:none;')+'">';
        imgSection+='<div style="display:flex;flex-direction:column;gap:4px;">';
        imgSection+='<button type="button" class="button" id="vso-edit-img-btn" style="font-size:12px;">📷 '+(hasImg?'Change':'Add')+' Image</button>';
        if(hasImg) imgSection+='<button type="button" class="button" id="vso-edit-img-rm" style="font-size:11px;color:#e74c3c;">✕ Remove</button>';
        imgSection+='</div></div></div>';

        let html='<div style="display:flex;align-items:center;justify-content:center;height:100%;">';
        html+='<div style="background:#fff;border-radius:10px;padding:24px;width:460px;max-width:90vw;box-shadow:0 20px 60px rgba(0,0,0,.2);">';
        html+='<h3 style="margin:0 0 16px;font-size:16px;font-weight:700;">Edit Product</h3>';
        html+='<form method="post" id="vso-edit-form">';
        html+='<input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('vso_update_product'));?>">';
        html+='<input type="hidden" name="product_id" value="'+id+'">';
        html+='<input type="hidden" name="product_image_id" id="vso-edit-img-id" value="'+(imgId||'')+'">';
        html+='<div style="margin-bottom:10px;"><label style="display:block;font-size:11px;font-weight:600;color:#8B7355;text-transform:uppercase;letter-spacing:1px;margin-bottom:2px;">Name</label><input type="text" name="product_name" id="vso-ep-name" required style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;"></div>';
        html+='<div style="margin-bottom:10px;"><label style="display:block;font-size:11px;font-weight:600;color:#8B7355;text-transform:uppercase;letter-spacing:1px;margin-bottom:2px;">Description</label><input type="text" name="product_desc" id="vso-ep-desc" style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;"></div>';
        html+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">';
        html+='<div><label style="display:block;font-size:11px;font-weight:600;color:#8B7355;text-transform:uppercase;letter-spacing:1px;margin-bottom:2px;">Price</label><input type="number" name="product_price" step="0.01" value="'+price+'" required style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;"></div>';
        html+='<div><label style="display:block;font-size:11px;font-weight:600;color:#8B7355;text-transform:uppercase;letter-spacing:1px;margin-bottom:2px;">Category</label><select name="product_cat" style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;">'+catOpts+'</select></div>';
        html+='</div>';
        html+=imgSection;
        html+='<div style="display:flex;gap:8px;"><button type="submit" name="vso_update_product" class="button button-primary">Save</button><button type="button" class="button" id="vso-ep-cancel">Cancel</button></div>';
        html+='</form></div></div>';

        m.innerHTML=html;
        m.style.display='flex';

        // Set values via JS (avoids quote escaping issues)
        document.getElementById('vso-ep-name').value=name;
        document.getElementById('vso-ep-desc').value=desc;

        // Bind events
        document.getElementById('vso-ep-cancel').onclick=function(){m.style.display='none';};
        m.querySelector('div').onclick=function(e){if(e.target===this)m.style.display='none';};
        document.getElementById('vso-edit-img-btn').onclick=function(){vsoPickImage('vso-edit-img-id','vso-edit-img-preview');};

        var rmBtn=document.getElementById('vso-edit-img-rm');
        if(rmBtn){
            rmBtn.onclick=function(){
                document.getElementById('vso-edit-img-id').value='';
                document.getElementById('vso-edit-img-preview').style.display='none';
                this.style.display='none';
                var ri=document.createElement('input');ri.type='hidden';ri.name='product_remove_image';ri.value='1';
                document.getElementById('vso-edit-form').appendChild(ri);
            };
        }
    }
    </script>
    <?php
}
