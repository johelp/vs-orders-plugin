<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Send order confirmation email to customer
 */
function vso_send_order_confirmation( $order_id ) {
    $email = get_post_meta( $order_id, '_vso_customer_email', true );
    $name  = get_post_meta( $order_id, '_vso_customer_name', true );
    $ref   = get_post_meta( $order_id, '_vso_ref', true );
    $items = get_post_meta( $order_id, '_vso_items', true );
    $total = get_post_meta( $order_id, '_vso_total', true );
    $zone  = get_post_meta( $order_id, '_vso_customer_zone', true );

    $items_html = '';
    foreach ( $items as $item ) {
        $line_total = number_format( $item['price'] * $item['qty'], 2 );
        $items_html .= "<tr>
            <td style='padding:8px 12px;border-bottom:1px solid #f0ebe4;font-family:Georgia,serif;'>{$item['name']}</td>
            <td style='padding:8px 12px;border-bottom:1px solid #f0ebe4;text-align:center;'>{$item['qty']}</td>
            <td style='padding:8px 12px;border-bottom:1px solid #f0ebe4;text-align:right;'>\${$line_total}</td>
        </tr>";
    }

    $subject = "Visit Sicily NYC — Order {$ref} Confirmed";

    $body = "
    <div style='max-width:600px;margin:0 auto;font-family:Georgia,serif;color:#2C1810;'>
        <div style='background:#2C1810;padding:24px;text-align:center;'>
            <h1 style='color:#D4AF37;font-size:24px;margin:0;letter-spacing:2px;'>VISIT SICILY NYC</h1>
        </div>

        <div style='padding:32px 24px;background:#FAF6F1;'>
            <h2 style='color:#2C1810;margin:0 0 8px;'>Grazie, {$name}!</h2>
            <p style='color:#8B7355;margin:0 0 24px;'>Your order has been received. Here are the details:</p>

            <div style='background:white;border-radius:8px;padding:16px;margin-bottom:20px;'>
                <p style='margin:0 0 4px;'><strong>Order Reference:</strong> <span style='color:#D4AF37;font-size:18px;'>{$ref}</span></p>
                <p style='margin:0;'><strong>Delivery Zone:</strong> {$zone}</p>
            </div>

            <table style='width:100%;border-collapse:collapse;background:white;border-radius:8px;overflow:hidden;'>
                <thead>
                    <tr style='background:#2C1810;color:#D4AF37;'>
                        <th style='padding:10px 12px;text-align:left;'>Item</th>
                        <th style='padding:10px 12px;text-align:center;'>Qty</th>
                        <th style='padding:10px 12px;text-align:right;'>Price</th>
                    </tr>
                </thead>
                <tbody>{$items_html}</tbody>
                <tfoot>
                    <tr>
                        <td colspan='2' style='padding:12px;font-weight:bold;font-size:16px;'>Total</td>
                        <td style='padding:12px;text-align:right;font-weight:bold;font-size:16px;color:#D4AF37;'>\$" . number_format($total, 2) . "</td>
                    </tr>
                </tfoot>
            </table>

            <div style='margin-top:24px;padding:16px;background:#fff8e1;border-radius:8px;border-left:4px solid #D4AF37;'>
                <p style='margin:0;font-size:14px;'>📍 <strong>Delivery Note:</strong> We deliver across NYC by zones each week. We'll contact you to confirm your delivery day based on your area. Follow <strong>@visitsicilynyc</strong> for zone schedule updates.</p>
            </div>

            <p style='margin-top:24px;color:#8B7355;font-size:13px;'>If you have questions, reply to this email or call us at (917) 261-2033.</p>
        </div>

        <div style='background:#2C1810;padding:16px;text-align:center;'>
            <p style='color:#8B7355;font-size:12px;margin:0;'>Visit Sicily NYC — 86 E 7th St, New York, NY 10003</p>
        </div>
    </div>";

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: Visit Sicily NYC <orders@visitsicilynyc.com>',
        'Reply-To: orders@visitsicilynyc.com',
    ];

    wp_mail( $email, $subject, $body, $headers );
}

/**
 * Send notification to admin
 */
