<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function vso_create_messages_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'vso_messages';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        direction ENUM('outgoing','incoming') NOT NULL DEFAULT 'outgoing',
        channel ENUM('email','whatsapp','internal') NOT NULL DEFAULT 'email',
        subject VARCHAR(255) DEFAULT '',
        body TEXT NOT NULL,
        sender VARCHAR(255) NOT NULL,
        message_id VARCHAR(255) DEFAULT '',
        status VARCHAR(20) DEFAULT 'sent',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME DEFAULT NULL,
        INDEX idx_order (order_id),
        INDEX idx_direction (direction)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function vso_save_message( $data ) {
    global $wpdb;
    return $wpdb->insert( $wpdb->prefix . 'vso_messages', [
        'order_id'   => $data['order_id'],
        'direction'  => $data['direction'],
        'channel'    => $data['channel'] ?? 'email',
        'subject'    => $data['subject'] ?? '',
        'body'       => $data['body'],
        'sender'     => $data['sender'],
        'message_id' => $data['message_id'] ?? '',
        'status'     => $data['status'] ?? 'sent',
        'created_at' => current_time('mysql'),
    ]);
}

function vso_get_order_messages( $order_id ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}vso_messages WHERE order_id = %d ORDER BY created_at ASC",
        $order_id
    ), ARRAY_A );
}

function vso_api_get_messages( $request ) {
    return rest_ensure_response( vso_get_order_messages( (int)$request['id'] ) );
}

/**
 * Send message to customer from admin panel
 * Uses same From headers as order confirmation emails (which work)
 */
function vso_api_send_message( $request ) {
    $order_id = (int) $request['id'];
    $body = $request->get_json_params();
    $message_body = sanitize_textarea_field( $body['body'] ?? '' );

    if ( empty( $message_body ) ) {
        return new WP_Error( 'empty_message', 'Message body is required.', [ 'status' => 400 ] );
    }

    $customer_email = get_post_meta( $order_id, '_vso_customer_email', true );
    $customer_name  = get_post_meta( $order_id, '_vso_customer_name', true );
    $ref            = get_post_meta( $order_id, '_vso_ref', true );

    if ( empty( $customer_email ) ) {
        return new WP_Error( 'no_email', 'Customer has no email address on file.', [ 'status' => 400 ] );
    }

    // Use the SAME From that works for order confirmation emails
    $from_name  = 'Visit Sicily NYC';
    $from_email = 'orders@visitsicilynyc.com';

    // But allow override from settings if configured
    $admin_email = get_option( 'vso_admin_email', '' );
    if ( ! empty( $admin_email ) && strpos( $admin_email, '@visitsicilynyc.com' ) !== false ) {
        $from_email = $admin_email;
    }

    $email_subject = "Visit Sicily NYC — Re: Order {$ref}";

    $email_body = "
    <div style='max-width:600px;margin:0 auto;font-family:Georgia,serif;color:#2C1810;'>
        <div style='background:#2C1810;padding:16px;text-align:center;'>
            <h1 style='color:#D4AF37;font-size:18px;margin:0;letter-spacing:2px;'>VISIT SICILY NYC</h1>
        </div>
        <div style='padding:24px;background:#FAF6F1;'>
            <p style='color:#8B7355;font-size:13px;margin:0 0 16px;'>Re: Order {$ref}</p>
            <div style='background:white;border-radius:8px;padding:16px;'>" . nl2br( esc_html( $message_body ) ) . "</div>
            <p style='margin-top:16px;color:#8B7355;font-size:12px;'>Reply directly to this email or call (917) 261-2033.</p>
        </div>
        <div style='background:#2C1810;padding:12px;text-align:center;'>
            <p style='color:#8B7355;font-size:11px;margin:0;'>Visit Sicily NYC — 86 E 7th St, New York, NY 10003</p>
        </div>
    </div>";

    // Exact same header format as order confirmation emails in emails.php
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $from_name . ' <' . $from_email . '>',
        'Reply-To: ' . $from_email,
    ];

    // Capture wp_mail errors
    $mail_error = '';
    $error_handler = function( $wp_error ) use ( &$mail_error ) {
        $mail_error = $wp_error->get_error_message();
    };
    add_action( 'wp_mail_failed', $error_handler );

    $sent = wp_mail( $customer_email, $email_subject, $email_body, $headers );

    remove_action( 'wp_mail_failed', $error_handler );

    // Always log the message
    vso_save_message([
        'order_id'  => $order_id,
        'direction' => 'outgoing',
        'channel'   => 'email',
        'subject'   => $email_subject,
        'body'      => $message_body,
        'sender'    => $from_name,
        'status'    => $sent ? 'sent' : 'failed',
    ]);

    if ( ! $sent ) {
        $debug = 'Email failed.';
        if ( $mail_error ) {
            $debug .= ' PHP error: ' . $mail_error;
        }
        $debug .= ' | To: ' . $customer_email . ' | From: ' . $from_email;
        error_log( 'VSO Message: ' . $debug );

        return rest_ensure_response([
            'success' => false,
            'message' => 'Email failed to send. Error: ' . ( $mail_error ?: 'Unknown' ) . '. Try installing WP Mail SMTP plugin.',
            'debug'   => $debug,
        ]);
    }

    return rest_ensure_response([
        'success' => true,
        'message' => 'Message sent to ' . $customer_email,
    ]);
}

function vso_process_incoming_email( $from, $subject, $body, $headers = [] ) {
    if ( preg_match('/VS-\d{6}-\d{4}/', $subject, $matches) ) {
        $orders = get_posts(['post_type'=>'vs_order','meta_key'=>'_vso_ref','meta_value'=>$matches[0],'posts_per_page'=>1]);
        if (!empty($orders)) {
            vso_save_message(['order_id'=>$orders[0]->ID,'direction'=>'incoming','channel'=>'email','subject'=>$subject,'body'=>$body,'sender'=>$from,'message_id'=>$headers['Message-ID']??'']);
            return true;
        }
    }
    return false;
}
