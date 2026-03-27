<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', 'vso_register_rest_routes' );

function vso_register_rest_routes() {
    $cap_check = function(){ return current_user_can('manage_vso_orders'); };
    register_rest_route('vs-order/v1','/products',['methods'=>'GET','callback'=>'vso_api_get_products','permission_callback'=>'__return_true']);
    register_rest_route('vs-order/v1','/orders',['methods'=>'POST','callback'=>'vso_api_create_order','permission_callback'=>'__return_true']);
    register_rest_route('vs-order/v1','/subscribe',['methods'=>'POST','callback'=>'vso_api_subscribe','permission_callback'=>'__return_true']);
    register_rest_route('vs-order/v1','/stripe/create-intent',['methods'=>'POST','callback'=>'vso_api_stripe_create_intent','permission_callback'=>'__return_true']);
    register_rest_route('vs-order/v1','/stripe/webhook',['methods'=>'POST','callback'=>'vso_api_stripe_webhook','permission_callback'=>'__return_true']);
    register_rest_route('vs-order/v1','/orders/(?P<id>\d+)/status',['methods'=>'POST','callback'=>'vso_api_update_order_status','permission_callback'=>$cap_check]);
    register_rest_route('vs-order/v1','/products/(?P<id>\d+)/toggle',['methods'=>'POST','callback'=>'vso_api_toggle_product','permission_callback'=>$cap_check]);
    register_rest_route('vs-order/v1','/products/bulk-toggle',['methods'=>'POST','callback'=>'vso_api_bulk_toggle_products','permission_callback'=>$cap_check]);
    register_rest_route('vs-order/v1','/orders/(?P<id>\d+)/messages',['methods'=>'GET','callback'=>'vso_api_get_messages','permission_callback'=>$cap_check]);
    register_rest_route('vs-order/v1','/orders/(?P<id>\d+)/messages',['methods'=>'POST','callback'=>'vso_api_send_message','permission_callback'=>$cap_check]);
    register_rest_route('vs-order/v1','/delivery/toggle',['methods'=>'POST','callback'=>'vso_api_toggle_delivery','permission_callback'=>$cap_check]);
}

function vso_api_get_products( $request ) {
    // Prevent any caching of this endpoint
    nocache_headers();

    $delivery_active = get_option('vso_delivery_active','0')==='1';
    $max_orders = (int)get_option('vso_max_orders',0);
    $current_count = (int)get_option('vso_current_order_count',0);
    $at_capacity = $max_orders > 0 && $current_count >= $max_orders;

    // Get ALL active products (no cache, force fresh query)
    $posts = get_posts([
        'post_type'      => 'vs_product',
        'posts_per_page' => 200,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
        'post_status'    => 'publish',
        'meta_query'     => [['key'=>'_vso_active','value'=>'1']], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small dataset, max ~200 products
        'suppress_filters' => true, // bypass any query filters/caching
    ]);

    $categories=[];$products=[];
    foreach($posts as $p){
        $terms=wp_get_object_terms($p->ID,'vs_product_cat');
        $cs=!empty($terms)?$terms[0]->slug:'uncategorized';
        $cn=!empty($terms)?$terms[0]->name:'Other';
        if(!isset($categories[$cs])){
            // Use term_meta _vso_order for sorting, fallback to term_id
            $cat_order = !empty($terms) ? (int)(get_term_meta($terms[0]->term_id,'_vso_order',true)?:$terms[0]->term_id) : 99999;
            $categories[$cs]=['slug'=>$cs,'name'=>$cn,'order'=>$cat_order];
        }
        $products[]=[
            'id'          => $p->ID,
            'name'        => $p->post_title,
            'description' => get_post_meta($p->ID,'_vso_description',true),
            'price'       => (float)get_post_meta($p->ID,'_vso_price',true),
            'category'    => $cs,
            'image'       => get_the_post_thumbnail_url($p->ID,'medium')?:null,
            'imageFull'   => get_the_post_thumbnail_url($p->ID,'large')?:null,
        ];
    }
    usort($categories,function($a,$b){return $a['order']-$b['order'];});

    $response = rest_ensure_response([
        'categories'    => array_values($categories),
        'products'      => $products,
        'deliveryActive'=> $delivery_active && !$at_capacity,
        'atCapacity'    => $at_capacity,
        'uberEatsUrl'   => get_option('vso_ubereats_url',''),
        'closedMessage' => get_option('vso_closed_message',''),
        'minimumOrder'  => (float)get_option('vso_minimum_order',100),
        'weeklyZone'    => get_option('vso_weekly_zone',''),
        'weeklyDeadline'=> get_option('vso_weekly_deadline',''),
        'weeklyDeliveryDay' => get_option('vso_weekly_delivery_day',''),
        'orderCount'    => $current_count,
        'maxOrders'     => $max_orders,
    ]);

    // Force no-cache on the REST response
    $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $response->header('Pragma', 'no-cache');

    return $response;
}

