<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function vso_admin_categories_page() {
    // Add category
    if ( isset($_POST['vso_add_cat']) && wp_verify_nonce($_POST['_wpnonce'],'vso_cat_action') ) {
        $name = sanitize_text_field($_POST['cat_name']??'');
        $icon = sanitize_text_field($_POST['cat_icon']??'');
        if ($name) {
            $result = wp_insert_term($name,'vs_product_cat');
            if (!is_wp_error($result) && $icon) {
                update_term_meta($result['term_id'],'_vso_icon',$icon);
            }
            if (!is_wp_error($result)) echo '<div class="notice notice-success"><p>Category "'.$name.'" created.</p></div>';
            else echo '<div class="notice notice-error"><p>'.$result->get_error_message().'</p></div>';
        }
    }
    // Update category
    if ( isset($_POST['vso_update_cat']) && wp_verify_nonce($_POST['_wpnonce'],'vso_cat_action') ) {
        $tid = (int)$_POST['cat_id'];
        $name = sanitize_text_field($_POST['cat_name']??'');
        $icon = sanitize_text_field($_POST['cat_icon']??'');
        if ($tid && $name) {
            wp_update_term($tid,'vs_product_cat',['name'=>$name]);
            update_term_meta($tid,'_vso_icon',$icon);
            echo '<div class="notice notice-success"><p>Category updated.</p></div>';
        }
    }
    // Delete category
    if ( isset($_GET['delete_cat']) && wp_verify_nonce($_GET['_wpnonce'],'vso_delete_cat') ) {
        wp_delete_term((int)$_GET['delete_cat'],'vs_product_cat');
        echo '<div class="notice notice-warning"><p>Category deleted. Products in this category are now uncategorized.</p></div>';
    }
    // Reorder
    if ( isset($_POST['vso_reorder_cats']) && wp_verify_nonce($_POST['_wpnonce'],'vso_cat_action') ) {
        $order = json_decode(stripslashes($_POST['cat_order']??'[]'),true);
        if (is_array($order)) {
            foreach ($order as $i => $tid) {
                update_term_meta((int)$tid,'_vso_order',$i);
            }
            echo '<div class="notice notice-success"><p>Category order saved.</p></div>';
        }
    }

    $cats = get_terms(['taxonomy'=>'vs_product_cat','hide_empty'=>false,'orderby'=>'meta_value_num','meta_key'=>'_vso_order','order'=>'ASC']);
    // Fallback sort by term_id if no order meta
    if (empty($cats) || !get_term_meta($cats[0]->term_id??0,'_vso_order',true)) {
        $cats = get_terms(['taxonomy'=>'vs_product_cat','hide_empty'=>false,'orderby'=>'term_id','order'=>'ASC']);
    }
    ?>
    <div class="wrap" style="max-width:700px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
        <h1 style="font-size:22px;font-weight:700;color:#2C1810;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;">
            <span>📂 Menu Categories</span>
            <button class="button button-primary" onclick="document.getElementById('vso-add-cat').style.display=document.getElementById('vso-add-cat').style.display==='none'?'block':'none'">+ Add Category</button>
        </h1>

        <!-- Add form -->
        <div id="vso-add-cat" style="display:none;background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:16px;margin-bottom:16px;">
            <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
                <?php wp_nonce_field('vso_cat_action'); ?>
                <div style="flex:1;min-width:160px;">
                    <label style="display:block;font-size:11px;font-weight:600;color:#8B7355;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px;">Name</label>
                    <input type="text" name="cat_name" required style="width:100%;padding:7px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;" placeholder="e.g. Antipasti">
                </div>
                <div style="width:80px;">
                    <label style="display:block;font-size:11px;font-weight:600;color:#8B7355;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px;">Icon</label>
                    <input type="text" name="cat_icon" style="width:100%;padding:7px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;text-align:center;" placeholder="🍝" maxlength="4">
                </div>
                <button type="submit" name="vso_add_cat" class="button button-primary">Add</button>
            </form>
        </div>

        <!-- Categories list -->
        <div style="background:#fff;border:1px solid #e5e5e5;border-radius:8px;overflow:hidden;">
            <div style="padding:10px 14px;background:#fafafa;border-bottom:1px solid #e5e5e5;display:flex;justify-content:space-between;align-items:center;">
                <span style="font-size:11px;font-weight:600;color:#8B7355;text-transform:uppercase;letter-spacing:1px;">Drag to reorder · <?php echo (int)count($cats); ?> categories</span>
                <form method="post" id="vso-reorder-form" style="display:inline;">
                    <?php wp_nonce_field('vso_cat_action'); ?>
                    <input type="hidden" name="cat_order" id="vso-cat-order" value="">
                    <button type="submit" name="vso_reorder_cats" class="button" id="vso-save-order" style="display:none;">Save Order</button>
                </form>
            </div>
            <div id="vso-cat-list">
            <?php foreach ($cats as $cat):
                $icon = get_term_meta($cat->term_id, '_vso_icon', true) ?: '';
                $count = $cat->count;
            ?>
            <div class="vso-cat-row" data-id="<?php echo (int)$cat->term_id;?>" draggable="true" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #f5f3f0;cursor:grab;transition:background .15s;">
                <span style="color:#ccc;font-size:14px;cursor:grab;">⋮⋮</span>
                <span style="font-size:20px;width:28px;text-align:center;"><?php echo esc_html($icon ?: '📁');?></span>
                <div style="flex:1;">
                    <div style="font-weight:600;font-size:14px;"><?php echo esc_html($cat->name);?></div>
                    <div style="font-size:11px;color:#8B7355;">slug: <?php echo esc_html($cat->slug);?> · <?php echo (int)$count;?> product<?php echo $count!==1?'s':'';?></div>
                </div>
                <div style="display:flex;gap:4px;">
                    <button class="button" onclick="vsoEditCat(<?php echo (int)$cat->term_id;?>,'<?php echo esc_js($cat->name);?>','<?php echo esc_js($icon);?>')">Edit</button>
                    <?php if ($count === 0): ?>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=vs-categories&delete_cat='.$cat->term_id),'vso_delete_cat'));?>" class="button" onclick="return confirm('Delete this category?')" style="color:#e74c3c;">×</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($cats)): ?>
            <div style="padding:30px;text-align:center;color:#8B7355;">No categories yet. Add your first one above!</div>
            <?php endif; ?>
            </div>
        </div>

        <!-- Edit modal -->
        <div id="vso-edit-cat" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:99999;display:none;align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:10px;padding:24px;width:380px;max-width:90vw;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
                <h3 style="margin:0 0 16px;font-size:16px;font-weight:700;">Edit Category</h3>
                <form method="post">
                    <?php wp_nonce_field('vso_cat_action'); ?>
                    <input type="hidden" name="cat_id" id="vso-ec-id">
                    <div style="margin-bottom:12px;">
                        <label style="display:block;font-size:11px;font-weight:600;color:#8B7355;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px;">Name</label>
                        <input type="text" name="cat_name" id="vso-ec-name" required style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                    </div>
                    <div style="margin-bottom:14px;">
                        <label style="display:block;font-size:11px;font-weight:600;color:#8B7355;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px;">Icon (emoji)</label>
                        <input type="text" name="cat_icon" id="vso-ec-icon" style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;text-align:center;" maxlength="4">
                    </div>
                    <div style="display:flex;gap:8px;">
                        <button type="submit" name="vso_update_cat" class="button button-primary">Save</button>
                        <button type="button" class="button" onclick="document.getElementById('vso-edit-cat').style.display='none'">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function vsoEditCat(id,name,icon){
        document.getElementById('vso-ec-id').value=id;
        document.getElementById('vso-ec-name').value=name;
        document.getElementById('vso-ec-icon').value=icon;
        document.getElementById('vso-edit-cat').style.display='flex';
    }

    // Drag and drop reorder
    (function(){
        const list=document.getElementById('vso-cat-list');
        if(!list)return;
        let dragged=null;
        list.querySelectorAll('.vso-cat-row').forEach(row=>{
            row.addEventListener('dragstart',e=>{dragged=row;row.style.opacity='.4';});
            row.addEventListener('dragend',e=>{dragged=null;row.style.opacity='1';});
            row.addEventListener('dragover',e=>{e.preventDefault();row.style.background='#faf6f1';});
            row.addEventListener('dragleave',e=>{row.style.background='';});
            row.addEventListener('drop',e=>{
                e.preventDefault();row.style.background='';
                if(dragged&&dragged!==row){
                    const rect=row.getBoundingClientRect();
                    const mid=rect.top+rect.height/2;
                    if(e.clientY<mid) list.insertBefore(dragged,row);
                    else list.insertBefore(dragged,row.nextSibling);
                    // Show save button and update order
                    document.getElementById('vso-save-order').style.display='inline-block';
                    const ids=[...list.querySelectorAll('.vso-cat-row')].map(r=>r.dataset.id);
                    document.getElementById('vso-cat-order').value=JSON.stringify(ids);
                }
            });
        });
    })();
    </script>
    <?php
}