function vso_send_admin_notification( $order_id ) {
    $admin_email = get_option( 'vso_admin_email', get_option( 'admin_email' ) );
    $ref   = get_post_meta( $order_id, '_vso_ref', true );
    $name  = get_post_meta( $order_id, '_vso_customer_name', true );
    $phone = get_post_meta( $order_id, '_vso_customer_phone', true );
    $total = get_post_meta( $order_id, '_vso_total', true );
    $zone  = get_post_meta( $order_id, '_vso_customer_zone', true );
    $items = get_post_meta( $order_id, '_vso_items', true );
    $payment = get_post_meta( $order_id, '_vso_payment_status', true );

    $items_text = '';
    foreach ( $items as $item ) {
        $items_text .= "  • {$item['name']} × {$item['qty']} — \$" . number_format($item['price'] * $item['qty'], 2) . "\n";
    }

    $subject = "🇮🇹 New Order {$ref} — \$" . number_format($total, 2) . " — {$name}";

    $body = "
    <div style='max-width:600px;margin:0 auto;font-family:Arial,sans-serif;'>
        <div style='background:#2C1810;padding:16px;text-align:center;'>
            <h2 style='color:#D4AF37;margin:0;'>New Order Received!</h2>
        </div>
        <div style='padding:20px;background:#f9f9f9;'>
            <p><strong>Reference:</strong> {$ref}</p>
            <p><strong>Customer:</strong> {$name}</p>
            <p><strong>Phone:</strong> {$phone}</p>
            <p><strong>Zone:</strong> {$zone}</p>
            <p><strong>Payment:</strong> " . ucfirst($payment) . "</p>
            <p><strong>Total:</strong> \$" . number_format($total, 2) . "</p>
            <hr>
            <pre style='font-family:monospace;font-size:13px;'>{$items_text}</pre>
            <hr>
            <p><a href='" . admin_url('admin.php?page=vs-orders') . "'>View in Dashboard →</a></p>
        </div>
    </div>";

    $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
    wp_mail( $admin_email, $subject, $body, $headers );
}

/**
 * Send status update email to customer
 */
function vso_send_status_update_email( $order_id, $new_status ) {
    $email = get_post_meta( $order_id, '_vso_customer_email', true );
    $name  = get_post_meta( $order_id, '_vso_customer_name', true );
    $ref   = get_post_meta( $order_id, '_vso_ref', true );

    $statuses = vso_order_statuses();
    $status_label = $statuses[ $new_status ]['label'] ?? ucfirst( $new_status );
    $status_color = $statuses[ $new_status ]['color'] ?? '#333';

    $messages = [
        'confirmed' => 'Your order has been confirmed! We\'re scheduling your delivery.',
        'preparing' => 'Great news! Your order is being prepared with love.',
        'delivered' => 'Your order has been delivered. Buon appetito! 🇮🇹',
        'cancelled' => 'Your order has been cancelled. If you have questions, please reply to this email.',
    ];
    $msg = $messages[ $new_status ] ?? "Your order status has been updated to: {$status_label}";

    $subject = "Visit Sicily NYC — Order {$ref} is now {$status_label}";

    $body = "
    <div style='max-width:600px;margin:0 auto;font-family:Georgia,serif;color:#2C1810;'>
        <div style='background:#2C1810;padding:20px;text-align:center;'>
            <h1 style='color:#D4AF37;font-size:20px;margin:0;letter-spacing:2px;'>VISIT SICILY NYC</h1>
        </div>
        <div style='padding:32px 24px;background:#FAF6F1;text-align:center;'>
            <p style='font-size:14px;color:#8B7355;margin:0 0 8px;'>Order {$ref}</p>
            <div style='display:inline-block;padding:8px 20px;background:{$status_color};color:white;border-radius:20px;font-size:16px;font-weight:bold;margin:12px 0;'>{$status_label}</div>
            <p style='font-size:16px;margin:16px 0 0;'>{$name}, {$msg}</p>
        </div>
        <div style='background:#2C1810;padding:12px;text-align:center;'>
            <p style='color:#8B7355;font-size:12px;margin:0;'>86 E 7th St, New York, NY 10003 | (917) 261-2033</p>
        </div>
    </div>";

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: Visit Sicily NYC <orders@visitsicilynyc.com>',
        'Reply-To: orders@visitsicilynyc.com',
    ];

    wp_mail( $email, $subject, $body, $headers );
}