function vso_api_create_order( $request ) {
    if (get_option('vso_delivery_active','0')!=='1')
        return new WP_Error('delivery_closed','Orders are currently closed.',['status'=>403]);

    $max = (int)get_option('vso_max_orders',0);
    $cur = (int)get_option('vso_current_order_count',0);
    if ($max > 0 && $cur >= $max)
        return new WP_Error('at_capacity','Sorry, we\'ve reached capacity for this delivery round.',['status'=>403]);

    $body=$request->get_json_params();
    foreach(['items','name','email','phone'] as $f)
        if(empty($body[$f])) return new WP_Error('missing_field',"Field '{$f}' is required.",['status'=>400]);

    $items=[];$subtotal=0;
    foreach($body['items'] as $item){
        $product=get_post($item['id']);
        if(!$product||$product->post_type!=='vs_product')continue;
        if(get_post_meta($item['id'],'_vso_active',true)!=='1')continue;
        $price=(float)get_post_meta($item['id'],'_vso_price',true);$qty=max(1,(int)$item['qty']);
        $items[]=['id'=>$item['id'],'name'=>$product->post_title,'price'=>$price,'qty'=>$qty];
        $subtotal+=$price*$qty;
    }
    if(empty($items)) return new WP_Error('empty_cart','No valid items.',['status'=>400]);

    $min = (float)get_option('vso_minimum_order',100);
    if ($subtotal < $min)
        return new WP_Error('below_minimum',"Minimum order is \${$min}. Your total is \$".number_format($subtotal,2).".",['status'=>400]);

    $result=vso_create_order([
        'items'=>$items,'subtotal'=>$subtotal,'total'=>$subtotal,
        'name'=>$body['name'],'email'=>$body['email'],'phone'=>$body['phone'],
        'address'=>$body['address']??'','zone'=>$body['zone']??get_option('vso_weekly_zone',''),
        'notes'=>$body['notes']??'',
        'payment_status'=>$body['payment_status']??'pending','stripe_pi'=>$body['stripe_pi']??'',
    ]);
    if(is_wp_error($result))return $result;

    // Increment order count
    update_option('vso_current_order_count', $cur + 1);

    vso_send_order_confirmation($result['order_id']);
    vso_send_admin_notification($result['order_id']);

    return rest_ensure_response(['success'=>true,'ref'=>$result['ref'],'order_id'=>$result['order_id'],'total'=>$subtotal]);
}

function vso_api_subscribe( $request ) {
    $body=$request->get_json_params();
    $result=vso_add_subscriber(['email'=>$body['email']??'','name'=>$body['name']??'','zone'=>$body['zone']??'']);
    if(!$result['success']) return new WP_Error('subscribe_error',$result['message'],['status'=>400]);
    return rest_ensure_response($result);
}

function vso_api_toggle_delivery($request){
    $cur=get_option('vso_delivery_active','0');$new=$cur==='1'?'0':'1';
    update_option('vso_delivery_active',$new);
    if($new==='1')update_option('vso_current_order_count',0);
    return rest_ensure_response(['success'=>true,'active'=>$new==='1']);
}

function vso_api_update_order_status($request){
    $id=(int)$request['id'];$body=$request->get_json_params();$ns=sanitize_text_field($body['status']??'');
    if(!in_array($ns,array_keys(vso_order_statuses())))return new WP_Error('invalid_status','Invalid status.',['status'=>400]);
    $os=get_post_meta($id,'_vso_status',true);update_post_meta($id,'_vso_status',$ns);
    if($os!==$ns)vso_send_status_update_email($id,$ns);
    return rest_ensure_response(['success'=>true,'status'=>$ns]);
}

function vso_api_toggle_product($request){
    $id=(int)$request['id'];$cur=get_post_meta($id,'_vso_active',true);$nv=$cur==='1'?'0':'1';
    update_post_meta($id,'_vso_active',$nv);
    return rest_ensure_response(['success'=>true,'active'=>$nv==='1']);
}

function vso_api_bulk_toggle_products($request){
    $body=$request->get_json_params();
    $ids=$body['ids']??[];$active=$body['active']?'1':'0';$count=0;
    foreach($ids as $id){
        $id=(int)$id;if($id>0){update_post_meta($id,'_vso_active',$active);$count++;}
    }
    return rest_ensure_response(['success'=>true,'count'=>$count,'active'=>$active==='1']);
}
