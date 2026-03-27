<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Save subscriber locally and sync to MailerLite if configured
 */
function vso_add_subscriber( $data ) {
    $email = sanitize_email( $data['email'] ?? '' );
    $name  = sanitize_text_field( $data['name'] ?? '' );
    $zone  = sanitize_text_field( $data['zone'] ?? '' );

    if ( empty( $email ) || ! is_email( $email ) ) {
        return [ 'success' => false, 'message' => 'Please enter a valid email.' ];
    }

    // ─── Save locally ───
    $subs = get_option( 'vso_subscribers', [] );
    foreach ( $subs as $s ) {
        if ( $s['email'] === $email ) {
            // Update existing subscriber's name/zone if provided
            $subs = array_map( function($s) use ($email, $name, $zone) {
                if ( $s['email'] === $email ) {
                    if ( $name ) $s['name'] = $name;
                    if ( $zone ) $s['zone'] = $zone;
                }
                return $s;
            }, $subs );
            update_option( 'vso_subscribers', $subs );
            // Still sync to MailerLite in case they weren't added there
            vso_mailerlite_sync( $email, $name, $zone );
            return [ 'success' => true, 'message' => 'You\'re already subscribed! We\'ll notify you.' ];
        }
    }

    $subs[] = [
        'email' => $email,
        'name'  => $name,
        'zone'  => $zone,
        'date'  => current_time( 'Y-m-d H:i' ),
    ];
    update_option( 'vso_subscribers', $subs );

    // ─── Sync to MailerLite ───
    vso_mailerlite_sync( $email, $name, $zone );

    return [ 'success' => true, 'message' => 'Subscribed! We\'ll notify you when delivery opens.' ];
}

/**
 * Sync subscriber to MailerLite (non-blocking)
 */
function vso_mailerlite_sync( $email, $name = '', $zone = '' ) {
    $api_key  = get_option( 'vso_mailerlite_api_key', '' );
    $group_id = get_option( 'vso_mailerlite_group_id', '' );

    if ( empty( $api_key ) ) return false;

    $body = [
        'email'  => $email,
        'fields' => [
            'name'      => $name ?: null,
            'last_name' => null,
            'city'      => 'New York',
            'z_i_p'     => null,
        ],
    ];

    // Add zone as a custom field
    if ( $zone ) {
        $body['fields']['company'] = $zone; // Using 'company' field for zone since it's a default field
    }

    // If group_id is set, add subscriber to that group
    if ( $group_id ) {
        $body['groups'] = [ $group_id ];
    }

    // MailerLite API v2 - create/update subscriber
    $response = wp_remote_post( 'https://connect.mailerlite.com/api/subscribers', [
        'timeout' => 10,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ],
        'body' => json_encode( $body ),
    ]);

    if ( is_wp_error( $response ) ) {
        error_log( 'VSO MailerLite sync failed: ' . $response->get_error_message() );
        return false;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code >= 200 && $code < 300 ) {
        return true;
    }

    $result = json_decode( wp_remote_retrieve_body( $response ), true );
    error_log( 'VSO MailerLite sync error: ' . json_encode( $result ) );
    return false;
}

/**
 * Send "Delivery is Open!" notification to all local subscribers
 * Called when delivery is activated
 */
function vso_notify_subscribers_delivery_open() {
    $subs = get_option( 'vso_subscribers', [] );
    if ( empty( $subs ) ) return 0;

    $site_url = home_url();
    // Find the page with the ordering shortcode
    $order_page = '';
    $pages = get_posts([
        'post_type' => 'page',
        'posts_per_page' => -1,
        's' => 'visit_sicily_order',
    ]);
    if ( ! empty( $pages ) ) {
        $order_page = get_permalink( $pages[0]->ID );
    }
    if ( ! $order_page ) $order_page = $site_url;

    $subject = '🇮🇹 Visit Sicily NYC — Delivery is Open! Order Now';

    $sent = 0;
    foreach ( $subs as $sub ) {
        $name = $sub['name'] ? $sub['name'] : 'there';
        $zone_text = $sub['zone'] ? "<p style='font-size:13px;color:#8B7355;'>Your zone: <strong>{$sub['zone']}</strong></p>" : '';

        $body = "
        <div style='max-width:600px;margin:0 auto;font-family:Georgia,serif;color:#2C1810;'>
            <div style='background:linear-gradient(135deg,#2C1810,#4A2C20);padding:28px;text-align:center;'>
                <h1 style='color:#D4AF37;font-size:22px;margin:0;letter-spacing:3px;'>VISIT SICILY NYC</h1>
                <p style='color:rgba(212,175,55,0.5);font-size:11px;margin:6px 0 0;letter-spacing:3px;'>DELIVERY IS OPEN</p>
            </div>
            <div style='padding:32px 24px;background:#FAF6F1;'>
                <h2 style='color:#2C1810;margin:0 0 12px;font-size:22px;'>Hey {$name}! 🎉</h2>
                <p style='color:#4A2C20;font-size:15px;line-height:1.6;margin:0 0 16px;'>
                    Great news — our special delivery service is now open!
                    Order your favorite Sicilian dishes and we'll bring them to you across NYC.
                </p>
                {$zone_text}
                <div style='text-align:center;margin:24px 0;'>
                    <a href='{$order_page}' style='display:inline-block;background:#2C1810;color:#D4AF37;padding:14px 32px;border-radius:10px;font-size:16px;font-weight:700;text-decoration:none;letter-spacing:1px;'>
                        Order Now →
                    </a>
                </div>
                <p style='color:#8B7355;font-size:12px;text-align:center;'>
                    Arancine · Cannoli · Fresh Pasta · and more, delivered to your door.
                </p>
            </div>
            <div style='background:#2C1810;padding:16px;text-align:center;'>
                <p style='color:#8B7355;font-size:11px;margin:0;'>Visit Sicily NYC — 86 E 7th St, East Village, NY 10003</p>
            </div>
        </div>";

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: Visit Sicily NYC <orders@visitsicilynyc.com>',
        ];

        if ( wp_mail( $sub['email'], $subject, $body, $headers ) ) {
            $sent++;
        }
    }

    // Log the notification
    update_option( 'vso_last_subscriber_notify', [
        'date'  => current_time( 'Y-m-d H:i' ),
        'count' => $sent,
        'total' => count( $subs ),
    ]);

    return $sent;
}

/**
 * Get subscriber stats
 */
function vso_subscriber_stats() {
    $subs = get_option( 'vso_subscribers', [] );
    $zones = [];
    foreach ( $subs as $s ) {
        $z = $s['zone'] ?: 'Unknown';
        $zones[ $z ] = ( $zones[ $z ] ?? 0 ) + 1;
    }
    arsort( $zones );
    return [
        'total' => count( $subs ),
        'zones' => $zones,
        'last_notify' => get_option( 'vso_last_subscriber_notify', null ),
    ];
}
